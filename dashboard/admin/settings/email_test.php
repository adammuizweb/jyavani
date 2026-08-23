<?php
declare(strict_types=1);

if (!defined('DASHBOARD_CONTEXT')) define('DASHBOARD_CONTEXT', true);
require_once __DIR__ . '/../_guard.php';
require_once __DIR__ . '/../_notify.php';

$defaultReturnTo = ADMIN_BASE_PATH . '/?page=admin/settings/email';
$returnTo = adiwira_safe_return_to($_POST['return_to'] ?? '', $defaultReturnTo);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    adiwira_redirect_with_flash($returnTo, 'error', __('Method not allowed.'));
}

[$uid] = adiwira_require_permission($pdo, 'core.settings.manage', false);
adiwira_require_site_owner($pdo, false);
if (!adiwira_csrf_validate(is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : '')) {
    adiwira_redirect_with_flash($returnTo, 'error', __('Invalid CSRF token.'));
}

$now = time();
$lastTest = (int)($_SESSION['jy_mail_test_last_at'] ?? 0);
if ($lastTest > 0 && ($now - $lastTest) < 30) {
    adiwira_redirect_with_flash($returnTo, 'warning', __('Please wait before sending another test email.'));
}
$recipient = is_string($_POST['recipient'] ?? null) ? trim($_POST['recipient']) : '';
if (!jy_mail_address_is_valid($recipient)) {
    adiwira_redirect_with_flash($returnTo, 'error', __('Enter a valid test recipient email address.'));
}

$_SESSION['jy_mail_test_last_at'] = $now;
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
$siteTitle = settings_get($pdo, 'site_title', 'Jyavani') ?? 'Jyavani';
$result = jy_mail_send($pdo, [
    'to' => [$recipient],
    'subject' => __('Jyavani test email'),
    'body' => sprintf(
        "%s\n\n%s",
        __('This test confirms that the configured Jyavani mail transport accepted an outgoing message.'),
        sprintf(__('Site: %s'), $siteTitle)
    ),
    'content_type' => 'text/plain',
]);

if (($result['ok'] ?? false) === true) {
    adiwira_redirect_with_flash($returnTo, 'success', __('Test email was accepted by the configured transport.'));
}
$code = (string)($result['code'] ?? 'failed');
$message = match ($code) {
    'invalid_sender' => __('Configure a valid From email address before sending a test.'),
    'transport_unavailable' => __('Configured mail transport is unavailable.'),
    default => __('Test email could not be accepted by the configured transport.'),
};
adiwira_redirect_with_flash($returnTo, 'error', $message);
