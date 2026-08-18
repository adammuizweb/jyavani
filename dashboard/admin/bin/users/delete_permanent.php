<?php
declare(strict_types=1);

// /adiwira/admin/bin/users/delete_permanent.php
if (!defined('DASHBOARD_CONTEXT')) {
    define('DASHBOARD_CONTEXT', true);
}

require_once __DIR__ . '/../../_guard.php';
require_once __DIR__ . '/../../_notify.php';

$defaultReturnTo = ADMIN_BASE_PATH . '/?page=admin/bin/users/index';
$returnTo = function_exists('adiwira_safe_return_to')
    ? adiwira_safe_return_to((string)($_POST['return_to'] ?? ''), $defaultReturnTo)
    : $defaultReturnTo;

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    adiwira_redirect_with_flash($returnTo, 'error', __('Method not allowed.'));
}

$identity = adiwira_fetch_identity($pdo);
if (($identity['ok'] ?? false) !== true) {
    adiwira_redirect_with_flash($returnTo, 'error', __('Access denied.'));
}

$role = (string)($identity['role'] ?? 'guest');
$uid = (int)($identity['uid'] ?? 0);
$actor = authorization_actor($pdo, $uid);
$actorIsSiteOwner = $actor !== null && $actor['is_site_owner'] === true;
$token = (string)($_POST['csrf_token'] ?? '');
if (!adiwira_csrf_validate($token)) {
    adiwira_redirect_with_flash($returnTo, 'error', __('Invalid CSRF token.'));
}

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    adiwira_redirect_with_flash($returnTo, 'error', __('ID user tidak valid.'));
}

try {
    $pdo->beginTransaction();
    $actorIsSiteOwner = authorization_lock_site_owner_actor($pdo, $uid);
    $stmt = $pdo->prepare("
        SELECT id, is_site_owner
        FROM users
        WHERE id = :id AND is_deleted = 1
        LIMIT 1
        FOR UPDATE
    ");
    $stmt->execute([':id' => $id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        $pdo->rollBack();
        adiwira_redirect_with_flash($returnTo, 'error', __('User tidak ditemukan di trash.'));
    }
    if (!user_can($pdo, $uid, 'core.users.purge', ['owner_id' => $id])) {
        $pdo->rollBack();
        adiwira_redirect_with_flash($returnTo, 'error', __('Access denied.'));
    }
    if ((int)$user['is_site_owner'] === 1 && !$actorIsSiteOwner) {
        $pdo->rollBack();
        adiwira_redirect_with_flash($returnTo, 'error', __('Only a Site Owner can modify a Site Owner account.'));
    }
    if (!authorization_audit($pdo, 'user.purged', $uid, $id, 'user', (string)$id)) {
        throw new RuntimeException('User purge audit failed.');
    }

    $pdo->prepare("
        DELETE FROM users
        WHERE id = :id
          AND is_deleted = 1
        LIMIT 1
    ")->execute([':id' => $id]);
    $pdo->commit();

    adiwira_redirect_with_flash($returnTo, 'success', __('User permanently deleted.'));

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('bin/users/delete_permanent.php error: ' . $e->getMessage());
    adiwira_redirect_with_flash($returnTo, 'error', __('Failed to permanently delete user.'));
}
