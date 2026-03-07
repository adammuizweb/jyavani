<?php
// /adiwira/admin/pages/delete.php

if (!defined('DASHBOARD_CONTEXT')) {
    define('DASHBOARD_CONTEXT', true);
}

require_once __DIR__ . '/../../bootstrap.php';

function redirect_pages_index(string $msg = '', string $err = ''): void {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();

    $_SESSION['flash'] = $_SESSION['flash'] ?? [];
    if ($msg !== '') $_SESSION['flash'][] = ['type' => 'success', 'text' => $msg];
    if ($err !== '') $_SESSION['flash'][] = ['type' => 'error', 'text' => $err];

    header('Location: /adiwira/index.php?page=admin/pages/index');
    exit;
}

// hanya POST
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    redirect_pages_index();
}

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

// CSRF
$token = $_POST['csrf_token'] ?? '';
if (!csrf_check($token)) {
    redirect_pages_index('', 'CSRF token tidak valid.');
}

// id
$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    redirect_pages_index('', 'ID tidak valid.');
}

// login
$uid = (int)($_SESSION['user_id'] ?? 0);
if ($uid <= 0) {
    redirect_pages_index('', 'Akses ditolak: belum login.');
}

// role
$role = function_exists('current_user_role') ? (current_user_role($pdo) ?: null) : null;
$role = $role ?: ($_SESSION['user_role'] ?? 'guest');
$role = is_string($role) ? strtolower(trim($role)) : 'guest';
$_SESSION['user_role'] = $role;

if (!in_array($role, ['author','editor','admin'], true)) {
    redirect_pages_index('', 'Akses ditolak.');
}

// fetch page
$stmt = $pdo->prepare("
    SELECT id, created_by
    FROM posts
    WHERE id = :id
      AND type = 'page'
      AND is_deleted = 0
    LIMIT 1
");
$stmt->execute([':id' => $id]);
$page = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$page) {
    redirect_pages_index('', 'Halaman tidak ditemukan.');
}

// ROLE ENGINEERING:
// - admin: boleh hapus semua
// - editor/author: hanya boleh hapus miliknya sendiri
if (in_array($role, ['author','editor'], true) && (int)$page['created_by'] !== $uid) {
    redirect_pages_index('', 'Akses ditolak: kamu hanya boleh menghapus halaman milikmu sendiri.');
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        UPDATE posts
        SET is_deleted = 1, deleted_at = NOW(), updated_at = NOW()
        WHERE id = :id
          AND type = 'page'
          AND is_deleted = 0
        LIMIT 1
    ");
    $stmt->execute([':id' => $id]);

    $pdo->prepare("DELETE FROM post_categories WHERE post_id = :id")->execute([':id' => $id]);

    $pdo->commit();

    redirect_pages_index('Halaman berhasil dihapus 🚮');

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    redirect_pages_index('', 'Gagal menghapus halaman: ' . $e->getMessage());
}