<?php
declare(strict_types=1);

// /adiwira/admin/bin/category/delete_permanent.php
if (!defined('DASHBOARD_CONTEXT')) {
    define('DASHBOARD_CONTEXT', true);
}

require_once __DIR__ . '/../../_guard.php';
require_once __DIR__ . '/../../_notify.php';

$defaultReturnTo = ADMIN_BASE_PATH . '/?page=admin/bin/category/index';
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

$uid  = (int)($identity['uid'] ?? 0);
$role = (string)($identity['role'] ?? 'guest');

if (!in_array($role, ['author', 'editor', 'admin'], true)) {
    adiwira_redirect_with_flash($returnTo, 'error', 'Akses ditolak.');
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
    SELECT id, created_by
    FROM categories
    WHERE id = :id
      AND is_deleted = 1
    LIMIT 1
");
$stmt->execute([':id' => $id]);
$cat = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$cat) {
    adiwira_redirect_with_flash($returnTo, 'error', 'Kategori tidak ditemukan di trash.');
}

if ($role === 'author' && (int)($cat['created_by'] ?? 0) !== $uid) {
    adiwira_redirect_with_flash($returnTo, 'error', 'Role kamu tidak punya akses hapus permanen kategori ini.');
}

// cegah delete permanen jika masih punya child (apapun statusnya)
$child = $pdo->prepare("SELECT COUNT(*) FROM categories WHERE parent_id = :id");
$child->execute([':id' => $id]);
if ((int)$child->fetchColumn() > 0) {
    adiwira_redirect_with_flash($returnTo, 'error', 'Tidak bisa hapus permanen: kategori masih punya subkategori. Hapus/pindahkan subkategori dulu.');
}

try {
    $pdo->beginTransaction();

    $pdo->prepare("DELETE FROM post_categories WHERE category_id = :id")
        ->execute([':id' => $id]);

    $pdo->prepare("
        DELETE FROM categories
        WHERE id = :id
          AND is_deleted = 1
        LIMIT 1
    ")->execute([':id' => $id]);

    $pdo->commit();

    adiwira_redirect_with_flash($returnTo, 'success', 'Kategori berhasil dihapus permanen.');

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('bin/category/delete_permanent.php error: ' . $e->getMessage());
    adiwira_redirect_with_flash($returnTo, 'error', 'Gagal hapus permanen kategori.');
}