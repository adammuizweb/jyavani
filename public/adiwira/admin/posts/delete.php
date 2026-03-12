<?php
declare(strict_types=1);

// /adiwira/admin/posts/delete.php
if (!defined('DASHBOARD_CONTEXT')) {
    define('DASHBOARD_CONTEXT', true);
}

require_once __DIR__ . '/../_guard.php';
require_once __DIR__ . '/../_notify.php';

$defaultReturnTo = '/adiwira/index.php?page=admin/posts/index';
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

$stmt = $pdo->prepare("\n    SELECT id, created_by\n    FROM posts\n    WHERE id = :id\n      AND type = 'article'\n      AND is_deleted = 0\n    LIMIT 1\n");
$stmt->execute([':id' => $id]);
$post = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$post) {
    adiwira_redirect_with_flash($returnTo, 'error', 'Artikel tidak ditemukan.');
}

if (in_array($role, ['author', 'editor'], true) && (int)($post['created_by'] ?? 0) !== $uid) {
    adiwira_redirect_with_flash($returnTo, 'error', 'Akses ditolak: kamu hanya boleh menghapus artikel milikmu sendiri.');
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("\n        UPDATE posts\n        SET is_deleted = 1,\n            deleted_at = NOW(),\n            updated_at = NOW()\n        WHERE id = :id\n          AND type = 'article'\n          AND is_deleted = 0\n        LIMIT 1\n    ");
    $stmt->execute([':id' => $id]);

    $pdo->prepare("DELETE FROM post_categories WHERE post_id = :id")
        ->execute([':id' => $id]);

    $pdo->commit();

    adiwira_redirect_with_flash($returnTo, 'success', 'Artikel berhasil dipindahkan ke trash.');

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('posts/delete.php error: ' . $e->getMessage());
    adiwira_redirect_with_flash($returnTo, 'error', 'Gagal menghapus artikel.');
}