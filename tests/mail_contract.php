<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/cfg/helpers/hooks.php';
require_once $root . '/cfg/helpers/settings_helpers.php';
require_once $root . '/cfg/helpers/mail.php';

$failures = [];
$checks = 0;
$check = static function (bool $condition, string $message) use (&$failures, &$checks): void {
    $checks++;
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE settings (`key` TEXT PRIMARY KEY, `value` TEXT NULL, `autoload` INTEGER NOT NULL DEFAULT 1)');
$insert = $pdo->prepare('INSERT INTO settings (`key`,`value`,`autoload`) VALUES (:key,:value,1)');
foreach ([
    'mail_transport' => 'contract_accept',
    'mail_fallback_transport' => '',
    'mail_from_address' => 'noreply@example.test',
    'mail_from_name' => 'Jyavani Contract',
    'mail_reply_to' => '',
    'mail_log' => 'off',
] as $key => $value) {
    $insert->execute([':key' => $key, ':value' => $value]);
}
unset($GLOBALS['__jy_settings_autoload_cache']);

$acceptedCalls = 0;
$capturedMessage = null;
$check(jy_mail_register_transport('contract_accept', static function (array $message) use (&$acceptedCalls, &$capturedMessage): array {
    $acceptedCalls++;
    $capturedMessage = $message;
    return ['status' => 'accepted'];
}, ['label' => 'Contract Accept']), 'custom transport registers once');
$check(!jy_mail_register_transport('contract_accept', static fn(): array => ['status' => 'accepted']), 'duplicate transport registration is rejected');
$check(!jy_mail_register_transport('native', static fn(): array => ['status' => 'accepted']), 'plugins cannot replace the native transport');
$check(isset(jy_mail_transports()['native']) && jy_mail_transports()['native']['builtin'] === true, 'native transport remains registered as a built-in');

$message = [
    'to' => ['recipient@example.test'],
    'subject' => 'Contract subject',
    'body' => "Contract body\nline two",
    'content_type' => 'text/plain',
];
$result = jy_mail_send($pdo, $message);
$check($result['ok'] === true && $result['status'] === 'accepted' && $result['transport'] === 'contract_accept', 'configured transport accepts a normalized message');
$check(array_keys($result) === ['id', 'ok', 'status', 'code', 'transport', 'fallback_used'], 'mail result exposes only the stable safe fields');
$check($acceptedCalls === 1 && is_array($capturedMessage)
    && ($capturedMessage['from']['email'] ?? '') === 'noreply@example.test', 'configured sender is supplied structurally to the transport');

foreach ([
    'recipient CRLF' => ['to' => ["victim@example.test\r\nBcc: attacker@example.test"]],
    'subject CRLF' => ['subject' => "Hello\r\nBcc: attacker@example.test"],
    'sender CRLF' => ['from' => ['email' => 'noreply@example.test', 'name' => "Sender\r\nBcc: attacker@example.test"]],
    'reply-to CRLF' => ['reply_to' => ['email' => "reply@example.test\r\nX-Test: yes", 'name' => '']],
] as $label => $override) {
    $before = $acceptedCalls;
    $injected = array_replace($message, $override);
    $rejected = jy_mail_send($pdo, $injected);
    $check($rejected['ok'] === false && $rejected['status'] === 'rejected' && $acceptedCalls === $before, "{$label} is rejected before transport invocation");
}

$invalidRecipientShapes = [
    'recipient@example.test',
    [],
    ['first@example.test', 'FIRST@example.test'],
    ['first@example.test,second@example.test'],
    ['first@example.test' => 'second@example.test'],
];
foreach ($invalidRecipientShapes as $index => $recipients) {
    $candidate = $message;
    $candidate['to'] = $recipients;
    $rejected = jy_mail_send($pdo, $candidate);
    $check($rejected['ok'] === false && $rejected['code'] === 'invalid_recipient', "recipient shape {$index} fails closed");
}

$unknownMessage = $message;
$unknownMessage['headers'] = ['Bcc' => 'attacker@example.test'];
$check(jy_mail_send($pdo, $unknownMessage)['code'] === 'invalid_message', 'caller-controlled raw headers are rejected');
$check(jy_mail_send($pdo, $message, ['unknown' => true])['code'] === 'invalid_options', 'unknown transport options are rejected');
$check(jy_mail_send($pdo, $message, ['transport' => 'native', 'fallback_transport' => 'native'])['code'] === 'invalid_options', 'a transport cannot fall back to itself');

$temporaryCalls = 0;
$fallbackCalls = 0;
jy_mail_register_transport('contract_temporary', static function () use (&$temporaryCalls): array {
    $temporaryCalls++;
    return ['status' => 'temporary_failure'];
});
jy_mail_register_transport('contract_fallback', static function () use (&$fallbackCalls): array {
    $fallbackCalls++;
    return ['status' => 'accepted'];
});
$fallbackResult = jy_mail_send($pdo, $message, [
    'transport' => 'contract_temporary',
    'fallback_transport' => 'contract_fallback',
]);
$check($fallbackResult['ok'] === true && $fallbackResult['fallback_used'] === true
    && $fallbackResult['transport'] === 'contract_fallback' && $temporaryCalls === 1 && $fallbackCalls === 1,
    'temporary transport failure invokes one explicit fallback');

$permanentCalls = 0;
jy_mail_register_transport('contract_permanent', static fn(): array => ['status' => 'permanent_failure']);
$permanentResult = jy_mail_send($pdo, $message, [
    'transport' => 'contract_permanent',
    'fallback_transport' => 'contract_fallback',
]);
$check($permanentResult['ok'] === false && $permanentResult['code'] === 'transport_permanent_failure'
    && $permanentResult['fallback_used'] === false && $fallbackCalls === 1,
    'permanent transport failure never invokes fallback');

jy_mail_register_transport('contract_exception', static function (): array {
    throw new RuntimeException('secret transport exception');
});
$exceptionResult = jy_mail_send($pdo, $message, ['transport' => 'contract_exception']);
$check($exceptionResult['ok'] === false && $exceptionResult['code'] === 'transport_exception'
    && !str_contains(json_encode($exceptionResult) ?: '', 'secret'), 'transport exceptions are reduced to a safe stable code');

$dryCalls = 0;
jy_mail_register_transport('contract_dry', static function () use (&$dryCalls): array {
    $dryCalls++;
    return ['status' => 'accepted'];
});
$dryResult = jy_mail_send($pdo, $message, ['transport' => 'contract_dry', 'dry_run' => true]);
$check($dryResult['ok'] === true && $dryResult['status'] === 'skipped' && $dryCalls === 0, 'dry run validates availability without invoking transport');

$injectingFilter = static function (array $filtered): array {
    $filtered['subject'] = "Filtered\r\nBcc: attacker@example.test";
    return $filtered;
};
add_filter('jy_mail_message', $injectingFilter);
$beforeFilter = $acceptedCalls;
$filteredResult = jy_mail_send($pdo, $message);
remove_filter('jy_mail_message', $injectingFilter);
$check($filteredResult['ok'] === false && $filteredResult['code'] === 'invalid_subject'
    && $acceptedCalls === $beforeFilter, 'mail message filters are revalidated before transport invocation');

$serialized = json_encode([
    'results' => [$result, $fallbackResult, $exceptionResult, $dryResult],
]);
$check(is_string($serialized)
    && !str_contains($serialized, 'recipient@example.test')
    && !str_contains($serialized, 'Contract subject')
    && !str_contains($serialized, 'Contract body'), 'result objects never expose message content');

$helperSource = (string)file_get_contents($root . '/cfg/helpers/mail.php');
$check(substr_count($helperSource, 'mail(') === 2
    && str_contains($helperSource, 'function jy_mail_native_transport')
    && !str_contains($helperSource, '@mail('), 'only the native transport invokes PHP mail without error suppression');
$check(!str_contains($helperSource, "'-f'") && !str_contains($helperSource, 'additional_parameters'), 'native transport never passes shell-controlled mail parameters');

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " mail contract checks failed.\n");
    exit(1);
}
echo "Mail contract passed ({$checks} checks).\n";
