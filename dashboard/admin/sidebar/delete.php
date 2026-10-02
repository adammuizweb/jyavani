<?php
declare(strict_types=1);

if (!defined('DASHBOARD_CONTEXT')) {
    define('DASHBOARD_CONTEXT', true);
}

require_once __DIR__ . '/../_guard.php';
require_once __DIR__ . '/../_notify.php';
require_once __DIR__ . '/../../../cfg/helpers/sidebar_helper.php';

$defaultReturnTo = ADMIN_BASE_PATH . '/?page=admin/sidebar/index';

$returnTo = function_exists('adiwira_safe_return_to')
    ? adiwira_safe_return_to((string)($_POST['return_to'] ?? ''), $defaultReturnTo)
    : $defaultReturnTo;

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    adiwira_redirect_with_flash($returnTo, 'error', __('Method not allowed.'));
}

[$uid] = adiwira_require_permission($pdo, 'core.sidebar.manage', false);

$token = (string)($_POST['csrf_token'] ?? '');
if (!adiwira_csrf_validate($token)) {
    adiwira_redirect_with_flash($returnTo, 'error', __('Invalid CSRF token.'));
}

$zoneId = (int)($_POST['zone_id'] ?? 0);
if ($zoneId <= 0) {
    adiwira_redirect_with_flash($returnTo, 'error', __('Invalid ID.'));
}

try {
    sidebar_delete_zone($pdo, $zoneId, $uid);
    adiwira_redirect_with_flash($returnTo, 'success', __('Zone berhasil dihapus.'));

} catch (Throwable $e) {
    error_log('sidebar/delete.php error: ' . $e->getMessage());
    adiwira_redirect_with_flash($returnTo, 'error', __('Failed to delete zone.'));
}
