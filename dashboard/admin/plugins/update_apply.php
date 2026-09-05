<?php
declare(strict_types=1);
ob_start();
require_once __DIR__ . '/../_guard.php';
adiwira_cosmetic_404_on_direct_open();
[$uid] = adiwira_require_permission($pdo, 'core.plugins.manage', true);
adiwira_require_site_owner($pdo, true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    adiwira_json(['ok' => false, 'error' => __('Method not allowed')], 405);
}

$csrf = (string)($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
if (!adiwira_csrf_validate($csrf)) {
    adiwira_json(['ok' => false, 'error' => __('CSRF invalid')], 419);
}

$name = (string)($_POST['plugin'] ?? '');
$token = (string)($_POST['token'] ?? '');
if ($name === '' || !preg_match('/^[a-zA-Z0-9_-]+$/', $name)) {
    adiwira_json(['ok' => false, 'error' => 'Invalid plugin name'], 400);
}
if ($token === '' || !preg_match('/^[a-f0-9]{32}$/', $token)) {
    adiwira_json(['ok' => false, 'error' => 'Invalid progress token'], 400);
}

if (!update_operation_begin($token, (int)$uid, 'plugin', $name, __('Starting...'))) {
    adiwira_json(['ok' => false, 'error' => __('Unable to start update operation.')], 409);
}
$updateLock = update_operation_acquire_lock();
if (!is_resource($updateLock)) {
    update_operation_fail($token, __('Update failed.'), __('Another update is already running.'));
    adiwira_json(['ok' => false, 'error' => __('Another update is already running.')], 409);
}

// Release session lock before long-running update so polling works
session_write_close();

require_once __DIR__ . '/../../../app/controllers/PluginStoreController.php';
require_once __DIR__ . '/../../../app/controllers/UpdateStatusController.php';

$result = ['success' => false, 'error' => __('Update failed.')];
try {
    $result = PluginStoreController::applyUpdate($pdo, $name, $token);
    if (($result['success'] ?? false) === true) {
        try {
            UpdateStatusController::removeUpdate('plugins', $name, (string)$result['new_version']);
        } catch (Throwable $error) {
            error_log('[plugin-update-status] ' . $error->getMessage());
        }
    }
} catch (UpdateOperationCancelled $error) {
    update_operation_mark_cancelled($token, __('Update cancelled.'));
    $result = ['success' => false, 'cancelled' => true, 'error' => null];
} catch (Throwable $error) {
    error_log('[plugin-update-apply] ' . $error->getMessage());
    $result = ['success' => false, 'error' => __('Plugin update failed safely.')];
} finally {
    if (($result['success'] ?? false) !== true) {
        $record = update_operation_read($token);
        if (($record['outcome'] ?? '') !== 'cancelled') {
            update_operation_fail($token, __('Update failed.'), (string)($result['error'] ?? __('Update failed.')));
        }
    }
    update_operation_release_lock($updateLock);
}

adiwira_json([
    'ok' => $result['success'],
    'error' => $result['error'] ?? null,
    'new_version' => $result['new_version'] ?? null,
    'cancelled' => ($result['cancelled'] ?? false) === true,
]);
