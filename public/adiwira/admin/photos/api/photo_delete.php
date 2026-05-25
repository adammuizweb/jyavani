<?php
require __DIR__ . '/_init.php';

$token = (string)($IN['csrf_token'] ?? '');
if (!csrf_ok($token)) out_json(['ok'=>false,'error'=>'CSRF invalid'], 400);

$id = (int)($IN['id'] ?? 0);
if ($id <= 0) out_json(['ok'=>false,'error'=>'Invalid id'], 400);

try {
  $chk = $pdo->prepare("SELECT id, created_by FROM posts WHERE id=:id AND type='photo' AND is_deleted=0 LIMIT 1");
  $chk->execute([':id'=>$id]);
  $row = $chk->fetch(PDO::FETCH_ASSOC);

  if (!$row) out_json(['ok'=>false,'error'=>'Not found'], 404);
  if (!$isAdmin && (int)$row['created_by'] !== $uid) out_json(['ok'=>false,'error'=>'Forbidden'], 403);

  // soft delete post
  $now = date('Y-m-d H:i:s');
  $pdo->prepare("UPDATE posts SET is_deleted=1, deleted_at=:d, updated_at=:u WHERE id=:id LIMIT 1")
      ->execute([':d'=>$now, ':u'=>$now, ':id'=>$id]);

  // cleanup relations (opsional tapi rapi)
  $pdo->prepare("DELETE FROM post_categories WHERE post_id=:pid")->execute([':pid'=>$id]);
  if ($PHOTO_HAS_MEDIA_ITEMS) {
    $pdo->prepare("DELETE FROM post_media_items WHERE post_id=:pid")->execute([':pid'=>$id]);
  }

  out_json(['ok'=>true]);

} catch (Throwable $e) {
  out_json(['ok'=>false,'error'=>$DEBUG ? $e->getMessage() : 'Internal server error'], 500);
}
