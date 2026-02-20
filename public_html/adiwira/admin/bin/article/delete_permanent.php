<?php
// /adiwira/admin/bin/article/delete_permanent.php
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
$back = $root . '/index.php?page=admin/bin/article/index';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') qz_redirect($back);

$token = $_POST['csrf_token'] ?? '';
if (!csrf_check($token)) qz_redirect($back . '&err=' . urlencode('CSRF token tidak valid.'));

$uid = (int)($_SESSION['user_id'] ?? 0);
if ($uid <= 0) qz_redirect($back . '&err=' . urlencode('Akses ditolak: belum login.'));

$role = current_user_role($pdo) ?: 'guest';

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) qz_redirect($back . '&err=' . urlencode('ID tidak valid.'));

$stmt = $pdo->prepare("SELECT id, created_by FROM posts WHERE id=:id AND type='article' AND is_deleted=1 LIMIT 1");
$stmt->execute([':id' => $id]);
$post = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$post) qz_redirect($back . '&err=' . urlencode('Artikel tidak ditemukan di trash.'));

if ($role === 'author' && (int)$post['created_by'] !== $uid) {
  qz_redirect($back . '&err=' . urlencode('Role kamu tidak punya akses hapus permanen artikel ini.'));
}

try {
  $pdo->beginTransaction();

  $pdo->prepare("DELETE FROM post_categories WHERE post_id=:id")->execute([':id' => $id]);
  $pdo->prepare("DELETE FROM posts WHERE id=:id AND type='article' AND is_deleted=1 LIMIT 1")->execute([':id' => $id]);

  $pdo->commit();
  qz_redirect($back . '&msg=' . urlencode('Artikel berhasil dihapus permanen 🧨'));
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  qz_redirect($back . '&err=' . urlencode('Gagal hapus permanen: ' . $e->getMessage()));
}
