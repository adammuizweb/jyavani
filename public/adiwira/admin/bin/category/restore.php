<?php
declare(strict_types=1);

// /adiwira/admin/bin/category/restore.php
if (!defined('DASHBOARD_CONTEXT')) {
    define('DASHBOARD_CONTEXT', true);
}

require_once __DIR__ . '/../../_guard.php';
require_once __DIR__ . '/../../_notify.php';

$defaultReturnTo = '/adiwira/index.php?page=admin/bin/category/index';
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
    SELECT id, parent_id, created_by
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
    adiwira_redirect_with_flash($returnTo, 'error', 'Role kamu tidak punya akses restore kategori ini.');
}

// kalau parent masih deleted / parent hilang → null-kan agar tidak hilang di tree
$pid = (int)($cat['parent_id'] ?? 0);
$parentSql = null;

if ($pid > 0) {
    $p = $pdo->prepare("SELECT id, is_deleted FROM categories WHERE id = :pid LIMIT 1");
    $p->execute([':pid' => $pid]);
    $parent = $p->fetch(PDO::FETCH_ASSOC);

    if ($parent && (int)($parent['is_deleted'] ?? 0) === 0) {
        $parentSql = $pid;
    }
}

try {
    $stmt = $pdo->prepare("
        UPDATE categories
        SET is_deleted = 0,
            deleted_at = NULL,
            parent_id = :pid,
            updated_at = NOW()
        WHERE id = :id
          AND is_deleted = 1
        LIMIT 1
    ");
    $stmt->execute([
        ':id'  => $id,
        ':pid' => $parentSql,
    ]);

    adiwira_redirect_with_flash($returnTo, 'success', 'Kategori berhasil direstore.');

} catch (Throwable $e) {
    error_log('bin/category/restore.php error: ' . $e->getMessage());
    adiwira_redirect_with_flash($returnTo, 'error', 'Gagal restore kategori.');
}