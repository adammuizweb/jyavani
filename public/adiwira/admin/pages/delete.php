<?php
declare(strict_types=1);

// /adiwira/admin/pages/delete.php

if (!defined('DASHBOARD_CONTEXT')) {
    define('DASHBOARD_CONTEXT', true);
}

require_once __DIR__ . '/../_guard.php';

if (!function_exists('redirect_pages_index')) {
    function redirect_pages_index(string $msg = '', string $err = ''): void
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

        header('Location: /adiwira/index.php?page=admin/pages/index');
        exit;
    }
}

// hanya POST
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    redirect_pages_index();
}

// identitas aktif
$identity = adiwira_fetch_identity($pdo);
if (($identity['ok'] ?? false) !== true) {
    redirect_pages_index('', 'Akses ditolak.');
}

$uid  = (int)($identity['uid'] ?? 0);
$role = (string)($identity['role'] ?? 'guest');

if (!in_array($role, ['author', 'editor', 'admin'], true)) {
    redirect_pages_index('', 'Akses ditolak.');
}

// CSRF
$token = (string)($_POST['csrf_token'] ?? '');
if (!adiwira_csrf_validate($token)) {
    redirect_pages_index('', 'CSRF token tidak valid.');
}

// id
$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    redirect_pages_index('', 'ID tidak valid.');
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

// admin: boleh hapus semua
// editor/author: hanya boleh hapus miliknya sendiri
if (in_array($role, ['author', 'editor'], true) && (int)($page['created_by'] ?? 0) !== $uid) {
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

    $pdo->prepare("DELETE FROM post_categories WHERE post_id = :id")
        ->execute([':id' => $id]);

    $pdo->commit();

    redirect_pages_index('Halaman berhasil dihapus 🚮');

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('pages/delete.php error: ' . $e->getMessage());
    redirect_pages_index('', 'Gagal menghapus halaman.');
}