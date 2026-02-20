<?php
// /adiwira/admin/bin/article/bulk_action.php
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

$role = current_user_role($pdo) ?: 'guest';
if ($role === 'author') {
  http_response_code(403);
  exit('Akses ditolak: role author tidak boleh bulk.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') qz_redirect($back);

$token = $_POST['csrf_token'] ?? '';
if (!csrf_check($token)) qz_redirect($back . '&err=' . urlencode('CSRF token tidak valid.'));

$ids = $_POST['ids'] ?? [];
if (!is_array($ids) || empty($ids)) qz_redirect($back . '&err=' . urlencode('Tidak ada artikel dipilih.'));

$ids = array_values(array_filter(array_map('intval', $ids), fn($v) => $v > 0));
if (empty($ids)) qz_redirect($back . '&err=' . urlencode('ID artikel tidak valid.'));

$action = $_POST['action'] ?? '';
$in = implode(',', array_fill(0, count($ids), '?'));

try {
  $pdo->beginTransaction();

  if ($action === 'restore') {
    $sql = "UPDATE posts SET is_deleted=0, deleted_at=NULL, updated_at=NOW()
            WHERE id IN ($in) AND type='article' AND is_deleted=1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($ids);
    $pdo->commit();
    qz_redirect($back . '&msg=' . urlencode('Berhasil restore ' . $stmt->rowCount() . ' artikel.'));
  }

  if ($action === 'delete_permanent') {
    $pdo->prepare("DELETE FROM post_categories WHERE post_id IN ($in)")->execute($ids);

    $sql = "DELETE FROM posts WHERE id IN ($in) AND type='article' AND is_deleted=1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($ids);

    $pdo->commit();
    qz_redirect($back . '&msg=' . urlencode('Berhasil hapus permanen ' . $stmt->rowCount() . ' artikel.'));
  }

  $pdo->rollBack();
  qz_redirect($back . '&err=' . urlencode('Aksi bulk tidak dikenal.'));
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  qz_redirect($back . '&err=' . urlencode('Terjadi kesalahan: ' . $e->getMessage()));
}
