<?php
// /adiwira/admin/bin/category/delete_permanent.php
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

$stmt = $pdo->prepare("SELECT id, created_by FROM categories WHERE id=:id AND is_deleted=1 LIMIT 1");
$stmt->execute([':id' => $id]);
$cat = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$cat) qz_redirect($back . '&err=' . urlencode('Kategori tidak ditemukan di trash.'));

if ($role === 'author' && (int)($cat['created_by'] ?? 0) !== $uid) {
  qz_redirect($back . '&err=' . urlencode('Role kamu tidak punya akses hapus permanen kategori ini.'));
}

// cegah delete permanen jika masih punya child (apapun statusnya)
$child = $pdo->prepare("SELECT COUNT(*) FROM categories WHERE parent_id=:id");
$child->execute([':id' => $id]);
if ((int)$child->fetchColumn() > 0) {
  qz_redirect($back . '&err=' . urlencode('Tidak bisa hapus permanen: kategori masih punya subkategori. Hapus/pindahkan subkategori dulu.'));
}

try {
  $pdo->beginTransaction();

  $pdo->prepare("DELETE FROM post_categories WHERE category_id=:id")->execute([':id' => $id]);
  $pdo->prepare("DELETE FROM categories WHERE id=:id AND is_deleted=1 LIMIT 1")->execute([':id' => $id]);

  $pdo->commit();
  qz_redirect($back . '&msg=' . urlencode('Kategori berhasil dihapus permanen 🧨'));
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  qz_redirect($back . '&err=' . urlencode('Gagal hapus permanen: ' . $e->getMessage()));
}
