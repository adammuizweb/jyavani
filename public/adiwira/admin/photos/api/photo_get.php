<?php
require __DIR__ . '/_init.php';

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) out_json(['ok'=>false,'error'=>'Invalid id'], 400);

try {
  $sql = "SELECT id,title,slug,status,thumbnail,created_at,updated_at,created_by
          FROM posts
          WHERE id=:id AND type='photo' AND is_deleted=0";
  $params = [':id'=>$id];

  if (!$isAdmin) { $sql .= " AND created_by=:uid"; $params[':uid'] = $uid; }
  $sql .= " LIMIT 1";

  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);
  $post = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$post) out_json(['ok'=>false,'error'=>'Not found'], 404);

  $cs = $pdo->prepare("SELECT category_id FROM post_categories WHERE post_id=:pid");
  $cs->execute([':pid'=>$id]);
  $cat_ids = array_map('intval', $cs->fetchAll(PDO::FETCH_COLUMN, 0) ?: []);

  $it = $pdo->prepare("
    SELECT
      pmi.media_id AS id,
      m.url,
      COALESCE(pmi.caption_override, '') AS caption,
      COALESCE(pmi.alt_override, '') AS alt,
      COALESCE(pmi.link_url_override, '') AS link_url,
      COALESCE(pmi.link_target_override, '') AS link_target,
      pmi.sort_order
    FROM post_media_items pmi
    JOIN media m ON m.id = pmi.media_id
    WHERE pmi.post_id = :pid
    ORDER BY pmi.sort_order ASC, pmi.media_id ASC
  ");
  $it->execute([':pid'=>$id]);
  $items = $it->fetchAll(PDO::FETCH_ASSOC) ?: [];

  out_json([
    'ok'=>true,
    'photo'=>[
      'id'=>(int)$post['id'],
      'title'=>$post['title'],
      'slug'=>$post['slug'],
      'status'=>$post['status'],
      'thumbnail'=>$post['thumbnail'],
      'created_at'=>$post['created_at'],
      'updated_at'=>$post['updated_at'],
      'category_ids'=>$cat_ids,
      'items'=>$items,
    ]
  ]);

} catch (Throwable $e) {
  out_json(['ok'=>false,'error'=>$DEBUG ? $e->getMessage() : 'Internal server error'], 500);
}
