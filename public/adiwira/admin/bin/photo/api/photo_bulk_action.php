<?php
// /adiwira/admin/bin/photo/api/photo_bulk_action.php
if (!defined('DASHBOARD_CONTEXT')) define('DASHBOARD_CONTEXT', true);
require_once __DIR__ . '/../../../../bootstrap.php';

if (session_status() === PHP_SESSION_NONE) session_start();

function json_out($arr, int $code=200): void {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($arr, JSON_UNESCAPED_UNICODE);
  exit;
}

$uid = (int)($_SESSION['user_id'] ?? 0);
if ($uid <= 0) json_out(['ok'=>false,'error'=>'Akses ditolak: belum login.'], 403);

$role = $_SESSION['user_role'] ?? null;
if (!$role) {
  if (function_exists('current_user_role')) $role = current_user_role($pdo) ?: null;
  if (!$role) {
    $st = $pdo->prepare("SELECT role FROM users WHERE id=:id AND is_deleted=0 LIMIT 1");
    $st->execute([':id'=>$uid]);
    $role = $st->fetchColumn() ?: null;
  }
  $_SESSION['user_role'] = $role;
}
if ($role !== 'admin') json_out(['ok'=>false,'error'=>'Akses ditolak: hanya admin.'], 403);

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);
if (!is_array($payload)) $payload = $_POST;

$token = (string)($payload['csrf_token'] ?? '');
if (!csrf_check($token)) json_out(['ok'=>false,'error'=>'CSRF token tidak valid.'], 400);

$action = (string)($payload['action'] ?? '');
$ids = $payload['ids'] ?? [];
if (!is_array($ids) || empty($ids)) json_out(['ok'=>false,'error'=>'Tidak ada item dipilih.'], 400);

$ids = array_values(array_filter(array_map('intval', $ids), fn($v)=>$v>0));
if (empty($ids)) json_out(['ok'=>false,'error'=>'ID tidak valid.'], 400);

$in = implode(',', array_fill(0, count($ids), '?'));

try {
  $pdo->beginTransaction();

  if ($action === 'restore') {
    $sql = "UPDATE posts SET is_deleted=0, deleted_at=NULL, updated_at=NOW()
            WHERE id IN ($in) AND type='photo' AND is_deleted=1";
    $st = $pdo->prepare($sql);
    $st->execute($ids);
    $pdo->commit();
    json_out(['ok'=>true,'affected'=>$st->rowCount()]);
  }

  if ($action === 'delete_permanent') {
    $pdo->prepare("DELETE FROM post_categories WHERE post_id IN ($in)")->execute($ids);

    $sql = "DELETE FROM posts WHERE id IN ($in) AND type='photo' AND is_deleted=1";
    $st = $pdo->prepare($sql);
    $st->execute($ids);

    $pdo->commit();
    json_out(['ok'=>true,'affected'=>$st->rowCount()]);
  }

  $pdo->rollBack();
  json_out(['ok'=>false,'error'=>'Aksi bulk tidak dikenal.'], 400);

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  json_out(['ok'=>false,'error'=>'Terjadi kesalahan: '.$e->getMessage()], 500);
}
