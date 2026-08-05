<?php
// /adiwira/admin/users/toggle_lock.php
declare(strict_types=1);

if (!defined('DASHBOARD_CONTEXT')) {
    define('DASHBOARD_CONTEXT', true);
}

require_once __DIR__ . '/../_guard.php';
require_once __DIR__ . '/../_notify.php';

$defaultReturnTo = ADMIN_BASE_PATH . '/?page=admin/users/index';
$returnTo = function_exists('adiwira_safe_return_to')
    ? adiwira_safe_return_to((string)($_POST['return_to'] ?? ''), $defaultReturnTo)
    : $defaultReturnTo;

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    adiwira_redirect_with_flash($returnTo, 'error', __('Method not allowed.'));
}

$identity = adiwira_fetch_identity($pdo);
if (($identity['ok'] ?? false) !== true) {
    adiwira_redirect_with_flash($returnTo, 'error', __('Access denied: not logged in.'));
}

$uid  = (int)($identity['uid'] ?? 0);
$role = (string)($identity['role'] ?? 'guest');

if ($role !== 'admin') {
    adiwira_redirect_with_flash($returnTo, 'error', __('Access denied: admins only.'));
}

$token = (string)($_POST['csrf_token'] ?? '');
if (!adiwira_csrf_validate($token)) {
    adiwira_redirect_with_flash($returnTo, 'error', __('Token CSRF tidak valid.'));
}

$id = (int)($_POST['id'] ?? 0);
$mode = strtolower(trim((string)($_POST['mode'] ?? '')));

if ($id <= 0) {
    adiwira_redirect_with_flash($returnTo, 'error', __('ID user tidak valid.'));
}

if ($id === $uid) {
    adiwira_redirect_with_flash($returnTo, 'error', __('You cannot lock or unlock your own account.'));
}

if (!in_array($mode, ['lock', 'unlock'], true)) {
    adiwira_redirect_with_flash($returnTo, 'error', __('Mode tidak valid.'));
}

$stmt = $pdo->prepare("
    SELECT id, name, email, is_locked
    FROM users
    WHERE id = :id
      AND is_deleted = 0
    LIMIT 1
");
$stmt->execute([':id' => $id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    adiwira_redirect_with_flash($returnTo, 'error', __('User tidak ditemukan.'));
}

$newLock = ($mode === 'lock') ? 1 : 0;

$upd = $pdo->prepare("
    UPDATE users
    SET is_locked = :locked,
        updated_at = NOW()
    WHERE id = :id
    LIMIT 1
");

try {
    $ok = $upd->execute([
        ':locked' => $newLock,
        ':id' => $id,
    ]);

    if ($ok) {
        adiwira_redirect_with_flash(
            $returnTo,
            'success',
            ($newLock === 1)
                ? __('User locked successfully.')
                : __('User approved/unlocked successfully.')
        );
    }

    adiwira_redirect_with_flash($returnTo, 'error', __('Failed to update user status.'));
} catch (Throwable $e) {
    error_log('[users/toggle_lock] ' . $e->getMessage());
    adiwira_redirect_with_flash($returnTo, 'error', __('Failed to update user status.'));
}