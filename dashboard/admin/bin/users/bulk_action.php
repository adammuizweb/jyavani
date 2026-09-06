<?php
declare(strict_types=1);

// /adiwira/admin/bin/users/bulk_action.php
if (!defined('DASHBOARD_CONTEXT')) {
    define('DASHBOARD_CONTEXT', true);
}

require_once __DIR__ . '/../../_guard.php';
require_once __DIR__ . '/../../_notify.php';

adiwira_cosmetic_404_on_direct_open();

[$uid, $role] = adiwira_require_login($pdo, true);
$actor = authorization_actor($pdo, $uid);
$actorIsSiteOwner = $actor !== null && $actor['is_site_owner'] === true;

if (!function_exists('is_ajax_request')) {
    function is_ajax_request(): bool {
        $xrw = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
        $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
        return ($xrw === 'xmlhttprequest') || (strpos($accept, 'application/json') !== false);
    }
}

$defaultReturnTo = ADMIN_BASE_PATH . '/?page=admin/bin/users/index';
$returnTo = function_exists('adiwira_safe_return_to')
    ? adiwira_safe_return_to((string)($_POST['return_to'] ?? ''), $defaultReturnTo)
    : $defaultReturnTo;

if (!function_exists('respond_users_bin_bulk')) {
    function respond_users_bin_bulk(bool $ok, string $message = '', int $httpCode = 200, array $extra = [], ?string $redirect = null): void {
        $redirect = $redirect ?: ADMIN_BASE_PATH . '/?page=admin/bin/users/index';

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

        adiwira_redirect_with_flash($redirect, $ok ? 'success' : 'error', $message);
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond_users_bin_bulk(false, 'Method Not Allowed', 405, [], $returnTo);
}

$token = (string)($_POST['csrf_token'] ?? '');
if (!adiwira_csrf_validate($token)) {
    respond_users_bin_bulk(false, __('Invalid CSRF token.'), 419, [], $returnTo);
}

$ids = $_POST['ids'] ?? [];
if (!is_array($ids) || empty($ids)) {
    respond_users_bin_bulk(false, __('No users selected.'), 400, [], $returnTo);
}

$ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($v) => $v > 0)));
sort($ids, SORT_NUMERIC);
if (empty($ids)) {
    respond_users_bin_bulk(false, __('Invalid user ID.'), 400, [], $returnTo);
}
if (count($ids) > 100) {
    respond_users_bin_bulk(false, __('You can select up to 100 users at a time.'), 400, [], $returnTo);
}

$action = (string)($_POST['action'] ?? '');
if ($action === '') {
    respond_users_bin_bulk(false, __('Unknown bulk action.'), 400, [], $returnTo);
}
$requiredPermission = match ($action) {
    'restore' => 'core.users.restore',
    'delete_permanent' => 'core.users.purge',
    default => '',
};
if ($requiredPermission === '' || user_permission_scope($pdo, $uid, $requiredPermission) === null) {
    respond_users_bin_bulk(false, __('Access denied.'), 403, [], $returnTo);
}

$in = implode(',', array_fill(0, count($ids), '?'));

try {
    $pdo->beginTransaction();
    $actorIsSiteOwner = authorization_lock_site_owner_actor($pdo, $uid);
    $selectedStmt = $pdo->prepare(
        "SELECT id, is_site_owner FROM users WHERE id IN ($in) AND is_deleted = 1 FOR UPDATE"
    );
    $selectedStmt->execute($ids);
    $selectedUsers = $selectedStmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($selectedUsers) !== count($ids)) {
        $pdo->rollBack();
        respond_users_bin_bulk(false, __('One or more selected users are no longer in trash.'), 409, [], $returnTo);
    }
    foreach ($selectedUsers as $selectedUser) {
        $selectedId = (int)$selectedUser['id'];
        if ((int)$selectedUser['is_site_owner'] === 1 && !$actorIsSiteOwner) {
            $pdo->rollBack();
            respond_users_bin_bulk(false, __('Only a Site Owner can modify a Site Owner account.'), 403, [], $returnTo);
        }
        if (!user_can($pdo, $uid, $requiredPermission, ['owner_id' => $selectedId])) {
            $pdo->rollBack();
            respond_users_bin_bulk(false, __('You cannot modify one or more selected users.'), 403, [], $returnTo);
        }
    }

    if ($action === 'restore') {
        $sql = "UPDATE users
                SET is_deleted = 0,
                    updated_at = NOW()
                WHERE id IN ($in)
                  AND is_deleted = 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($ids);
        $affected = $stmt->rowCount();
        foreach ($selectedUsers as $selectedUser) {
            $selectedId = (int)$selectedUser['id'];
            if (!authorization_audit($pdo, 'user.restored', $uid, $selectedId, 'user', (string)$selectedId)) {
                throw new RuntimeException('Bulk user restore audit failed.');
            }
        }

        $pdo->commit();
        respond_users_bin_bulk(true, "Successfully restored  {$affected} user.", 200, ['count' => $affected], $returnTo);
    }

    if ($action === 'delete_permanent') {
        foreach ($selectedUsers as $selectedUser) {
            $selectedId = (int)$selectedUser['id'];
            if (!authorization_audit($pdo, 'user.purged', $uid, $selectedId, 'user', (string)$selectedId)) {
                throw new RuntimeException('Bulk user purge audit failed.');
            }
        }
        $sql = "DELETE FROM users
                WHERE id IN ($in)
                  AND is_deleted = 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($ids);
        $affected = $stmt->rowCount();

        $pdo->commit();
        respond_users_bin_bulk(true, "Permanently deleted  {$affected} user.", 200, ['count' => $affected], $returnTo);
    }

    $pdo->rollBack();
    respond_users_bin_bulk(false, __('Unknown bulk action.'), 400, [], $returnTo);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('bin/users/bulk_action.php error: ' . $e->getMessage());
    respond_users_bin_bulk(false, __('An error occurred during bulk action.'), 500, [], $returnTo);
}
