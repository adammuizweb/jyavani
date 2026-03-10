<?php
// /adiwira/admin/bin/users/restore.php
if (!defined('DASHBOARD_CONTEXT')) define('DASHBOARD_CONTEXT', true);

require_once __DIR__ . '/../../../bootstrap.php';
if (session_status() === PHP_SESSION_NONE) session_start();

function adiwira_base_url(): string {
  $base = rtrim(str_replace('\\','/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
  $pos = strpos($base, '/admin');
  if ($pos !== false) return substr($base, 0, $pos);
  return $base;
}

$root = adiwira_base_url();
$back = $root . '/index.php?page=admin/bin/users/index';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') qz_redirect($back);

$token = $_POST['csrf_token'] ?? '';
if (!csrf_check($token)) qz_redirect($back . '&err=' . urlencode('CSRF token tidak valid.'));

$uid = (int)($_SESSION['user_id'] ?? 0);
if ($uid <= 0) qz_redirect($back . '&err=' . urlencode('Akses ditolak: belum login.'));

$role = current_user_role($pdo) ?: 'guest';
if ($role !== 'admin') {
  qz_redirect($back . '&err=' . urlencode('Akses ditolak: hanya admin yang boleh restore user.'));
}

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) qz_redirect($back . '&err=' . urlencode('ID user tidak valid.'));

$stmt = $pdo->prepare("SELECT id FROM users WHERE id = :id AND is_deleted = 1 LIMIT 1");
$stmt->execute([':id' => $id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) qz_redirect($back . '&err=' . urlencode('User tidak ditemukan di trash.'));

$pdo->prepare("UPDATE users SET is_deleted = 0, updated_at = NOW() WHERE id = :id AND is_deleted = 1 LIMIT 1")
    ->execute([':id' => $id]);

qz_redirect($back . '&msg=' . urlencode('User berhasil direstore ✅'));