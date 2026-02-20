<?php
// /adiwira/admin/users/delete.php
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

// only POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo '<p>Method tidak diijinkan.</p>';
    exit;
}

$id = (int)($_POST['id'] ?? 0);
$token = $_POST['csrf_token'] ?? '';

$base = rtrim(str_replace('\\','/', dirname($_SERVER['SCRIPT_NAME'])), '/');
$redirectUrl = $base . '/index.php?page=admin/users/index';

function safe_redirect(string $url) {
    if (!headers_sent()) {
        header('Location: ' . $url);
        exit;
    }
    echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<script>window.location.href=' . json_encode($url) . ';</script>';
    echo '<noscript><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($url, ENT_QUOTES) . '"></noscript>';
    echo '</head><body></body></html>';
    exit;
}

if ($id <= 0) {
    $_SESSION['_flash_error'] = 'ID tidak valid.';
    safe_redirect($redirectUrl);
}

if (!csrf_check($token)) {
    $_SESSION['_flash_error'] = 'Token CSRF tidak valid.';
    safe_redirect($redirectUrl);
}

// tidak boleh hapus diri sendiri
if ($id === $uid) {
    $_SESSION['_flash_error'] = 'Tidak dapat menghapus user sendiri.';
    safe_redirect($redirectUrl);
}

// role check
if (!in_array($role, ['admin','editor'], true)) {
    $_SESSION['_flash_error'] = 'Akses ditolak: kamu tidak boleh menghapus user.';
    safe_redirect($redirectUrl);
}

// pastikan user ada
$stmt = $pdo->prepare("SELECT id FROM users WHERE id = :id AND is_deleted = 0 LIMIT 1");
$stmt->execute([':id' => $id]);
if (!$stmt->fetch()) {
    $_SESSION['_flash_error'] = 'User tidak ditemukan atau sudah dihapus.';
    safe_redirect($redirectUrl);
}

// lakukan soft delete
$stmtDel = $pdo->prepare("UPDATE users SET is_deleted = 1, updated_at = NOW() WHERE id = :id LIMIT 1");
$ok = $stmtDel->execute([':id' => $id]);

if ($ok) {
    $_SESSION['_flash_success'] = 'User berhasil dihapus.';
    safe_redirect($redirectUrl);
} else {
    $_SESSION['_flash_error'] = 'Gagal menghapus user.';
    safe_redirect($redirectUrl);
}
