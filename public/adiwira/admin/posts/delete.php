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
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();

    $_SESSION['flash'] = $_SESSION['flash'] ?? [];
    if ($msg !== '') $_SESSION['flash'][] = ['type' => 'success', 'text' => $msg];
    if ($err !== '') $_SESSION['flash'][] = ['type' => 'error', 'text' => $err];

    header('Location: /adiwira/index.php?page=admin/posts/index');
    exit;
}

// hanya izinkan POST
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    redirect_posts_index();
}

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

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
$uid = (int)($_SESSION['user_id'] ?? 0);
if ($uid <= 0) {
    redirect_posts_index('', 'Akses ditolak: belum login.');
}

// role (sync dari helper/DB)
$role = function_exists('current_user_role') ? (current_user_role($pdo) ?: null) : null;
$role = $role ?: ($_SESSION['user_role'] ?? 'guest');
$role = is_string($role) ? strtolower(trim($role)) : 'guest';
$_SESSION['user_role'] = $role;

if (!in_array($role, ['author','editor','admin'], true)) {
    redirect_posts_index('', 'Akses ditolak.');
}

// ambil data post (article)
$stmt = $pdo->prepare("SELECT id, created_by FROM posts WHERE id = :id AND type = 'article' AND is_deleted = 0 LIMIT 1");
$stmt->execute([':id' => $id]);
$post = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$post) {
    redirect_posts_index('', 'Artikel tidak ditemukan.');
}

// ROLE ENGINEERING:
// - admin: boleh hapus semua
// - editor/author: hanya boleh hapus miliknya sendiri
if (in_array($role, ['author','editor'], true) && (int)$post['created_by'] !== $uid) {
    redirect_posts_index('', 'Akses ditolak: kamu hanya boleh menghapus artikel milikmu sendiri.');
}

// soft delete
try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        UPDATE posts
        SET is_deleted = 1, deleted_at = NOW(), updated_at = NOW()
        WHERE id = :id AND type = 'article' AND is_deleted = 0
        LIMIT 1
    ");
    $stmt->execute([':id' => $id]);

    // konsisten dengan perilaku lama kamu
    $pdo->prepare("DELETE FROM post_categories WHERE post_id = :id")->execute([':id' => $id]);

    $pdo->commit();

    redirect_posts_index('Artikel berhasil dihapus 🚮');

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    redirect_posts_index('', 'Gagal menghapus artikel: ' . $e->getMessage());
}