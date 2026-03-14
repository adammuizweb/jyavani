<?php
declare(strict_types=1);

// /adiwira/admin/bin/photo/restore.php
if (!defined('DASHBOARD_CONTEXT')) {
    define('DASHBOARD_CONTEXT', true);
}

require_once __DIR__ . '/../../_guard.php';
require_once __DIR__ . '/../../_notify.php';

$defaultReturnTo = '/adiwira/index.php?page=admin/bin/photo/index';
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
if ($role !== 'admin') {
    adiwira_redirect_with_flash($returnTo, 'error', 'Akses ditolak: hanya admin yang boleh restore photo post.');
}

$token = (string)($_POST['csrf_token'] ?? '');
if (!adiwira_csrf_validate($token)) {
    adiwira_redirect_with_flash($returnTo, 'error', 'CSRF token tidak valid.');
}

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    adiwira_redirect_with_flash($returnTo, 'error', 'ID photo post tidak valid.');
}

$stmt = $pdo->prepare("
    SELECT id
    FROM posts
    WHERE id = :id
      AND type = 'photo'
      AND is_deleted = 1
    LIMIT 1
");
$stmt->execute([':id' => $id]);
$post = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$post) {
    adiwira_redirect_with_flash($returnTo, 'error', 'Photo post tidak ditemukan di trash.');
}

try {
    $pdo->prepare("
        UPDATE posts
        SET is_deleted = 0,
            deleted_at = NULL,
            updated_at = NOW()
        WHERE id = :id
          AND type = 'photo'
          AND is_deleted = 1
        LIMIT 1
    ")->execute([':id' => $id]);

    adiwira_redirect_with_flash($returnTo, 'success', 'Photo post berhasil direstore.');

} catch (Throwable $e) {
    error_log('bin/photo/restore.php error: ' . $e->getMessage());
    adiwira_redirect_with_flash($returnTo, 'error', 'Gagal restore photo post.');
}