<?php
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

[$uid] = adiwira_require_login($pdo, false);
$actor = authorization_actor($pdo, $uid);
if ($actor === null || $actor['is_site_owner'] !== true) {
    adiwira_redirect_with_flash($returnTo, 'error', __('Only a Site Owner can change Site Owner access.'));
}

if (!adiwira_csrf_validate((string)($_POST['csrf_token'] ?? ''))) {
    adiwira_redirect_with_flash($returnTo, 'error', __('Invalid CSRF token.'));
}

$targetId = (int)($_POST['id'] ?? 0);
$mode = strtolower(trim((string)($_POST['mode'] ?? '')));
$password = (string)($_POST['current_password'] ?? '');
$blockedUntil = (int)($_SESSION['site_owner_reauth_blocked_until'] ?? 0);
if ($blockedUntil > time()) {
    adiwira_redirect_with_flash($returnTo, 'error', __('Too many password attempts. Try again later.'));
}
if ($targetId <= 0 || !in_array($mode, ['grant', 'revoke'], true)) {
    adiwira_redirect_with_flash($returnTo, 'error', __('Invalid Site Owner request.'));
}
if ($targetId === $uid) {
    adiwira_redirect_with_flash($returnTo, 'error', __('Use another Site Owner account to change your own Site Owner access.'));
}
if ($password === '') {
    adiwira_redirect_with_flash($returnTo, 'error', __('Current password is required.'));
}

$passwordStmt = $pdo->prepare('SELECT password FROM users WHERE id = :id AND is_deleted = 0 AND is_locked = 0 LIMIT 1');
$passwordStmt->execute([':id' => $uid]);
$passwordHash = (string)($passwordStmt->fetchColumn() ?: '');
if ($passwordHash === '' || !password_verify($password, $passwordHash)) {
    $failures = (int)($_SESSION['site_owner_reauth_failures'] ?? 0) + 1;
    $_SESSION['site_owner_reauth_failures'] = $failures;
    if ($failures >= 5) {
        $_SESSION['site_owner_reauth_blocked_until'] = time() + 900;
        unset($_SESSION['site_owner_reauth_failures']);
    }
    usleep(250000);
    adiwira_redirect_with_flash($returnTo, 'error', __('Current password is incorrect.'));
}
unset($_SESSION['site_owner_reauth_failures'], $_SESSION['site_owner_reauth_blocked_until']);

$result = authorization_set_site_owner($pdo, $uid, $targetId, $mode === 'grant');
if ($result === 'ok') {
    adiwira_redirect_with_flash(
        $returnTo,
        'success',
        $mode === 'grant'
            ? __('Site Owner access granted successfully.')
            : __('Site Owner access revoked successfully.')
    );
}
if ($result === 'unchanged') {
    adiwira_redirect_with_flash($returnTo, 'success', __('Site Owner access was already in the requested state.'));
}
if ($result === 'last_site_owner') {
    adiwira_redirect_with_flash($returnTo, 'error', __('The final active Site Owner cannot be revoked.'));
}
if ($result === 'target_inactive') {
    adiwira_redirect_with_flash($returnTo, 'error', __('Site Owner access can only be granted to an active user.'));
}

error_log('[users/site_owner] status=' . $result);
adiwira_redirect_with_flash($returnTo, 'error', __('Failed to change Site Owner access.'));
