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
if (!user_can($pdo, $uid, 'core.users.lock', ['owner_id' => $id])) {
    adiwira_redirect_with_flash($returnTo, 'error', __('Access denied.'));
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
$result = authorization_change_user_status($pdo, $id, $mode, $uid, null, 'core.users.lock');
if ($result === 'last_site_owner') {
    adiwira_redirect_with_flash($returnTo, 'error', __('The final active Site Owner cannot be locked.'));
}
if ($result === 'site_owner_required') {
    adiwira_redirect_with_flash($returnTo, 'error', __('Only a Site Owner can modify a Site Owner account.'));
}
if ($result === 'forbidden') {
    adiwira_redirect_with_flash($returnTo, 'error', __('Access denied.'));
}
if ($result !== 'ok') {
    error_log('[users/toggle_lock] status=' . $result);
    adiwira_redirect_with_flash($returnTo, 'error', __('Failed to update user status.'));
}
adiwira_redirect_with_flash(
    $returnTo,
    'success',
    ($newLock === 1)
        ? __('User locked successfully.')
        : __('User approved/unlocked successfully.')
);
