<?php
// /adiwira/admin/categories/bulk_action.php
if (!defined('DASHBOARD_CONTEXT')) define('DASHBOARD_CONTEXT', true);

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../posts/bulk_succ.php';

function adiwira_root(): string {
  $base = rtrim(str_replace('\\','/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
  $pos  = strpos($base, '/admin');
  return ($pos !== false) ? substr($base, 0, $pos) : $base; // => /adiwira
}
$root = adiwira_root();
$back = $root . '/index.php?page=admin/categories/index';

$role = current_user_role($pdo) ?: 'guest';
if ($role === 'author') {
  http_response_code(403);
  exit('Akses ditolak: Anda tidak memiliki izin untuk melakukan bulk action.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  exit('Method Not Allowed');
}

$token = $_POST['csrf_token'] ?? '';
if (!csrf_check($token)) {
  header('Location: ' . $back . '&err=' . urlencode('CSRF token tidak valid.'));
  exit;
}

$ids = $_POST['ids'] ?? [];
if (!is_array($ids) || empty($ids)) {
  header('Location: ' . $back . '&err=' . urlencode('Tidak ada kategori dipilih.'));
  exit;
}

$ids = array_values(array_filter(array_map('intval', $ids), fn($v) => $v > 0));
if (empty($ids)) {
  header('Location: ' . $back . '&err=' . urlencode('ID kategori tidak valid.'));
  exit;
}

$action = (string)($_POST['action'] ?? '');
$in = implode(',', array_fill(0, count($ids), '?'));

try {
  $pdo->beginTransaction();

  if ($action === 'delete') {
    // GUARD: jangan delete parent yang masih punya child aktif
    $chk = $pdo->prepare("SELECT DISTINCT parent_id FROM categories WHERE parent_id IN ($in) AND is_deleted=0");
    $chk->execute($ids);
    $badParents = $chk->fetchAll(PDO::FETCH_COLUMN, 0);
    if (!empty($badParents)) {
      $pdo->rollBack();
      header('Location: ' . $back . '&err=' . urlencode('Gagal: ada kategori yang masih punya subkategori aktif. IDs: ' . implode(',', array_slice($badParents, 0, 20))));
      exit;
    }

    $sql = "UPDATE categories
            SET is_deleted=1, deleted_at=NOW(), updated_at=NOW()
            WHERE id IN ($in) AND is_deleted=0";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($ids);

    // IMPORTANT: soft delete JANGAN hapus post_categories (biar restore bisa balik)
    // $pdo->prepare(\"DELETE FROM post_categories WHERE category_id IN ($in)\")->execute($ids);

    $pdo->commit();

    show_success_and_redirect(
      'Berhasil memindahkan ' . $stmt->rowCount() . ' kategori ke Trash.',
      $back
    );
  }

  if ($action === 'change_parent') {
    $parent = isset($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;
    if ($parent === null) {
      $pdo->rollBack();
      header('Location: ' . $back . '&err=' . urlencode('Parent wajib dipilih (atau pilih Tanpa Parent).'));
      exit;
    }

    // parent tidak boleh termasuk ids terpilih
    if ($parent > 0 && in_array($parent, $ids, true)) {
      $pdo->rollBack();
      header('Location: ' . $back . '&err=' . urlencode('Parent tidak boleh termasuk kategori yang dipilih.'));
      exit;
    }

    if ($parent !== 0) {
      $v = $pdo->prepare("SELECT id FROM categories WHERE id=? AND is_deleted=0 LIMIT 1");
      $v->execute([$parent]);
      if (!$v->fetchColumn()) {
        $pdo->rollBack();
        header('Location: ' . $back . '&err=' . urlencode('Parent kategori tidak ditemukan.'));
        exit;
      }
    }

    if ($parent === 0) {
      $sql = "UPDATE categories SET parent_id=NULL, updated_at=NOW() WHERE id IN ($in) AND is_deleted=0";
      $stmt = $pdo->prepare($sql);
      $stmt->execute($ids);
    } else {
      $sql = "UPDATE categories SET parent_id=?, updated_at=NOW() WHERE id IN ($in) AND is_deleted=0";
      $stmt = $pdo->prepare($sql);
      $stmt->execute(array_merge([$parent], $ids));
    }

    $pdo->commit();

    show_success_and_redirect(
      'Parent berhasil diubah untuk ' . $stmt->rowCount() . ' kategori.',
      $back
    );
  }

  $pdo->rollBack();
  header('Location: ' . $back . '&err=' . urlencode('Aksi bulk tidak dikenal.'));
  exit;

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  header('Location: ' . $back . '&err=' . urlencode('Terjadi kesalahan saat proses bulk: ' . $e->getMessage()));
  exit;
}
