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

if (!update_operation_begin($token, (int)$uid, 'core', '', __('Starting...'))) {
    adiwira_json(['ok' => false, 'error' => __('Unable to start update operation.')], 409);
}
$updateLock = update_operation_acquire_lock();
if (!is_resource($updateLock)) {
    update_operation_fail($token, __('Update failed.'), __('Another update is already running.'));
    adiwira_json(['ok' => false, 'error' => __('Another update is already running.')], 409);
}
$lifecycleLocks = [];
try {
    $lifecycleLocks = theme_operation_acquire(theme_lifecycle_lock_keys());
} catch (Throwable $error) {
    update_operation_fail($token, __('Update failed.'), __('Unable to lock the CMS during update.'));
    update_operation_release_lock($updateLock);
    adiwira_json(['ok' => false, 'error' => __('Unable to lock the CMS during update.')], 409);
}

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

$result = ['success' => false, 'message' => __('Update failed.')];
try {
    // Load version info
    $versionFile = dirname(DASH_PATH) . '/version.json';
    $currentVersion = ['version' => '0.0.0'];
    if (is_file($versionFile)) {
        $currentVersion = array_merge($currentVersion, _cms_decode_json_array((string)file_get_contents($versionFile), 'version.json'));
    }

    if (!is_array($remote)) throw new RuntimeException(__('No update data in session. Run "Check for Updates" first.'));
    $hasUploadedPackage = $packageZip !== '' && is_file($packageZip);
    if (!$hasUploadedPackage && !UpdateStatusController::isUpdateActionable('core', '', (string)($remote['version'] ?? ''))) {
        throw new RuntimeException(__('No update data in session. Run "Check for Updates" first.'));
    }

    if ($hasUploadedPackage) {
        $result = _apply_cms_update_from_zip($packageZip, $remote, $currentVersion['version'] ?? '0.0.0', $token);
    } elseif ($baseUrl !== '') {
        $downloadUrl = $remote['download_url'] ?? $baseUrl;
        $result = _apply_cms_update($remote, $downloadUrl, $currentVersion['version'] ?? '0.0.0', $token);
    } else {
        throw new RuntimeException(__('No download URL or uploaded package found.'));
    }

    if (($result['success'] ?? false) === true) {
        try {
            UpdateStatusController::removeUpdate('core');
        } catch (Throwable $error) {
            error_log('[core-update-status] ' . $error->getMessage());
        }
    }
} catch (UpdateOperationCancelled $error) {
    update_operation_mark_cancelled($token, __('Update cancelled.'));
    $result = ['success' => false, 'cancelled' => true, 'message' => __('Update cancelled.')];
} catch (Throwable $error) {
    error_log('[core-update-apply] ' . $error->getMessage());
    $result = ['success' => false, 'message' => $error->getMessage()];
} finally {
    $cleanupPackage((string)$packageZip);
    if (($result['success'] ?? false) !== true) {
        $record = update_operation_read($token);
        if (($record['outcome'] ?? '') !== 'cancelled') {
            update_operation_fail($token, __('Update failed.'), (string)($result['message'] ?? __('Update failed.')));
        }
    }
    if ($lifecycleLocks !== []) theme_operation_release($lifecycleLocks);
    update_operation_release_lock($updateLock);
}

adiwira_json(($result['success'] ?? false) === true
    ? ['ok' => true, 'message' => $result['message']]
    : ['ok' => false, 'error' => $result['message'] ?? null, 'cancelled' => ($result['cancelled'] ?? false) === true]);
