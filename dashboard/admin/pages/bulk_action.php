<?php
declare(strict_types=1);

if (!defined('DASHBOARD_CONTEXT')) define('DASHBOARD_CONTEXT', true);

require_once __DIR__ . '/../_guard.php';
require_once __DIR__ . '/../_notify.php';

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
        adiwira_redirect_with_flash($redirect, $ok ? 'success' : 'error', $message);
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond_pages_bulk(false, __('Method Not Allowed'), 405, [], $returnTo);
}
if (!adiwira_csrf_validate((string)($_POST['csrf_token'] ?? ''))) {
    respond_pages_bulk(false, __('Invalid CSRF token.'), 419, [], $returnTo);
}

$ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['ids'] ?? [])), static fn(int $id): bool => $id > 0)));
if ($ids === []) respond_pages_bulk(false, __('No pages selected.'), 400, [], $returnTo);

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
    $selectedStmt = $pdo->prepare("SELECT id, status, created_by FROM posts WHERE id IN ($in) AND type = 'page' AND is_deleted = 0 FOR UPDATE");
    $selectedStmt->execute($ids);
    $selectedPages = $selectedStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (count($selectedPages) !== count($ids)) throw new DomainException('Page selection changed.');
    if (!authorization_lock_owner_contexts($pdo, array_column($selectedPages, 'created_by'))) {
        throw new DomainException('Page owner context lock failed.');
    }
    foreach ($selectedPages as &$selectedPage) {
        $editorStatus = apply_filters('admin_page_editor_status', (string)($selectedPage['status'] ?? 'draft'), $selectedPage, $pdo);
        if (!is_string($editorStatus) || !in_array($editorStatus, ['draft', 'published', 'private'], true)) {
            throw new DomainException('Page editor status is invalid.');
        }
        $selectedPage['status'] = $editorStatus;
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
        $pdo->prepare("DELETE FROM post_categories WHERE post_id IN ($in)")->execute($ids);
        $pdo->commit();
        respond_pages_bulk(true, sprintf(__('%d page(s) deleted.'), $affected), 200, ['count' => $affected], $returnTo);
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
        $stmt = $pdo->prepare("UPDATE posts SET status = ?, updated_at = NOW(), updated_by = ? WHERE id IN ($in) AND type = 'page' AND is_deleted = 0");
        $stmt->execute(array_merge([$newStatus, $uid], $ids));
        $affected = $stmt->rowCount();
        $pdo->commit();
        respond_pages_bulk(true, sprintf(__('%d page(s) status changed to "%s".'), $affected, $newStatus), 200, ['count' => $affected], $returnTo);
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
