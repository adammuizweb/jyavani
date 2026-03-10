<?php
// /adiwira/admin/users/toggle_lock.php
if (!defined('DASHBOARD_CONTEXT')) define('DASHBOARD_CONTEXT', true);
require_once __DIR__ . '/../../bootstrap.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['user_id'])) {
    http_response_code(403);
    echo '<p>Akses ditolak: belum login.</p>';
    exit;
}

$uid = (int)$_SESSION['user_id'];
$role = $_SESSION['user_role'] ?? null;

if (!$role) {
    $stmtRole = $pdo->prepare("SELECT role FROM users WHERE id = :id AND is_deleted = 0 LIMIT 1");
    $stmtRole->execute([':id' => $uid]);
    $role = $stmtRole->fetchColumn() ?: 'author';
    $_SESSION['user_role'] = $role;
}

$role = strtolower(trim((string)$role));
if ($role !== 'admin') {
    http_response_code(403);
    echo '<p>Akses ditolak: hanya admin.</p>';
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo '<p>Method tidak diijinkan.</p>';
    exit;
}

$base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
$redirectUrl = $base . '/index.php?page=admin/users/index';

function safe_redirect_users_toggle(string $url): void {
    if (!headers_sent()) {
        header('Location: ' . $url);
        exit;
    }

    echo '<!doctype html><html><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<script>window.location.href=' . json_encode($url) . ';</script>';
    echo '<noscript><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"></noscript>';
    echo '</head><body></body></html>';
    exit;
}

$token = $_POST['csrf_token'] ?? '';
if (!csrf_check($token)) {
    $_SESSION['_flash_error'] = 'Token CSRF tidak valid.';
    safe_redirect_users_toggle($redirectUrl);
}

$id = (int)($_POST['id'] ?? 0);
$mode = strtolower(trim((string)($_POST['mode'] ?? '')));

if ($id <= 0) {
    $_SESSION['_flash_error'] = 'ID user tidak valid.';
    safe_redirect_users_toggle($redirectUrl);
}

if ($id === $uid) {
    $_SESSION['_flash_error'] = 'Tidak dapat lock/unlock akun sendiri.';
    safe_redirect_users_toggle($redirectUrl);
}

if (!in_array($mode, ['lock', 'unlock'], true)) {
    $_SESSION['_flash_error'] = 'Mode tidak valid.';
    safe_redirect_users_toggle($redirectUrl);
}

$stmt = $pdo->prepare("
    SELECT id, name, email, is_locked
    FROM users
    WHERE id = :id
      AND is_deleted = 0
    LIMIT 1
");
$stmt->execute([':id' => $id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    $_SESSION['_flash_error'] = 'User tidak ditemukan.';
    safe_redirect_users_toggle($redirectUrl);
}

$newLock = ($mode === 'lock') ? 1 : 0;

$upd = $pdo->prepare("
    UPDATE users
    SET is_locked = :locked,
        updated_at = NOW()
    WHERE id = :id
    LIMIT 1
");

$ok = $upd->execute([
    ':locked' => $newLock,
    ':id' => $id,
]);

if ($ok) {
    $_SESSION['_flash_success'] = ($newLock === 1)
        ? 'User berhasil di-lock.'
        : 'User berhasil di-approve / di-unlock.';
    safe_redirect_users_toggle($redirectUrl);
}

$_SESSION['_flash_error'] = 'Gagal memperbarui status user.';
safe_redirect_users_toggle($redirectUrl);