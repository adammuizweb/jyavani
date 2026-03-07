<?php
// /adiwira/admin/bin/photo/api/photo_restore.php
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

$id = (int)($payload['id'] ?? 0);
if ($id <= 0) json_out(['ok'=>false,'error'=>'ID tidak valid.'], 400);

$st = $pdo->prepare("UPDATE posts
                     SET is_deleted=0, deleted_at=NULL, updated_at=NOW()
                     WHERE id=:id AND type='photo' AND is_deleted=1
                     LIMIT 1");
$st->execute([':id'=>$id]);

if ($st->rowCount() < 1) json_out(['ok'=>false,'error'=>'Gagal restore (item tidak ditemukan / sudah direstore).'], 404);

json_out(['ok'=>true,'id'=>$id]);
