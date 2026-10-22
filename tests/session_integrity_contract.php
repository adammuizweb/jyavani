<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$fixture = sys_get_temp_dir() . '/jyavani-session-integrity-' . bin2hex(random_bytes(6));
$secret = str_repeat('s', 64);
mkdir($fixture, 0700, true);
$script = <<<'PHP'
<?php
declare(strict_types=1);
putenv('SESSION_NAME=session_integrity_contract');
putenv('SESSION_SAVE_PATH=' . $argv[2]);
putenv('SESSION_INTEGRITY_SECRET=' . $argv[3]);
putenv('SESSION_PHP_COOKIE_DISABLED=1');
putenv('SESSION_ALLOW_INSECURE_COOKIES=0');
putenv('SESSION_DEBUG=0');
$_SERVER['HTTPS'] = 'on';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['HTTP_USER_AGENT'] = 'Jyavani integrity contract';
if ($argv[1] === 'read') $_COOKIE['session_integrity_contract'] = (string)$argv[4];
$rootArgument = $argv[1] === 'read' ? $argv[5] : $argv[4];
require $rootArgument . '/cfg/session.php';
if ($argv[1] === 'write') {
    login_user(7, 'integrity@example.test');
    $sid = session_id();
    session_write_close();
    echo $sid;
    exit;
}
$started = ensure_session_started(false);
echo json_encode(['started' => $started, 'has_user' => isset($_SESSION['user_id'])]);
PHP;
$scriptFile = $fixture . '/runner.php';
file_put_contents($scriptFile, $script);
$savePath = $fixture . '/sessions';
mkdir($savePath, 0700, true);
$command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($scriptFile) . ' write '
    . escapeshellarg($savePath) . ' ' . escapeshellarg($secret) . ' ' . escapeshellarg($root);
exec($command, $output, $status);
$sid = trim(implode('', $output));
$sessionFile = $savePath . '/sess_' . $sid;
$raw = is_file($sessionFile) ? (string)file_get_contents($sessionFile) : '';
$checks = [
    $status === 0 && preg_match('/\A[a-f0-9]{64}\n/', $raw) === 1,
    str_contains($raw, 'user_id'),
];

if (str_contains($raw, "\n")) {
    $payload = substr($raw, strpos($raw, "\n") + 1);
    file_put_contents($sessionFile, str_repeat('0', 64) . "\n" . $payload);
}

$command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($scriptFile) . ' read '
    . escapeshellarg($savePath) . ' ' . escapeshellarg($secret) . ' ' . escapeshellarg($sid) . ' ' . escapeshellarg($root);
$output = [];
exec($command, $output, $status);
$decoded = json_decode(trim(implode('', $output)), true);
$checks[] = $status === 0 && is_array($decoded) && ($decoded['started'] ?? false) === true
    && ($decoded['has_user'] ?? true) === false;

$remove = static function (string $path) use (&$remove): void {
    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') $remove($path . DIRECTORY_SEPARATOR . $entry);
        }
        @rmdir($path);
        return;
    }
    if (is_file($path) || is_link($path)) @unlink($path);
};
$remove($fixture);

foreach ($checks as $index => $passed) {
    echo ($passed ? 'PASS' : 'FAIL') . ' session integrity check ' . ($index + 1) . PHP_EOL;
}
if (in_array(false, $checks, true)) exit(1);
echo "RESULT: ALL PASS\n";
