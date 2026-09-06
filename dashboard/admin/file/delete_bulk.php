<?php
declare(strict_types=1);

ob_start();
require_once __DIR__ . '/../_guard.php';
require_once __DIR__ . '/../_notify.php';
require_once __DIR__ . '/../bin/_undo.php';

adiwira_cosmetic_404_on_direct_open();
$identity = adiwira_fetch_identity($pdo);
if (($identity['ok'] ?? false) !== true) adiwira_json(['ok' => false, 'error' => __('Access denied.')], 403);
$uid = (int)$identity['uid'];
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') adiwira_json(['ok' => false, 'error' => __('Method not allowed.')], 405);
if (!adiwira_csrf_validate((string)($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')))) {
    adiwira_json(['ok' => false, 'error' => __('Invalid CSRF token.')], 419);
}

try {
    $result = asset_lifecycle_trash($pdo, 'file', (array)($_POST['ids'] ?? []), $uid);
    $action = null;
    try {
        $action = adiwira_asset_issue_trash_undo($pdo, 'file', $uid, $result['items']);
    } catch (Throwable $undoError) {
        error_log('[file/delete_bulk] trash committed but Undo grant failed: ' . $undoError->getMessage());
    }
    adiwira_json([
        'ok' => true,
        'operation' => 'trashed',
        'deleted_count' => $result['count'],
        'deleted_ids' => $result['ids'],
        'warnings' => $result['warnings'],
        'action' => $action,
    ]);
} catch (AssetLifecycleAccessDenied $e) {
    adiwira_json(['ok' => false, 'error' => __('Access denied.')], 403);
} catch (AssetLifecycleConflict $e) {
    adiwira_json(['ok' => false, 'error' => __($e->getMessage())], 409);
} catch (InvalidArgumentException $e) {
    adiwira_json(['ok' => false, 'error' => __($e->getMessage())], 400);
} catch (Throwable $e) {
    error_log('[file/delete_bulk] ' . $e->getMessage());
    adiwira_json(['ok' => false, 'error' => __('Failed to move file to trash.')], 500);
}
