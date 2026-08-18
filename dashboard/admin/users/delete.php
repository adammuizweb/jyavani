<?php
// /adiwira/admin/users/delete.php
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
if ($id <= 0) {
    adiwira_redirect_with_flash($returnTo, 'error', __('Invalid ID.'));
}

if ($id === $uid) {
    adiwira_redirect_with_flash($returnTo, 'error', __('You cannot delete your own account.'));
}
if (!user_can($pdo, $uid, 'core.users.delete', ['owner_id' => $id])) {
    adiwira_redirect_with_flash($returnTo, 'error', __('Access denied.'));
}

$stmt = $pdo->prepare("SELECT id FROM users WHERE id = :id AND is_deleted = 0 LIMIT 1");
$stmt->execute([':id' => $id]);
if (!$stmt->fetch()) {
    adiwira_redirect_with_flash($returnTo, 'error', __('User tidak ditemukan atau sudah dihapus.'));
}

$result = authorization_change_user_status($pdo, $id, 'delete', $uid, null, 'core.users.delete');
if ($result === 'ok') {
    adiwira_redirect_with_flash($returnTo, 'success', __('User berhasil dihapus.'));
}
if ($result === 'last_site_owner') {
    adiwira_redirect_with_flash($returnTo, 'error', __('The final active Site Owner cannot be deleted.'));
}
if ($result === 'site_owner_required') {
    adiwira_redirect_with_flash($returnTo, 'error', __('Only a Site Owner can modify a Site Owner account.'));
}
if ($result === 'forbidden') {
    adiwira_redirect_with_flash($returnTo, 'error', __('Access denied.'));
}
if ($result !== 'missing') {
    error_log('[users/delete] status=' . $result);
}
adiwira_redirect_with_flash($returnTo, 'error', __('Failed to delete user.'));
