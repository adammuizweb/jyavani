<?php
declare(strict_types=1);

if (!defined('DASHBOARD_CONTEXT')) {
    define('DASHBOARD_CONTEXT', true);
}

require_once __DIR__ . '/../_guard.php';
require_once __DIR__ . '/../_notify.php';

$defaultReturnTo = ADMIN_BASE_PATH . '/?page=admin/sidebar/index';

$returnTo = function_exists('adiwira_safe_return_to')
    ? adiwira_safe_return_to((string)($_POST['return_to'] ?? ''), $defaultReturnTo)
    : $defaultReturnTo;

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    adiwira_redirect_with_flash($returnTo, 'error', __('Method not allowed.'));
}

$identity = adiwira_fetch_identity($pdo);
if (($identity['ok'] ?? false) !== true) {
    adiwira_redirect_with_flash($returnTo, 'error', __('Access denied.'));
}

$role = (string)($identity['role'] ?? 'guest');
if (!in_array($role, ['editor', 'admin'], true)) {
    adiwira_redirect_with_flash($returnTo, 'error', __('Role kamu tidak memiliki akses.'));
}

$token = (string)($_POST['csrf_token'] ?? '');
if (!adiwira_csrf_validate($token)) {
    adiwira_redirect_with_flash($returnTo, 'error', __('Invalid CSRF token.'));
}

$zoneId = (int)($_POST['zone_id'] ?? 0);
if ($zoneId <= 0) {
    adiwira_redirect_with_flash($returnTo, 'error', __('Invalid ID.'));
}

$st = $pdo->prepare("SELECT is_primary FROM sidebar_zones WHERE id = :id");
$st->execute([':id' => $zoneId]);
$zone = $st->fetch(PDO::FETCH_ASSOC);

if (!$zone) {
    adiwira_redirect_with_flash($returnTo, 'error', __('Zone tidak ditemukan.'));
}

if (!empty($zone['is_primary'])) {
    $count = (int)$pdo->query("SELECT COUNT(*) FROM sidebar_zones")->fetchColumn();
    if ($count > 1) {
        adiwira_redirect_with_flash($returnTo, 'error', __('Cannot delete the primary zone. Set another zone as primary first.'));
    }
}

try {
    $pdo->beginTransaction();

    $pdo->prepare("DELETE FROM sidebar_zone_items WHERE zone_id = :zid")->execute([':zid' => $zoneId]);
    $pdo->prepare("DELETE FROM sidebar_zones WHERE id = :id")->execute([':id' => $zoneId]);

    $pdo->commit();
    adiwira_redirect_with_flash($returnTo, 'success', __('Zone berhasil dihapus.'));

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('sidebar/delete.php error: ' . $e->getMessage());
    adiwira_redirect_with_flash($returnTo, 'error', __('Failed to delete zone.'));
}
