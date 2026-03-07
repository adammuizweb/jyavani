<?php
if (!defined('DASHBOARD_CONTEXT')) define('DASHBOARD_CONTEXT', true);
require_once __DIR__ . '/../../../../bootstrap.php';
if (session_status() === PHP_SESSION_NONE) session_start();

function json_out($arr, int $code=200): void {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($arr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

$uid = (int)($_SESSION['user_id'] ?? 0);
if ($uid <= 0) json_out(['ok'=>false,'error'=>'Unauthorized'], 403);

$role = $_SESSION['user_role'] ?? null;
if (!$role && function_exists('current_user_role')) $role = current_user_role($pdo) ?: null;
if (!$role) {
  $st = $pdo->prepare("SELECT role FROM users WHERE id=:id AND is_deleted=0 LIMIT 1");
  $st->execute([':id'=>$uid]);
  $role = $st->fetchColumn() ?: null;
  $_SESSION['user_role'] = $role;
}
if ($role !== 'admin') json_out(['ok'=>false,'error'=>'Admin only'], 403);

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) json_out(['ok'=>false,'error'=>'Invalid id'], 400);

$st = $pdo->prepare("
  SELECT id,title,slug,status,thumbnail,created_at,updated_at,deleted_at,created_by
  FROM posts
  WHERE id=:id AND type='photo' AND is_deleted=1
  LIMIT 1
");
$st->execute([':id'=>$id]);
$post = $st->fetch(PDO::FETCH_ASSOC);
if (!$post) json_out(['ok'=>false,'error'=>'Not found in trash'], 404);

$cs = $pdo->prepare("SELECT category_id FROM post_categories WHERE post_id=:pid");
$cs->execute([':pid'=>$id]);
$cat_ids = array_map('intval', $cs->fetchAll(PDO::FETCH_COLUMN) ?: []);

$it = $pdo->prepare("
  SELECT
    pmi.media_id AS id,
    m.url,
    COALESCE(pmi.caption_override,'') AS caption,
    COALESCE(pmi.alt_override,'') AS alt,
    COALESCE(pmi.link_url_override,'') AS link_url,
    COALESCE(pmi.link_target_override,'') AS link_target,
    pmi.sort_order
  FROM post_media_items pmi
  JOIN media m ON m.id = pmi.media_id
  WHERE pmi.post_id = :pid
  ORDER BY pmi.sort_order ASC, pmi.media_id ASC
");
$it->execute([':pid'=>$id]);
$items = $it->fetchAll(PDO::FETCH_ASSOC) ?: [];

json_out([
  'ok'=>true,
  'photo'=>[
    'id'=>(int)$post['id'],
    'title'=>$post['title'],
    'slug'=>$post['slug'],
    'status'=>$post['status'],
    'thumbnail'=>$post['thumbnail'],
    'created_at'=>$post['created_at'],
    'updated_at'=>$post['updated_at'],
    'deleted_at'=>$post['deleted_at'],
    'created_by'=>(int)($post['created_by'] ?? 0),
    'category_ids'=>$cat_ids,
    'items'=>$items,
  ]
]);
