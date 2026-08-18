<?php
declare(strict_types=1);

// /adiwira/admin/menus/delete.php
if (!defined('DASHBOARD_CONTEXT')) {
    define('DASHBOARD_CONTEXT', true);
}

require_once __DIR__ . '/../_guard.php';
require_once __DIR__ . '/../_notify.php';

$defaultReturnTo = ADMIN_BASE_PATH . '/?page=admin/menus/index';

$returnTo = function_exists('adiwira_safe_return_to')
    ? adiwira_safe_return_to((string)($_POST['return_to'] ?? ''), $defaultReturnTo)
    : $defaultReturnTo;

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    adiwira_redirect_with_flash($returnTo, 'error', __('Method not allowed.'));
}

adiwira_require_permission($pdo, 'core.menus.manage', false);

$token = (string)($_POST['csrf_token'] ?? '');
if (!adiwira_csrf_validate($token)) {
    adiwira_redirect_with_flash($returnTo, 'error', __('Invalid CSRF token.'));
}

$menuId = (int)($_POST['menu_id'] ?? 0);
if ($menuId <= 0) {
    adiwira_redirect_with_flash($returnTo, 'error', __('Invalid ID.'));
}

$st = $pdo->prepare("SELECT is_default FROM menus WHERE id = :id");
$st->execute([':id' => $menuId]);
$menu = $st->fetch(PDO::FETCH_ASSOC);

if (!$menu) {
    adiwira_redirect_with_flash($returnTo, 'error', __('Menu not found.'));
}

if (!empty($menu['is_default'])) {
    $count = (int)$pdo->query("SELECT COUNT(*) FROM menus")->fetchColumn();
    if ($count > 1) {
        adiwira_redirect_with_flash($returnTo, 'error', __('Cannot delete the default menu. Set another menu as default first.'));
    }
}

try {
    $pdo->beginTransaction();

    $pdo->prepare("DELETE FROM menu_items WHERE menu_id = :mid")->execute([':mid' => $menuId]);
    $pdo->prepare("DELETE FROM menus WHERE id = :id")->execute([':id' => $menuId]);

    $pdo->commit();
    adiwira_redirect_with_flash($returnTo, 'success', __('Menu deleted successfully.'));

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('menus/delete.php error: ' . $e->getMessage());
    adiwira_redirect_with_flash($returnTo, 'error', __('Failed to delete menu.'));
}
