<?php
require __DIR__ . '/_init.php';

$q = trim((string)($_GET['q'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$per  = (int)($_GET['per_page'] ?? 30);
if ($per < 5) $per = 5;
if ($per > 100) $per = 100;

try {
  $where = "p.type='photo' AND p.is_deleted=0";
  $params = [];

  if (!$isAdmin) { $where .= " AND p.created_by=:uid"; $params[':uid'] = $uid; }

  if ($q !== '') {
    $where .= " AND (p.title LIKE :q OR p.slug LIKE :q OR CAST(p.id AS CHAR) LIKE :q2)";
    $params[':q'] = '%' . $q . '%';
    $params[':q2'] = '%' . $q . '%';
  }

  // total
  $csql = "SELECT COUNT(*) FROM posts p WHERE $where";
  $cst = $pdo->prepare($csql);
  $cst->execute($params);
  $total = (int)$cst->fetchColumn();

  $total_pages = (int)max(1, ceil($total / $per));
  if ($page > $total_pages) $page = $total_pages;

  $offset = ($page - 1) * $per;

  // rows
  $sql = "
    SELECT
      p.id, p.title, p.slug, p.status, p.thumbnail, p.updated_at, p.sort_order,
      (SELECT COUNT(*) FROM post_media_items x WHERE x.post_id = p.id) AS media_count
    FROM posts p
    WHERE $where
    ORDER BY
      CASE WHEN p.sort_order IS NULL THEN 0 ELSE 1 END DESC,
      p.sort_order ASC,
      p.id DESC
    LIMIT :lim OFFSET :off
  ";

  $st = $pdo->prepare($sql);
  foreach ($params as $k=>$v) $st->bindValue($k, $v);
  $st->bindValue(':lim', (int)$per, PDO::PARAM_INT);
  $st->bindValue(':off', (int)$offset, PDO::PARAM_INT);
  $st->execute();

  $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

  out_json([
    'ok'=>true,
    'photos'=>$rows,
    'page'=>$page,
    'per_page'=>$per,
    'total'=>$total,
    'total_pages'=>$total_pages,
  ]);

} catch (Throwable $e) {
  out_json(['ok'=>false,'error'=>$DEBUG ? $e->getMessage() : 'Internal server error'], 500);
}
