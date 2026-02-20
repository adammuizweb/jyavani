<?php
// /adiwira/admin/categories/delete.php
if (!defined('DASHBOARD_CONTEXT')) define('DASHBOARD_CONTEXT', true);

require_once __DIR__ . '/../../bootstrap.php';

function adiwira_root(): string {
  $base = rtrim(str_replace('\\','/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
  $pos  = strpos($base, '/admin');
  return ($pos !== false) ? substr($base, 0, $pos) : $base; // => /adiwira
}

function redirect_categories_index(string $msg = '', string $err = ''): void {
  $root = adiwira_root();
  $url  = $root . '/index.php?page=admin/categories/index';
  $sep  = (strpos($url, '?') === false) ? '?' : '&';
  if ($msg !== '') $url .= $sep . 'msg=' . urlencode($msg);
  if ($err !== '') $url .= ($msg === '' ? $sep : '&') . 'err=' . urlencode($err);
  header('Location: ' . $url);
  exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect_categories_index();
if (session_status() === PHP_SESSION_NONE) session_start();

$token = $_POST['csrf_token'] ?? '';
if (!csrf_check($token)) redirect_categories_index('', 'CSRF token tidak valid.');

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) redirect_categories_index('', 'ID tidak valid.');

$uid = (int)($_SESSION['user_id'] ?? 0);
if ($uid <= 0) redirect_categories_index('', 'Akses ditolak: belum login.');

$role = current_user_role($pdo) ?: 'guest';
if (!in_array($role, ['editor','admin'], true)) {
  redirect_categories_index('', 'Role kamu tidak memiliki akses untuk hapus kategori ini.');
}

$stmt = $pdo->prepare("SELECT id FROM categories WHERE id=:id AND is_deleted=0 LIMIT 1");
$stmt->execute([':id' => $id]);
if (!$stmt->fetchColumn()) redirect_categories_index('', 'Kategori tidak ditemukan.');

// GUARD: jangan hapus parent yang masih punya child aktif
$child = $pdo->prepare("SELECT COUNT(*) FROM categories WHERE parent_id=:id AND is_deleted=0");
$child->execute([':id' => $id]);
if ((int)$child->fetchColumn() > 0) {
  redirect_categories_index('', 'Kategori masih punya subkategori aktif. Pindahkan/hapus subkategori dulu.');
}

try {
  $pdo->beginTransaction();

  $pdo->prepare("
    UPDATE categories
    SET is_deleted=1, deleted_at=NOW(), updated_at=NOW()
    WHERE id=:id AND is_deleted=0
    LIMIT 1
  ")->execute([':id' => $id]);

  // IMPORTANT: soft delete JANGAN hapus post_categories (biar restore bisa balik normal)
  // $pdo->prepare("DELETE FROM post_categories WHERE category_id=:id")->execute([':id'=>$id]);

  $pdo->commit();
  redirect_categories_index('Kategori berhasil masuk Trash 🚮');

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  redirect_categories_index('', 'Gagal menghapus kategori: ' . $e->getMessage());
}
