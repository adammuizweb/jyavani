<?php
declare(strict_types=1);
ob_start();
require_once __DIR__ . '/../_guard.php';
adiwira_cosmetic_404_on_direct_open();
[$uid, $role] = adiwira_require_permission($pdo, 'core.themes.manage', true);
adiwira_require_site_owner($pdo, true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    adiwira_json(['ok' => false, 'error' => __('Method not allowed')], 405);
}

$csrf = (string)($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
if (!adiwira_csrf_validate($csrf)) {
    adiwira_json(['ok' => false, 'error' => __('CSRF invalid')], 419);
}

$folderName = (string)($_POST['theme'] ?? '');
$token = (string)($_POST['token'] ?? '');
if ($folderName === '' || strlen($folderName) > 128
    || preg_match('/\A[a-zA-Z0-9_-][a-zA-Z0-9._-]*\z/', $folderName) !== 1
    || in_array($folderName, ['.', '..'], true)) {
    adiwira_json(['ok' => false, 'error' => 'Invalid theme name'], 400);
}
if ($token === '' || !preg_match('/^[a-f0-9]{32}$/', $token)) {
    adiwira_json(['ok' => false, 'error' => 'Invalid progress token'], 400);
}
$decisionsJson = (string)($_POST['decisions'] ?? '{}');
$decisionsTrimmed = trim($decisionsJson);
if (strlen($decisionsJson) > 16384 || $decisionsTrimmed === ''
    || $decisionsTrimmed[0] !== '{' || !str_ends_with($decisionsTrimmed, '}')) {
    adiwira_json(['ok' => false, 'error' => __('Invalid update decisions.')], 400);
}
try {
    $decisions = json_decode($decisionsJson, true, 16, JSON_THROW_ON_ERROR);
} catch (JsonException $error) {
    $decisions = null;
}
if (!is_array($decisions)) {
    adiwira_json(['ok' => false, 'error' => __('Invalid update decisions.')], 400);
}

if (!update_operation_begin($token, (int)$uid, 'theme', $folderName, __('Starting...'))) {
    adiwira_json(['ok' => false, 'error' => __('Unable to start update operation.')], 409);
}
$updateLock = update_operation_acquire_lock();
if (!is_resource($updateLock)) {
    update_operation_fail($token, __('Update failed.'), __('Another update is already running.'));
    adiwira_json(['ok' => false, 'error' => __('Another update is already running.')], 409);
}

session_write_close();

require_once __DIR__ . '/../../../app/controllers/ThemeStoreClient.php';
require_once __DIR__ . '/../../../app/controllers/UpdateStatusController.php';
require_once __DIR__ . '/../../../cfg/helpers/theme_helper.php';

$result = ['success' => false, 'error' => __('Update failed.')];
try {
    $result = ThemeStoreClient::applyUpdate($pdo, $folderName, $token, $decisions);
    if (($result['success'] ?? false) === true) {
        try {
            UpdateStatusController::removeUpdate('themes', $folderName, (string)$result['new_version']);
        } catch (Throwable $error) {
            error_log('[theme-update-status] ' . $error->getMessage());
        }
    }
} catch (UpdateOperationCancelled $error) {
    update_operation_mark_cancelled($token, __('Update cancelled.'));
    $result = ['success' => false, 'cancelled' => true, 'error' => null];
} catch (Throwable $error) {
    error_log('[theme-update-apply] ' . $error->getMessage());
    $result = ['success' => false, 'error' => __('Theme update failed safely.')];
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
    'code' => $result['code'] ?? null,
    'issues' => is_array($result['issues'] ?? null) ? $result['issues'] : [],
    'new_version' => $result['new_version'] ?? null,
    'warning' => $result['warning'] ?? null,
    'restored' => isset($result['restored']) ? ($result['restored'] === true) : null,
    'metadata_restored' => isset($result['metadata_restored']) ? ($result['metadata_restored'] === true) : null,
    'cancelled' => ($result['cancelled'] ?? false) === true,
]);
