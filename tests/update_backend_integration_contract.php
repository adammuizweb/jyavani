<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/cfg/helpers/package_archive.php';

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};

$sources = [
    'theme endpoint' => (string)file_get_contents($root . '/dashboard/admin/themes/update_apply.php'),
    'plugin endpoint' => (string)file_get_contents($root . '/dashboard/admin/plugins/update_apply.php'),
    'core endpoint' => (string)file_get_contents($root . '/dashboard/admin/update/update_apply.php'),
];
foreach ($sources as $label => $source) {
    $check(str_contains($source, 'update_operation_begin(')
        && str_contains($source, 'update_operation_acquire_lock()')
        && str_contains($source, 'update_operation_release_lock(')
        && str_contains($source, 'update_operation_fail('), $label . ' coordinates the shared operation lifecycle');
}
$theme = (string)file_get_contents($root . '/app/controllers/ThemeStoreClient.php');
$plugin = (string)file_get_contents($root . '/app/controllers/PluginStoreController.php');
$core = (string)file_get_contents($root . '/dashboard/admin/update/_update_helpers.php');
$themeManager = (string)file_get_contents($root . '/dashboard/admin/themes/assign.php');
$packageHelper = (string)file_get_contents($root . '/cfg/helpers/package_archive.php');
$config = (string)file_get_contents($root . '/cfg/config.php');
$bootstrap = (string)file_get_contents($root . '/app/bootstrap_core.php');
$pluginProgress = (string)file_get_contents($root . '/dashboard/admin/plugins/update_progress.php');
$themeProgress = (string)file_get_contents($root . '/dashboard/admin/themes/update_progress.php');
$check(str_contains($sources['core endpoint'], 'theme_operation_acquire(theme_lifecycle_lock_keys())')
    && str_contains($sources['core endpoint'], 'theme_operation_release($lifecycleLocks)'), 'Core update blocks cooperating requests while managed files are changing');
$check(str_contains($sources['core endpoint'], 'hash_equals(UpdateStatusController::officialCoreUrl(), $baseUrl)')
    && str_contains($sources['core endpoint'], "UpdateStatusController::isUpdateActionable('core'"),
    'Core apply rejects stale metadata from a non-official update source');
$actions = (string)file_get_contents($root . '/dashboard/admin/update/_update_actions.php');
$check(str_contains($actions, 'function cms_update_reinstall')
    && str_contains($actions, '$updateLock = update_operation_acquire_lock()')
    && str_contains($actions, '$lifecycleLocks = theme_operation_acquire(theme_lifecycle_lock_keys())')
    && str_contains($actions, 'update_operation_release_lock($updateLock)'), 'Core reinstall joins the shared updater and lifecycle locks');
$check(strpos($theme, 'update_operation_enter_critical($progressToken', strpos($theme, '$newIdentity'))
    < strpos($theme, 'package_guarded_publish('), 'theme cancellation closes atomically immediately before publication');
$check(strpos($plugin, 'update_operation_enter_critical($progressToken', strpos($plugin, '$migrationStarted'))
    < strpos($plugin, 'package_guarded_publish('), 'plugin cancellation closes atomically immediately before publication');
$check(strpos($core, 'update_operation_enter_critical($progressToken', strpos($core, '$backupDir'))
    < strpos($core, 'mkdir($backupDir'), 'Core cancellation closes atomically before backup creation and live mutation');
$check(str_contains($themeManager, "update_operation_begin(\$token, (int)\$user_id, 'theme'")
    && str_contains($themeManager, '$directUpdateLock = update_operation_acquire_lock()'), 'legacy Theme Manager apply path joins the shared operation lifecycle');
$check(str_contains($config, "if (defined('UPDATE_PROCESS_CONTROL_REQUEST'))")
    && str_contains($config, "require_once __DIR__ . '/helpers/authorization.php';")
    && str_contains($bootstrap, "!defined('UPDATE_PROCESS_CONTROL_REQUEST') && function_exists('ensure_writable_runtime')")
    && str_contains($bootstrap, "!defined('UPDATE_PROCESS_CONTROL_REQUEST') && is_file(\$migrationPath)")
    && str_contains($bootstrap, "!defined('UPDATE_PROCESS_CONTROL_REQUEST') && function_exists('ensure_ui_translations_seeded')"), 'control endpoint uses a bounded read-mostly bootstrap without migration or seed side effects');
$check(str_contains($core, 'function _cms_atomic_replace_file(')
    && str_contains($core, "fopen(\$temporaryPath, 'xb')")
    && str_contains($core, 'rename($temporaryPath, $targetPath)')
    && str_contains($core, '_cms_atomic_replace_file($manifestPath, $manifestContents)')
    && !str_contains($core, 'file_put_contents($targetPath, $contents'), 'Core publication atomically replaces managed files and the local manifest instead of truncating them in place');
$check(str_contains($packageHelper, "package_download_configured_int('PACKAGE_DOWNLOAD_TIMEOUT_SECONDS'")
    && str_contains($packageHelper, "package_download_configured_int('PACKAGE_DOWNLOAD_ATTEMPTS'")
    && str_contains($packageHelper, 'CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS')
    && str_contains($core, "' [download:' . \$diagnostic . ']'")
    && str_contains($actions, "package_download_last_error_code()"),
    'Core package downloads use bounded transient retries and expose safe transport diagnostics');
$check(str_contains($actions, "package_download(\n        \$url,")
    && !str_contains($actions, '$zipData = @file_get_contents($url'),
    'Core reinstall shares the streamed resilient package downloader');
$check(str_contains($pluginProgress, "(string)\$record['type'] !== 'plugin'")
    && str_contains($themeProgress, "(string)\$record['type'] !== 'theme'")
    && str_contains($pluginProgress, "(int)\$record['owner_id'] !== (int)\$uid")
    && str_contains($themeProgress, "(int)\$record['owner_id'] !== (int)\$uid"), 'legacy progress routes enforce operation owner and type binding');

$fixture = sys_get_temp_dir() . '/jyavani-update-backend-' . bin2hex(random_bytes(6));
mkdir($fixture, 0700, true);
try {
    if (class_exists(ZipArchive::class)) {
        $archivePath = $fixture . '/extract.zip';
        $zip = new ZipArchive();
        $zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('large.bin', str_repeat('x', 2 * 1024 * 1024));
        $zip->close();
        mkdir($fixture . '/stage', 0700);
        $zip = new ZipArchive();
        $zip->open($archivePath);
        $calls = 0;
        $extracted = package_archive_extract_files($zip, [
            ['source' => 'large.bin', 'relative' => 'large.bin'],
        ], $fixture . '/stage', static function () use (&$calls): bool {
            $calls++;
            return $calls < 2;
        });
        $zip->close();
        $check($extracted === false && !file_exists($fixture . '/stage/large.bin'), 'archive checkpoint abort closes streams and removes the partial target');
    } else {
        $check(true, 'archive checkpoint test skipped because ZipArchive is unavailable');
    }

    file_put_contents($fixture . '/package.bin', str_repeat('p', 2 * 1024 * 1024));
    file_put_contents($fixture . '/router.php', <<<'PHP'
<?php
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if ($path !== '/retry.bin') return false;
$counterPath = __DIR__ . '/retry-count';
$count = is_file($counterPath) ? (int)file_get_contents($counterPath) : 0;
file_put_contents($counterPath, (string)($count + 1));
if ($count === 0) {
    http_response_code(503);
    echo 'retry';
    exit;
}
$package = __DIR__ . '/package.bin';
header('Content-Type: application/octet-stream');
header('Content-Length: ' . filesize($package));
readfile($package);
PHP);
    $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if (is_resource($listener)) {
        $address = (string)stream_socket_get_name($listener, false);
        fclose($listener);
        $port = (int)substr(strrchr($address, ':'), 1);
        $process = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $fixture, $fixture . '/router.php'], [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', '/dev/null', 'a'],
            2 => ['file', '/dev/null', 'a'],
        ], $pipes);
        if (is_resource($process)) {
            usleep(250000);
            $prefix = 'jyavani-cancel-' . bin2hex(random_bytes(4)) . '-';
            $before = glob(sys_get_temp_dir() . '/' . $prefix . '*') ?: [];
            $download = package_download('http://127.0.0.1:' . $port . '/package.bin', $prefix, 'Contract', static fn(int $downloaded, int $total): bool => false);
            $after = glob(sys_get_temp_dir() . '/' . $prefix . '*') ?: [];
            $retryPrefix = 'jyavani-retry-' . bin2hex(random_bytes(4)) . '-';
            $retryDownload = package_download('http://127.0.0.1:' . $port . '/retry.bin', $retryPrefix, 'Contract');
            $retryValid = is_string($retryDownload) && hash_equals(hash_file('sha256', $fixture . '/package.bin'), hash_file('sha256', $retryDownload));
            if (is_string($retryDownload)) unlink($retryDownload);
            $invalidDownload = package_download('file:///tmp/not-http', 'jyavani-invalid-', 'Contract');
            proc_terminate($process);
            proc_close($process);
            $check($download === null && $after === $before, 'download callback abort closes handles and removes the temporary file');
            $check($retryValid && (int)file_get_contents($fixture . '/retry-count') === 2, 'transient package failure retries within the bounded download budget');
            $check($invalidDownload === null && package_download_last_error_code() === 'invalid-url', 'package downloader exposes a safe diagnostic code without attempting an unsafe URL');
        } else {
            $check(true, 'download callback test skipped because the local server could not start');
        }
    } else {
        $check(true, 'download callback test skipped because a local port could not be reserved');
    }
} finally {
    package_remove_tree($fixture);
}

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " backend update integration check(s) failed.\n");
    exit(1);
}
echo "Backend update integration contract passed.\n";
