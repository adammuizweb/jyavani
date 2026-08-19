<?php
// /adiwira/admin/users/bulk_action.php
declare(strict_types=1);

if (!defined('DASHBOARD_CONTEXT')) {
    define('DASHBOARD_CONTEXT', true);
}

require_once __DIR__ . '/../_guard.php';
require_once __DIR__ . '/../_notify.php';

adiwira_cosmetic_404_on_direct_open();

[$uid, $role] = adiwira_require_login($pdo, true);

if (!function_exists('adiwira_users_is_ajax_request')) {
    function adiwira_users_is_ajax_request(): bool {
        $xrw = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
        $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
        return ($xrw === 'xmlhttprequest') || (strpos($accept, 'application/json') !== false);
    }
}

$defaultReturnTo = ADMIN_BASE_PATH . '/?page=admin/users/index';
$returnTo = function_exists('adiwira_safe_return_to')
    ? adiwira_safe_return_to((string)($_POST['return_to'] ?? ''), $defaultReturnTo)
    : $defaultReturnTo;

if (!function_exists('adiwira_users_bulk_respond')) {
    function adiwira_users_bulk_respond(bool $ok, string $message = '', int $httpCode = 200, array $extra = [], ?string $redirect = null): void {
        $redirect = $redirect ?: ADMIN_BASE_PATH . '/?page=admin/users/index';

        if (adiwira_users_is_ajax_request()) {
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
    adiwira_users_bulk_respond(false, __('Method Not Allowed'), 405, [], $returnTo);
}

$token = (string)($_POST['csrf_token'] ?? '');
if (!adiwira_csrf_validate($token)) {
    adiwira_users_bulk_respond(false, __('Invalid CSRF token.'), 419, [], $returnTo);
}

$ids = $_POST['ids'] ?? [];
if (!is_array($ids) || empty($ids)) {
    adiwira_users_bulk_respond(false, __('No users selected.'), 400, [], $returnTo);
}

$ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($v) => $v > 0)));
$ids = array_values(array_filter($ids, fn($v) => $v !== $uid)); // jangan proses diri sendiri

if (empty($ids)) {
    adiwira_users_bulk_respond(false, __('No valid user selected.'), 400, [], $returnTo);
}

$action = (string)($_POST['action'] ?? '');
if ($action === '') {
    adiwira_users_bulk_respond(false, __('Unknown bulk action.'), 400, [], $returnTo);
}
$requiredPermission = match ($action) {
    'delete' => 'core.users.delete',
    'lock', 'unlock' => 'core.users.lock',
    'change_role' => 'core.users.assign_roles',
    default => '',
};
if ($requiredPermission === '' || user_permission_scope($pdo, $uid, $requiredPermission) === null) {
    adiwira_users_bulk_respond(false, __('Access denied.'), 403, [], $returnTo);
}
$bulkActor = authorization_actor($pdo, $uid);
if ($action === 'change_role' && ($bulkActor === null || $bulkActor['is_site_owner'] !== true)) {
    adiwira_users_bulk_respond(false, __('Only a Site Owner can change role assignments.'), 403, [], $returnTo);
}

try {
    $pdo->beginTransaction();
    $actorStillSiteOwner = authorization_lock_site_owner_actor($pdo, $uid);
    if ($action === 'change_role' && !$actorStillSiteOwner) {
        $pdo->rollBack();
        adiwira_users_bulk_respond(false, __('Only a Site Owner can change role assignments.'), 403, [], $returnTo);
    }
    $ownerPlaceholders = implode(',', array_fill(0, count($ids), '?'));
    $ownerCheck = $pdo->prepare(
        "SELECT id, is_site_owner FROM users WHERE id IN ($ownerPlaceholders) AND is_deleted = 0 FOR UPDATE"
    );
    $ownerCheck->execute($ids);
    $selectedUsers = $ownerCheck->fetchAll(PDO::FETCH_ASSOC);
    if (count($selectedUsers) !== count($ids)) {
        $pdo->rollBack();
        adiwira_users_bulk_respond(false, __('One or more selected users are no longer active.'), 409, [], $returnTo);
    }
    foreach ($selectedUsers as $selectedUser) {
        $selectedId = (int)$selectedUser['id'];
        if ((int)$selectedUser['is_site_owner'] === 1) {
            $pdo->rollBack();
            adiwira_users_bulk_respond(false, __('Site Owner accounts cannot be changed through bulk actions.'), 403, [], $returnTo);
        }
        if (!user_can($pdo, $uid, $requiredPermission, ['owner_id' => $selectedId])) {
            $pdo->rollBack();
            adiwira_users_bulk_respond(false, __('You cannot modify one or more selected users.'), 403, [], $returnTo);
        }
    }

    if ($action === 'delete') {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $sql = "UPDATE users SET is_deleted = 1, updated_at = NOW() WHERE id IN ($in) AND is_site_owner = 0";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($ids);
        $cnt = $stmt->rowCount();

        $pdo->commit();
        adiwira_users_bulk_respond(true, sprintf(__('%d user(s) deleted.'), $cnt), 200, ['count' => $cnt], $returnTo);
    }

    if ($action === 'change_role') {
        $newRoleId = (int)($_POST['role_id'] ?? 0);
        $roleStmt = $pdo->prepare('SELECT id, name FROM roles WHERE id = :id LIMIT 1');
        $roleStmt->execute([':id' => $newRoleId]);
        $newRole = $roleStmt->fetch(PDO::FETCH_ASSOC);
        if (!$newRole) {
            $pdo->rollBack();
            adiwira_users_bulk_respond(false, __('Invalid target role.'), 400, [], $returnTo);
        }
        if (!authorization_actor_can_assign_roles($pdo, $uid, [$newRoleId])) {
            $pdo->rollBack();
            adiwira_users_bulk_respond(false, __('You cannot assign a role above your authority rank.'), 403, [], $returnTo);
        }

        $cnt = 0;
        $roleChanges = [];
        foreach ($ids as $targetId) {
            $roleChange = null;
            if (!authorization_assign_roles($pdo, $targetId, [$newRoleId], $uid, $roleChange)) {
                throw new RuntimeException('Failed to assign role to user ID ' . $targetId);
            }
            if ($roleChange !== null) $roleChanges[] = $roleChange;
            if (!authorization_audit(
                $pdo,
                'role.assigned',
                $uid,
                $targetId,
                'user',
                (string)$targetId,
                ['role_ids' => [$newRoleId]]
            )) {
                throw new RuntimeException('Failed to audit role assignment for user ID ' . $targetId);
            }
            $cnt++;
        }

        $pdo->commit();
        if (function_exists('do_action')) {
            foreach ($roleChanges as $roleChange) {
                try {
                    do_action('authorization_user_roles_changed', $roleChange['user_id'], $roleChange['old_role_ids'], $roleChange['new_role_ids'], $uid, $pdo);
                } catch (Throwable $hookError) {
                    error_log('[authorization_user_roles_changed] ' . $hookError->getMessage());
                }
            }
        }
        adiwira_users_bulk_respond(true, sprintf(__('%d user(s) role changed to "%s".'), $cnt, (string)$newRole['name']), 200, ['count' => $cnt], $returnTo);
    }

    if ($action === 'lock') {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $sql = "UPDATE users SET is_locked = 1, updated_at = NOW() WHERE id IN ($in) AND is_site_owner = 0";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($ids);
        $cnt = $stmt->rowCount();

        $pdo->commit();
        adiwira_users_bulk_respond(true, sprintf(__('%d user(s) locked.'), $cnt), 200, ['count' => $cnt], $returnTo);
    }

    if ($action === 'unlock') {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $sql = "UPDATE users SET is_locked = 0, updated_at = NOW() WHERE id IN ($in)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($ids);
        $cnt = $stmt->rowCount();

        $pdo->commit();
        adiwira_users_bulk_respond(true, sprintf(__('%d user(s) approved / unlocked.'), $cnt), 200, ['count' => $cnt], $returnTo);
    }

    $pdo->rollBack();
    adiwira_users_bulk_respond(false, __('Unknown bulk action.'), 400, [], $returnTo);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[users/bulk_action] ' . $e->getMessage());
    adiwira_users_bulk_respond(false, __('An error occurred during bulk action.'), 500, [], $returnTo);
}
