<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};

$run = static function (bool $https, bool $allowInsecure, string $savePath, bool $disableAutomaticCookies = true) use ($root): array {
    $script = <<<'PHP'
<?php
declare(strict_types=1);
putenv('SESSION_NAME=session_contract');
putenv('SESSION_SAVE_PATH=' . $argv[1]);
putenv('SESSION_PHP_COOKIE_DISABLED=' . $argv[5]);
putenv('SESSION_ALLOW_INSECURE_COOKIES=' . $argv[2]);
putenv('FORCE_HTTPS=0');
putenv('SESSION_DEBUG=0');
$_SERVER['HTTPS'] = $argv[3];
$_SERVER['REQUEST_URI'] = '/login/';
$_SERVER['HTTP_USER_AGENT'] = 'Jyavani session contract';
require $argv[4] . '/cfg/session.php';
try {
    login_user(42, 'contract@example.test');
    $sid = (string)($_COOKIE[session_name()] ?? '');
    $file = rtrim($argv[1], '/\\') . '/sess_' . $sid;
    $body = $sid !== '' && is_file($file) ? (string)file_get_contents($file) : '';
    echo json_encode([
        'ok' => true,
        'secure' => (bool)($cookie_options['secure'] ?? false),
        'persisted' => $body !== '' && str_contains($body, 'user_id'),
        'automatic_secure' => (bool)(session_get_cookie_params()['secure'] ?? false),
    ]);
} catch (Throwable $error) {
    echo json_encode(['ok' => false, 'secure' => (bool)($cookie_options['secure'] ?? false), 'persisted' => false]);
}
PHP;
    $scriptFile = tempnam(sys_get_temp_dir(), 'jy-session-contract-');
    file_put_contents($scriptFile, $script);
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($scriptFile) . ' '
        . escapeshellarg($savePath) . ' ' . escapeshellarg($allowInsecure ? '1' : '0') . ' '
        . escapeshellarg($https ? 'on' : 'off') . ' ' . escapeshellarg($root) . ' '
        . escapeshellarg($disableAutomaticCookies ? '1' : '0');
    exec($command, $output, $status);
    @unlink($scriptFile);
    $decoded = json_decode(implode("\n", $output), true);
    return $status === 0 && is_array($decoded) ? $decoded : ['ok' => false, 'secure' => null, 'persisted' => false];
};

$fixture = sys_get_temp_dir() . '/jy-session-contract-' . bin2hex(random_bytes(6));
mkdir($fixture, 0770, true);
try {
    $http = $run(false, true, $fixture . '/http');
    $https = $run(true, true, $fixture . '/https');
    $automatic = $run(true, true, $fixture . '/automatic', false);
    $failClosed = $run(false, true, '/proc/jyavani-session-contract-unavailable');

    $check($http['ok'] === true && $http['secure'] === false && $http['persisted'] === true, 'explicit local HTTP mode persists login with a non-Secure cookie');
    $check($https['ok'] === true && $https['secure'] === true && $https['persisted'] === true, 'HTTPS always persists login with a Secure cookie');
    $check($automatic['ok'] === true && $automatic['automatic_secure'] === true, 'legacy automatic session cookies inherit the hardened attributes');
    $check($failClosed['ok'] === false && $failClosed['persisted'] === false, 'unavailable session storage fails login closed');
} finally {
    if (is_dir($fixture)) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        @rmdir($fixture);
    }
}

$sessionSource = (string)file_get_contents($root . '/cfg/session.php');
$installer = (string)file_get_contents($root . '/public/pondasi/index.php');
$check(str_contains($sessionSource, "'0;0660;' . \$session_save_path"), 'file session handler creates group-writable files immediately');
$check(str_contains($installer, 'verify_session_storage($sessionDir)'), 'Pondasi verifies the real PHP session handler before completion');
$check(str_contains($installer, "SESSION_COOKIE_DOMAIN=\\n"), 'Pondasi generates host-only cookies by default');
$check(str_contains($installer, "preg_match('#^/mnt/[a-z](?:/|$)#i'") && str_contains($installer, "'/jyavani-sessions-'"), 'Pondasi keeps WSL /mnt session data on the native Linux filesystem');

if ($failures !== []) exit(1);
echo "RESULT: ALL PASS\n";
