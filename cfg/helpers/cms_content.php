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

function cms_posts_fetch(PDO $pdo, array $opt = []): array {
  $type   = isset($opt['type']) ? (string)$opt['type'] : 'article';
  $status = isset($opt['status']) ? (string)$opt['status'] : 'published';

  $limit  = isset($opt['limit']) ? (int)$opt['limit'] : 10;
  $offset = isset($opt['offset']) ? (int)$opt['offset'] : 0;
  if ($limit < 1) $limit = 10;
  $limit = min(200, $limit);
  if ($offset < 0) $offset = 0;

  $orderBy = isset($opt['order_by']) ? (string)$opt['order_by'] : 'created_at';
  $orderDir = isset($opt['order_dir']) ? strtoupper((string)$opt['order_dir']) : 'DESC';
  if ($orderDir !== 'ASC' && $orderDir !== 'DESC') $orderDir = 'DESC';

  $isRandom = strtoupper($orderBy) === 'RAND()';
  if ($isRandom) {
    $orderSql = 'RAND()';
    $orderDir = ''; // RAND() takes no direction
  } else {
    $allowedOrder = [
      'sort_order' => 'p.sort_order',
      'created_at' => 'p.created_at',
      'id' => 'p.id',
      'updated_at' => 'p.updated_at',
      'title' => 'p.title',
    ];
    $orderSql = isset($allowedOrder[$orderBy]) ? $allowedOrder[$orderBy] : $allowedOrder['created_at'];
  }

  $tables = ['posts p'];
  $where = ["p.is_deleted = 0", "p.status = :cms_status", "p.type = :cms_type"];
  $params = [':cms_status' => $status, ':cms_type' => $type];

  // Category filter
  $catKey = $opt['category'] ?? null;
  if ($catKey !== null && $catKey !== '') {
    $rootId = cms_category_id($pdo, $catKey);
    if (!$rootId) return [];
    $includeChildren = isset($opt['include_children']) ? (bool)$opt['include_children'] : true;
    $catIds = $includeChildren
      ? cms_category_descendant_ids($pdo, (int)$rootId, true)
      : [(int)$rootId];
    if ($catIds) {
      $tables[] = 'post_categories pc';
      $placeholders = [];
      foreach (array_values($catIds) as $index => $cid) {
        $placeholder = ':cms_category_' . $index;
        $placeholders[] = $placeholder;
        $params[$placeholder] = (int)$cid;
      }
      $where[] = 'pc.post_id = p.id AND pc.category_id IN (' . implode(',', $placeholders) . ')';
    }
  }

  // Author filter
  if (array_key_exists('created_by', $opt) && $opt['created_by'] !== null) {
    $where[] = "p.created_by = :cms_created_by";
    $params[':cms_created_by'] = (int)$opt['created_by'];
  }

  // Date range filters
  if (!empty($opt['date_from'])) {
    $where[] = "p.created_at >= :cms_date_from";
    $params[':cms_date_from'] = (string)$opt['date_from'];
  }
  if (!empty($opt['date_to'])) {
    $where[] = "p.created_at <= :cms_date_to";
    $params[':cms_date_to'] = (string)$opt['date_to'];
  }

  $collectionContext = is_array($opt['collection_context'] ?? null) ? $opt['collection_context'] : [];
  if ($collectionContext !== [] && function_exists('collection_query_clauses')) {
    $collectionContext['table_alias'] = 'p';
    $clauses = collection_query_clauses(['where' => [], 'params' => []], $collectionContext);
    $where = array_merge($where, $clauses['where']);
    $params = array_merge($params, $clauses['params']);
  }

  $from = implode(' JOIN ', $tables);

  if ($isRandom) {
    $orderClause = 'RAND()';
  } else {
    $orderClause = "$orderSql $orderDir, p.id $orderDir";
  }

  $sql = "
    SELECT DISTINCT p.id, p.title, p.slug, p.content, p.type, p.meta, p.youtube, p.thumbnail,
           p.status, p.created_by, p.created_at, p.updated_at
    FROM $from
    WHERE " . implode(' AND ', $where) . "
    ORDER BY $orderClause
    LIMIT $limit OFFSET $offset
  ";

  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
  return $collectionContext !== [] && function_exists('collection_filter_rows')
    ? collection_filter_rows($rows, $collectionContext)
    : $rows;
}

function cms_posts_by_category(PDO $pdo, $categoryKey, array $opt = []) {
  $opt['category'] = $categoryKey;
  return cms_posts_fetch($pdo, $opt);
}
