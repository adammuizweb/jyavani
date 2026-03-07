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

$q   = trim((string)($_GET['q'] ?? ''));
$p   = max(1, (int)($_GET['p'] ?? 1));
$per = max(1, min(100, (int)($_GET['per'] ?? 30)));
$off = ($p - 1) * $per;

$where = ["p.type='photo'", "p.is_deleted=1"];
$params = [];
if ($q !== '') {
  $where[] = "(p.title LIKE :q OR p.slug LIKE :q OR CAST(p.id AS CHAR) LIKE :q)";
  $params[':q'] = '%' . $q . '%';
}
$where_sql = implode(' AND ', $where);

$stc = $pdo->prepare("SELECT COUNT(*) FROM posts p WHERE $where_sql");
$stc->execute($params);
$total = (int)$stc->fetchColumn();

$sql = "
  SELECT
    p.id, p.title, p.slug, p.status, p.thumbnail, p.deleted_at, p.updated_at,
    (SELECT COUNT(*) FROM post_media_items x WHERE x.post_id = p.id) AS media_count
  FROM posts p
  WHERE $where_sql
  ORDER BY p.deleted_at DESC, p.id DESC
  LIMIT :lim OFFSET :off
";
$st = $pdo->prepare($sql);
foreach ($params as $k=>$v) $st->bindValue($k,$v);
$st->bindValue(':lim', $per, PDO::PARAM_INT);
$st->bindValue(':off', $off, PDO::PARAM_INT);
$st->execute();
$rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

json_out(['ok'=>true,'rows'=>$rows,'total'=>$total,'p'=>$p,'per'=>$per]);
