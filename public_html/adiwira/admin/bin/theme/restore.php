<?php
// /adiwira/admin/bin/theme/restore.php
if (!defined('DASHBOARD_CONTEXT')) define('DASHBOARD_CONTEXT', true);

require_once __DIR__ . '/../../../bootstrap.php';
if (session_status() === PHP_SESSION_NONE) session_start();

function adiwira_root(): string {
  $base = rtrim(str_replace('\\','/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
  $pos = strpos($base, '/admin');
  return ($pos !== false) ? substr($base, 0, $pos) : $base;
}

$root = adiwira_root();
$back = $root . '/index.php?page=admin/bin/theme/index';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') qz_redirect($back);

$token = $_POST['csrf_token'] ?? '';
if (!csrf_check($token)) qz_redirect($back . '&err=' . urlencode('CSRF token tidak valid.'));

$uid = (int)($_SESSION['user_id'] ?? 0);
if ($uid <= 0) qz_redirect($back . '&err=' . urlencode('Akses ditolak: belum login.'));

$role = current_user_role($pdo) ?: 'guest';

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) qz_redirect($back . '&err=' . urlencode('ID tidak valid.'));

$stmt = $pdo->prepare("SELECT id, created_by FROM posts WHERE id=:id AND type='theme' AND is_deleted=1 LIMIT 1");
$stmt->execute([':id' => $id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) qz_redirect($back . '&err=' . urlencode('Theme tidak ditemukan di trash.'));

if ($role === 'author' && (int)$row['created_by'] !== $uid) {
  qz_redirect($back . '&err=' . urlencode('Role kamu tidak punya akses restore theme ini.'));
}

$pdo->prepare("UPDATE posts SET is_deleted=0, deleted_at=NULL, updated_at=NOW() WHERE id=:id AND type='theme' LIMIT 1")
    ->execute([':id' => $id]);

qz_redirect($back . '&msg=' . urlencode('Theme berhasil direstore ✅'));
