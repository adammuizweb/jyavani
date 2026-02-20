<?php
// /adiwira/admin/bin/category/bulk_action.php
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

$role = current_user_role($pdo) ?: 'guest';
if ($role === 'author') {
  http_response_code(403);
  exit('Akses ditolak: role author tidak boleh bulk.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') qz_redirect($back);

$token = $_POST['csrf_token'] ?? '';
if (!csrf_check($token)) qz_redirect($back . '&err=' . urlencode('CSRF token tidak valid.'));

$ids = $_POST['ids'] ?? [];
if (!is_array($ids) || empty($ids)) qz_redirect($back . '&err=' . urlencode('Tidak ada kategori dipilih.'));

$ids = array_values(array_filter(array_map('intval', $ids), fn($v) => $v > 0));
if (empty($ids)) qz_redirect($back . '&err=' . urlencode('ID kategori tidak valid.'));

$action = (string)($_POST['action'] ?? '');
$in = implode(',', array_fill(0, count($ids), '?'));

try {
  $pdo->beginTransaction();

  if ($action === 'restore') {
    // restore + null-kan parent kalau parent masih deleted (agar tidak hilang dari tree)
    $sql = "
      UPDATE categories c
      LEFT JOIN categories p ON p.id = c.parent_id
      SET
        c.is_deleted = 0,
        c.deleted_at = NULL,
        c.parent_id = CASE
          WHEN c.parent_id IS NULL OR c.parent_id=0 THEN NULL
          WHEN p.id IS NULL THEN NULL
          WHEN p.is_deleted=1 THEN NULL
          ELSE c.parent_id
        END,
        c.updated_at = NOW()
      WHERE c.id IN ($in) AND c.is_deleted=1
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($ids);

    $pdo->commit();
    qz_redirect($back . '&msg=' . urlencode('Berhasil restore ' . $stmt->rowCount() . ' kategori.'));
  }

  if ($action === 'delete_permanent') {
    // block jika ada child
    $chk = $pdo->prepare("SELECT parent_id, COUNT(*) AS cnt FROM categories WHERE parent_id IN ($in) GROUP BY parent_id");
    $chk->execute($ids);
    $rows = $chk->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (!empty($rows)) {
      $bad = array_map(fn($r) => (int)$r['parent_id'], $rows);
      $pdo->rollBack();
      qz_redirect($back . '&err=' . urlencode('Gagal: beberapa kategori masih punya subkategori. IDs: ' . implode(',', array_slice($bad, 0, 20))));
    }

    $pdo->prepare("DELETE FROM post_categories WHERE category_id IN ($in)")->execute($ids);

    $sql = "DELETE FROM categories WHERE id IN ($in) AND is_deleted=1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($ids);

    $pdo->commit();
    qz_redirect($back . '&msg=' . urlencode('Berhasil hapus permanen ' . $stmt->rowCount() . ' kategori.'));
  }

  $pdo->rollBack();
  qz_redirect($back . '&err=' . urlencode('Aksi bulk tidak dikenal.'));
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  qz_redirect($back . '&err=' . urlencode('Terjadi kesalahan: ' . $e->getMessage()));
}
