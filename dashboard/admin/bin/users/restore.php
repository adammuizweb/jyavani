<?php
declare(strict_types=1);

// /adiwira/admin/bin/users/restore.php
if (!defined('DASHBOARD_CONTEXT')) {
    define('DASHBOARD_CONTEXT', true);
}

require_once __DIR__ . '/../../_guard.php';
require_once __DIR__ . '/../../_notify.php';

$defaultReturnTo = ADMIN_BASE_PATH . '/?page=admin/bin/users/index';
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
    adiwira_redirect_with_flash($returnTo, 'error', 'Akses ditolak: hanya admin yang boleh restore user.');
}

$token = (string)($_POST['csrf_token'] ?? '');
if (!adiwira_csrf_validate($token)) {
    adiwira_redirect_with_flash($returnTo, 'error', 'CSRF token tidak valid.');
}

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    adiwira_redirect_with_flash($returnTo, 'error', 'ID user tidak valid.');
}

$stmt = $pdo->prepare("
    SELECT id
    FROM users
    WHERE id = :id
      AND is_deleted = 1
    LIMIT 1
");
$stmt->execute([':id' => $id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    adiwira_redirect_with_flash($returnTo, 'error', 'User tidak ditemukan di trash.');
}

try {
    $pdo->prepare("
        UPDATE users
        SET is_deleted = 0,
            updated_at = NOW()
        WHERE id = :id
          AND is_deleted = 1
        LIMIT 1
    ")->execute([':id' => $id]);

    adiwira_redirect_with_flash($returnTo, 'success', 'User berhasil direstore.');

} catch (Throwable $e) {
    error_log('bin/users/restore.php error: ' . $e->getMessage());
    adiwira_redirect_with_flash($returnTo, 'error', 'Gagal restore user.');
}