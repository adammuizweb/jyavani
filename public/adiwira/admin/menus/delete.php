<?php
declare(strict_types=1);

// /adiwira/admin/menus/delete.php
require_once __DIR__ . '/../_deny.php';

if (!defined('DASHBOARD_CONTEXT') && !defined('ADAM_THEME')) {
    adiwira_admin_404();
}

require_once __DIR__ . '/../_guard.php';
require_once __DIR__ . '/../_notify.php';

[$uid, $role] = adiwira_require_role($pdo, ['editor', 'admin'], true);

$base = rtrim(str_replace('\\','/', dirname($_SERVER['SCRIPT_NAME'])), '/');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    adiwira_redirect_with_flash($pdo, $base . '/index.php?page=admin/menus/index', 'Method tidak diizinkan', 'error');
    exit;
}

$csrf = $_POST['csrf_token'] ?? '';
if (!adiwira_csrf_validate($csrf)) {
    adiwira_redirect_with_flash($pdo, $base . '/index.php?page=admin/menus/index', 'Token CSRF tidak valid', 'error');
    exit;
}

$menuId = (int)($_POST['menu_id'] ?? 0);
if ($menuId <= 0) {
    adiwira_redirect_with_flash($pdo, $base . '/index.php?page=admin/menus/index', 'Menu tidak valid', 'error');
    exit;
}

// Prevent deleting default menu if there are other menus
$st = $pdo->prepare("SELECT is_default FROM menus WHERE id = :id");
$st->execute([':id' => $menuId]);
$menu = $st->fetch(PDO::FETCH_ASSOC);

if (!$menu) {
    adiwira_redirect_with_flash($pdo, $base . '/index.php?page=admin/menus/index', 'Menu tidak ditemukan', 'error');
    exit;
}

if (!empty($menu['is_default'])) {
    $count = (int)$pdo->query("SELECT COUNT(*) FROM menus")->fetchColumn();
    if ($count > 1) {
        adiwira_redirect_with_flash($pdo, $base . '/index.php?page=admin/menus/index', 'Tidak bisa menghapus menu default. Set menu lain sebagai default terlebih dahulu.', 'error');
        exit;
    }
}

// Delete menu items first (CASCADE should handle this, but be safe)
$st = $pdo->prepare("DELETE FROM menu_items WHERE menu_id = :mid");
$st->execute([':mid' => $menuId]);

// Delete the menu itself
$st = $pdo->prepare("DELETE FROM menus WHERE id = :id");
$st->execute([':id' => $menuId]);

adiwira_redirect_with_flash($pdo, $base . '/index.php?page=admin/menus/index', 'Menu berhasil dihapus', 'success');
