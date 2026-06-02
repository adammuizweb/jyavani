<?php
declare(strict_types=1);

// /adiwira/admin/themes/delete.php
if (!defined('DASHBOARD_CONTEXT')) {
    define('DASHBOARD_CONTEXT', true);
}

require_once __DIR__ . '/../_guard.php';
require_once __DIR__ . '/../_notify.php';

$defaultReturnTo = ADMIN_BASE_PATH . '/?page=admin/themes/index';
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

$role = strtolower(trim((string)($identity['role'] ?? 'guest')));
if ($role !== 'admin') {
    adiwira_redirect_with_flash($returnTo, 'error', 'Akses ditolak: menu Themes hanya untuk admin.');
}

$token = (string)($_POST['csrf_token'] ?? '');
if (!adiwira_csrf_validate($token)) {
    adiwira_redirect_with_flash($returnTo, 'error', 'CSRF token tidak valid.');
}

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    adiwira_redirect_with_flash($returnTo, 'error', 'ID tidak valid.');
}

$stmt = $pdo->prepare("SELECT id FROM posts WHERE id = :id AND type = 'theme' AND is_deleted = 0 LIMIT 1");
$stmt->execute([':id' => $id]);
if (!$stmt->fetchColumn()) {
    adiwira_redirect_with_flash($returnTo, 'error', 'Theme partial tidak ditemukan.');
}

try {
    $pdo->beginTransaction();

    $pdo->prepare("
        UPDATE posts
        SET is_deleted = 1, deleted_at = NOW(), updated_at = NOW()
        WHERE id = :id AND type = 'theme' AND is_deleted = 0
        LIMIT 1
    ")->execute([':id' => $id]);

    $pdo->commit();
    adiwira_redirect_with_flash($returnTo, 'success', 'Theme partial berhasil dipindahkan ke trash.');

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('themes/delete.php error: ' . $e->getMessage());
    adiwira_redirect_with_flash($returnTo, 'error', 'Gagal menghapus theme partial.');
}