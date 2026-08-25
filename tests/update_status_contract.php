<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$temporary = sys_get_temp_dir() . '/jyavani-update-status-' . bin2hex(random_bytes(6)) . '.json';
define('UPDATE_STATUS_FILE', $temporary);
require_once $root . '/app/controllers/UpdateStatusController.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$failures = [];
$checks = 0;
$check = static function (bool $condition, string $message) use (&$failures, &$checks): void {
    $checks++;
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};

$pdo = new PDO('sqlite::memory:');
$calls = ['core' => 0, 'plugins' => 0, 'themes' => 0];
$providers = [
    'core' => static function (string $url, string $current) use (&$calls): array {
        $calls['core']++;
        return [
            'version' => '99.0.0',
            'download_url' => 'https://updates.example.test/core.zip',
            'critical_advisory' => [
                'severity' => 'critical',
                'id' => 'ADV-TEST',
                'fixed_version' => '99.0.0',
                'title' => 'Test advisory',
                'message' => 'Test only',
            ],
        ];
    },
    'plugins' => static function (PDO $pdo) use (&$calls): array {
        $calls['plugins']++;
        return ['state' => 'ok', 'errors' => [], 'updates' => [
            'sample-plugin' => ['current_version' => '1.0.0', 'new_version' => '1.1.0'],
        ]];
    },
    'themes' => static function (PDO $pdo) use (&$calls): array {
        $calls['themes']++;
        return ['state' => 'ok', 'errors' => [], 'updates' => [
            'sample-theme' => ['current_version' => '2.0.0', 'new_version' => '2.1.0'],
        ]];
    },
];

try {
    $initial = UpdateStatusController::getSnapshot();
    $check($initial['state'] === 'unknown' && $initial['stale'] === true && $initial['total'] === 0, 'missing snapshot is unknown and stale, never falsely current');

    $snapshot = UpdateStatusController::checkAll($pdo, 'https://updates.example.test/latest/', $providers);
    $check($calls === ['core' => 1, 'plugins' => 1, 'themes' => 1], 'one coordinated check invokes every provider exactly once');
    $check($snapshot['state'] === 'ok' && $snapshot['stale'] === false && $snapshot['total'] === 3, 'successful snapshot aggregates Core, plugin, and theme updates');
    $check(is_file($temporary) && is_file($temporary . '.lock'), 'snapshot and global coordination lock are persisted');
    $check(($snapshot['components']['core']['remote']['download_url'] ?? '') === 'https://updates.example.test/core.zip', 'Core package metadata is retained for one-click handoff');
    $check(($snapshot['components']['core']['check_url'] ?? '') === 'https://updates.example.test/latest/', 'snapshot records the exact Core URL used for safe request coalescing');

    $contendedCalls = 0;
    $contendedLock = fopen($temporary . '.lock', 'c+');
    if ($contendedLock !== false && flock($contendedLock, LOCK_EX)) {
        $contended = UpdateStatusController::checkAll($pdo, 'https://updates.example.test/latest/', [
            'core' => static function () use (&$contendedCalls): array { $contendedCalls++; return ['version' => '100.0.0']; },
        ]);
        $check($contendedCalls === 0 && $contended['generation'] === $snapshot['generation'], 'a concurrent refresh returns the current snapshot without waiting or issuing duplicate requests');
        $check((static function () use ($pdo, $providers): bool {
            try {
                UpdateStatusController::checkAll($pdo, 'https://other-updates.example.test/latest/', $providers);
                return false;
            } catch (RuntimeException $error) {
                return true;
            }
        })(), 'a contended custom Core check never reuses metadata from a different update URL');
        flock($contendedLock, LOCK_UN);
        fclose($contendedLock);
    } else {
        if (is_resource($contendedLock)) fclose($contendedLock);
        $check(false, 'a concurrent refresh returns the current snapshot without waiting or issuing duplicate requests');
    }

    $expiredDeadlineStart = microtime(true);
    $expiredDeadlineResult = update_metadata_fetch_json('https://192.0.2.1/version.json', 'JyavaniCMS-Test', $expiredDeadlineStart - 1);
    $check($expiredDeadlineResult === null && microtime(true) - $expiredDeadlineStart < 0.25, 'an exhausted update deadline prevents another network request');

    if (function_exists('pcntl_fork')) {
        $server = stream_socket_server('tcp://127.0.0.1:0', $serverError, $serverMessage);
        $pid = is_resource($server) ? pcntl_fork() : -1;
        if ($pid === 0) {
            $client = stream_socket_accept($server, 2);
            if (is_resource($client)) {
                fread($client, 4096);
                usleep(1000000);
                fclose($client);
            }
            fclose($server);
            exit(0);
        }
        if ($pid > 0) {
            $address = (string)stream_socket_get_name($server, false);
            fclose($server);
            $stallStarted = microtime(true);
            $stallResult = update_metadata_fetch_json('http://' . $address . '/version.json', 'JyavaniCMS-Test', $stallStarted + 0.2);
            $stallElapsed = microtime(true) - $stallStarted;
            $streamStarted = microtime(true);
            $streamResult = update_metadata_stream_request('http://' . $address . '/version.json', 'JyavaniCMS-Test', $streamStarted + 0.2);
            $streamElapsed = microtime(true) - $streamStarted;
            pcntl_waitpid($pid, $childStatus);
            $check($stallResult === null && $stallElapsed < 1.0, 'an active metadata request cannot exceed its remaining whole-check deadline');
            $check($streamResult === null && $streamElapsed < 1.0, 'the no-cURL metadata transport bounds connection and header waits by the same deadline');
        } else {
            if (is_resource($server)) fclose($server);
            $check(false, 'an active metadata request cannot exceed its remaining whole-check deadline');
        }
    }
    if (function_exists('pcntl_fork')) {
        $slowServer = stream_socket_server('tcp://127.0.0.1:0', $slowError, $slowMessage);
        $slowPid = is_resource($slowServer) ? pcntl_fork() : -1;
        if ($slowPid === 0) {
            $client = stream_socket_accept($slowServer, 2);
            if (is_resource($client)) {
                fread($client, 4096);
                foreach (str_split("HTTP/1.1 200 OK\r\n") as $byte) {
                    if (@fwrite($client, $byte) !== 1) break;
                    usleep(50000);
                }
                fclose($client);
            }
            fclose($slowServer);
            exit(0);
        }
        if ($slowPid > 0) {
            $slowAddress = (string)stream_socket_get_name($slowServer, false);
            fclose($slowServer);
            $slowStarted = microtime(true);
            $slowResult = update_metadata_stream_request('http://' . $slowAddress . '/version.json', 'JyavaniCMS-Test', $slowStarted + 0.2);
            $slowElapsed = microtime(true) - $slowStarted;
            pcntl_waitpid($slowPid, $slowStatus);
            $check($slowResult === null && $slowElapsed < 1.0, 'slow-drip response lines cannot extend the absolute metadata deadline');
        } else {
            if (is_resource($slowServer)) fclose($slowServer);
            $check(false, 'slow-drip response lines cannot extend the absolute metadata deadline');
        }

        $hintsServer = stream_socket_server('tcp://127.0.0.1:0', $hintsError, $hintsMessage);
        $hintsPid = is_resource($hintsServer) ? pcntl_fork() : -1;
        if ($hintsPid === 0) {
            $client = stream_socket_accept($hintsServer, 2);
            if (is_resource($client)) {
                fread($client, 4096);
                $json = '{"version":"9.9.9"}';
                fwrite($client, "HTTP/1.1 103 Early Hints\r\nLink: </version.json>; rel=preload\r\n\r\n"
                    . "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: " . strlen($json) . "\r\n\r\n" . $json);
                fclose($client);
            }
            fclose($hintsServer);
            exit(0);
        }
        if ($hintsPid > 0) {
            $hintsAddress = (string)stream_socket_get_name($hintsServer, false);
            fclose($hintsServer);
            $hintsResult = update_metadata_stream_request('http://' . $hintsAddress . '/version.json', 'JyavaniCMS-Test', microtime(true) + 1.0);
            pcntl_waitpid($hintsPid, $hintsStatus);
            $check(($hintsResult['status'] ?? 0) === 200
                && (json_decode((string)($hintsResult['body'] ?? ''), true)['version'] ?? '') === '9.9.9',
                'the no-cURL transport continues from informational responses to the final JSON response');
        } else {
            if (is_resource($hintsServer)) fclose($hintsServer);
            $check(false, 'the no-cURL transport continues from informational responses to the final JSON response');
        }
    }
    $check(update_metadata_redirect_url('https://example.test/store/version.json', '?format=json') === 'https://example.test/store/version.json?format=json'
        && update_metadata_redirect_url('https://[::1]/store/version.json', '/next.json') === 'https://[::1]/next.json',
        'the no-cURL redirect resolver preserves query-only targets and IPv6 origins');

    $payload = UpdateStatusController::publicPayload($snapshot);
    $serializedPayload = json_encode($payload, JSON_UNESCAPED_SLASHES);
    $check($payload['total'] === 3 && count($payload['plugins']) === 1 && count($payload['themes']) === 1, 'public payload uses the same aggregate count');
    $check(is_string($serializedPayload) && !str_contains($serializedPayload, 'core.zip'), 'public payload does not expose package metadata');
    $check(($payload['critical_advisory']['id'] ?? '') === 'ADV-TEST', 'critical advisory remains available to the notification UI');

    $readOnly = UpdateStatusController::getSnapshot();
    $check($calls === ['core' => 1, 'plugins' => 1, 'themes' => 1] && $readOnly['generation'] === $snapshot['generation'], 'snapshot reads perform no remote checks');

    UpdateStatusController::hydrateCoreSession($snapshot);
    $check(($_SESSION['cms_update_remote']['version'] ?? '') === '99.0.0'
        && ($_SESSION['cms_update_base_url'] ?? '') === 'https://updates.example.test/latest/', 'shared Core result hydrates the existing apply flow without a second check');
    $check(UpdateStatusController::isUpdateActionable('core', '', '99.0.0'), 'fresh Core metadata is actionable only for the checked target version');
    $expired = $snapshot;
    $expired['checked_at'] = time() - 3601;
    $expired['expires_at'] = time() - 1;
    $expired['components']['core']['checked_at'] = time() - 3601;
    $writeSnapshot = new ReflectionMethod(UpdateStatusController::class, 'writeSnapshot');
    $writeSnapshot->invoke(null, $expired);
    unset($_SESSION['cms_update_remote'], $_SESSION['cms_update_base_url']);
    UpdateStatusController::hydrateCoreSession(UpdateStatusController::getSnapshot());
    $check(!isset($_SESSION['cms_update_remote']) && !UpdateStatusController::isUpdateActionable('core'), 'expired Core metadata cannot hydrate or pass the apply-time guard');
    $writeSnapshot->invoke(null, $snapshot);
    $_SESSION['cms_update_package'] = '/tmp/manual-update.zip';
    $_SESSION['cms_update_remote'] = ['version' => '88.0.0'];
    UpdateStatusController::hydrateCoreSession($snapshot);
    $check(($_SESSION['cms_update_remote']['version'] ?? '') === '88.0.0', 'shared status never overwrites a pending manual upload manifest');
    unset($_SESSION['cms_update_package'], $_SESSION['cms_update_remote']);

    $partialProviders = [
        'core' => static fn(): ?array => null,
        'plugins' => static fn(PDO $pdo): array => ['state' => 'partial', 'errors' => ['sample-plugin'], 'updates' => [
            'sample-plugin' => ['current_version' => '1.0.0', 'new_version' => '1.1.0'],
        ]],
        'themes' => static fn(PDO $pdo): array => ['state' => 'ok', 'errors' => [], 'updates' => [
            'sample-theme' => ['current_version' => '2.0.0', 'new_version' => '2.1.0'],
        ]],
    ];
    $partial = UpdateStatusController::checkAll($pdo, 'https://updates.example.test/latest/', $partialProviders);
    $check($partial['state'] === 'partial' && $partial['components']['core']['state'] === 'error', 'failed sources produce a partial state instead of a false success');
    $check(($partial['components']['core']['latest'] ?? '') === '99.0.0' && $partial['total'] === 2, 'partial checks retain last-known data without counting non-actionable failures');
    UpdateStatusController::hydrateCoreSession($partial);
    $check(!isset($_SESSION['cms_update_remote']), 'failed Core checks retain status but disable stale package application');
    $check(!UpdateStatusController::isUpdateActionable('core'), 'failed Core metadata is rejected by the apply-time guard');
    $check($partial['expires_at'] - $partial['checked_at'] === 300, 'partial results use a short retry interval');
    $partialPayload = UpdateStatusController::publicPayload($partial);
    $check(in_array('core', $partialPayload['failed_components'], true)
        && in_array('plugins', $partialPayload['failed_components'], true), 'public payload identifies incomplete components');

    UpdateStatusController::removeUpdate('plugins', 'sample-plugin', '1.0.1');
    $afterOlderPluginApply = UpdateStatusController::getSnapshot();
    $check(($afterOlderPluginApply['components']['plugins']['updates']['sample-plugin']['new_version'] ?? '') === '1.1.0'
        && ($afterOlderPluginApply['components']['plugins']['updates']['sample-plugin']['current_version'] ?? '') === '1.0.1', 'plugin completion retains a newer concurrently discovered global update');
    UpdateStatusController::removeUpdate('plugins', 'sample-plugin', '1.1.0');
    $afterPlugin = UpdateStatusController::getSnapshot();
    $check($afterPlugin['total'] === 1 && !isset($afterPlugin['components']['plugins']['updates']['sample-plugin']), 'successful plugin apply removes its exact global notification immediately');
    UpdateStatusController::removeUpdate('core', '', '99.0.0');
    $afterCore = UpdateStatusController::getSnapshot();
    $check($afterCore['total'] === 1 && $afterCore['components']['core']['has_update'] === false, 'successful Core apply clears its global notification immediately');
    UpdateStatusController::removeUpdate('themes', 'sample-theme', '2.0.0');
    $afterOlderThemeApply = UpdateStatusController::getSnapshot();
    $check($afterOlderThemeApply['total'] === 1
        && ($afterOlderThemeApply['components']['themes']['updates']['sample-theme']['new_version'] ?? '') === '2.1.0', 'theme completion retains a newer concurrently discovered update');
    UpdateStatusController::removeUpdate('themes', 'sample-theme', '2.1.0');
    $afterThemeApply = UpdateStatusController::getSnapshot();
    $check($afterThemeApply['total'] === 0 && !isset($afterThemeApply['components']['themes']['updates']['sample-theme']), 'theme completion removes only the update version that was installed');

    $endpoint = (string)file_get_contents($root . '/dashboard/admin/check_updates_ajax.php');
    $javascript = (string)file_get_contents($root . '/public/static/dashboard/js/update-notif.js');
    $layout = (string)file_get_contents($root . '/dashboard/theme/adam/layout.php');
    $updatePage = (string)file_get_contents($root . '/dashboard/admin/update/index.php');
    $pluginPage = (string)file_get_contents($root . '/dashboard/admin/plugins/index.php');
    $themePage = (string)file_get_contents($root . '/dashboard/admin/themes/assign.php');
    $themeApply = (string)file_get_contents($root . '/dashboard/admin/themes/update_apply.php');
    $statusController = (string)file_get_contents($root . '/app/controllers/UpdateStatusController.php');
    $pluginStore = (string)file_get_contents($root . '/app/controllers/PluginStoreController.php');
    $themeStore = (string)file_get_contents($root . '/app/controllers/ThemeStoreClient.php');
    $coreActions = (string)file_get_contents($root . '/dashboard/admin/update/_update_actions.php');
    $check(str_contains($endpoint, "if (\$method === 'POST')") && str_contains($endpoint, 'adiwira_csrf_validate(')
        && str_contains($endpoint, 'UpdateStatusController::getSnapshot()'), 'GET is read-only while coordinated refresh is POST with CSRF');
    $check(str_contains($javascript, "xhr.open(refreshMode ? 'POST' : 'GET'")
        && str_contains($javascript, 'window.jyavaniUpdateStatus')
        && str_contains($javascript, 'jyavani:update-status'), 'notification client renders the shared snapshot and broadcasts refreshes');
    $check(str_contains($updatePage, 'data-cms-latest data-latest-class="up-latest"')
        && str_contains($javascript, "document.querySelectorAll('[data-cms-latest]')")
        && str_contains($javascript, "latest.getAttribute('data-latest-class') || 'dw-latest'"), 'dashboard and Update Manager receive the live Latest badge after an asynchronous check');
    $check(str_contains($layout, '/static/dashboard/js/update-notif.js?v=')
        && str_contains($layout, "filemtime(\$updateNotifFile)"), 'update notification script uses a file-version cache buster');
    $check(str_contains($layout, 'JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT'), 'remote update metadata is safe inside the dashboard script context');
    $check(str_contains($pluginPage, 'UpdateStatusController::checkAll($pdo)')
        && str_contains($themePage, 'UpdateStatusController::checkAll($pdo)')
        && str_contains($coreActions, 'UpdateStatusController::checkAll($pdo, $inputUrl)'), 'all dedicated Check buttons invoke the same coordinator');
    $metadataClient = (string)file_get_contents($root . '/cfg/helpers/update_metadata_http.php');
    $check(str_contains($statusController, 'CHECK_BUDGET_SECONDS')
        && str_contains($statusController, 'LOCK_EX | LOCK_NB')
        && str_contains($pluginStore, 'update_metadata_fetch_json(')
        && str_contains($themeStore, 'update_metadata_fetch_json(')
        && str_contains($metadataClient, 'CURLOPT_CONNECTTIMEOUT_MS')
        && str_contains($metadataClient, 'CURLOPT_TIMEOUT_MS')
        && str_contains($metadataClient, 'stream_socket_client(')
        && str_contains($metadataClient, 'stream_select('), 'manual checks share bounded metadata requests, a whole-check budget, and a non-blocking coordinator lock');
    $check(str_contains($coreActions, "UpdateStatusController::removeUpdate('core')"), 'successful reinstall synchronizes the global Core snapshot');
    $check(strpos($pluginPage, "(\$snapshot['state'] ?? 'ok') !== 'ok'") < strpos($pluginPage, 'elseif ($count > 0)')
        && strpos($themePage, "(\$snapshot['state'] ?? 'ok') !== 'ok'") < strpos($themePage, 'elseif ($count > 0)'), 'dedicated checks report partial failures before update counts');
    $check(str_contains($themeApply, "removeUpdate('themes', \$folderName, (string)\$result['new_version'])")
        && str_contains($themePage, "removeUpdate('themes', \$folder, (string)\$result['new_version'])"), 'both theme apply endpoints synchronize the exact installed version');
    $scanStart = strpos($themeStore, '$scanLocks = self::discoveryLocks(theme_lifecycle_lock_keys($scanFolders), $deadline)');
    $scanRelease = strpos($themeStore, 'theme_operation_release($scanLocks)', $scanStart === false ? 0 : $scanStart);
    $remoteCheck = strpos($themeStore, 'self::fetchVersionInfo(', $scanStart === false ? 0 : $scanStart);
    $commitLock = strpos($themeStore, '$commitLocks = self::discoveryLocks(theme_lifecycle_lock_keys(array_keys($themes)), $deadline)', $remoteCheck === false ? 0 : $remoteCheck);
    $transientCommit = strpos($themeStore, '$committed = self::mutateTransient(', $commitLock === false ? 0 : $commitLock);
    $check($scanStart !== false && $scanRelease !== false && $remoteCheck !== false && $commitLock !== false && $transientCommit !== false
        && $scanStart < $scanRelease && $scanRelease < $remoteCheck && $remoteCheck < $commitLock && $commitLock < $transientCommit,
        'theme scan binds its transient generation under the global lock and reacquires global before transient commit revalidation');

    $check(str_contains($pluginStore, "['actionable'] = false") && str_contains($pluginStore, "'actionable' => true")
        && str_contains($themeStore, "['actionable'] = false") && str_contains($themeStore, "'actionable' => true"), 'failed Store checks remain visible but cannot apply retained metadata');
    $check(str_contains($themeStore, "hash_file('sha256', \$tmpZip)")
        && str_contains($themeStore, "version_compare(\$manifest['version'], (string)\$update['new_version'], '!=')"), 'theme updates verify package checksum and advertised version before installation');
    $check(str_contains($themeStore, 'package_archive_validate($zip)')
        && str_contains($themeStore, 'package_archive_extract_files($zip, $files, $stage)')
        && str_contains($themeStore, 'is_link($themeCandidate)'), 'theme updates use the shared validator to reject escaped, duplicate, symbolic-link, and special-file package targets');
    $check(str_contains($themeStore, 'package_guarded_publish($stage, $themeDir, $oldIdentity, $newIdentity)')
        && str_contains($themeStore, 'package_guarded_rollback($themeDir, $rollbackPath, $oldIdentity)')
        && str_contains($themeStore, 'package_guarded_finalize($themeDir, $rollbackPath, $oldIdentity)'),
        'theme updates retain the exact old tree through registration, guarded rollback, and final cleanup');
    $check(str_contains($pluginStore, 'removeCachedUpdate($name, (string)$update[\'new_version\'])')
        && str_contains($pluginPage, "removeUpdate('plugins', \$pluginName, (string)\$updateResult['new_version'])")
        && str_contains($themeStore, 'array_key_exists($folder, $observed)'), 'plugin completion and global status use exact versions, while explicit null theme observations remain distinguishable');

    require_once $root . '/app/controllers/ThemeStoreClient.php';
    $themeFixture = sys_get_temp_dir() . '/jyavani-theme-target-' . bin2hex(random_bytes(6));
    $outsideFixture = sys_get_temp_dir() . '/jyavani-theme-outside-' . bin2hex(random_bytes(6));
    mkdir($themeFixture, 0700, true);
    mkdir($outsideFixture, 0700, true);
    $safeThemeTarget = new ReflectionMethod(ThemeStoreClient::class, 'safeThemeTarget');
    $check($safeThemeTarget->invoke(null, $themeFixture, 'assets/style.css') === $themeFixture . '/assets/style.css', 'theme target validation accepts a contained new path');
    if (symlink($outsideFixture, $themeFixture . '/assets')) {
        $check($safeThemeTarget->invoke(null, $themeFixture, 'assets/pwn.php') === null, 'theme target validation rejects an existing parent symlink outside the theme');
        unlink($themeFixture . '/assets');
    } else {
        $check(false, 'theme target validation rejects an existing parent symlink outside the theme');
    }
    rmdir($themeFixture);
    rmdir($outsideFixture);

    $translations = (string)file_get_contents($root . '/schema/translations.sql');
    foreach (['Check All Updates', 'Updates have not been checked yet.', 'Some update sources could not be reached. Showing the last known results.', 'Last checked:', 'Failed to create complete theme backup.'] as $source) {
        $check(substr_count($translations, "'" . str_replace("'", "''", $source) . "'") >= 2, 'translation coverage: ' . $source);
    }
} finally {
    foreach ([$temporary, $temporary . '.lock'] as $file) if (is_file($file)) unlink($file);
}

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " update status contract checks failed.\n");
    exit(1);
}
echo "Update status contract passed ({$checks} checks).\n";
