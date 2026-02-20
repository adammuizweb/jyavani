<?php
// /adiwira/admin/posts/delete.php

if (!defined('DASHBOARD_CONTEXT')) {
    define('DASHBOARD_CONTEXT', true);
}

require_once __DIR__ . '/../../bootstrap.php';

/**
 * Redirect helper — selalu kembali ke dashboard router utama
 */
function redirect_posts_index(string $msg = '', string $err = ''): void {
    $url = '/adiwira/index.php?page=admin/posts/index';
    $sep = (strpos($url, '?') === false) ? '?' : '&';
    if ($msg !== '') $url .= $sep . 'msg=' . urlencode($msg);
    if ($err !== '') $url .= ($msg === '' ? $sep : '&') . 'err=' . urlencode($err);
    header('Location: ' . $url);
    exit;
}

// hanya izinkan POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect_posts_index();
}

if (session_status() === PHP_SESSION_NONE) session_start();

// CSRF check
$token = $_POST['csrf_token'] ?? '';
if (!csrf_check($token)) {
    redirect_posts_index('', 'CSRF token tidak valid.');
}

// ambil ID
$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    redirect_posts_index('', 'ID tidak valid.');
}

// pastikan login
if (empty($_SESSION['user_id'])) {
    redirect_posts_index('', 'Akses ditolak: belum login.');
}

// ambil role user
$uid = (int)$_SESSION['user_id'];
$role = $_SESSION['user_role'] ?? null;
if (!$role) {
    $stmt = $pdo->prepare("SELECT role FROM users WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $uid]);
    $role = $stmt->fetchColumn();
    $_SESSION['user_role'] = $role;
}

// ambil data post (article)
$stmt = $pdo->prepare("SELECT id, created_by FROM posts WHERE id = :id AND type = 'article' AND is_deleted = 0 LIMIT 1");
$stmt->execute([':id' => $id]);
$post = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$post) {
    redirect_posts_index('', 'Artikel tidak ditemukan.');
}

// role check
// - admin dan editor boleh hapus semua
// - author hanya boleh hapus miliknya sendiri
if ($role === 'author' && (int)$post['created_by'] !== $uid) {
    redirect_posts_index('', 'Waduh 😅 Role kamu tidak memiliki akses untuk hapus artikel ini.');
}

// soft delete
try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        UPDATE posts
        SET is_deleted = 1, deleted_at = NOW(), updated_at = NOW()
        WHERE id = :id AND type = 'article' LIMIT 1
    ");
    $stmt->execute([':id' => $id]);

    $pdo->prepare("DELETE FROM post_categories WHERE post_id = :id")->execute([':id' => $id]);

    $pdo->commit();

    redirect_posts_index('Artikel berhasil dihapus 🚮');

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    redirect_posts_index('', 'Gagal menghapus artikel: ' . $e->getMessage());
}
