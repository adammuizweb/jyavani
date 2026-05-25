<?php
declare(strict_types=1);

// /adiwira/admin/menus/delete.php
if (!defined('DASHBOARD_CONTEXT')) {
    define('DASHBOARD_CONTEXT', true);
}

require_once __DIR__ . '/../_guard.php';
require_once __DIR__ . '/../_notify.php';

$defaultReturnTo = '/adiwira/index.php?page=admin/menus/index';

$returnTo = function_exists('adiwira_safe_return_to')
    ? adiwira_safe_return_to((string)($_POST['return_to'] ?? ''), $defaultReturnTo)
    : $defaultReturnTo;

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    adiwira_redirect_with_flash($returnTo, 'error', 'Method tidak diizinkan.');
}

$identity = adiwira_fetch_identity($pdo);
if (($identity['ok'] ?? false) !== true) {
    adiwira_redirect_with_flash($returnTo, 'error', 'Akses ditolak.');
}

$role = (string)($identity['role'] ?? 'guest');
if (!in_array($role, ['editor', 'admin'], true)) {
    adiwira_redirect_with_flash($returnTo, 'error', 'Role kamu tidak memiliki akses.');
}

$token = (string)($_POST['csrf_token'] ?? '');
if (!adiwira_csrf_validate($token)) {
    adiwira_redirect_with_flash($returnTo, 'error', 'CSRF token tidak valid.');
}

$menuId = (int)($_POST['menu_id'] ?? 0);
if ($menuId <= 0) {
    adiwira_redirect_with_flash($returnTo, 'error', 'ID tidak valid.');
}

$st = $pdo->prepare("SELECT is_default FROM menus WHERE id = :id");
$st->execute([':id' => $menuId]);
$menu = $st->fetch(PDO::FETCH_ASSOC);

if (!$menu) {
    adiwira_redirect_with_flash($returnTo, 'error', 'Menu tidak ditemukan.');
}

if (!empty($menu['is_default'])) {
    $count = (int)$pdo->query("SELECT COUNT(*) FROM menus")->fetchColumn();
    if ($count > 1) {
        adiwira_redirect_with_flash($returnTo, 'error', 'Tidak bisa menghapus menu default. Set menu lain sebagai default terlebih dahulu.');
    }
}

try {
    $pdo->beginTransaction();

    $pdo->prepare("DELETE FROM menu_items WHERE menu_id = :mid")->execute([':mid' => $menuId]);
    $pdo->prepare("DELETE FROM menus WHERE id = :id")->execute([':id' => $menuId]);

    $pdo->commit();
    adiwira_redirect_with_flash($returnTo, 'success', 'Menu berhasil dihapus.');

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('menus/delete.php error: ' . $e->getMessage());
    adiwira_redirect_with_flash($returnTo, 'error', 'Gagal menghapus menu.');
}
