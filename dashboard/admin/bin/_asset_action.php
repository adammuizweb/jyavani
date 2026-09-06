<?php
declare(strict_types=1);

if (!isset($assetResource, $assetAction) || !in_array($assetResource, ['media', 'file'], true)) {
    throw new LogicException('Asset Bin action is missing.');
}

if (!defined('DASHBOARD_CONTEXT')) define('DASHBOARD_CONTEXT', true);
require_once __DIR__ . '/../_guard.php';
require_once __DIR__ . '/../_notify.php';

$route = 'admin/bin/' . $assetResource . '/index';
$returnTo = adiwira_safe_return_to((string)($_POST['return_to'] ?? ''), ADMIN_BASE_PATH . '/?page=' . $route);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') adiwira_redirect_with_flash($returnTo, 'error', __('Method not allowed.'));
[$uid] = adiwira_require_login($pdo, true);
if (!adiwira_csrf_validate((string)($_POST['csrf_token'] ?? ''))) adiwira_redirect_with_flash($returnTo, 'error', __('Invalid CSRF token.'));
if (!in_array($assetAction, ['restore', 'purge'], true)) adiwira_redirect_with_flash($returnTo, 'error', __('Unknown bulk action.'));

$rawIds = !empty($assetBulk) ? (array)($_POST['ids'] ?? []) : [$_POST['id'] ?? null];
try {
    $ids = asset_lifecycle_ids($rawIds);
    $result = $assetAction === 'restore'
        ? asset_lifecycle_restore($pdo, $assetResource, $ids, $uid)
        : asset_lifecycle_purge($pdo, $assetResource, $ids, $uid);
    foreach ($result['warnings'] as $warning) {
        if (!empty($warning['message'])) adiwira_flash_push('warning', __((string)$warning['message']));
    }
    $label = $assetResource === 'media' ? __('media') : __('file');
    $message = $assetAction === 'restore'
        ? sprintf(__('Successfully restored %d %s item(s).'), (int)$result['count'], $label)
        : sprintf(__('Permanently deleted %d %s item(s).'), (int)$result['count'], $label);
    adiwira_redirect_with_flash($returnTo, 'success', $message);
} catch (AssetLifecycleAccessDenied $e) {
    adiwira_redirect_with_flash($returnTo, 'error', __('Access denied.'));
} catch (AssetLifecycleConflict|InvalidArgumentException $e) {
    adiwira_redirect_with_flash($returnTo, 'error', __($e->getMessage()));
} catch (Throwable $e) {
    error_log('[bin/' . $assetResource . '/' . $assetAction . '] ' . $e->getMessage());
    adiwira_redirect_with_flash($returnTo, 'error', $assetAction === 'restore' ? __('Failed to restore asset.') : __('Failed to permanently delete asset.'));
}
