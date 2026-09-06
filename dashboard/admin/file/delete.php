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

$id = (int)($_POST['id'] ?? 0);
$url = trim((string)($_POST['url'] ?? ''));
try {
    if ($id <= 0) {
        asset_lifecycle_delete_temporary_public($pdo, 'file', $url, $uid, (string)($_POST['cleanup_token'] ?? ''));
        adiwira_json(['ok' => true, 'operation' => 'purged', 'url' => $url]);
    }

    $result = asset_lifecycle_trash($pdo, 'file', [$id], $uid);
    $action = null;
    try {
        $action = adiwira_asset_issue_trash_undo($pdo, 'file', $uid, $result['items']);
    } catch (Throwable $undoError) {
        error_log('[file/delete] trash committed but Undo grant failed: ' . $undoError->getMessage());
    }
    adiwira_json([
        'ok' => true,
        'operation' => 'trashed',
        'id' => $id,
        'deleted_ids' => [$id],
        'deleted_count' => 1,
        'warning' => $result['warnings'][0]['message'] ?? null,
        'warnings' => $result['warnings'],
        'action' => $action,
    ]);
} catch (AssetLifecycleAccessDenied $e) {
    adiwira_json(['ok' => false, 'error' => $e->getMessage()], 403);
} catch (AssetLifecycleConflict $e) {
    adiwira_json(['ok' => false, 'error' => __($e->getMessage())], 409);
} catch (InvalidArgumentException $e) {
    adiwira_json(['ok' => false, 'error' => $e->getMessage()], 400);
} catch (Throwable $e) {
    error_log('[file/delete] ' . $e->getMessage());
    adiwira_json(['ok' => false, 'error' => __('Failed to move file to trash.')], 500);
}
