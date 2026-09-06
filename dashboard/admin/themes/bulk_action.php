<?php
declare(strict_types=1);

// /adiwira/admin/themes/bulk_action.php
if (!defined('DASHBOARD_CONTEXT')) {
    define('DASHBOARD_CONTEXT', true);
}

require_once __DIR__ . '/../_guard.php';
require_once __DIR__ . '/../_notify.php';
require_once __DIR__ . '/../bin/_undo.php';

adiwira_cosmetic_404_on_direct_open();

[$uid] = adiwira_require_login($pdo, true);

if (!function_exists('is_ajax_request')) {
    function is_ajax_request(): bool {
        $xrw = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
        $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
        return ($xrw === 'xmlhttprequest') || (strpos($accept, 'application/json') !== false);
    }
}

$defaultReturnTo = ADMIN_BASE_PATH . '/?page=admin/themes/index';
$returnTo = function_exists('adiwira_safe_return_to')
    ? adiwira_safe_return_to((string)($_POST['return_to'] ?? ''), $defaultReturnTo)
    : $defaultReturnTo;

if (!function_exists('respond_theme_bulk')) {
    function respond_theme_bulk(bool $ok, string $message = '', int $httpCode = 200, array $extra = [], ?string $redirect = null): void {
        $redirect = $redirect ?: ADMIN_BASE_PATH . '/?page=admin/themes/index';

        if (is_ajax_request()) {
            http_response_code($httpCode);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(array_merge([
                'ok' => $ok,
                'message' => $message,
                'redirect' => $redirect,
            ], $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        adiwira_redirect_with_flash($redirect, $ok ? 'success' : 'error', $message, 302, $extra);
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond_theme_bulk(false, 'Method Not Allowed', 405, [], $returnTo);
}

$token = (string)($_POST['csrf_token'] ?? '');
if (!adiwira_csrf_validate($token)) {
    respond_theme_bulk(false, __('Invalid CSRF token.'), 419, [], $returnTo);
}

$ids = $_POST['ids'] ?? [];
if (!is_array($ids) || empty($ids)) {
    respond_theme_bulk(false, __('No themes selected.'), 400, [], $returnTo);
}

$ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($v) => $v > 0)));
sort($ids, SORT_NUMERIC);
if (empty($ids)) {
    respond_theme_bulk(false, __('Invalid theme ID.'), 400, [], $returnTo);
}
if (count($ids) > 100) {
    respond_theme_bulk(false, __('You can select up to 100 items at a time.'), 400, [], $returnTo);
}

$action = (string)($_POST['action'] ?? '');
if ($action === '') {
    respond_theme_bulk(false, __('Unknown bulk action.'), 400, [], $returnTo);
}

$in = implode(',', array_fill(0, count($ids), '?'));

try {
    $pdo->beginTransaction();
    if (!authorization_lock_actor_permissions($pdo, $uid)) throw new DomainException('Theme actor permission lock failed.');
    $selected = $pdo->prepare("SELECT id, status, status_revision, created_by FROM posts WHERE id IN ($in) AND type = 'theme' AND is_deleted = 0 ORDER BY id FOR UPDATE");
    $selected->execute($ids);
    $themes = $selected->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (count($themes) !== count($ids) || !authorization_lock_owner_contexts($pdo, array_column($themes, 'created_by'))) {
        throw new DomainException('Theme selection changed.');
    }
    $permission = $action === 'delete' ? 'core.theme_content.delete' : 'core.theme_content.update';
    foreach ($themes as $theme) {
        if (!user_can($pdo, $uid, $permission, ['owner_id' => (int)$theme['created_by']])) throw new DomainException('Theme permission changed.');
    }

    if ($action === 'delete') {
        $stmt = $pdo->prepare("
            UPDATE posts
            SET is_deleted = 1, deleted_at = NOW(), updated_at = NOW(), updated_by = ?
            WHERE id IN ($in) AND type = 'theme' AND is_deleted = 0
        ");
        $stmt->execute(array_merge([$uid], $ids));
        $affected = $stmt->rowCount();
        if ($affected !== count($ids)) {
            throw new RuntimeException('Bulk theme deletion did not affect the complete selection.');
        }

        $undoItems = [];
        foreach ($ids as $selectedId) {
            $undoItems[] = [
                'id' => $selectedId,
                'audit_id' => adiwira_bin_record_audit(
                    $pdo,
                    'theme',
                    $selectedId,
                    $uid,
                    'theme.trashed',
                    ['bulk' => true]
                ),
            ];
        }

        $pdo->commit();
        $successMessage = sprintf(__('%d theme partial(s) moved to trash.'), $affected);
        try {
            $extra = ['count' => $affected];
            $undoAction = adiwira_bin_issue_trash_undo($pdo, 'theme', $uid, $undoItems);
            if ($undoAction !== null) {
                $extra['action'] = $undoAction;
            }
            respond_theme_bulk(true, $successMessage, 200, $extra, $returnTo);
        } catch (Throwable $notifyError) {
            error_log('[themes/bulk_action] deletion committed but notification failed: ' . $notifyError->getMessage());
            if (is_ajax_request()) {
                adiwira_json(['ok' => true, 'message' => $successMessage, 'count' => $affected, 'redirect' => $returnTo]);
            }
            header('Location: ' . $returnTo, true, 302);
            exit;
        }
    }

    if ($action === 'change_status') {
        $new_status = (string)($_POST['status'] ?? '');
        if (!in_array($new_status, ['draft','published','private'], true)) {
            $pdo->rollBack();
            respond_theme_bulk(false, __('Invalid status.'), 400, [], $returnTo);
        }

        $changedThemes = array_values(array_filter(
            $themes,
            static fn(array $theme): bool => (string)$theme['status'] !== $new_status
        ));

        $stmt = $pdo->prepare("
            UPDATE posts
            SET status = ?, status_revision = status_revision + 1, updated_at = NOW(), updated_by = ?
            WHERE id IN ($in) AND type = 'theme' AND is_deleted = 0 AND status <> ?
        ");
        $stmt->execute(array_merge([$new_status, $uid], $ids, [$new_status]));
        $affected = $stmt->rowCount();
        if ($affected !== count($changedThemes)) {
            throw new RuntimeException('Bulk theme status change did not affect the expected selection.');
        }

        $undoItems = [];
        foreach ($changedThemes as $theme) {
            $selectedId = (int)$theme['id'];
            $previousStatus = (string)$theme['status'];
            $undoItems[] = [
                'id' => $selectedId,
                'audit_id' => adiwira_bin_record_audit(
                    $pdo,
                    'theme',
                    $selectedId,
                    $uid,
                    'theme.status_changed',
                    ['bulk' => true, 'from' => $previousStatus, 'to' => $new_status]
                ),
                'previous_status' => $previousStatus,
                'changed_status' => $new_status,
                'status_revision' => (int)$theme['status_revision'] + 1,
            ];
        }

        $pdo->commit();
        $successMessage = sprintf(__('%d theme partial(s) status changed to "%s".'), $affected, $new_status);
        $extra = ['count' => $affected];
        try {
            if ($undoItems !== []) {
                $undoAction = adiwira_content_issue_status_undo($pdo, 'theme', $uid, $undoItems);
                if ($undoAction !== null) {
                    $extra['action'] = $undoAction;
                }
            }
        } catch (Throwable $undoError) {
            error_log('[themes/bulk_action] status change committed but Undo issuance failed: ' . $undoError->getMessage());
        }
        try {
            respond_theme_bulk(true, $successMessage, 200, $extra, $returnTo);
        } catch (Throwable $notifyError) {
            error_log('[themes/bulk_action] status change committed but notification failed: ' . $notifyError->getMessage());
            if (is_ajax_request()) {
                adiwira_json(array_merge([
                    'ok' => true,
                    'message' => $successMessage,
                    'redirect' => $returnTo,
                ], $extra));
            }
            header('Location: ' . $returnTo, true, 302);
            exit;
        }
    }

    $pdo->rollBack();
    respond_theme_bulk(false, __('Unknown bulk action.'), 400, [], $returnTo);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('themes/bulk_action.php error: ' . $e->getMessage());
    respond_theme_bulk(false, __('An error occurred during bulk processing.'), 500, [], $returnTo);
}
