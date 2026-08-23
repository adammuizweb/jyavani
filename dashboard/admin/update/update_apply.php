<?php
declare(strict_types=1);
ob_start();
require_once __DIR__ . '/../_guard.php';
adiwira_cosmetic_404_on_direct_open();
[$uid] = adiwira_require_permission($pdo, 'core.updates.manage', true);
adiwira_require_site_owner($pdo, true);

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

require_once __DIR__ . '/_update_helpers.php';
require_once __DIR__ . '/../../../app/controllers/UpdateStatusController.php';

// Copy and clear pending state, then release the lock for the long update.
ensure_session_started(false);
$remote = $_SESSION['cms_update_remote'] ?? null;
$packageZip = $_SESSION['cms_update_package'] ?? '';
$baseUrl = (string)($_SESSION['cms_update_remote_url'] ?? ($_SESSION['cms_update_base_url'] ?? ''));
unset($_SESSION['cms_update_remote'], $_SESSION['cms_update_base_url'], $_SESSION['cms_update_package'], $_SESSION['cms_update_remote_url']);
session_write_close();

$cleanupPackage = static function (string $path): void {
    $uploadRoot = realpath(dirname(DASH_PATH) . '/cfg/var/uploads');
    $realPath = $path !== '' ? realpath($path) : false;
    if ($uploadRoot !== false && $realPath !== false && str_starts_with($realPath, $uploadRoot . DIRECTORY_SEPARATOR)) {
        @unlink($realPath);
    }
};

// Load version info
$versionFile = dirname(DASH_PATH) . '/version.json';
$currentVersion = ['version' => '0.0.0'];
if (is_file($versionFile)) {
    try {
        $currentVersion = array_merge($currentVersion, _cms_decode_json_array((string)file_get_contents($versionFile), 'version.json'));
    } catch (Throwable $error) {
        $cleanupPackage((string)$packageZip);
        _cms_write_progress($token, 0, __('Invalid local version data.'), true, $error->getMessage());
        adiwira_json(['ok' => false, 'error' => $error->getMessage()]);
    }
}

if (!$remote) {
    $cleanupPackage((string)$packageZip);
    _cms_write_progress($token, 0, __('No update data.'), true, __('No update data in session. Run "Check for Updates" first.'));
    adiwira_json(['ok' => false, 'error' => __('No update data in session.')]);
}
$hasUploadedPackage = $packageZip !== '' && is_file($packageZip);
if (!$hasUploadedPackage && !UpdateStatusController::isUpdateActionable('core', '', (string)($remote['version'] ?? ''))) {
    _cms_write_progress($token, 0, __('No update data.'), true, __('No update data in session. Run "Check for Updates" first.'));
    adiwira_json(['ok' => false, 'error' => __('No update data in session. Run "Check for Updates" first.')]);
}

if ($hasUploadedPackage) {
    // Apply from uploaded zip
    $result = _apply_cms_update_from_zip($packageZip, $remote, $currentVersion['version'] ?? '0.0.0', $token);
    $cleanupPackage((string)$packageZip);
} elseif ($baseUrl !== '') {
    // Download and apply from remote
    $downloadUrl = $remote['download_url'] ?? $baseUrl;
    $result = _apply_cms_update($remote, $downloadUrl, $currentVersion['version'] ?? '0.0.0', $token);
} else {
    $cleanupPackage((string)$packageZip);
    _cms_write_progress($token, 0, __('No source URL.'), true, __('No download URL or uploaded package found.'));
    adiwira_json(['ok' => false, 'error' => __('No download URL or uploaded package found.')]);
}

if ($result['success']) {
    UpdateStatusController::removeUpdate('core');
    adiwira_json(['ok' => true, 'message' => $result['message']]);
} else {
    adiwira_json(['ok' => false, 'error' => $result['message']]);
}
