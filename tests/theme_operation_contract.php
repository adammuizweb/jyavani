<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$fixture = sys_get_temp_dir() . '/jy-theme-operation-' . bin2hex(random_bytes(6));
mkdir($fixture . '/backend/var', 0750, true);
mkdir($fixture . '/themes/sample', 0750, true);
define('BACKEND_PATH', $fixture . '/backend');
define('VIEWS_BASE', $fixture . '/themes');
define('PUBLIC_PATH', $fixture);
define('THEME_DEBUG', false);

function __(string $message, mixed ...$values): string {
    return $values === [] ? $message : sprintf($message, ...$values);
}

require_once $root . '/cfg/helpers/hooks.php';
require_once $root . '/cfg/helpers/theme_helper.php';
require_once $root . '/app/controllers/ThemeStoreClient.php';

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
    } catch (Throwable $error) {
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
    $locks = theme_operation_acquire(['sample', 'sample']);
    $check(count($locks) === 1 && is_resource($locks[0]), 'theme locks sort and deduplicate exact folder names');
    theme_operation_release($locks);
    $sharedLocks = theme_operation_acquire([THEME_LIFECYCLE_LOCK_KEY], LOCK_SH);
    $check(theme_operation_holds_lock(THEME_LIFECYCLE_LOCK_KEY, LOCK_SH), 'validated lock primitive supports generic shared readers');
    theme_operation_release($sharedLocks);
    theme_lifecycle_reader_start();
    $upgradeLocks = theme_operation_acquire(theme_lifecycle_lock_keys(['sample']));
    $check(theme_operation_holds_lock(THEME_LIFECYCLE_LOCK_KEY, LOCK_EX) && !theme_lifecycle_reader_is_active(),
        'request reader is released before global-first exclusive acquisition');
    theme_operation_release($upgradeLocks);
    $check(theme_lifecycle_reader_is_active(), 'request reader resumes after exclusive lifecycle release');
    theme_lifecycle_reader_stop();
    $heldLocks = theme_operation_acquire(theme_lifecycle_lock_keys(['sample']));
    $check($throws(static fn() => theme_operation_acquire(theme_lifecycle_lock_keys(['sample']))), 'callback re-entry fails fast when this request already holds a requested lifecycle key');
    theme_operation_release($heldLocks);
    $releasedLocks = theme_operation_acquire(theme_lifecycle_lock_keys(['sample']));
    theme_operation_release($releasedLocks);
    $check(($GLOBALS['_theme_operation_held_keys'] ?? []) === [] && ($GLOBALS['_theme_operation_lock_keys'] ?? []) === [], 'lock release clears request-local ownership bookkeeping');
    $legacyName = 'Legacy Theme (v1)';
    $legacyLocks = theme_operation_acquire([$legacyName]);
    theme_operation_release($legacyLocks);
    $legacyLock = BACKEND_PATH . '/var/theme-operation-locks/' . hash('sha256', $legacyName) . '.lock';
    $check(is_file($legacyLock) && !file_exists(BACKEND_PATH . '/var/theme-operation-locks/' . $legacyName . '.lock'), 'legacy exact basenames are supported without interpolation into lock filenames');
    $lockDirectoryMode = fileperms(BACKEND_PATH . '/var/theme-operation-locks') & 02777;
    $lockFile = BACKEND_PATH . '/var/theme-operation-locks/' . hash('sha256', 'sample') . '.lock';
    $lockFileMode = fileperms($lockFile) & 0777;
    $check($lockDirectoryMode === 02770 && $lockFileMode === 0660, 'theme locks use shared-group setgid directory and group-writable files');
    $check($throws(static fn() => theme_operation_acquire(['../sample']))
        && $throws(static fn() => theme_operation_acquire(['sample\\child']))
        && $throws(static fn() => theme_operation_acquire(['.']))
        && $throws(static fn() => theme_operation_acquire(["bad\0name"]))
        && $throws(static fn() => theme_operation_acquire(["bad\nname"]))
        && $throws(static fn() => theme_operation_acquire([str_repeat('a', 256)])), 'theme locks reject separators, dot names, control bytes, and overlong keys');

    chmod($lockFile, 0666);
    $check($throws(static fn() => theme_operation_acquire(['sample'])), 'theme locks reject world-writable lock files');
    chmod($lockFile, 0660);
    link($lockFile, BACKEND_PATH . '/var/theme-operation-locks/linked.lock');
    $check($throws(static fn() => theme_operation_acquire(['sample'])), 'theme locks reject multiply-linked lock files');
    @unlink(BACKEND_PATH . '/var/theme-operation-locks/linked.lock');
    @unlink($lockFile);
    chmod(dirname($lockFile), 02772);
    $check($throws(static fn() => theme_operation_acquire(['sample'])), 'theme locks reject world-writable lock directories');
    chmod(dirname($lockFile), 02770);
    symlink($fixture . '/outside-lock', $lockFile);
    $check($throws(static fn() => theme_operation_acquire(['sample'])), 'theme locks reject symbolic-link lock files');
    @unlink($lockFile);
    @unlink($legacyLock);
    foreach (glob(dirname($lockFile) . '/*.lock') ?: [] as $remainingLock) @unlink($remainingLock);
    @rmdir(dirname($lockFile));
    mkdir($fixture . '/outside-lock-dir', 0750);
    symlink($fixture . '/outside-lock-dir', dirname($lockFile));
    $check($throws(static fn() => theme_operation_acquire(['sample'])), 'theme locks reject symbolic-link lock directories');
    @unlink(dirname($lockFile));

    $helperSource = (string)file_get_contents($root . '/cfg/helpers/theme_helper.php');
    $check(str_contains($helperSource, '@fstat($handle)') && substr_count($helperSource, '@lstat($path)') >= 2
        && str_contains($helperSource, 'flock($handle, $mode)') && str_contains($helperSource, '[LOCK_SH, LOCK_EX]')
        && !str_contains($helperSource, 'posix_geteuid'), 'shared and exclusive lock modes verify regular descriptor identities without requiring one effective UID');
    $check(str_contains($helperSource, "hash('sha256', \$folder) . '.lock'")
        && str_contains($helperSource, '$left === THEME_LIFECYCLE_LOCK_KEY')
        && substr_count($helperSource, '$handle = @fopen($path, \'r+\');') >= 2, 'lock keys are hashed, the lifecycle key sorts first, and create-race losers reopen safely');
    $check(strpos($helperSource, '$operationLocks = theme_operation_acquire(theme_lifecycle_lock_keys([$finalFolder]))')
        < strpos($helperSource, '$destFs = path_candidate(VIEWS_BASE, $finalFolder')
        && strpos($helperSource, "do_action_isolated('theme_install_completed'") < strpos($helperSource, 'theme_operation_release($operationLocks)'),
        'installer holds the folder lock from destination validation through completion action');

    $isolatedCalls = [];
    add_action('contract_isolated', static function (): void { throw new RuntimeException('first listener failed'); }, 5);
    add_action('contract_isolated', static function () use (&$isolatedCalls): void { $isolatedCalls[] = 'second'; }, 10);
    $isolatedErrors = do_action_isolated('contract_isolated');
    $check(count($isolatedErrors) === 1 && $isolatedCalls === ['second'] && $isolatedErrors[0]['message'] === 'first listener failed', 'isolated actions continue after a throwing listener and return loggable errors');
    $aliasCalls = [];
    add_action('jy_head', static function () use (&$aliasCalls): void { $aliasCalls[] = 'canonical'; });
    add_action('wp_head', static function () use (&$aliasCalls): void { $aliasCalls[] = 'alias'; });
    $check(do_action_isolated('jy_head') === [] && $aliasCalls === ['canonical', 'alias'], 'isolated actions include canonical and legacy alias listeners');

    $normalize = new ReflectionMethod(ThemeStoreClient::class, 'normalizePreflight');
    $token = str_repeat('a', 64);
    $validIssue = [
        'id' => 'example.issue', 'label' => 'Example', 'message' => 'Resolve this issue.',
        'blocking' => true, 'resolved' => false, 'state_token' => $token,
        'choices' => [['id' => 'replace', 'label' => 'Replace data', 'destructive' => true]],
        'links' => [['label' => 'Review', 'method' => 'GET', 'url' => '/admin/review', 'params' => ['theme' => 'sample']]],
        'details' => ['count' => 1],
    ];
    $normalized = $normalize->invoke(null, ['schema' => 1, 'issues' => [$validIssue], 'decisions' => []], []);
    $check($normalized['allowed'] === false && $normalized['issues'][0]['state_token'] === $token, 'strict preflight normalization denies unresolved blocking issues');
    $check($throws(static fn() => $normalize->invoke(null, ['schema' => 1, 'issues' => [$validIssue, $validIssue], 'decisions' => []], [])), 'strict preflight normalization rejects duplicate issue IDs');
    $badLink = $validIssue;
    $badLink['links'][0]['url'] = 'https://example.test/escape';
    $check($throws(static fn() => $normalize->invoke(null, ['schema' => 1, 'issues' => [$badLink], 'decisions' => []], [])), 'strict preflight normalization rejects nonrelative links');
    $destructiveWithoutToken = $validIssue;
    unset($destructiveWithoutToken['state_token']);
    $check($throws(static fn() => $normalize->invoke(null, ['schema' => 1, 'issues' => [$destructiveWithoutToken], 'decisions' => []], [])), 'destructive preflight choices require a 64-hex issue state token');

    add_filter('theme_update_preflight', static fn(array $state): array => ['schema' => 1, 'issues' => [$validIssue, $validIssue], 'decisions' => $state['decisions']]);
    $run = new ReflectionMethod(ThemeStoreClient::class, 'runPreflight');
    $failedClosed = $run->invoke(null, new PDO('sqlite::memory:'), 'sample', ['current_version' => '1.0.0'], ['folder' => 'sample', 'version' => '1.0.0'], []);
    $check($failedClosed['allowed'] === false && ($failedClosed['issues'][0]['id'] ?? '') === 'core_preflight_invalid', 'malformed filter output becomes one generic unresolved blocking Core issue');
    $GLOBALS['_hooks']['filters']['theme_update_preflight'] = [];
    $secondIssue = $validIssue;
    $secondIssue['id'] = 'example.second';
    $secondIssue['state_token'] = str_repeat('b', 64);
    add_filter('theme_update_preflight', static function (array $state) use ($validIssue): array {
        $state['issues'][] = $validIssue;
        return $state;
    }, 5);
    add_filter('theme_update_preflight', static function (array $state) use ($secondIssue): array {
        $state['issues'][] = $secondIssue;
        return $state;
    }, 10);
    $monotonic = $run->invoke(null, new PDO('sqlite::memory:'), 'sample', [], ['folder' => 'sample', 'version' => '1.0.0'], []);
    $check(array_column($monotonic['issues'], 'id') === ['example.issue', 'example.second'], 'multiple preflight filters accumulate normalized issues in callback order');
    $GLOBALS['_hooks']['filters']['theme_update_preflight'] = [];
    add_filter('theme_update_preflight', static function (array $state) use ($validIssue): array {
        $state['issues'][] = $validIssue;
        return $state;
    }, 5);
    add_filter('theme_update_preflight', static fn(array $state): array => ['schema' => 1, 'issues' => [], 'decisions' => $state['decisions']], 10);
    $dropped = $run->invoke(null, new PDO('sqlite::memory:'), 'sample', [], ['folder' => 'sample', 'version' => '1.0.0'], []);
    $check(($dropped['issues'][0]['id'] ?? '') === 'core_preflight_invalid', 'later preflight filters cannot drop earlier normalized issues');
    $GLOBALS['_hooks']['filters']['theme_update_preflight'] = [];
    add_filter('theme_update_preflight', static function (array $state) use ($validIssue): array {
        $state['issues'][] = $validIssue;
        return $state;
    }, 5);
    add_filter('theme_update_preflight', static function (array $state): array {
        $state['issues'][0]['resolved'] = true;
        return $state;
    }, 10);
    $changed = $run->invoke(null, new PDO('sqlite::memory:'), 'sample', [], ['folder' => 'sample', 'version' => '1.0.0'], []);
    $check(($changed['issues'][0]['id'] ?? '') === 'core_preflight_invalid', 'later preflight filters cannot change or resolve earlier normalized issues');
    $GLOBALS['_hooks']['filters']['theme_update_preflight'] = [];

    $controller = (string)file_get_contents($root . '/app/controllers/ThemeStoreClient.php');
    $endpoint = (string)file_get_contents($root . '/dashboard/admin/themes/update_preflight.php');
    $applyEndpoint = (string)file_get_contents($root . '/dashboard/admin/themes/update_apply.php');
    $assign = (string)file_get_contents($root . '/dashboard/admin/themes/assign.php');
    $plugins = (string)file_get_contents($root . '/plugins/index.php');
    $updateSection = substr($assign, (int)strpos($assign, '// Theme update preflight and progress'));
    $check(str_contains($endpoint, "\$_SERVER['REQUEST_METHOD'] !== 'POST'") && str_contains($endpoint, 'adiwira_csrf_validate($csrf)')
        && str_contains($endpoint, "adiwira_require_permission(\$pdo, 'core.themes.manage', true)")
        && str_contains($endpoint, 'adiwira_require_site_owner($pdo, true)')
        && str_contains($endpoint, 'ThemeStoreClient::preflightUpdate($pdo, $folder, $decisions)'), 'preflight endpoint is POST/CSRF Site Owner theme-manage only');
    $check(str_contains($applyEndpoint, 'applyUpdate($pdo, $folderName, $token, $decisions)')
        && str_contains($assign, 'applyUpdate($pdo, $folder, $token, $decisions)'), 'both update apply entry points pass bounded decisions without bypass');
    $check(str_contains($assign, "do_action_isolated('theme_manager_theme_actions', \$th, \$completePhysicalManifest, \$themeActionContext)")
        && str_contains($assign, 'theme_complete_physical_manifest_for_hook($folder)'), 'each card action area exposes the generic action with a safely read full manifest');
    $check(str_contains($updateSection, 'update_preflight.php') && str_contains($updateSection, 'window.crypto.getRandomValues(bytes)')
        && str_contains($updateSection, 'theme_update_preflight_required') && str_contains($updateSection, 'clearInterval(pollingInterval)')
        && str_contains($updateSection, 'showCleanUpdateConfirmation(folderName)') && str_contains($updateSection, 'updateInFlight')
        && !str_contains($updateSection, 'confirm(') && !str_contains($updateSection, 'alert('), 'theme update UI preflights, confirms clean updates without native dialogs, prevents duplicate requests, and stops polling');
    $registrationPosition = strpos($controller, 'register_theme_in_db($pdo, $folderName, $installedManifest)');
    $completionPosition = strpos($controller, "do_action_isolated('theme_update_completed'", $registrationPosition);
    $releasePosition = strpos($controller, 'theme_operation_release($locks)', $completionPosition);
    $check($registrationPosition !== false && $completionPosition !== false && $releasePosition !== false
        && $registrationPosition < $completionPosition && $completionPosition < $releasePosition,
        'verified registration precedes isolated update completion while the lock is held');
    $check(str_contains($assign, '$managerLocks = theme_operation_acquire(theme_lifecycle_lock_keys($affectedFolders))')
        && strpos($assign, '$managerLocks = theme_operation_acquire(theme_lifecycle_lock_keys($affectedFolders))') < strpos($assign, '$pdo->beginTransaction()')
        && str_contains($assign, 'theme_operation_release($managerLocks)')
        && str_contains($assign, 'str_starts_with($realTarget, $realBase . DIRECTORY_SEPARATOR)'), 'Theme Manager deletion holds the folder lock across DB and exactly-contained filesystem mutation');
    $check(str_contains($controller, '$swapped = false')
        && str_contains($controller, 'package_guarded_publish($stage, $themeDir, $oldIdentity, $newIdentity)')
        && substr_count($controller, 'package_guarded_rollback($themeDir, $rollbackPath, $oldIdentity)') >= 2
        && str_contains($controller, "'metadata_restored'"),
        'all post-publication failures use guarded exact file rollback and verify metadata restoration');
    $check(substr_count($controller, 'theme_operation_acquire(theme_lifecycle_lock_keys([') >= 2
        && str_contains($assign, 'theme_operation_acquire(theme_lifecycle_lock_keys($affectedFolders))')
        && str_contains($plugins, 'theme_operation_acquire(theme_lifecycle_lock_keys([$name]))'), 'theme Store, Theme Manager, and generic plugin lifecycle operations share the global-first lock set');
    $check(str_contains($controller, "fopen(\$temporary, 'x+b')")
        && str_contains($controller, 'fflush($handle)') && str_contains($controller, 'fsync($handle)')
        && str_contains($controller, '!rename($temporary, $file)')
        && str_contains($controller, '$next[\'generation\'] = bin2hex(random_bytes(16))'), 'theme update transients use locked atomic replacement with a mutation generation');
    $packageHelper = (string)file_get_contents($root . '/cfg/helpers/package_archive.php');
    $check(str_contains($packageHelper, "'/.package-publication-recovery-'")
        && str_contains($packageHelper, "bin2hex(random_bytes(12))")
        && str_contains($packageHelper, 'package_publication_recovery_paths($target)'),
        'publication recovery paths are clearly named, unique, and detected before mutation');

    $firstThemeRecovery = package_private_directory(BACKEND_PATH . '/var', 'theme-sample-recovery');
    file_put_contents($firstThemeRecovery . '/preserved.marker', 'recovery');
    $secondThemeRecovery = package_private_directory(BACKEND_PATH . '/var', 'theme-sample-recovery');
    $check($firstThemeRecovery !== $secondThemeRecovery && is_file($firstThemeRecovery . '/preserved.marker')
        && is_dir($secondThemeRecovery), 'theme recovery attempts never reuse or overwrite a preserved artifact');

    $writeTransient = new ReflectionMethod(ThemeStoreClient::class, 'writeTransient');
    $removeCachedUpdate = new ReflectionMethod(ThemeStoreClient::class, 'removeCachedUpdate');
    $writeTransient->invoke(null, ['updates' => ['sample' => [
        'current_version' => '1.0.0', 'new_version' => '3.0.0', 'actionable' => true, 'checked_at' => time(),
    ]]]);
    $removeCachedUpdate->invoke(null, 'sample', '2.0.0');
    $check((ThemeStoreClient::getCachedUpdates()['sample']['new_version'] ?? '') === '3.0.0', 'cached theme completion retains a newer discovered update');
    $removeCachedUpdate->invoke(null, 'sample', '3.0.0');
    $check(!isset(ThemeStoreClient::getCachedUpdates()['sample']), 'cached theme completion removes the installed update generation without resurrection');

    file_put_contents(VIEWS_BASE . '/sample/theme.json', json_encode(['folder' => 'sample', 'name' => 'Sample', 'version' => '1.0.0']));
    file_put_contents(VIEWS_BASE . '/sample/old.txt', 'old');
    $oldIdentity = package_tree_identity(VIEWS_BASE . '/sample');
    $stage = package_private_directory(VIEWS_BASE, 'theme-stage-sample');
    file_put_contents($stage . '/theme.json', json_encode(['folder' => 'sample', 'name' => 'Sample', 'version' => '2.0.0']));
    file_put_contents($stage . '/new.txt', 'new');
    $newIdentity = package_tree_identity($stage);
    $publicationLocks = theme_operation_acquire(theme_lifecycle_lock_keys(['sample']));
    $publication = package_guarded_publish($stage, VIEWS_BASE . '/sample', $oldIdentity, $newIdentity);
    file_put_contents(VIEWS_BASE . '/sample/runtime-extra.txt', 'extra');
    $rollback = $publication['success']
        ? package_guarded_rollback(VIEWS_BASE . '/sample', $publication['rollback_path'], $oldIdentity)
        : ['restored' => false];
    theme_operation_release($publicationLocks);
    $check($rollback['restored'] && package_tree_matches_identity(VIEWS_BASE . '/sample', $oldIdentity)
        && !is_file(VIEWS_BASE . '/sample/runtime-extra.txt') && is_file(VIEWS_BASE . '/sample/old.txt'),
        'guarded rollback verifies exact old hashes and removes files added to the failed new tree');
} finally {
    $removeTree($fixture);
}

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " theme operation contract checks failed.\n");
    exit(1);
}
echo "Theme operation contract passed ({$checks} checks).\n";
