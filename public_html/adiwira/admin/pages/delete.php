<?php
if (!defined('DASHBOARD_CONTEXT')) {
    define('DASHBOARD_CONTEXT', true);
}

require_once __DIR__ . '/../../bootstrap.php';

/**
 * Redirect helper — selalu kembali ke dashboard router utama
 */
function redirect_pages_index(string $msg = '', string $err = ''): void {
    $url = '/adiwira/index.php?page=admin/pages/index';
    $sep = (strpos($url, '?') === false) ? '?' : '&';
    if ($msg !== '') $url .= $sep . 'msg=' . urlencode($msg);
    if ($err !== '') $url .= ($msg === '' ? $sep : '&') . 'err=' . urlencode($err);
    header('Location: ' . $url);
    exit;
}

// hanya POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect_pages_index();
}

if (session_status() === PHP_SESSION_NONE) session_start();

// CSRF
$token = $_POST['csrf_token'] ?? '';
if (!csrf_check($token)) {
    redirect_pages_index('', 'CSRF token tidak valid.');
}

// ambil ID
$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    redirect_pages_index('', 'ID tidak valid.');
}

// pastikan login
if (empty($_SESSION['user_id'])) {
    redirect_pages_index('', 'Akses ditolak: belum login.');
}

// ambil user info
$uid = (int)$_SESSION['user_id'];
$role = $_SESSION['user_role'] ?? null;
if (!$role) {
    $stmt = $pdo->prepare("SELECT role FROM users WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $uid]);
    $role = $stmt->fetchColumn();
    $_SESSION['user_role'] = $role;
}

// ambil data page
$stmt = $pdo->prepare("SELECT id, created_by FROM posts WHERE id = :id AND type = 'page' AND is_deleted = 0 LIMIT 1");
$stmt->execute([':id' => $id]);
$page = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$page) {
    redirect_pages_index('', 'Halaman tidak ditemukan.');
}

// role check
// - admin dan editor boleh hapus semua
// - author hanya boleh hapus miliknya sendiri
if ($role === 'author' && (int)$page['created_by'] !== $uid) {
    redirect_pages_index('', 'Role kamu tidak memiliki akses untuk hapus halaman ini.');
}

// soft delete
try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        UPDATE posts
        SET is_deleted = 1, deleted_at = NOW(), updated_at = NOW()
        WHERE id = :id AND type = 'page' LIMIT 1
    ");
    $stmt->execute([':id' => $id]);

    $pdo->prepare("DELETE FROM post_categories WHERE post_id = :id")->execute([':id' => $id]);

    $pdo->commit();

    redirect_pages_index('Halaman berhasil dihapus 🚮');

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    redirect_pages_index('', 'Gagal menghapus halaman: ' . $e->getMessage());
}
