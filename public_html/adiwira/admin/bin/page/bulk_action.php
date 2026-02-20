<?php
// /adiwira/admin/bin/page/bulk_action.php
if (!defined('DASHBOARD_CONTEXT')) define('DASHBOARD_CONTEXT', true);

require_once __DIR__ . '/../../../bootstrap.php';
if (session_status() === PHP_SESSION_NONE) session_start();

function adiwira_root(): string {
  $base = rtrim(str_replace('\\','/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
  $pos = strpos($base, '/admin');
  return ($pos !== false) ? substr($base, 0, $pos) : $base;
}

$root = adiwira_root();
$back = $root . '/index.php?page=admin/bin/page/index';

$role = current_user_role($pdo) ?: 'guest';
if ($role === 'author') {
  http_response_code(403);
  exit('Akses ditolak: role author tidak boleh bulk.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') qz_redirect($back);

$token = $_POST['csrf_token'] ?? '';
if (!csrf_check($token)) qz_redirect($back . '&err=' . urlencode('CSRF token tidak valid.'));

$ids = $_POST['ids'] ?? [];
if (!is_array($ids) || empty($ids)) qz_redirect($back . '&err=' . urlencode('Tidak ada page dipilih.'));

$ids = array_values(array_filter(array_map('intval', $ids), fn($v) => $v > 0));
if (empty($ids)) qz_redirect($back . '&err=' . urlencode('ID page tidak valid.'));

$action = $_POST['action'] ?? '';
$in = implode(',', array_fill(0, count($ids), '?'));

try {
  $pdo->beginTransaction();

  if ($action === 'restore') {
    $sql = "UPDATE posts SET is_deleted=0, deleted_at=NULL, updated_at=NOW()
            WHERE id IN ($in) AND type='page' AND is_deleted=1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($ids);
    $pdo->commit();
    qz_redirect($back . '&msg=' . urlencode('Berhasil restore ' . $stmt->rowCount() . ' page.'));
  }

  if ($action === 'delete_permanent') {
    $pdo->prepare("DELETE FROM post_categories WHERE post_id IN ($in)")->execute($ids);

    $sql = "DELETE FROM posts WHERE id IN ($in) AND type='page' AND is_deleted=1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($ids);

    $pdo->commit();
    qz_redirect($back . '&msg=' . urlencode('Berhasil hapus permanen ' . $stmt->rowCount() . ' page.'));
  }

  $pdo->rollBack();
  qz_redirect($back . '&err=' . urlencode('Aksi bulk tidak dikenal.'));
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  qz_redirect($back . '&err=' . urlencode('Terjadi kesalahan: ' . $e->getMessage()));
}
