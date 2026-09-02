<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};

require_once $root . '/app/dev_lock.php';
$check(jy_dev_lock_enabled_value(null) === false
    && jy_dev_lock_enabled_value('0') === false
    && jy_dev_lock_enabled_value('off') === false,
    'development gate is disabled by default and by explicit false values');
$check(jy_dev_lock_enabled_value('1') === true
    && jy_dev_lock_enabled_value('true') === true
    && jy_dev_lock_enabled_value('on') === true,
    'development gate accepts bounded explicit true values');
$check(jy_dev_lock_enabled_value('enabled-ish') === null,
    'invalid development gate flags fail configuration validation');

$index = (string)file_get_contents($root . '/public/index.php');
$router = (string)file_get_contents($root . '/public/router.php');
$gate = (string)file_get_contents($root . '/app/dev_lock.php');
$sample = (string)file_get_contents($root . '/cfg/env-sample');
$check(str_contains($index, "require_once __DIR__ . '/dev_lock.php';")
    && str_contains($router, "require_once __DIR__ . '/dev_lock.php';"),
    'both public entrypoints enforce the canonical gate before bootstrap');
$check(strpos($index, "require_once __DIR__ . '/dev_lock.php';") < strpos($index, "bootstrap_core.php")
    && strpos($router, "require_once __DIR__ . '/dev_lock.php';") < strpos($router, "bootstrap_core.php"),
    'development gate runs before database and plugin bootstrap');
$check(str_contains($gate, "password_verify(") && !str_contains($gate, "DEV_LOCK_PASSWORD =")
    && str_contains($sample, 'DEV_LOCK_PASSWORD_HASH='),
    'development gate uses an environment-owned password hash instead of a source password');
$check(str_contains($gate, "X-Robots-Tag: noindex, nofollow, noarchive")
    && str_contains($gate, '<meta name="robots" content="noindex,nofollow,noarchive">')
    && str_contains($gate, 'Cache-Control: no-store'),
    'locked responses block indexing and caching');
$check(str_contains($gate, "http_response_code(503)")
    && str_contains($gate, 'hash_equals($csrf, $submittedToken)')
    && str_contains($gate, "session_regenerate_id(true)"),
    'development gate uses unavailable status, CSRF validation, and session rotation');

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " development gate checks failed.\n");
    exit(1);
}
echo "Development gate contract passed.\n";
