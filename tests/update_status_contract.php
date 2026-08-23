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
    $check(($partial['components']['core']['latest'] ?? '') === '99.0.0' && $partial['total'] === 3, 'partial checks preserve last-known update data');
    UpdateStatusController::hydrateCoreSession($partial);
    $check(!isset($_SESSION['cms_update_remote']), 'failed Core checks retain status but disable stale package application');
    $check(!UpdateStatusController::isUpdateActionable('core'), 'failed Core metadata is rejected by the apply-time guard');
    $check($partial['expires_at'] - $partial['checked_at'] === 300, 'partial results use a short retry interval');
    $partialPayload = UpdateStatusController::publicPayload($partial);
    $check(in_array('core', $partialPayload['failed_components'], true)
        && in_array('plugins', $partialPayload['failed_components'], true), 'public payload identifies incomplete components');

    UpdateStatusController::removeUpdate('plugins', 'sample-plugin');
    $afterPlugin = UpdateStatusController::getSnapshot();
    $check($afterPlugin['total'] === 2 && !isset($afterPlugin['components']['plugins']['updates']['sample-plugin']), 'successful plugin apply removes its global notification immediately');
    UpdateStatusController::removeUpdate('core', '', '99.0.0');
    $afterCore = UpdateStatusController::getSnapshot();
    $check($afterCore['total'] === 1 && $afterCore['components']['core']['has_update'] === false, 'successful Core apply clears its global notification immediately');

    $endpoint = (string)file_get_contents($root . '/dashboard/admin/check_updates_ajax.php');
    $javascript = (string)file_get_contents($root . '/public/static/dashboard/js/update-notif.js');
    $layout = (string)file_get_contents($root . '/dashboard/theme/adam/layout.php');
    $updatePage = (string)file_get_contents($root . '/dashboard/admin/update/index.php');
    $pluginPage = (string)file_get_contents($root . '/dashboard/admin/plugins/index.php');
    $themePage = (string)file_get_contents($root . '/dashboard/admin/themes/assign.php');
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
    $check(str_contains($coreActions, "UpdateStatusController::removeUpdate('core')"), 'successful reinstall synchronizes the global Core snapshot');
    $check(strpos($pluginPage, "(\$snapshot['state'] ?? 'ok') !== 'ok'") < strpos($pluginPage, 'elseif ($count > 0)')
        && strpos($themePage, "(\$snapshot['state'] ?? 'ok') !== 'ok'") < strpos($themePage, 'elseif ($count > 0)'), 'dedicated checks report partial failures before update counts');

    $pluginStore = (string)file_get_contents($root . '/app/controllers/PluginStoreController.php');
    $themeStore = (string)file_get_contents($root . '/app/controllers/ThemeStoreClient.php');
    $check(str_contains($pluginStore, "['actionable'] = false") && str_contains($pluginStore, "'actionable' => true")
        && str_contains($themeStore, "['actionable'] = false") && str_contains($themeStore, "'actionable' => true"), 'failed Store checks remain visible but cannot apply retained metadata');
    $check(str_contains($themeStore, "hash_file('sha256', \$tmpZip)")
        && str_contains($themeStore, "version_compare(\$manifest['version'], (string)\$update['new_version'], '!=')"), 'theme updates verify package checksum and advertised version before installation');
    $check(str_contains($themeStore, 'safeThemeTarget(') && str_contains($themeStore, 'isset($logicalTargets[$relative])')
        && str_contains($themeStore, 'zipEntryIsSymlink(') && str_contains($themeStore, 'is_link($themeCandidate)'), 'theme updates reject escaped, duplicate, and symbolic-link package targets');
    $check(str_contains($themeStore, 'Failed to create complete theme backup.')
        && str_contains($themeStore, 'restoreBackup(')
        && str_contains($themeStore, "preg_replace('/[^0-9A-Za-z._-]+/', '-', (string)\$update['current_version'])"), 'theme updates require a complete contained backup and retain rollback support through registration');

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
