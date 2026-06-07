<?php
declare(strict_types=1);
ob_start();
require_once __DIR__ . '/../_guard.php';
adiwira_cosmetic_404_on_direct_open();
[$uid, $role] = adiwira_require_admin($pdo, true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    adiwira_json(['ok' => false, 'error' => __('Method not allowed')], 405);
}

$csrf = (string)($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
if (!adiwira_csrf_validate($csrf)) {
    adiwira_json(['ok' => false, 'error' => __('CSRF invalid')], 419);
}

$token = (string)($_POST['token'] ?? '');
if ($token === '' || !preg_match('/^[a-f0-9]{32}$/', $token)) {
    adiwira_json(['ok' => false, 'error' => 'Invalid progress token'], 400);
}

// Release session lock before long-running update so polling works
session_write_close();

require_once __DIR__ . '/_update_helpers.php';

// Load version info
$versionFile = dirname(DASH_PATH) . '/version.json';
$currentVersion = ['version' => '0.0.0'];
if (is_file($versionFile)) {
    $v = json_decode(file_get_contents($versionFile), true);
    if (is_array($v)) $currentVersion = array_merge($currentVersion, $v);
}

// Read session data
ensure_session_started(false);
$remote = $_SESSION['cms_update_remote'] ?? null;
$packageZip = $_SESSION['cms_update_package'] ?? '';
$baseUrl = $_SESSION['cms_update_remote_url'] ?: ($_SESSION['cms_update_base_url'] ?? '');

if (!$remote) {
    _cms_write_progress($token, 0, __('No update data.'), true, __('No update data in session. Run "Check for Updates" first.'));
    adiwira_json(['ok' => false, 'error' => __('No update data in session.')]);
}

if ($packageZip && is_file($packageZip)) {
    // Apply from uploaded zip
    $result = _apply_cms_update_from_zip($packageZip, $remote, $currentVersion['version'] ?? '0.0.0', $token);
    @unlink($packageZip);
} elseif ($baseUrl !== '') {
    // Download and apply from remote
    $downloadUrl = $remote['download_url'] ?? $baseUrl;
    $result = _apply_cms_update($remote, $downloadUrl, $currentVersion['version'] ?? '0.0.0', $token);
} else {
    _cms_write_progress($token, 0, __('No source URL.'), true, __('No download URL or uploaded package found.'));
    adiwira_json(['ok' => false, 'error' => __('No download URL or uploaded package found.')]);
}

// Clean up session
unset($_SESSION['cms_update_remote'], $_SESSION['cms_update_base_url'], $_SESSION['cms_update_package'], $_SESSION['cms_update_remote_url']);

if ($result['success']) {
    adiwira_json(['ok' => true, 'message' => $result['message']]);
} else {
    adiwira_json(['ok' => false, 'error' => $result['message']]);
}
