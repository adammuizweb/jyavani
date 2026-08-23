<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$checks = 0;
$check = static function (bool $condition, string $message) use (&$failures, &$checks): void {
    $checks++;
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};

$helper = (string)file_get_contents($root . '/cfg/helpers/mail.php');
$config = (string)file_get_contents($root . '/cfg/config.php');
$page = (string)file_get_contents($root . '/dashboard/admin/settings/email.php');
$testAction = (string)file_get_contents($root . '/dashboard/admin/settings/email_test.php');
$aside = (string)file_get_contents($root . '/dashboard/theme/adam/part/aside.php');
$hub = (string)file_get_contents($root . '/dashboard/admin/settings/index.php');
$translations = (string)file_get_contents($root . '/schema/translations.sql');
$router = (string)file_get_contents($root . '/public/router.php');

$check(str_contains($config, "helpers/mail.php") && strpos($config, 'helpers/settings_helpers.php') < strpos($config, 'helpers/mail.php'), 'Core loads the Mail API after settings support');
$check(str_contains($helper, 'function jy_mail_send(')
    && str_contains($helper, 'function jy_mail_register_transport(')
    && str_contains($helper, 'function jy_mail_transports('), 'Core exposes transport-neutral send and registration APIs');
$check(str_contains($helper, "if (\$name === 'native'")
    && str_contains($helper, "isset(\$GLOBALS['__jy_mail_transports'][\$name])"), 'transport registry prevents native replacement and duplicate registration');
$check(str_contains($helper, "array_diff(array_keys(\$message)")
    && str_contains($helper, "array_diff(array_keys(\$options)")
    && str_contains($helper, 'jy_mail_address_is_valid(')
    && str_contains($helper, "preg_match('/[\\x00-\\x1F\\x7F]/'"), 'Mail API allowlists input keys and rejects header controls');
$check(str_contains($helper, "'jy_mail_message'")
    && substr_count($helper, 'jy_mail_normalize_message(') >= 3, 'filtered messages are normalized and validated again');
$check(str_contains($helper, "'jy_mail_before_send'")
    && str_contains($helper, "'jy_mail_after_send'")
    && str_contains($helper, 'after-send observer failed'), 'mail lifecycle hooks fail safely around transport execution');
$check(str_contains($helper, '[jy-mail] id=%s status=%s code=%s transport=%s fallback=%d recipients=%d')
    && !str_contains($helper, 'error->getMessage()'), 'mail logging is redacted and excludes exception details');

foreach ([$page, $testAction] as $index => $route) {
    $check(str_contains($route, "adiwira_require_permission(\$pdo, 'core.settings.manage', false)")
        && str_contains($route, 'adiwira_require_site_owner($pdo, false)')
        && !str_contains($route, 'adiwira_require_admin'), 'email admin route ' . $index . ' requires permission and Site Owner');
}
$check(str_contains($page, 'adiwira_csrf_validate(')
    && str_contains($page, 'jy_mail_header_text_is_valid(')
    && str_contains($page, 'jy_mail_address_is_valid(')
    && str_contains($page, "settings_set(\$pdo"), 'Email settings save validates CSRF, headers, addresses, and persisted values');
$check(str_contains($page, '$ownsTransaction = false')
    && str_contains($page, 'if ($ownsTransaction && $pdo->inTransaction())')
    && str_contains($page, "unset(\$GLOBALS['__jy_settings_autoload_cache'])"), 'Email settings own their transaction and invalidate rolled-back cache values');
$check(str_contains($page, '$transport !== $savedTransport')
    && str_contains($page, '$fallbackTransport !== $savedFallbackTransport')
    && str_contains($page, "!isset(\$transports[\$fallbackTransport])"), 'temporarily unavailable configured transports can be preserved without accepting new unknown transports');
$methodPosition = strpos($testAction, "REQUEST_METHOD");
$permissionPosition = strpos($testAction, "core.settings.manage");
$csrfPosition = strpos($testAction, 'adiwira_csrf_validate(');
$sendPosition = strpos($testAction, 'jy_mail_send(');
$check($methodPosition !== false && $permissionPosition !== false && $csrfPosition !== false && $sendPosition !== false
    && $methodPosition < $permissionPosition && $permissionPosition < $csrfPosition && $csrfPosition < $sendPosition,
    'test-send action enforces POST, authorization, and CSRF before sending');
$check(strpos($testAction, 'adiwira_safe_return_to(') < $methodPosition
    && str_contains($testAction, "\$_SESSION['jy_mail_test_last_at']")
    && str_contains($testAction, '< 30')
    && strpos($testAction, 'session_write_close()') < $sendPosition, 'test-send action sanitizes redirects, rate-limits sends, and releases the session lock before transport');
$check(str_contains($testAction, "'content_type' => 'text/plain'")
    && !str_contains($testAction, "\$_POST['subject']")
    && !str_contains($testAction, "\$_POST['body']")
    && !str_contains($testAction, "\$_POST['from']"), 'test-send action accepts only a recipient and uses server-owned message content');
$check(str_contains($aside, "admin/settings/email")
    && str_contains($aside, "\$navActor['is_site_owner'] === true")
    && str_contains($hub, "'href'  => \$base . '/?page=admin/settings/email'")
    && str_contains($hub, "'badge' => __('Site Owner')"), 'Settings navigation exposes Email only under the Site Owner policy');
$check(!str_contains($router, 'email_test') && !str_contains($router, 'jy_mail_send'), 'Core Mail API has no public frontend route');

$visibleSources = [
    'Email Delivery',
    'Configure sender identity, transport selection, and outgoing email tests.',
    'Mail Configuration',
    'Primary transport',
    'Fallback transport',
    'From name',
    'From email address',
    'Reply-To email address',
    'Delivery logging',
    'Save Email Settings',
    'Configured Transport',
    'Send Test Email',
    'Recipient email address',
    'Email settings saved successfully.',
    'Test email was accepted by the configured transport.',
    'Test email could not be accepted by the configured transport.',
];
foreach ($visibleSources as $source) {
    $check(substr_count($translations, "'" . str_replace("'", "''", $source) . "'") >= 2, "email UI translation coverage: {$source}");
}

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " email settings contract checks failed.\n");
    exit(1);
}
echo "Email settings contract passed ({$checks} checks).\n";
