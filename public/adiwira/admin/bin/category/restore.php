<?php
// /adiwira/admin/bin/category/restore.php
if (!defined('DASHBOARD_CONTEXT')) define('DASHBOARD_CONTEXT', true);

require_once __DIR__ . '/../../../bootstrap.php';
if (session_status() === PHP_SESSION_NONE) session_start();

function adiwira_root(): string {
  $base = rtrim(str_replace('\\','/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
  $pos = strpos($base, '/admin');
  return ($pos !== false) ? substr($base, 0, $pos) : $base;
}

$root = adiwira_root();
$back = $root . '/index.php?page=admin/bin/category/index';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') qz_redirect($back);

$token = $_POST['csrf_token'] ?? '';
if (!csrf_check($token)) qz_redirect($back . '&err=' . urlencode('CSRF token tidak valid.'));

$uid = (int)($_SESSION['user_id'] ?? 0);
if ($uid <= 0) qz_redirect($back . '&err=' . urlencode('Akses ditolak: belum login.'));

$role = current_user_role($pdo) ?: 'guest';

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) qz_redirect($back . '&err=' . urlencode('ID tidak valid.'));

$stmt = $pdo->prepare("SELECT id, parent_id, created_by FROM categories WHERE id=:id AND is_deleted=1 LIMIT 1");
$stmt->execute([':id' => $id]);
$cat = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$cat) qz_redirect($back . '&err=' . urlencode('Kategori tidak ditemukan di trash.'));

if ($role === 'author' && (int)($cat['created_by'] ?? 0) !== $uid) {
  qz_redirect($back . '&err=' . urlencode('Role kamu tidak punya akses restore kategori ini.'));
}

// kalau parent masih deleted → null-kan agar tidak "hilang" di tree
$pid = (int)($cat['parent_id'] ?? 0);
if ($pid > 0) {
  $p = $pdo->prepare("SELECT is_deleted FROM categories WHERE id=:pid LIMIT 1");
  $p->execute([':pid' => $pid]);
  $pdel = $p->fetchColumn();
  if ($pdel !== false && (int)$pdel === 1) $pid = 0;
}
$parentSql = ($pid > 0) ? $pid : null;

$pdo->prepare("UPDATE categories SET is_deleted=0, deleted_at=NULL, parent_id=:pid, updated_at=NOW() WHERE id=:id AND is_deleted=1 LIMIT 1")
    ->execute([':id' => $id, ':pid' => $parentSql]);

qz_redirect($back . '&msg=' . urlencode('Kategori berhasil direstore ✅'));
