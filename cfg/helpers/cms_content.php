<?php
declare(strict_types=1);
// lokasi file cfg\helpers\cms_content.php
if (defined('CMS_CONTENT_INCLUDED')) return;
define('CMS_CONTENT_INCLUDED', true);

function cms_slugify($s) {
  $s = trim((string)$s);
  if ($s === '') return '';
  $s = function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
  $s = preg_replace('~[^\pL\pN]+~u', '-', $s);
  $s = trim($s, '-');
  return $s ?: '';
}

function cms_categories_index(PDO $pdo) {
  static $cache = null;
  if ($cache !== null) return $cache;

  $rows = $pdo->query("SELECT id,name,slug,parent_id FROM categories WHERE is_deleted=0")
              ->fetchAll(PDO::FETCH_ASSOC);

  $byId = [];
  $children = [];      // key parent_id (0 for null) => [childId...]
  $byParentSlug = [];  // key parent_id => [slug => id]
  $bySlug = [];        // slug => id (unique)

  foreach ($rows as $r) {
    $id = (int)$r['id'];
    $pid = ($r['parent_id'] !== null) ? (int)$r['parent_id'] : null;
    $slug = (string)$r['slug'];
    $pkey = ($pid === null) ? 0 : $pid;

    $byId[$id] = [
      'id' => $id,
      'name' => (string)$r['name'],
      'slug' => $slug,
      'parent_id' => $pid,
    ];

    if (!isset($children[$pkey])) $children[$pkey] = [];
    $children[$pkey][] = $id;

    if (!isset($byParentSlug[$pkey])) $byParentSlug[$pkey] = [];
    $byParentSlug[$pkey][$slug] = $id;

    $bySlug[$slug] = $id;
  }

  $cache = [
    'byId' => $byId,
    'children' => $children,
    'byParentSlug' => $byParentSlug,
    'bySlug' => $bySlug,
  ];
  return $cache;
}

function cms_category_id(PDO $pdo, $key) {
  if (is_int($key) || ctype_digit((string)$key)) {
    $id = (int)$key;
    return ($id > 0) ? $id : null;
  }

  $raw = trim((string)$key);
  if ($raw === '') return null;

  $idx = cms_categories_index($pdo);

  // path parent/child/grandchild
  if (strpos($raw, '/') !== false) {
    $parts = array_values(array_filter(array_map('trim', explode('/', trim($raw, '/'))), function($x){ return $x !== ''; }));
    if (!$parts) return null;

    // coba slugify tiap part (kalau user ngasih "Agenda Kegiatan")
    $norm = [];
    foreach ($parts as $p) {
      $sp = cms_slugify($p);
      $norm[] = ($sp !== '') ? $sp : $p;
    }

    $parentId = null;
    foreach ($norm as $slug) {
      $pkey = ($parentId === null) ? 0 : (int)$parentId;
      if (!isset($idx['byParentSlug'][$pkey][$slug])) return null;
      $parentId = (int)$idx['byParentSlug'][$pkey][$slug];
    }
    return $parentId;
  }

  // slug langsung (unique)
  if (isset($idx['bySlug'][$raw])) return (int)$idx['bySlug'][$raw];

  $slug2 = cms_slugify($raw);
  if ($slug2 !== '' && isset($idx['bySlug'][$slug2])) return (int)$idx['bySlug'][$slug2];

  return null;
}

function cms_category_descendant_ids(PDO $pdo, $rootId, $includeSelf = true) {
  $rootId = (int)$rootId;
  if ($rootId <= 0) return [];

  $idx = cms_categories_index($pdo);

  $out = [];
  $queue = [];

  if ($includeSelf) $out[] = $rootId;
  $queue[] = $rootId;

  while (!empty($queue)) {
    $cur = array_shift($queue);
    $pkey = (int)$cur;
    $kids = isset($idx['children'][$pkey]) ? $idx['children'][$pkey] : [];
    foreach ($kids as $kidId) {
      $kidId = (int)$kidId;
      $out[] = $kidId;
      $queue[] = $kidId;
    }
  }

  $out = array_values(array_unique($out));
  return $out;
}

function cms_posts_by_category(PDO $pdo, $categoryKey, array $opt = []) {
  $rootId = cms_category_id($pdo, $categoryKey);
  if (!$rootId) return [];

  $includeChildren = isset($opt['include_children']) ? (bool)$opt['include_children'] : true;
  $type   = isset($opt['type']) ? (string)$opt['type'] : 'article';
  $status = isset($opt['status']) ? (string)$opt['status'] : 'published';

  $limit  = isset($opt['limit']) ? (int)$opt['limit'] : 10;
  $offset = isset($opt['offset']) ? (int)$opt['offset'] : 0;
  if ($limit < 1) $limit = 10;
  if ($offset < 0) $offset = 0;

  $orderBy = isset($opt['order_by']) ? (string)$opt['order_by'] : 'created_at';
  $orderDir = isset($opt['order_dir']) ? strtoupper((string)$opt['order_dir']) : 'DESC';
  if ($orderDir !== 'ASC' && $orderDir !== 'DESC') $orderDir = 'DESC';

  $allowedOrder = [
    'sort_order' => 'p.sort_order',
    'created_at' => 'p.created_at',
    'id' => 'p.id',
  ];
  $orderSql = isset($allowedOrder[$orderBy]) ? $allowedOrder[$orderBy] : $allowedOrder['created_at'];

  $catIds = $includeChildren
    ? cms_category_descendant_ids($pdo, (int)$rootId, true)
    : [(int)$rootId];

  if (!$catIds) return [];

  $in = implode(',', array_fill(0, count($catIds), '?'));

  // LIMIT/OFFSET di-inject sebagai integer yang sudah di-cast (lebih kompatibel PDO MySQL)
  $sql = "
    SELECT DISTINCT
      p.id, p.title, p.slug, p.content, p.type, p.meta, p.youtube, p.thumbnail,
      p.sort_order, p.status, p.created_by, p.created_at, p.updated_at
    FROM posts p
    JOIN post_categories pc ON pc.post_id = p.id
    WHERE p.is_deleted = 0
      AND p.status = ?
      AND p.type = ?
      AND pc.category_id IN ($in)
    ORDER BY $orderSql $orderDir, p.id $orderDir
    LIMIT $limit OFFSET $offset
  ";

  $params = [$status, $type];
  foreach ($catIds as $cid) $params[] = (int)$cid;

  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);
  return $stmt->fetchAll(PDO::FETCH_ASSOC);
}