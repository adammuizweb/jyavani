<?php
declare(strict_types=1);

if (!defined('DASHBOARD_CONTEXT')) define('DASHBOARD_CONTEXT', true);

require_once __DIR__ . '/../_guard.php';
require_once __DIR__ . '/../_notify.php';
require_once __DIR__ . '/../bin/_undo.php';

adiwira_cosmetic_404_on_direct_open();
[$uid] = adiwira_require_login($pdo, true);

if (!function_exists('is_ajax_request')) {
    function is_ajax_request(): bool {
        return strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
            || str_contains(strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json');
    }
}

$defaultReturnTo = ADMIN_BASE_PATH . '/?page=admin/pages/index';
$returnTo = adiwira_safe_return_to((string)($_POST['return_to'] ?? ''), $defaultReturnTo);

if (!function_exists('respond_pages_bulk')) {
    function respond_pages_bulk(bool $ok, string $message, int $httpCode, array $extra, string $redirect): void {
        if (is_ajax_request()) {
            adiwira_json(array_merge(['ok' => $ok, 'message' => $message, 'redirect' => $redirect], $extra), $httpCode);
        }
        adiwira_redirect_with_flash($redirect, $ok ? 'success' : 'error', $message, 302, $extra);
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond_pages_bulk(false, __('Method Not Allowed'), 405, [], $returnTo);
}
if (!adiwira_csrf_validate((string)($_POST['csrf_token'] ?? ''))) {
    respond_pages_bulk(false, __('Invalid CSRF token.'), 419, [], $returnTo);
}

$ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['ids'] ?? [])), static fn(int $id): bool => $id > 0)));
sort($ids, SORT_NUMERIC);
if ($ids === []) respond_pages_bulk(false, __('No pages selected.'), 400, [], $returnTo);
if (count($ids) > 100) respond_pages_bulk(false, __('You can select up to 100 items at a time.'), 400, [], $returnTo);

$action = (string)($_POST['action'] ?? '');
$requiredPermission = match ($action) {
    'delete' => 'core.pages.trash',
    'change_status' => 'core.pages.update',
    'change_author' => 'core.pages.change_owner',
    'change_date' => 'core.pages.change_dates',
    default => '',
};
if ($requiredPermission === '' || user_permission_scope($pdo, $uid, $requiredPermission) === null) {
    respond_pages_bulk(false, __('Access denied.'), 403, [], $returnTo);
}

try {
    $pdo->beginTransaction();
    if (!authorization_lock_actor_permissions($pdo, $uid)) throw new DomainException('Page actor permission lock failed.');

    $in = implode(',', array_fill(0, count($ids), '?'));
    $selectedStmt = $pdo->prepare("SELECT id, status, status_revision, created_by FROM posts WHERE id IN ($in) AND type = 'page' AND is_deleted = 0 FOR UPDATE");
    $selectedStmt->execute($ids);
    $selectedPages = $selectedStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (count($selectedPages) !== count($ids)) throw new DomainException('Page selection changed.');
    $rawStatusesById = [];
    $statusRevisionsById = [];
    foreach ($selectedPages as $selectedPage) {
        $rawStatusesById[(int)$selectedPage['id']] = (string)($selectedPage['status'] ?? 'draft');
        $statusRevisionsById[(int)$selectedPage['id']] = (int)($selectedPage['status_revision'] ?? 0);
    }
    ksort($rawStatusesById, SORT_NUMERIC);
    $statusUndoEligible = $action === 'change_status';
    if (!authorization_lock_owner_contexts($pdo, array_column($selectedPages, 'created_by'))) {
        throw new DomainException('Page owner context lock failed.');
    }
    foreach ($selectedPages as &$selectedPage) {
        $editorStatus = apply_filters('admin_page_editor_status', (string)($selectedPage['status'] ?? 'draft'), $selectedPage, $pdo);
        if (!is_string($editorStatus) || !in_array($editorStatus, ['draft', 'published', 'private'], true)) {
            throw new DomainException('Page editor status is invalid.');
        }
        $selectedPage['status'] = $editorStatus;
        if ($statusUndoEligible && $editorStatus !== $rawStatusesById[(int)$selectedPage['id']]) {
            $statusUndoEligible = false;
        }
    }
    unset($selectedPage);
    foreach ($selectedPages as $page) {
        if (!user_can($pdo, $uid, $requiredPermission, ['owner_id' => (int)$page['created_by']])) {
            throw new DomainException('Page permission changed.');
        }
    }
    if ($action === 'delete') {
        do_action('admin_pages_bulk_before_mutation', $action, $selectedPages, $pdo, []);
        $stmt = $pdo->prepare("UPDATE posts SET is_deleted = 1, deleted_at = NOW(), updated_at = NOW(), updated_by = ? WHERE id IN ($in) AND type = 'page' AND is_deleted = 0");
        $stmt->execute(array_merge([$uid], $ids));
        $affected = $stmt->rowCount();
        if ($affected !== count($ids)) throw new RuntimeException('Bulk page deletion did not affect the complete selection.');
        $categoryMap = adiwira_bin_post_category_map($pdo, $ids);
        $undoItems = [];
        foreach ($ids as $selectedId) {
            $auditId = adiwira_bin_record_audit($pdo, 'page', $selectedId, $uid, 'page.trashed', ['bulk' => true]);
            $undoItems[] = [
                'id' => $selectedId,
                'audit_id' => $auditId,
                'category_ids' => $categoryMap[$selectedId] ?? [],
            ];
        }
        $pdo->commit();
        $extra = ['count' => $affected];
        try {
            $undoAction = adiwira_bin_issue_trash_undo($pdo, 'page', $uid, $undoItems);
            if ($undoAction !== null) {
                $extra['action'] = $undoAction;
            }
        } catch (Throwable $e) {
            error_log('pages/bulk_action undo issuance error: ' . $e->getMessage());
        }
        $successMessage = sprintf(__('%d page(s) moved to trash.'), $affected);
        try {
            respond_pages_bulk(true, $successMessage, 200, $extra, $returnTo);
        } catch (Throwable $notifyError) {
            error_log('pages/bulk_action deletion committed but notification failed: ' . $notifyError->getMessage());
            if (is_ajax_request()) {
                adiwira_json(['ok' => true, 'message' => $successMessage, 'count' => $affected, 'redirect' => $returnTo]);
            }
            header('Location: ' . $returnTo, true, 302);
            exit;
        }
    }

    if ($action === 'change_status') {
        $newStatus = (string)($_POST['status'] ?? '');
        if (!in_array($newStatus, ['draft', 'published', 'private'], true)) throw new InvalidArgumentException('Invalid status.');
        if ($newStatus !== 'draft' || array_filter($selectedPages, static fn(array $page): bool => (string)$page['status'] !== 'draft')) {
            foreach ($selectedPages as $page) {
                if (!user_can($pdo, $uid, 'core.pages.publish', ['owner_id' => (int)$page['created_by']])) {
                    throw new DomainException('Page publish permission changed.');
                }
            }
        }
        do_action('admin_pages_bulk_before_mutation', $action, $selectedPages, $pdo, ['status' => $newStatus]);
        $changedIds = [];
        foreach ($rawStatusesById as $selectedId => $previousStatus) {
            if ($previousStatus !== $newStatus) $changedIds[] = $selectedId;
        }
        $affected = 0;
        if ($changedIds !== []) {
            $stmt = $pdo->prepare("UPDATE posts SET status = ?, status_revision = status_revision + 1, updated_at = NOW(), updated_by = ? WHERE id IN ($in) AND type = 'page' AND is_deleted = 0 AND status <> ?");
            $stmt->execute(array_merge([$newStatus, $uid], $ids, [$newStatus]));
            $affected = $stmt->rowCount();
        }
        if ($affected !== count($changedIds)) {
            throw new RuntimeException('Bulk page status change affected an unexpected number of rows.');
        }
        if ($statusUndoEligible) {
            foreach ($selectedPages as $selectedPage) {
                $changedPage = array_replace($selectedPage, ['status' => $newStatus]);
                if (apply_filters('admin_page_editor_status', $newStatus, $changedPage, $pdo) !== $newStatus) {
                    $statusUndoEligible = false;
                    break;
                }
            }
        }
        $undoItems = [];
        if ($statusUndoEligible) {
            foreach ($changedIds as $selectedId) {
                $previousStatus = $rawStatusesById[$selectedId];
                $auditId = adiwira_bin_record_audit($pdo, 'page', $selectedId, $uid, 'page.status_changed', [
                    'bulk' => true,
                    'from' => $previousStatus,
                    'to' => $newStatus,
                ]);
                $undoItems[] = [
                    'id' => $selectedId,
                    'audit_id' => $auditId,
                    'previous_status' => $previousStatus,
                    'changed_status' => $newStatus,
                    'status_revision' => $statusRevisionsById[$selectedId] + 1,
                ];
            }
        }
        $pdo->commit();
        $extra = ['count' => $affected];
        if ($undoItems !== []) {
            try {
                $undoAction = adiwira_content_issue_status_undo($pdo, 'page', $uid, $undoItems);
                if ($undoAction !== null) {
                    $extra['action'] = $undoAction;
                }
            } catch (Throwable $e) {
                error_log('pages/bulk_action status undo issuance error: ' . $e->getMessage());
            }
        }
        $successMessage = sprintf(__('%d page(s) status changed to "%s".'), $affected, $newStatus);
        try {
            respond_pages_bulk(true, $successMessage, 200, $extra, $returnTo);
        } catch (Throwable $notifyError) {
            error_log('pages/bulk_action status change committed but notification failed: ' . $notifyError->getMessage());
            if (is_ajax_request()) {
                adiwira_json(array_merge(['ok' => true, 'message' => $successMessage, 'redirect' => $returnTo], $extra));
            }
            header('Location: ' . $returnTo, true, 302);
            exit;
        }
    }

    if ($action === 'change_author') {
        $authorId = (int)($_POST['author_id'] ?? 0);
        if ($authorId <= 0) throw new InvalidArgumentException('Invalid author.');
        $ownerLock = $pdo->prepare('SELECT id FROM users WHERE id = :id AND is_deleted = 0 AND is_locked = 0 FOR UPDATE');
        $ownerLock->execute([':id' => $authorId]);
        if (!$ownerLock->fetchColumn()) throw new InvalidArgumentException('Author not found.');
        if (!user_can($pdo, $uid, 'core.pages.change_owner', ['owner_id' => $authorId])) {
            throw new DomainException('Page owner target permission changed.');
        }
        do_action('admin_pages_bulk_before_mutation', $action, $selectedPages, $pdo, ['author_id' => $authorId]);
        $stmt = $pdo->prepare("UPDATE posts SET created_by = ?, updated_at = NOW(), updated_by = ? WHERE id IN ($in) AND type = 'page' AND is_deleted = 0");
        $stmt->execute(array_merge([$authorId, $uid], $ids));
        $affected = $stmt->rowCount();
        $pdo->commit();
        respond_pages_bulk(true, __('Author changed for') . " {$affected} " . __('pages.'), 200, ['count' => $affected], $returnTo);
    }

    $createdAt = trim((string)($_POST['created_at'] ?? ''));
    $updatedAt = trim((string)($_POST['updated_at'] ?? ''));
    if ($createdAt === '' && $updatedAt === '') throw new InvalidArgumentException('Please enter at least one date.');
    $fields = [];
    $values = [];
    foreach (['created_at' => $createdAt, 'updated_at' => $updatedAt] as $field => $value) {
        if ($value === '') continue;
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, new DateTimeZone('Asia/Jakarta'));
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new InvalidArgumentException('Invalid date format.');
        }
        $fields[] = "$field = ?";
        $values[] = $date->format('Y-m-d H:i:s');
    }
    do_action('admin_pages_bulk_before_mutation', $action, $selectedPages, $pdo, [
        'created_at' => $fields !== [] && str_starts_with($fields[0], 'created_at') ? $values[0] : null,
        'updated_at' => $updatedAt !== '' ? $values[count($values) - 1] : null,
    ]);
    $fields[] = 'updated_by = ?';
    $values[] = $uid;
    $stmt = $pdo->prepare('UPDATE posts SET ' . implode(', ', $fields) . " WHERE id IN ($in) AND type = 'page' AND is_deleted = 0");
    $stmt->execute(array_merge($values, $ids));
    $affected = $stmt->rowCount();
    $pdo->commit();
    respond_pages_bulk(true, __('Date changed for') . " {$affected} " . __('pages.'), 200, ['count' => $affected], $returnTo);
} catch (InvalidArgumentException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    respond_pages_bulk(false, __($e->getMessage()), 400, [], $returnTo);
} catch (DomainException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    respond_pages_bulk(false, __('Access denied.'), 403, [], $returnTo);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('pages/bulk_action.php error: ' . $e->getMessage());
    respond_pages_bulk(false, __('An error occurred during bulk action.'), 500, [], $returnTo);
}
