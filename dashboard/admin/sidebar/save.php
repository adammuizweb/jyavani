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

$action = (string)($_POST['action'] ?? '');

if ($action === 'create') {
    $name = trim((string)($_POST['name'] ?? ''));
    $slug = trim((string)($_POST['slug'] ?? ''));
    $desc = trim((string)($_POST['description'] ?? ''));

    if ($name === '' || $slug === '') {
        adiwira_redirect_with_flash($returnTo, 'error', __('Nama dan slug harus diisi.'));
    }

    $st = $pdo->prepare("SELECT COUNT(*) FROM sidebar_zones WHERE slug = :slug");
    $st->execute([':slug' => $slug]);
    if ((int)$st->fetchColumn() > 0) {
        adiwira_redirect_with_flash($returnTo, 'error', 'Slug "' . htmlspecialchars($slug) . '" sudah digunakan.');
    }

    $st = $pdo->prepare("INSERT INTO sidebar_zones (name, slug, description) VALUES (:name, :slug, :desc)");
    $st->execute([':name' => $name, ':slug' => $slug, ':desc' => $desc]);
    $zoneId = (int)$pdo->lastInsertId();

    $returnTo = ADMIN_BASE_PATH . '/?page=admin/sidebar/index&zone_id=' . $zoneId;
    adiwira_redirect_with_flash($returnTo, 'success', __('Zone') . ' "' . htmlspecialchars($name) . '" ' . __('created successfully.'));
}

if ($action === 'rename') {
    $zoneId = (int)($_POST['zone_id'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));
    $slug = trim((string)($_POST['slug'] ?? ''));
    $desc = trim((string)($_POST['description'] ?? ''));

    if ($zoneId <= 0 || $name === '' || $slug === '') {
        adiwira_redirect_with_flash($returnTo, 'error', __('Data tidak lengkap.'));
    }

    $st = $pdo->prepare("SELECT COUNT(*) FROM sidebar_zones WHERE slug = :slug AND id != :id");
    $st->execute([':slug' => $slug, ':id' => $zoneId]);
    if ((int)$st->fetchColumn() > 0) {
        adiwira_redirect_with_flash($returnTo, 'error', 'Slug "' . htmlspecialchars($slug) . '" sudah digunakan.');
    }

    $st = $pdo->prepare("UPDATE sidebar_zones SET name = :name, slug = :slug, description = :desc WHERE id = :id");
    $st->execute([':name' => $name, ':slug' => $slug, ':desc' => $desc, ':id' => $zoneId]);

    adiwira_redirect_with_flash($returnTo, 'success', __('Zone berhasil diubah.'));
}

if ($action === 'set_primary') {
    $zoneId = (int)($_POST['zone_id'] ?? 0);
    if ($zoneId <= 0) {
        adiwira_redirect_with_flash($returnTo, 'error', __('Zone tidak valid.'));
    }

    $pdo->exec("UPDATE sidebar_zones SET is_primary = 0");
    $st = $pdo->prepare("UPDATE sidebar_zones SET is_primary = 1 WHERE id = :id");
    $st->execute([':id' => $zoneId]);

    adiwira_redirect_with_flash($returnTo, 'success', __('Zone primary berhasil diubah.'));
}

adiwira_redirect_with_flash($returnTo, 'error', __('Aksi tidak dikenal.'));
