<?php
require __DIR__ . '/_init.php';

$token = (string)($IN['csrf_token'] ?? '');
if (!csrf_ok($token)) out_json(['ok'=>false,'error'=>'CSRF invalid'], 400);

$ids = $IN['ids'] ?? [];
if (!is_array($ids)) out_json(['ok'=>false,'error'=>'Invalid payload'], 400);

$ids = array_values(array_unique(array_map('intval', $ids)));
$ids = array_values(array_filter($ids, fn($v)=>$v > 0));
if (!$ids) out_json(['ok'=>false,'error'=>'Empty ids'], 400);

try {
  // pastikan semua id valid & boleh diakses
  $place = implode(',', array_fill(0, count($ids), '?'));
  $sql = "SELECT id FROM posts
          WHERE type='photo' AND is_deleted=0
            AND id IN ($place)";
  $params = $ids;

  if (!$isAdmin) {
    $sql .= " AND created_by=?";
    $params[] = $uid;
  }

  $st = $pdo->prepare($sql);
  $st->execute($params);
  $found = $st->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
  $found = array_map('intval', $found);

  if (count($found) !== count($ids)) {
    out_json(['ok'=>false,'error'=>'Some ids not found / forbidden'], 403);
  }

  $pdo->beginTransaction();

  $updSql = "UPDATE posts SET sort_order=:ord WHERE id=:id AND type='photo' AND is_deleted=0";
  if (!$isAdmin) $updSql .= " AND created_by=:uid";
  $updSql .= " LIMIT 1";

  $upd = $pdo->prepare($updSql);

  $step = 10;
  $ord  = 10;
  foreach ($ids as $id) {
    $bind = [':ord'=>$ord, ':id'=>$id];
    if (!$isAdmin) $bind[':uid'] = $uid;
    $upd->execute($bind);
    $ord += $step;
  }

  $pdo->commit();

  out_json(['ok'=>true, 'count'=>count($ids)]);

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  out_json(['ok'=>false,'error'=>$DEBUG ? $e->getMessage() : 'Internal server error'], 500);
}
