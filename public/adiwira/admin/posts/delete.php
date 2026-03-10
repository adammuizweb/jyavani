<?php
declare(strict_types=1);

// /adiwira/admin/posts/delete.php

if (!defined('DASHBOARD_CONTEXT')) {
    define('DASHBOARD_CONTEXT', true);
}

require_once __DIR__ . '/../_guard.php';

/**
 * Redirect helper — selalu kembali ke dashboard router utama
 */
if (!function_exists('redirect_posts_index')) {
    function redirect_posts_index(string $msg = '', string $err = ''): void
    {
        if (function_exists('ensure_session_started')) {
            ensure_session_started(true);
        } elseif (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $_SESSION['flash'] = $_SESSION['flash'] ?? [];

        if ($msg !== '') {
            $_SESSION['flash'][] = ['type' => 'success', 'text' => $msg];
        }

        if ($err !== '') {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => $err];
        }

        header('Location: /adiwira/index.php?page=admin/posts/index');
        exit;
    }
}

// hanya izinkan POST
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    redirect_posts_index();
}

// validasi identitas aktif dari guard baru
$identity = adiwira_fetch_identity($pdo);
if (($identity['ok'] ?? false) !== true) {
    redirect_posts_index('', 'Akses ditolak.');
}

$uid  = (int)($identity['uid'] ?? 0);
$role = (string)($identity['role'] ?? 'guest');

if (!in_array($role, ['author', 'editor', 'admin'], true)) {
    redirect_posts_index('', 'Akses ditolak.');
}

// CSRF
$token = (string)($_POST['csrf_token'] ?? '');
if (!adiwira_csrf_validate($token)) {
    redirect_posts_index('', 'CSRF token tidak valid.');
}

// ambil ID
$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    redirect_posts_index('', 'ID tidak valid.');
}

// ambil data post (article)
$stmt = $pdo->prepare("
    SELECT id, created_by
    FROM posts
    WHERE id = :id
      AND type = 'article'
      AND is_deleted = 0
    LIMIT 1
");
$stmt->execute([':id' => $id]);
$post = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$post) {
    redirect_posts_index('', 'Artikel tidak ditemukan.');
}

// ROLE ENGINEERING:
// - admin: boleh hapus semua
// - editor/author: hanya boleh hapus miliknya sendiri
if (in_array($role, ['author', 'editor'], true) && (int)($post['created_by'] ?? 0) !== $uid) {
    redirect_posts_index('', 'Akses ditolak: kamu hanya boleh menghapus artikel milikmu sendiri.');
}

// soft delete
try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        UPDATE posts
        SET is_deleted = 1,
            deleted_at = NOW(),
            updated_at = NOW()
        WHERE id = :id
          AND type = 'article'
          AND is_deleted = 0
        LIMIT 1
    ");
    $stmt->execute([':id' => $id]);

    // konsisten dengan perilaku lama
    $pdo->prepare("DELETE FROM post_categories WHERE post_id = :id")
        ->execute([':id' => $id]);

    $pdo->commit();

    redirect_posts_index('Artikel berhasil dihapus 🚮');

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('posts/delete.php error: ' . $e->getMessage());
    redirect_posts_index('', 'Gagal menghapus artikel.');
}