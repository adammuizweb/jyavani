<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$fixture = sys_get_temp_dir() . '/jy-theme-external-lock-' . bin2hex(random_bytes(6));
mkdir($fixture . '/backend/var', 0750, true);
mkdir($fixture . '/themes/sample', 0750, true);
mkdir($fixture . '/native', 0750, true);
mkdir($fixture . '/native/locks', 02770);
chmod($fixture . '/native/locks', 02770);

define('BACKEND_PATH', $fixture . '/backend');
define('VIEWS_BASE', $fixture . '/themes');
define('PUBLIC_PATH', $fixture);
define('THEME_DEBUG', false);

function __(string $message, mixed ...$values): string {
    return $values === [] ? $message : sprintf($message, ...$values);
}

$previousLockDir = getenv('THEME_OPERATION_LOCK_DIR');
putenv('THEME_OPERATION_LOCK_DIR=' . $fixture . '/native/locks');

require_once $root . '/cfg/helpers/hooks.php';
require_once $root . '/cfg/helpers/theme_helper.php';

$failures = [];
$checks = 0;
$check = static function (bool $condition, string $message) use (&$failures, &$checks): void {
    $checks++;
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};
$throws = static function (callable $callback): bool {
    try {
        $callback();
        return false;
    } catch (Throwable) {
        return true;
    }
};
$removeTree = static function (string $path) use (&$removeTree): void {
    if (is_link($path) || is_file($path)) { @unlink($path); return; }
    if (!is_dir($path)) return;
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') $removeTree($path . '/' . $entry);
    }
    @rmdir($path);
};

try {
    $locks = theme_operation_acquire(['sample']);
    theme_operation_release($locks);
    $lockFile = $fixture . '/native/locks/' . hash('sha256', 'sample') . '.lock';
    $check(is_file($lockFile) && !is_dir(BACKEND_PATH . '/var/theme-operation-locks'),
        'configured external directory owns lifecycle locks instead of the backend filesystem');
    $check((fileperms($fixture . '/native/locks') & 02777) === 02770
        && (fileperms($lockFile) & 0777) === 0660,
        'external lock directory and files retain shared-group safe modes');

    putenv('THEME_OPERATION_LOCK_DIR=relative/locks');
    $check($throws(static fn() => theme_operation_acquire(['sample'])),
        'external lock directory must be absolute');

    mkdir($fixture . '/unsafe', 0777);
    chmod($fixture . '/unsafe', 0777);
    putenv('THEME_OPERATION_LOCK_DIR=' . $fixture . '/unsafe/locks');
    $check($throws(static fn() => theme_operation_acquire(['sample'])),
        'external lock directory rejects a world-writable parent');
} finally {
    if ($previousLockDir === false) putenv('THEME_OPERATION_LOCK_DIR');
    else putenv('THEME_OPERATION_LOCK_DIR=' . $previousLockDir);
    $removeTree($fixture);
}

echo "Checks: {$checks}, Failures: " . count($failures) . PHP_EOL;
exit($failures === [] ? 0 : 1);
