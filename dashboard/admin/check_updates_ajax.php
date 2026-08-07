<?php
declare(strict_types=1);
// AJAX — update notification data for CMS, plugins, themes
ob_start();
require_once __DIR__ . '/_guard.php';
adiwira_cosmetic_404_on_direct_open();
[$uid, $role] = adiwira_require_admin($pdo, true);

require_once __DIR__ . '/../../app/controllers/PluginStoreController.php';
require_once __DIR__ . '/../../app/controllers/ThemeStoreClient.php';

$forceRefresh = ($_GET['refresh'] ?? '') === '1';

// --- CMS update ---
$defaultUpdateUrl = 'https://jyavani.com/download/latest/';
$versionFile = realpath(__DIR__ . '/../../version.json');
$currentVersion = ($versionFile && is_file($versionFile)) ? (json_decode(file_get_contents($versionFile), true) ?: []) : [];
$localVer = $currentVersion['version'] ?? '0.0.0';

// Read cached CMS update from session (already started by bootstrap)
$cmsUpdate = null;
if (!empty($_SESSION['cms_update_cache'])) {
    $cmsUpdate = $_SESSION['cms_update_cache'];
}

$cacheTtl = 6 * 60 * 60;
$lastChecked = is_array($cmsUpdate) ? (int)($cmsUpdate['checked_at'] ?? 0) : 0;
if ($forceRefresh || !$cmsUpdate || (time() - $lastChecked) >= $cacheTtl) {
    $ctx = stream_context_create([
        'http' => ['timeout' => 10, 'user_agent' => 'JyavaniCMS-Update/' . $localVer],
    ]);
    $remoteJson = @file_get_contents($defaultUpdateUrl . '?format=json', false, $ctx);
    if ($remoteJson !== false) {
        $remote = json_decode($remoteJson, true);
        if (is_array($remote) && isset($remote['version'])) {
            $remoteVer = $remote['version'] ?? '0.0.0';
            $cmsUpdate = [
                'has_update' => version_compare($remoteVer, $localVer, '>'),
                'current'    => $localVer,
                'latest'     => $remoteVer,
                'checked_at' => time(),
            ];
            $advisory = $remote['critical_advisory'] ?? null;
            if (is_array($advisory) && ($advisory['severity'] ?? '') === 'critical'
                && is_string($advisory['id'] ?? null) && is_string($advisory['fixed_version'] ?? null)
                && version_compare($localVer, $advisory['fixed_version'], '<')) {
                $cmsUpdate['critical_advisory'] = [
                    'id' => $advisory['id'],
                    'title' => (string)($advisory['title'] ?? 'Critical security update'),
                    'message' => (string)($advisory['message'] ?? ''),
                    'fixed_version' => $advisory['fixed_version'],
                    'url' => (string)($advisory['url'] ?? ''),
                ];
            }
            $_SESSION['cms_update_cache'] = $cmsUpdate;
        }
    }
}

if (!$cmsUpdate) {
    $cmsUpdate = [
        'has_update' => false,
        'current'    => $localVer,
        'latest'     => $localVer,
    ];
}

session_write_close();

// --- Plugin updates ---
$pluginUpdates = $forceRefresh
    ? PluginStoreController::checkUpdates($pdo)
    : PluginStoreController::getCachedUpdates();

$plugins = [];
foreach ($pluginUpdates as $name => $info) {
    $plugins[] = [
        'name'    => $name,
        'current' => $info['current_version'] ?? '?',
        'latest'  => $info['new_version'] ?? '?',
    ];
}

// --- Theme updates ---
$themeUpdates = $forceRefresh
    ? ThemeStoreClient::checkUpdates($pdo)
    : ThemeStoreClient::getCachedUpdates();

$themes = [];
foreach ($themeUpdates as $folder => $info) {
    $themes[] = [
        'name'    => $folder,
        'current' => $info['current_version'] ?? '?',
        'latest'  => $info['new_version'] ?? '?',
    ];
}

$total = ($cmsUpdate['has_update'] ? 1 : 0) + count($plugins) + count($themes);

adiwira_json([
    'ok'      => true,
    'total'   => $total,
    'cms'     => $cmsUpdate,
    'critical_advisory' => $cmsUpdate['critical_advisory'] ?? null,
    'plugins' => $plugins,
    'themes'  => $themes,
]);
