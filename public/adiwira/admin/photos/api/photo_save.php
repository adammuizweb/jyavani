<?php
require __DIR__ . '/_init.php';

function slugify_simple(string $text): string {
  $text = mb_strtolower($text, 'UTF-8');
  $text = preg_replace('/[^\p{L}\p{N}\-]+/u', '-', $text);
  $text = preg_replace('/[-]{2,}/', '-', $text);
  $text = trim($text, '-');
  return $text ?: bin2hex(random_bytes(4));
}

function make_unique_slug(PDO $pdo, string $slug, int $excludeId = 0): string {
  $base = $slug;
  $n = 2;

  while (true) {
    if ($excludeId > 0) {
      $st = $pdo->prepare("SELECT id FROM posts WHERE slug=:s AND id!=:id LIMIT 1");
      $st->execute([':s'=>$slug, ':id'=>$excludeId]);
    } else {
      $st = $pdo->prepare("SELECT id FROM posts WHERE slug=:s LIMIT 1");
      $st->execute([':s'=>$slug]);
    }
    if (!$st->fetch()) return $slug;

    $slug = $base . '-' . $n;
    $n++;

    if ($n > 50) {
      $slug = $base . '-' . bin2hex(random_bytes(2));
    }
  }
}

$token = (string)($IN['csrf_token'] ?? '');
if (!csrf_ok($token)) out_json(['ok'=>false,'error'=>'CSRF invalid'], 400);

$id = (int)($IN['id'] ?? 0);
$title = trim((string)($IN['title'] ?? ''));
$slug  = trim((string)($IN['slug'] ?? ''));
$status = (string)($IN['status'] ?? 'draft');
$category_ids = $IN['category_ids'] ?? [];
$items = $IN['items'] ?? [];

if ($title === '') out_json(['ok'=>false,'error'=>'Judul wajib'], 400);
if (!in_array($status, ['draft','published','private'], true)) $status = 'draft';

$slug = ($slug === '') ? slugify_simple($title) : slugify_simple($slug);

$category_ids = is_array($category_ids) ? array_values(array_unique(array_map('intval', $category_ids))) : [];
$category_ids = array_values(array_filter($category_ids, fn($v)=>$v>0));

$now = (new DateTime('now'))->format('Y-m-d H:i:s');

try {
  // ===== upsert post (photo)
  if ($id > 0) {
    $chk = "SELECT id, created_by FROM posts WHERE id=:id AND type='photo' AND is_deleted=0 LIMIT 1";
    $st = $pdo->prepare($chk);
    $st->execute([':id'=>$id]);
    $row = $st->fetch();
    if (!$row) out_json(['ok'=>false,'error'=>'Photo post not found'], 404);
    if (!$isAdmin && (int)$row['created_by'] !== (int)$uid) out_json(['ok'=>false,'error'=>'Forbidden'], 403);

    // untuk UPDATE: kalau slug bentrok, error (biar user sadar)
    $u = $pdo->prepare("SELECT id FROM posts WHERE slug=:slug AND id!=:id LIMIT 1");
    $u->execute([':slug'=>$slug, ':id'=>$id]);
    if ($u->fetch()) out_json(['ok'=>false,'error'=>'Slug sudah dipakai'], 400);

    $upd = $pdo->prepare("
      UPDATE posts
      SET title=:title, slug=:slug, status=:status, updated_at=:u
      WHERE id=:id AND type='photo'
      LIMIT 1
    ");
    $upd->execute([
      ':title'=>$title, ':slug'=>$slug, ':status'=>$status,
      ':u'=>$now, ':id'=>$id
    ]);

  } else {
    // untuk INSERT: auto-unique slug
    $slug = make_unique_slug($pdo, $slug, 0);

    // sort_order baru taruh paling bawah
    $mx = $pdo->query("SELECT COALESCE(MAX(sort_order),0) FROM posts WHERE type='photo' AND is_deleted=0");
    $maxSort = (int)($mx ? $mx->fetchColumn() : 0);
    $newSort = $maxSort + 10;

    $ins = $pdo->prepare("
      INSERT INTO posts (title, slug, content, type, meta, youtube, thumbnail, status, created_by, created_at, updated_at, sort_order)
      VALUES (:title, :slug, :content, 'photo', NULL, NULL, NULL, :status, :by, :c, :u, :sort)
    ");
    $ins->execute([
      ':title'=>$title,
      ':slug'=>$slug,
      ':content'=>'',
      ':status'=>$status,
      ':by'=>$uid,
      ':c'=>$now,
      ':u'=>$now,
      ':sort'=>$newSort
    ]);
    $id = (int)$pdo->lastInsertId();
  }

  // ===== sync categories
  $pdo->prepare("DELETE FROM post_categories WHERE post_id=:pid")->execute([':pid'=>$id]);
  if (!empty($category_ids)) {
    $pc = $pdo->prepare("INSERT INTO post_categories (post_id, category_id, assigned_by) VALUES (:pid,:cid,:by)");
    foreach ($category_ids as $cid) {
      $pc->execute([':pid'=>$id, ':cid'=>$cid, ':by'=>$uid]);
    }
  }

  // ===== sync items (order = index array)
  if (is_array($items)) {
    $norm = [];
    $seen = [];

    $step = 10;
    $ord = 10;
    foreach ($items as $it) {
      if (!is_array($it)) continue;
      $mid = (int)($it['id'] ?? $it['media_id'] ?? 0);
      if ($mid <= 0) continue;
      if (isset($seen[$mid])) continue;
      $seen[$mid] = true;

      $norm[] = [
        'media_id' => $mid,
        'sort_order' => $ord,
        'caption' => trim((string)($it['caption'] ?? '')),
        'alt' => trim((string)($it['alt'] ?? '')),
        'link_url' => trim((string)($it['link_url'] ?? '')),
        'link_target' => trim((string)($it['link_target'] ?? '')),
      ];
      $ord += $step;
    }

    $keep = array_values(array_unique(array_map(fn($x)=>$x['media_id'], $norm)));
    if (count($keep) > 0) {
      $in = implode(',', array_fill(0, count($keep), '?'));
      $del = $pdo->prepare("DELETE FROM post_media_items WHERE post_id=? AND media_id NOT IN ($in)");
      $del->execute(array_merge([$id], $keep));
    } else {
      $pdo->prepare("DELETE FROM post_media_items WHERE post_id=?")->execute([$id]);
    }

    $up = $pdo->prepare("
      INSERT INTO post_media_items
        (post_id, media_id, sort_order, caption_override, alt_override, link_url_override, link_target_override)
      VALUES
        (:pid, :mid, :ord, :cap, :alt, :lurl, :ltgt)
      ON DUPLICATE KEY UPDATE
        sort_order=VALUES(sort_order),
        caption_override=VALUES(caption_override),
        alt_override=VALUES(alt_override),
        link_url_override=VALUES(link_url_override),
        link_target_override=VALUES(link_target_override)
    ");

    foreach ($norm as $x) {
      $lt = $x['link_target'];
      if (!in_array($lt, ['_self','_blank','_parent','_top'], true)) $lt = null;

      $up->execute([
        ':pid'=>$id,
        ':mid'=>$x['media_id'],
        ':ord'=>$x['sort_order'],
        ':cap'=>$x['caption'] !== '' ? $x['caption'] : null,
        ':alt'=>$x['alt'] !== '' ? $x['alt'] : null,
        ':lurl'=>$x['link_url'] !== '' ? $x['link_url'] : null,
        ':ltgt'=>$lt,
      ]);
    }
  }

  // ===== update cover thumbnail from first item
  $cover = $pdo->prepare("
    SELECT m.url
    FROM post_media_items pmi
    JOIN media m ON m.id = pmi.media_id
    WHERE pmi.post_id = :pid
    ORDER BY pmi.sort_order ASC, pmi.media_id ASC
    LIMIT 1
  ");
  $cover->execute([':pid'=>$id]);
  $coverUrl = (string)($cover->fetchColumn() ?: '');

  $pdo->prepare("UPDATE posts SET thumbnail=:t, updated_at=:u WHERE id=:id LIMIT 1")
      ->execute([':t'=>($coverUrl!==''?$coverUrl:null), ':u'=>$now, ':id'=>$id]);

  $cnt = $pdo->prepare("SELECT COUNT(*) FROM post_media_items WHERE post_id=:pid");
  $cnt->execute([':pid'=>$id]);
  $itemsCount = (int)$cnt->fetchColumn();

  out_json([
    'ok'=>true,
    'id'=>$id,
    'slug'=>$slug,
    'updated_at'=>$now,
    'items_count'=>$itemsCount,
    'thumbnail'=>$coverUrl !== '' ? $coverUrl : null
  ]);

} catch (Throwable $e) {
  out_json(['ok'=>false,'error'=>$DEBUG ? $e->getMessage() : 'Internal server error'], 500);
}
