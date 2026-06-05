<?php
declare(strict_types=1);

// /adiwira/admin/menus/save.php
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

$action = (string)($_POST['action'] ?? '');

if ($action === 'create') {
    $name = trim((string)($_POST['name'] ?? ''));
    $slug = trim((string)($_POST['slug'] ?? ''));

    if ($name === '' || $slug === '') {
        adiwira_redirect_with_flash($returnTo, 'error', __('Nama dan slug harus diisi.'));
    }

    $st = $pdo->prepare("SELECT COUNT(*) FROM menus WHERE slug = :slug");
    $st->execute([':slug' => $slug]);
    if ((int)$st->fetchColumn() > 0) {
        adiwira_redirect_with_flash($returnTo, 'error', 'Slug "' . htmlspecialchars($slug) . '" sudah digunakan.');
    }

    $st = $pdo->prepare("INSERT INTO menus (name, slug) VALUES (:name, :slug)");
    $st->execute([':name' => $name, ':slug' => $slug]);
    $menuId = (int)$pdo->lastInsertId();

    $returnTo = ADMIN_BASE_PATH . '/?page=admin/menus/index&menu_id=' . $menuId;
    adiwira_redirect_with_flash($returnTo, 'success', 'Menu "' . htmlspecialchars($name) . '" berhasil dibuat.');
}

if ($action === 'rename') {
    $menuId = (int)($_POST['menu_id'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));
    $slug = trim((string)($_POST['slug'] ?? ''));

    if ($menuId <= 0 || $name === '' || $slug === '') {
        adiwira_redirect_with_flash($returnTo, 'error', __('Data tidak lengkap.'));
    }

    $st = $pdo->prepare("SELECT COUNT(*) FROM menus WHERE slug = :slug AND id != :id");
    $st->execute([':slug' => $slug, ':id' => $menuId]);
    if ((int)$st->fetchColumn() > 0) {
        adiwira_redirect_with_flash($returnTo, 'error', 'Slug "' . htmlspecialchars($slug) . '" sudah digunakan.');
    }

    $st = $pdo->prepare("UPDATE menus SET name = :name, slug = :slug WHERE id = :id");
    $st->execute([':name' => $name, ':slug' => $slug, ':id' => $menuId]);

    adiwira_redirect_with_flash($returnTo, 'success', __('Menu berhasil diubah.'));
}

if ($action === 'set_default') {
    $menuId = (int)($_POST['menu_id'] ?? 0);
    if ($menuId <= 0) {
        adiwira_redirect_with_flash($returnTo, 'error', __('Menu tidak valid.'));
    }

    $pdo->exec("UPDATE menus SET is_default = 0");
    $st = $pdo->prepare("UPDATE menus SET is_default = 1 WHERE id = :id");
    $st->execute([':id' => $menuId]);

    adiwira_redirect_with_flash($returnTo, 'success', __('Menu default berhasil diubah.'));
}

adiwira_redirect_with_flash($returnTo, 'error', __('Aksi tidak dikenal.'));
