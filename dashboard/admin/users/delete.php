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
$role = (string)($identity['role'] ?? 'guest');

if ($role !== 'admin') {
    adiwira_redirect_with_flash($returnTo, 'error', __('Access denied: only admins can delete users.'));
}

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

$stmt = $pdo->prepare("SELECT id FROM users WHERE id = :id AND is_deleted = 0 LIMIT 1");
$stmt->execute([':id' => $id]);
if (!$stmt->fetch()) {
    adiwira_redirect_with_flash($returnTo, 'error', __('User tidak ditemukan atau sudah dihapus.'));
}

$stmtDel = $pdo->prepare("UPDATE users SET is_deleted = 1, updated_at = NOW() WHERE id = :id LIMIT 1");

try {
    $ok = $stmtDel->execute([':id' => $id]);

    if ($ok) {
        adiwira_redirect_with_flash($returnTo, 'success', __('User berhasil dihapus.'));
    }

    adiwira_redirect_with_flash($returnTo, 'error', __('Failed to delete user.'));
} catch (Throwable $e) {
    error_log('[users/delete] ' . $e->getMessage());
    adiwira_redirect_with_flash($returnTo, 'error', __('Failed to delete user.'));
}