<?php
declare(strict_types=1);

// /adiwira/admin/categories/delete.php
if (!defined('DASHBOARD_CONTEXT')) {
    define('DASHBOARD_CONTEXT', true);
}

require_once __DIR__ . '/../_guard.php';
require_once __DIR__ . '/../_notify.php';

$defaultReturnTo = ADMIN_BASE_PATH . '/?page=admin/categories/index';
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
    adiwira_redirect_with_flash($returnTo, 'error', 'Role kamu tidak memiliki akses untuk hapus kategori ini.');
}

$token = (string)($_POST['csrf_token'] ?? '');
if (!adiwira_csrf_validate($token)) {
    adiwira_redirect_with_flash($returnTo, 'error', 'CSRF token tidak valid.');
}

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    adiwira_redirect_with_flash($returnTo, 'error', 'ID tidak valid.');
}

$stmt = $pdo->prepare("
    SELECT id
    FROM categories
    WHERE id = :id
      AND is_deleted = 0
    LIMIT 1
");
$stmt->execute([':id' => $id]);
if (!$stmt->fetchColumn()) {
    adiwira_redirect_with_flash($returnTo, 'error', 'Kategori tidak ditemukan.');
}

$child = $pdo->prepare("
    SELECT COUNT(*)
    FROM categories
    WHERE parent_id = :id
      AND is_deleted = 0
");
$child->execute([':id' => $id]);
if ((int)$child->fetchColumn() > 0) {
    adiwira_redirect_with_flash($returnTo, 'error', 'Kategori masih punya subkategori aktif. Pindahkan/hapus subkategori dulu.');
}

try {
    $pdo->beginTransaction();

    $pdo->prepare("
        UPDATE categories
        SET is_deleted = 1,
            deleted_at = NOW(),
            updated_at = NOW()
        WHERE id = :id
          AND is_deleted = 0
        LIMIT 1
    ")->execute([':id' => $id]);

    $pdo->commit();
    adiwira_redirect_with_flash($returnTo, 'success', 'Kategori berhasil dipindahkan ke trash.');

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('categories/delete.php error: ' . $e->getMessage());
    adiwira_redirect_with_flash($returnTo, 'error', 'Gagal menghapus kategori.');
}