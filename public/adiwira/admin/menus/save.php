<?php
declare(strict_types=1);

// /adiwira/admin/menus/save.php
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

$action = $_POST['action'] ?? '';

if ($action === 'create') {
    $name = trim((string)($_POST['name'] ?? ''));
    $slug = trim((string)($_POST['slug'] ?? ''));

    if ($name === '' || $slug === '') {
        adiwira_redirect_with_flash($pdo, $base . '/index.php?page=admin/menus/index', 'Nama dan slug harus diisi', 'error');
        exit;
    }

    // Check slug uniqueness
    $st = $pdo->prepare("SELECT COUNT(*) FROM menus WHERE slug = :slug");
    $st->execute([':slug' => $slug]);
    if ((int)$st->fetchColumn() > 0) {
        adiwira_redirect_with_flash($pdo, $base . '/index.php?page=admin/menus/index', 'Slug "' . htmlspecialchars($slug) . '" sudah digunakan', 'error');
        exit;
    }

    $st = $pdo->prepare("INSERT INTO menus (name, slug) VALUES (:name, :slug)");
    $st->execute([':name' => $name, ':slug' => $slug]);
    $menuId = (int)$pdo->lastInsertId();

    adiwira_redirect_with_flash($pdo, $base . '/index.php?page=admin/menus/index&menu_id=' . $menuId, 'Menu "' . htmlspecialchars($name) . '" berhasil dibuat', 'success');
    exit;
}

if ($action === 'rename') {
    $menuId = (int)($_POST['menu_id'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));
    $slug = trim((string)($_POST['slug'] ?? ''));

    if ($menuId <= 0 || $name === '' || $slug === '') {
        adiwira_redirect_with_flash($pdo, $base . '/index.php?page=admin/menus/index', 'Data tidak lengkap', 'error');
        exit;
    }

    // Check slug uniqueness (exclude current menu)
    $st = $pdo->prepare("SELECT COUNT(*) FROM menus WHERE slug = :slug AND id != :id");
    $st->execute([':slug' => $slug, ':id' => $menuId]);
    if ((int)$st->fetchColumn() > 0) {
        adiwira_redirect_with_flash($pdo, $base . '/index.php?page=admin/menus/index&menu_id=' . $menuId, 'Slug "' . htmlspecialchars($slug) . '" sudah digunakan', 'error');
        exit;
    }

    $st = $pdo->prepare("UPDATE menus SET name = :name, slug = :slug WHERE id = :id");
    $st->execute([':name' => $name, ':slug' => $slug, ':id' => $menuId]);

    adiwira_redirect_with_flash($pdo, $base . '/index.php?page=admin/menus/index&menu_id=' . $menuId, 'Menu berhasil diubah', 'success');
    exit;
}

if ($action === 'set_default') {
    $menuId = (int)($_POST['menu_id'] ?? 0);
    if ($menuId <= 0) {
        adiwira_redirect_with_flash($pdo, $base . '/index.php?page=admin/menus/index', 'Menu tidak valid', 'error');
        exit;
    }

    // Unset all defaults
    $pdo->exec("UPDATE menus SET is_default = 0");
    // Set new default
    $st = $pdo->prepare("UPDATE menus SET is_default = 1 WHERE id = :id");
    $st->execute([':id' => $menuId]);

    adiwira_redirect_with_flash($pdo, $base . '/index.php?page=admin/menus/index&menu_id=' . $menuId, 'Menu default berhasil diubah', 'success');
    exit;
}

adiwira_redirect_with_flash($pdo, $base . '/index.php?page=admin/menus/index', 'Aksi tidak dikenal', 'error');
