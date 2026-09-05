<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$temporary = sys_get_temp_dir() . '/jyavani-update-process-' . bin2hex(random_bytes(6));
if (!mkdir($temporary, 0700, true)) throw new RuntimeException('Unable to create test directory.');
define('UPDATE_OPERATION_DIRECTORY', $temporary);
require_once $root . '/cfg/helpers/update_operation.php';

$failures = [];
$checks = 0;
$check = static function (bool $condition, string $message) use (&$failures, &$checks): void {
    $checks++;
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};

$token = static fn(): string => bin2hex(random_bytes(16));

try {
    $progressToken = $token();
    $check(update_operation_begin($progressToken, 41, 'plugin', 'sample-plugin', 'Starting'), 'begin creates a valid operation');
    update_operation_progress($progressToken, 60, 'download', 'Downloading', true);
    update_operation_progress($progressToken, 20, 'verify', 'Verifying', true);
    $progress = update_operation_read($progressToken);
    $check(($progress['percentage'] ?? -1) === 60 && ($progress['stage'] ?? '') === 'verify', 'progress percentage is monotonic while stage advances');

    $wrongOwner = update_operation_request_cancel($progressToken, 42);
    $check(($wrongOwner['ok'] ?? true) === false && ($wrongOwner['reason'] ?? '') === 'owner_mismatch', 'cancellation is bound to the operation owner');
    $requested = update_operation_request_cancel($progressToken, 41);
    update_operation_progress($progressToken, 70, 'extract', 'Extracting', true);
    $afterProgress = update_operation_read($progressToken);
    $check(($requested['ok'] ?? false) === true && ($afterProgress['cancel_requested'] ?? false) === true, 'progress mutation preserves a concurrent cancellation request');

    $thrown = false;
    try {
        update_operation_checkpoint($progressToken);
    } catch (UpdateOperationCancelled $error) {
        $thrown = true;
    }
    $cancelling = update_operation_read($progressToken);
    $check($thrown && ($cancelling['stage'] ?? '') === 'cancelling' && ($cancelling['cancel_allowed'] ?? true) === false, 'checkpoint safely marks cancellation before throwing the dedicated exception');
    update_operation_mark_cancelled($progressToken, 'Cancelled');
    $cancelled = update_operation_read($progressToken);
    $check(($cancelled['done'] ?? false) === true && ($cancelled['outcome'] ?? '') === 'cancelled', 'mark_cancelled produces a terminal cancelled outcome');

    $deniedToken = $token();
    update_operation_begin($deniedToken, 41, 'theme', 'sample-theme', 'Starting');
    update_operation_progress($deniedToken, 10, 'publish', 'Publishing', false);
    $denied = update_operation_request_cancel($deniedToken, 41);
    $check(($denied['ok'] ?? true) === false && ($denied['reason'] ?? '') === 'not_allowed', 'cancellation is denied after the operation disallows it');

    $completeToken = $token();
    update_operation_begin($completeToken, 41, 'core', '', 'Starting');
    update_operation_progress($completeToken, 75, 'publish', 'Publishing', false);
    update_operation_complete($completeToken, 'Complete');
    $complete = update_operation_read($completeToken);
    $check(($complete['percentage'] ?? 0) === 100 && ($complete['done'] ?? false) === true
        && ($complete['outcome'] ?? '') === 'completed', 'complete produces a 100 percent completed terminal outcome');

    $failedToken = $token();
    update_operation_begin($failedToken, 41, 'core', '', 'Starting');
    update_operation_progress($failedToken, 37, 'verify', 'Verifying', false);
    update_operation_fail($failedToken, 'Failed', 'Package rejected');
    $failed = update_operation_read($failedToken);
    $check(($failed['percentage'] ?? 0) === 37 && ($failed['outcome'] ?? '') === 'failed'
        && ($failed['error'] ?? '') === 'Package rejected', 'failure preserves percentage and records a terminal error');

    $recordPath = $temporary . '/update-operation-' . $completeToken . '.json';
    $decoded = json_decode((string)file_get_contents($recordPath), true);
    $check(is_array($decoded) && update_operation_record_valid($decoded) && (fileperms($recordPath) & 0777) === 0660,
        'atomic record output is valid schema JSON with group-writable mode 0660');
    $check(glob($temporary . '/*.tmp-*') === [], 'atomic writes leave no temporary files behind');

    $firstLock = update_operation_acquire_lock();
    $secondLock = update_operation_acquire_lock();
    $check(is_resource($firstLock) && $secondLock === null, 'global operation lock excludes a concurrent updater without blocking');
    update_operation_release_lock($firstLock);
    $thirdLock = update_operation_acquire_lock();
    $check(is_resource($thirdLock), 'global operation lock can be acquired after release');
    update_operation_release_lock($thirdLock);

    $criticalToken = $token();
    update_operation_begin($criticalToken, 41, 'theme', 'critical-theme', 'Starting');
    update_operation_progress($criticalToken, 70, 'validate', 'Validating', true);
    update_operation_enter_critical($criticalToken, 80, 'publish', 'Publishing');
    $critical = update_operation_read($criticalToken);
    $check(($critical['stage'] ?? '') === 'publish' && ($critical['cancel_allowed'] ?? true) === false,
        'critical transition atomically closes cancellation before publication');

    $staleToken = $token();
    update_operation_begin($staleToken, 41, 'core', '', 'Starting');
    $stalePath = $temporary . '/update-operation-' . $staleToken . '.json';
    $stale = json_decode((string)file_get_contents($stalePath), true);
    $stale['updated_at'] = time() - 1801;
    file_put_contents($stalePath, json_encode($stale));
    chmod($stalePath, 0660);
    $abandoned = update_operation_read($staleToken);
    $check(($abandoned['done'] ?? false) === true && ($abandoned['outcome'] ?? '') === 'failed',
        'an abandoned running operation becomes a terminal failure after its worker lock disappears');

    $staleToken = $token();
    $check(update_operation_begin($staleToken, 41, 'core', '', 'Starting'), 'stale cleanup fixture begins');
    update_operation_complete($staleToken, 'Complete');
    $stalePath = update_operation_path($staleToken);
    $staleRecord = json_decode((string)file_get_contents((string)$stalePath), true, 32, JSON_THROW_ON_ERROR);
    $staleRecord['updated_at'] = time() - 3601;
    file_put_contents((string)$stalePath, json_encode($staleRecord, JSON_THROW_ON_ERROR));
    chmod((string)$stalePath, 0660);
    touch((string)$stalePath, time() - 3601);
    $check(update_operation_begin($token(), 41, 'core', '', 'Starting') && !file_exists((string)$stalePath),
        'new operations reap stale terminal records while retaining a bounded lock stripe set');

    $config = (string)file_get_contents($root . '/cfg/config.php');
    $router = (string)file_get_contents($root . '/public/router.php');
    $endpoint = (string)file_get_contents($root . '/dashboard/admin/update/process.php');
    $helperPosition = strpos($config, "helpers/update_operation.php");
    $readerPosition = strpos($config, 'theme_lifecycle_reader_start();');
    $check($helperPosition !== false && $readerPosition !== false && $helperPosition < $readerPosition
        && str_contains($config, "!defined('UPDATE_PROCESS_CONTROL_REQUEST')"), 'config loads operation helpers before the suppressible lifecycle reader');
    $helper = (string)file_get_contents($root . '/cfg/helpers/update_operation.php');
    $check(str_contains($helper, "'/record-lock-' . substr(\$token, 0, 2) . '.lock'")
        && str_contains($helper, 'update_operation_cleanup_stale();'), 'operation records use bounded lock stripes and opportunistic stale cleanup');
    $check(str_contains($router, "str_ends_with(\$updateProcessPath, '/admin/update/process.php')")
        && strpos($router, "define('UPDATE_PROCESS_CONTROL_REQUEST', true)") < strpos($router, "require_once __DIR__ . '/../app/bootstrap_theme.php'"),
        'router dispatches the exact process suffix before theme and plugin bootstrap');
    $check(str_contains($endpoint, "ADMIN_BASE_PATH . '/admin/update/process.php'")
        && str_contains($endpoint, 'adiwira_require_site_owner(')
        && str_contains($endpoint, "'core' => 'core.updates.manage'")
        && str_contains($endpoint, "'plugin' => 'core.plugins.manage'")
        && str_contains($endpoint, "'theme' => 'core.themes.manage'")
        && str_contains($endpoint, 'adiwira_csrf_validate('), 'endpoint enforces exact route, Site Owner, typed permission, and POST CSRF contracts');
    $check(str_contains($endpoint, "'found' => false") && str_contains($endpoint, "'found' => true"),
        'endpoint distinguishes an operation that has not started from authoritative running state');
    $check(!str_contains($endpoint, "'owner_id' =>") && !str_contains($endpoint, "'target' =>") && !str_contains($endpoint, "'type' =>"),
        'endpoint public payload excludes owner and operation target metadata');
} finally {
    foreach ((array)glob($temporary . '/*') as $file) if (is_file($file) || is_link($file)) @unlink($file);
    @rmdir($temporary);
}

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " update process contract checks failed.\n");
    exit(1);
}
echo "Update process contract passed ({$checks} checks).\n";
