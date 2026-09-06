<?php
declare(strict_types=1);

if (!defined('DASHBOARD_CONTEXT')) define('DASHBOARD_CONTEXT', true);
require_once __DIR__ . '/../_guard.php';
require_once __DIR__ . '/../_notify.php';
require_once __DIR__ . '/../bin/_undo.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    adiwira_json(['ok' => false, 'error' => __('Method not allowed.')], 405);
}
$identity = adiwira_fetch_identity($pdo);
if (($identity['ok'] ?? false) !== true) {
    adiwira_json(['ok' => false, 'error' => __('Access denied.')], 403);
}
$uid = (int)($identity['uid'] ?? 0);
if (!adiwira_csrf_validate((string)($_POST['csrf_token'] ?? ''))) {
    adiwira_json(['ok' => false, 'error' => __('Invalid CSRF token.')], 419);
}

$resource = (string)($_POST['resource'] ?? '');
$undoToken = (string)($_POST['undo_token'] ?? '');
if (!in_array($resource, ['media', 'file'], true)) {
    adiwira_json(['ok' => false, 'error' => __('This action can no longer be undone.')], 409);
}
$undo = adiwira_undo_get($undoToken, 'asset.trash.' . $resource, $uid);
$items = is_array($undo) && ($undo['state']['resource'] ?? null) === $resource
    ? adiwira_bin_parse_undo_items($undo['state']['items'] ?? null)
    : [];
if ($items === []) {
    adiwira_undo_consume($undoToken);
    adiwira_json(['ok' => false, 'error' => __('This action can no longer be undone.')], 409);
}

try {
    $result = asset_lifecycle_restore($pdo, $resource, array_keys($items), $uid, $items);
    try {
        adiwira_undo_consume($undoToken);
        adiwira_flash_push('success', sprintf(__('%d asset(s) restored.'), (int)$result['count']));
    } catch (Throwable $notifyError) {
        error_log('[assets/undo_trash] restore committed but notification failed: ' . $notifyError->getMessage());
    }
    adiwira_json(['ok' => true, 'reload' => true]);
} catch (AssetLifecycleConflict|AssetLifecycleAccessDenied|InvalidArgumentException $e) {
    error_log('[assets/undo_trash] ' . $e->getMessage());
    adiwira_undo_consume($undoToken);
    adiwira_json(['ok' => false, 'error' => __('This action can no longer be undone.')], 409);
} catch (Throwable $e) {
    error_log('[assets/undo_trash] ' . $e->getMessage());
    adiwira_json(['ok' => false, 'error' => __('Failed to restore asset.')], 500);
}
