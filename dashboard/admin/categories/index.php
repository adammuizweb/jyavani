<?php
declare(strict_types=1);

// /adiwira/admin/categories/?
require_once __DIR__ . '/../_deny.php';

if (!defined('DASHBOARD_CONTEXT') && !defined('ADAM_THEME')) {
    adiwira_admin_404();
}

require_once __DIR__ . '/../_guard.php';
require_once __DIR__ . '/../_notify.php';

[$uid] = adiwira_require_permission_scope($pdo, 'core.categories.read', false);
$readCondition = authorization_owner_scope_condition(
  $pdo,
  $uid,
  'core.categories.read',
  'c.created_by',
  'category_read'
);
if ($readCondition === null) {
  adiwira_render_404();
}

$page_toasts = function_exists('adiwira_collect_query_toasts')
    ? adiwira_collect_query_toasts()
    : [];

// filters
$search        = trim((string)($_GET['q'] ?? ''));
$filter_parent = (int)($_GET['parent'] ?? 0);
$filter_author = (int)($_GET['author'] ?? 0);
$listContext = [
  'schema' => 1,
  'type' => 'category',
  'actor_id' => $uid,
  'page' => 'admin/categories/index',
  'filter_form_id' => 'categories-list-filter',
  'search' => $search,
  'parent_id' => $filter_parent,
  'author_id' => $filter_author,
];

// pagination
$page_num = max(1, (int)($_GET['p'] ?? 1));
$per_page = 20;
$offset   = ($page_num - 1) * $per_page;

// query builder
$where  = ["c.is_deleted = 0"];
$where[] = '(' . $readCondition['sql'] . ')';
$params = $readCondition['params'];

if ($search !== '') {
  $where[] = "(c.name LIKE :search OR c.slug LIKE :search)";
  $params[':search'] = '%' . $search . '%';
}
if ($filter_parent > 0) {
  $where[] = "c.parent_id = :parent_id";
  $params[':parent_id'] = $filter_parent;
}
if ($filter_author > 0) {
  $where[] = "c.created_by = :created_by";
  $params[':created_by'] = $filter_author;
}

$where_sql = implode(' AND ', $where);

// ambil kategori yang match filter
$sql = "
  SELECT 
    c.id,
    c.name,
    c.slug,
    c.description,
    c.parent_id,
    c.created_at,
    c.updated_at,
    c.created_by,
    COALESCE(NULLIF(u.name, ''), NULLIF(u.username, ''), CAST(u.id AS CHAR)) AS created_by_label
  FROM categories c
  LEFT JOIN users u ON u.id = c.created_by
  WHERE $where_sql
  ORDER BY COALESCE(c.parent_id, 0) ASC, c.name ASC
";
$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) {
  $stmt->bindValue($k, $v);
}
$stmt->execute();
$allCategories = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Filter and bulk-parent choices stay stable while respecting the same read scope.
$optionStmt = $pdo->prepare(
  "SELECT c.id, c.name, c.slug, c.description, c.parent_id, c.created_at, c.updated_at, c.created_by,
          COALESCE(NULLIF(u.name, ''), NULLIF(u.username, ''), CAST(u.id AS CHAR)) AS created_by_label
   FROM categories c
   LEFT JOIN users u ON u.id = c.created_by
   WHERE c.is_deleted = 0 AND ({$readCondition['sql']})
   ORDER BY COALESCE(c.parent_id, 0) ASC, c.name ASC"
);
$optionStmt->execute($readCondition['params']);
$filterOptionCategories = $optionStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
$localizedFilterOptions = apply_filters('admin_category_list_rows', $filterOptionCategories, $listContext, $pdo);
if (is_array($localizedFilterOptions)) $filterOptionCategories = $localizedFilterOptions;

// jika search aktif, ambil semua ancestor agar tree tidak putus
if ($search !== '' && !empty($allCategories)) {
  $existingIds = array_map(fn($r) => (int)$r['id'], $allCategories);
  $needParents = [];

  foreach ($allCategories as $r) {
    $pid = $r['parent_id'];
    if ($pid !== null && $pid !== 0 && !in_array((int)$pid, $existingIds, true)) {
      $needParents[] = (int)$pid;
    }
  }
  $needParents = array_values(array_unique($needParents));

  while (!empty($needParents)) {
    $ancestorParams = $readCondition['params'];
    $ancestorPlaceholders = [];
    foreach ($needParents as $index => $parentId) {
      $parameter = ':category_ancestor_' . $index;
      $ancestorPlaceholders[] = $parameter;
      $ancestorParams[$parameter] = $parentId;
    }
    $placeholders = implode(',', $ancestorPlaceholders);
    $ancestorSql = "
      SELECT 
        c.id,
        c.name,
        c.slug,
        c.description,
        c.parent_id,
        c.created_at,
        c.updated_at,
        c.created_by,
        COALESCE(NULLIF(u.name, ''), NULLIF(u.username, ''), CAST(u.id AS CHAR)) AS created_by_label
      FROM categories c
      LEFT JOIN users u ON u.id = c.created_by
      WHERE c.id IN ($placeholders)
        AND c.is_deleted = 0
        AND ({$readCondition['sql']})
    ";
    $stmt2 = $pdo->prepare($ancestorSql);
    $stmt2->execute($ancestorParams);
    $rows = $stmt2->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $nextMissing = [];
    foreach ($rows as $r) {
      $rid = (int)$r['id'];
      if (!in_array($rid, $existingIds, true)) {
        $allCategories[] = $r;
        $existingIds[] = $rid;
        $pp = $r['parent_id'];
        if ($pp !== null && $pp !== 0 && !in_array((int)$pp, $existingIds, true)) {
          $nextMissing[] = (int)$pp;
        }
      }
    }
    $needParents = array_values(array_unique($nextMissing));
  }
}

$displayCategories = apply_filters('admin_category_list_rows', $allCategories, $listContext, $pdo);
if (is_array($displayCategories)) {
  $displayById = [];
  foreach ($displayCategories as $displayCategory) {
    if (!is_array($displayCategory)) continue;
    $displayId = (int)($displayCategory['id'] ?? 0);
    if ($displayId > 0) $displayById[$displayId] = $displayCategory;
  }
  foreach ($allCategories as &$category) {
    $display = $displayById[(int)$category['id']] ?? null;
    if (!is_array($display)) continue;
    foreach (['name', 'description'] as $field) {
      if (isset($display[$field]) && is_string($display[$field])) $category[$field] = $display[$field];
    }
    $displayUrl = trim((string)($display['display_url'] ?? ''));
    if ($displayUrl !== '' && str_starts_with($displayUrl, '/') && !str_starts_with($displayUrl, '//')
        && preg_match('/[\x00-\x1F\x7F]/', $displayUrl) !== 1) {
      $category['display_url'] = $displayUrl;
    }
  }
  unset($category);
}

// map by id + children
$catsById = [];
$children = [];
$visibleCategoryIds = array_fill_keys(array_map(static fn($category) => (int)$category['id'], $allCategories), true);
foreach ($allCategories as $r) {
  $id  = (int)$r['id'];
  $pid = ($r['parent_id'] === null) ? 0 : (int)$r['parent_id'];
  if ($pid > 0 && !isset($visibleCategoryIds[$pid])) {
    $pid = 0;
  }
  $r['parent_id'] = $pid;
  $catsById[$id] = $r;
  $children[$pid][] = $id;
}

// helper path
$categoryPathCache = [];
$buildCategoryPath = function(int $catId) use (&$catsById, &$categoryPathCache): ?string {
  if (isset($categoryPathCache[$catId])) return $categoryPathCache[$catId];
  if (!isset($catsById[$catId])) {
    $categoryPathCache[$catId] = null;
    return null;
  }

  $segments = [];
  $cur = $catId;
  $seen = [];

  while ($cur && isset($catsById[$cur]) && !in_array($cur, $seen, true)) {
    $seen[] = $cur;
    $slug = (string)($catsById[$cur]['slug'] ?? '');
    if ($slug !== '') array_unshift($segments, $slug);
    $cur = (int)($catsById[$cur]['parent_id'] ?? 0);
    if ($cur === 0) break;
  }

  if (empty($segments)) {
    $categoryPathCache[$catId] = null;
    return null;
  }

  $path = implode('/', $segments);
  $categoryPathCache[$catId] = $path;
  return $path;
};

// flatten tree
$flatCats = [];
$visited = [];
$traverseCategories = function(int $parentId = 0, int $depth = 0) use (&$children, &$catsById, &$flatCats, &$visited, &$traverseCategories): void {
  if (empty($children[$parentId])) return;
  foreach ($children[$parentId] as $cid) {
    if (isset($visited[$cid])) continue;
    $visited[$cid] = true;
    $item = $catsById[$cid];
    $item['depth'] = $depth;
    $flatCats[] = $item;
    $traverseCategories($cid, $depth + 1);
  }
};
$traverseCategories(0, 0);

// pagination after flatten
$total = count($flatCats);
$pages = max(1, (int)ceil($total / $per_page));
if ($page_num > $pages) $page_num = $pages;
$offset = ($page_num - 1) * $per_page;

$categories_list = array_slice($flatCats, $offset, $per_page);

// build parent options
$optionCatsById = [];
$optionChildren = [];
foreach ($filterOptionCategories as $category) {
  $optionId = (int)($category['id'] ?? 0);
  if ($optionId <= 0) continue;
  $optionCatsById[$optionId] = $category;
}
foreach ($optionCatsById as $optionId => $category) {
  $optionParentId = (int)($category['parent_id'] ?? 0);
  if ($optionParentId > 0 && !isset($optionCatsById[$optionParentId])) $optionParentId = 0;
  $optionChildren[$optionParentId][] = $optionId;
}
$parentOptions = [];
$visitedOptions = [];
$buildParentOptions = function(int $parentId = 0, int $depth = 0) use (&$optionChildren, &$optionCatsById, &$parentOptions, &$visitedOptions, &$buildParentOptions): void {
  if (empty($optionChildren[$parentId])) return;
  foreach ($optionChildren[$parentId] as $cid) {
    if (isset($visitedOptions[$cid])) continue;
    $visitedOptions[$cid] = true;
    $label = str_repeat('— ', $depth) . (string)($optionCatsById[$cid]['name'] ?? '');
    $parentOptions[] = ['id' => $cid, 'label' => $label];
    $buildParentOptions($cid, $depth + 1);
  }
};
$buildParentOptions(0, 0);

// Creator filters only expose identities already visible through category scope.
$authors = [];
foreach ($filterOptionCategories as $category) {
  $creatorId = (int)($category['created_by'] ?? 0);
  if ($creatorId <= 0 || isset($authors[$creatorId])) continue;
  $authors[$creatorId] = [
    'id' => $creatorId,
    'label' => (string)($category['created_by_label'] ?? $creatorId),
  ];
}
usort($authors, static fn(array $a, array $b): int => strcasecmp($a['label'], $b['label']));

// base
$base = ADMIN_BASE_PATH;
$_catPath = function_exists('get_category_path') ? get_category_path($pdo) : 'category';
$catBase = $_catPath !== '' ? '/' . $_catPath . '/' : '/';

$canCreate = user_can($pdo, $uid, 'core.categories.create');
$canBulkUpdate = user_permission_scope($pdo, $uid, 'core.categories.update') !== null;
$canBulkTrash = user_permission_scope($pdo, $uid, 'core.categories.trash') !== null;
$canBulk = $canBulkUpdate || $canBulkTrash;
$canOpenTrash = user_permission_scope($pdo, $uid, 'core.categories.restore') !== null
  || user_permission_scope($pdo, $uid, 'core.categories.purge') !== null;

$currentQuery = $_GET;
$currentQuery['page'] = 'admin/categories/index';
$currentReturnTo = $base . '/?' . http_build_query($currentQuery);

$addHref = $base . '/?' . http_build_query([
  'page' => 'admin/categories/add',
  'return_to' => $currentReturnTo,
]);

if (!function_exists('build_pagination_items')) {
  function build_pagination_items(int $current, int $total, int $max_visible = 9): array {
    if ($total <= $max_visible) return range(1, $total);

    $items = [];
    $reserved = 6;
    $middle_slots = max(1, $max_visible - $reserved);
    $half = (int)floor($middle_slots / 2);
    $start = max(3, $current - $half);
    $end = min($total - 2, $current + $half);

    if ($start === 3) $end = min($total - 2, $start + $middle_slots - 1);
    if ($end === $total - 2) $start = max(3, $end - $middle_slots + 1);

    $items[] = 1;
    $items[] = 2;
    if ($start > 3) $items[] = '...';
    for ($i = $start; $i <= $end; $i++) $items[] = $i;
    if ($end < $total - 2) $items[] = '...';
    $items[] = $total - 1;
    $items[] = $total;

    while (count($items) > $max_visible) {
      for ($i = 0; $i < count($items); $i++) {
        if (is_int($items[$i]) && !in_array($items[$i], [1,2,$total-1,$total], true)) {
          array_splice($items, $i, 1);
          break;
        }
      }
    }
    return $items;
  }
}
$paging_items = build_pagination_items($page_num, $pages, 9);
$activeFilterCount = ($filter_parent > 0 ? 1 : 0) + ($filter_author > 0 ? 1 : 0);
$hasActiveQuery = $search !== '' || $activeFilterCount > 0;
$activeParentLabel = '';
foreach ($parentOptions as $option) {
  if ((int)$option['id'] === $filter_parent) {
    $activeParentLabel = (string)(preg_replace('/^(?:— )+/u', '', (string)$option['label']) ?? $option['label']);
    break;
  }
}
$activeAuthorLabel = '';
foreach ($authors as $author) {
  if ((int)$author['id'] === $filter_author) {
    $activeAuthorLabel = (string)$author['label'];
    break;
  }
}
$filterUrlWithout = static function (string $key) use ($base): string {
  $query = $_GET;
  unset($query[$key], $query['p']);
  $query['page'] = 'admin/categories/index';
  return $base . '/?' . http_build_query($query);
};
?>

<section class="adam-card posts-list-card">
  <div class="posts-toolbar">
    <div class="posts-toolbar-head">
      <div class="posts-toolbar-title"><h2 class="page-heading"><?= _e('Categories') ?></h2></div>
      <div class="posts-toolbar-actions">
        <div class="posts-toolbar-extensions"><?php do_action('admin_content_list_filters', $listContext, $pdo); ?></div>
        <?php if ($canCreate): ?><a class="adam-button toolbar-add posts-toolbar-add" href="<?= htmlspecialchars($addHref, ENT_QUOTES, 'UTF-8') ?>"><?= svg_ico('plus') ?><span><?=_e('Add Category')?></span></a><?php endif; ?>
        <?php if ($canOpenTrash): ?><a class="adam-att toolbar-trash posts-toolbar-trash" href="<?= htmlspecialchars($base . '/?page=admin/bin/category/index', ENT_QUOTES, 'UTF-8') ?>" title="<?= htmlspecialchars(__('Trash'), ENT_QUOTES, 'UTF-8') ?>"><?= svg_ico('trash-2') ?><span class="posts-toolbar-trash-label"><?=_e('Trash')?></span></a><?php endif; ?>
      </div>
    </div>

    <div class="posts-command-bar posts-command-bar--no-columns">
      <form method="get" class="toolbar-filter posts-filter-shell" id="categories-list-filter">
        <input type="hidden" name="page" value="admin/categories/index">
        <div class="posts-search-control">
          <label class="sr-only" for="categories-search"><?=_e('Search')?></label>
          <input id="categories-search" type="search" name="q" placeholder="<?=_e('Search categories…')?>" value="<?=htmlspecialchars($search, ENT_QUOTES, 'UTF-8')?>" class="inp posts-search-input">
          <?php if ($search !== ''): ?><a class="posts-search-clear" href="<?= htmlspecialchars($filterUrlWithout('q'), ENT_QUOTES, 'UTF-8') ?>" aria-label="<?= htmlspecialchars(__('Reset') . ' ' . __('Search'), ENT_QUOTES, 'UTF-8') ?>" title="<?= htmlspecialchars(__('Reset') . ' ' . __('Search'), ENT_QUOTES, 'UTF-8') ?>"><?= svg_ico('x') ?></a><?php endif; ?>
          <button type="submit" class="posts-search-submit" aria-label="<?= htmlspecialchars(__('Search'), ENT_QUOTES, 'UTF-8') ?>" title="<?= htmlspecialchars(__('Search'), ENT_QUOTES, 'UTF-8') ?>"><?= svg_ico('search') ?></button>
        </div>
        <details class="posts-filter-disclosure<?= $activeFilterCount > 0 ? ' has-active' : '' ?>">
          <summary class="posts-filter-trigger"><?= svg_ico('list-collapse') ?><span><?=_e('Filters')?></span><?php if ($activeFilterCount > 0): ?><span class="posts-filter-count"><?= $activeFilterCount ?></span><?php endif; ?><span class="posts-filter-chevron" aria-hidden="true"><?= svg_ico('chevron-down') ?></span></summary>
          <div class="posts-filter-panel">
            <label class="posts-filter-field">
              <span><?=_e('Parent')?></span>
              <select name="parent" class="inp">
                <option value="0"><?=_e('All Parents')?></option>
                <?php foreach ($parentOptions as $opt): ?><option value="<?=(int)$opt['id']?>" <?=$filter_parent === (int)$opt['id'] ? 'selected' : ''?>><?=htmlspecialchars($opt['label'], ENT_QUOTES, 'UTF-8')?></option><?php endforeach; ?>
              </select>
            </label>
            <label class="posts-filter-field">
              <span><?=_e('Creator')?></span>
              <select name="author" class="inp">
                <option value="0"><?= _e('All Creators') ?></option>
                <?php foreach ($authors as $a): ?><option value="<?= (int)$a['id'] ?>" <?= $filter_author === (int)$a['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string)$a['label'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?>
              </select>
            </label>
            <div class="posts-filter-actions">
              <button type="submit" class="adam-button posts-filter-apply"><?= svg_ico('circle-check') ?><span><?=_e('Apply filters')?></span></button>
              <?php if ($hasActiveQuery): ?><a href="<?= htmlspecialchars($base . '/?page=admin/categories/index', ENT_QUOTES, 'UTF-8') ?>" class="adam-cancle posts-filter-reset"><?= svg_ico('rotate-ccw') ?><span><?=_e('Reset')?></span></a><?php endif; ?>
            </div>
          </div>
        </details>
      </form>

      <?php if ($canBulk): ?>
        <form id="categoriesBulkForm" class="posts-bulk-form" method="post" action="<?= htmlspecialchars($base . '/admin/categories/bulk_action.php', ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="return_to" value="<?= htmlspecialchars($currentReturnTo, ENT_QUOTES, 'UTF-8') ?>">
          <div class="bulk-bar posts-bulk-bar">
            <select id="bulkActionCategories" name="action" class="inp posts-bulk-action">
              <option value=""><?=_e('-- Bulk action --')?></option>
              <?php if ($canBulkTrash): ?><option value="delete"><?= _e('Delete') ?></option><?php endif; ?>
              <?php if ($canBulkUpdate): ?><option value="change_parent"><?= _e('Change Parent') ?></option><?php endif; ?>
            </select>
            <button type="submit" class="adam-button posts-bulk-apply"><?= svg_ico('circle-check') ?><span><?= _e('Apply') ?></span></button>
          </div>
          <div id="bulkOptionsPanelCategories" class="posts-bulk-options" hidden>
            <label id="bulkParentOptionCategories" class="posts-bulk-option" hidden>
              <span><?=_e('Change Parent')?></span>
              <select id="bulkParentCategories" name="parent_id" class="inp">
                <option value=""><?= _e('-- Select Parent --') ?></option>
                <option value="0"><?= _e('(No Parent)') ?></option>
                <?php foreach ($parentOptions as $opt): ?><option value="<?= (int)$opt['id'] ?>"><?= htmlspecialchars($opt['label'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?>
              </select>
            </label>
          </div>
        </form>
      <?php endif; ?>
    </div>

    <?php if ($canBulk): ?><span id="bulkSelectionCountCategories" class="bulk-selection-count posts-bulk-selection-count" role="status" aria-live="polite" hidden><span class="bsc-number">0</span><span class="bsc-label"><?=_e('Category Selected')?></span></span><?php endif; ?>
    <?php if ($activeFilterCount > 0): ?>
      <div class="posts-filter-chips" aria-label="<?= htmlspecialchars(__('Filters'), ENT_QUOTES, 'UTF-8') ?>">
        <?php if ($filter_parent > 0 && $activeParentLabel !== ''): ?><a class="posts-filter-chip" href="<?= htmlspecialchars($filterUrlWithout('parent'), ENT_QUOTES, 'UTF-8') ?>" title="<?= htmlspecialchars(__('Reset') . ' ' . __('Parent'), ENT_QUOTES, 'UTF-8') ?>"><span><?=_e('Parent')?>:</span><strong><?= htmlspecialchars($activeParentLabel, ENT_QUOTES, 'UTF-8') ?></strong><?= svg_ico('x') ?></a><?php endif; ?>
        <?php if ($filter_author > 0 && $activeAuthorLabel !== ''): ?><a class="posts-filter-chip" href="<?= htmlspecialchars($filterUrlWithout('author'), ENT_QUOTES, 'UTF-8') ?>" title="<?= htmlspecialchars(__('Reset') . ' ' . __('Creator'), ENT_QUOTES, 'UTF-8') ?>"><span><?=_e('Creator')?>:</span><strong><?= htmlspecialchars($activeAuthorLabel, ENT_QUOTES, 'UTF-8') ?></strong><?= svg_ico('x') ?></a><?php endif; ?>
      </div>
    <?php endif; ?>
  </div>

  <div class="adam-table-wrapper">
    <table class="adam-table mt-8">
      <thead>
        <tr>
          <th class="th-narrow"><?php if ($canBulk): ?><input type="checkbox" id="selectAllCategories" class="adam-choice" aria-label="<?= htmlspecialchars(__('Select all on page'), ENT_QUOTES, 'UTF-8') ?>"><?php endif; ?></th>
          <th><?= _e('Name') ?></th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($categories_list)): ?>
          <tr><td class="empty-state" colspan="2"><?= _e('No categories yet.') ?></td></tr>
        <?php else: ?>
          <?php foreach ($categories_list as $cat):
            $depth  = max(0, (int)$cat['depth']);
            $catId  = (int)$cat['id'];
            $categoryOwnerId = (int)($cat['created_by'] ?? 0);
            $canUpdateCategory = user_can($pdo, $uid, 'core.categories.update', ['owner_id' => $categoryOwnerId]);
            $canTrashCategory = user_can($pdo, $uid, 'core.categories.trash', ['owner_id' => $categoryOwnerId]);
            $canSelectCategory = $canUpdateCategory || $canTrashCategory;

            $levelClass = 'cat-level-' . min($depth, 3);
            $icon = match ($depth) {
              0 => svg_ico('folder', 'cat-svg-icon'),
              1 => svg_ico('folder-open', 'cat-svg-icon'),
              default => svg_ico('file-text', 'cat-svg-icon'),
            };
            $indentHtml = '<span class="cat-indent ' . $levelClass . '">' . $icon . '</span>';

            $catPath = $buildCategoryPath($catId);
            $displayUrl = trim((string)($cat['display_url'] ?? ''));
            if ($displayUrl !== '') {
              $href = $displayUrl;
              $nameHtml = '<a class="adam-link" href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars((string)$cat['name'], ENT_QUOTES, 'UTF-8') . '</a>';
            } elseif ($catPath !== null && $catPath !== '') {
              $segments = array_map('rawurlencode', explode('/', $catPath));
              $href = $catBase . implode('/', $segments) . '/';
              $nameHtml = '<a class="adam-link" href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars((string)$cat['name'], ENT_QUOTES, 'UTF-8') . '</a>';
            } else {
              $nameHtml = htmlspecialchars((string)$cat['name'], ENT_QUOTES, 'UTF-8');
            }

            $editHref = $base . '/?' . http_build_query([
              'page' => 'admin/categories/edit',
              'id' => $catId,
              'return_to' => $currentReturnTo,
            ]);
          ?>
            <?php
              $showCoreEdit = !$canUpdateCategory || !function_exists('content_core_edit_action_visible')
                || content_core_edit_action_visible($pdo, $cat, [
                    'schema' => 1,
                    'content_type' => 'category',
                    'actor_id' => $uid,
                    'return_to' => $currentReturnTo,
                    'can_update' => $canUpdateCategory,
                  ]);
            ?>
            <tr>
              <td class="td-center">
                <?php if ($canBulk && $canSelectCategory): ?>
                  <input type="checkbox" class="bulkCheckboxCategory adam-choice" name="ids[]" value="<?= $catId ?>" form="categoriesBulkForm">
                <?php else: ?>
                  &mdash;
                <?php endif; ?>
              </td>

              <td>
                <div class="title-wrap">
                  <?= $indentHtml . $nameHtml ?>
                  <div class="row-actions">
                    <?php if ($canUpdateCategory && $showCoreEdit): ?><a class="adam-ubah" href="<?= htmlspecialchars($editHref, ENT_QUOTES, 'UTF-8') ?>"><?= svg_ico('pen', '', ['class' => 'lucide-icon']) ?><?=_e('Edit')?></a><?php endif; ?>
                    <?php do_action('admin_category_row_actions', $cat, [
                      'content_type' => 'category',
                      'actor_id' => $uid,
                      'return_to' => $currentReturnTo,
                      'can_update' => $canUpdateCategory,
                      'can_trash' => $canTrashCategory,
                    ], $pdo); ?>
                    <?php if ($canTrashCategory): ?>
                      <span class="muted-divider">|</span>
                      <button type="button"
                              class="adam-hapus js-category-delete"
                              data-id="<?= $catId ?>"
                              data-name="<?= htmlspecialchars((string)($cat['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                              data-return-to="<?= htmlspecialchars($currentReturnTo, ENT_QUOTES, 'UTF-8') ?>">
                        <?= svg_ico('trash-2', '', ['class' => 'lucide-icon']) ?><?=_e('Delete')?>
                      </button>
                    <?php endif; ?>
                  </div>
                </div>
              </td>

            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <?php if ($pages > 1): ?>
    <nav class="adam-pagination pagination-wrap">
      <?php foreach ($paging_items as $item):
        if ($item === '...') { echo '<span class="dots">…</span> '; continue; }
        $i = (int)$item;
        $query = $_GET;
        $query['p'] = $i;
        $link = $base . '/?' . http_build_query($query);
      ?>
        <?php if ($i === $page_num): ?>
          <strong><?= $i ?></strong>
        <?php else: ?>
          <a href="<?= htmlspecialchars($link, ENT_QUOTES, 'UTF-8') ?>"><?= $i ?></a>
        <?php endif; ?>
      <?php endforeach; ?>
    </nav>
  <?php endif; ?>

  <?php if ($canBulkTrash): ?>
    <form id="newnotif-category-delete-form" method="post" action="<?= htmlspecialchars($base . '/admin/categories/delete.php', ENT_QUOTES, 'UTF-8') ?>" class="hide">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="id" id="newnotif-category-delete-id">
      <input type="hidden" name="return_to" id="newnotif-category-delete-return-to" value="<?= htmlspecialchars($currentReturnTo, ENT_QUOTES, 'UTF-8') ?>">
    </form>
  <?php endif; ?>
</section>

<?php
if (!empty($page_toasts) && function_exists('adiwira_bootstrap_toasts_script')) {
  echo adiwira_bootstrap_toasts_script($page_toasts);
}
?>

<script>
(function(){
  const selectAll = document.getElementById('selectAllCategories');
  const bulkForm = document.getElementById('categoriesBulkForm');
  const bulkAction = document.getElementById('bulkActionCategories');
  const bulkOptionsPanel = document.getElementById('bulkOptionsPanelCategories');
  const bulkParentOption = document.getElementById('bulkParentOptionCategories');
  const bulkParent = document.getElementById('bulkParentCategories');
  const bulkSelectionCount = document.getElementById('bulkSelectionCountCategories');
  const deleteForm = document.getElementById('newnotif-category-delete-form');
  const deleteIdInput = document.getElementById('newnotif-category-delete-id');
  const deleteReturnTo = document.getElementById('newnotif-category-delete-return-to');

  function toast(type, message, title){
    if (window.NewNotifToast && typeof window.NewNotifToast.show === 'function') {
      window.NewNotifToast.show({ type, title, message });
      return;
    }
    alert(message);
  }

  function ask(variant, opts){
    if (window.NewNotifConfirm) {
      if (variant === 'danger' && typeof window.NewNotifConfirm.danger === 'function') {
        return window.NewNotifConfirm.danger(opts);
      }
      if (typeof window.NewNotifConfirm.warning === 'function') {
        return window.NewNotifConfirm.warning(opts);
      }
    }
    return Promise.resolve(window.confirm(opts.message || '<?=__('Continue this action?')?>'));
  }

  function checkedCount(){
    return document.querySelectorAll('.bulkCheckboxCategory:checked').length;
  }

  function updateSelectionCount(){
    const checkboxes = Array.from(document.querySelectorAll('.bulkCheckboxCategory'));
    const count = checkboxes.filter(function(cb){ return cb.checked; }).length;
    if (selectAll) {
      selectAll.checked = checkboxes.length > 0 && count === checkboxes.length;
      selectAll.indeterminate = count > 0 && count < checkboxes.length;
      selectAll.disabled = checkboxes.length === 0;
    }
    if (!bulkSelectionCount) return;
    const number = bulkSelectionCount.querySelector('.bsc-number');
    const label = bulkSelectionCount.querySelector('.bsc-label');
    if (number) number.textContent = String(count);
    if (label) label.textContent = count === 1 ? <?= json_encode(__('Category Selected')) ?> : <?= json_encode(__('Categories Selected')) ?>;
    bulkSelectionCount.hidden = count === 0;
    if (count > 0) {
      bulkSelectionCount.classList.remove('is-pulse');
      void bulkSelectionCount.offsetWidth;
      bulkSelectionCount.classList.add('is-pulse');
    }
  }

  function getBulkSummary(){
    const action = bulkAction ? bulkAction.value : '';
    const count = checkedCount();

    if (!action) {
      return { ok:false, message: <?= json_encode(__('Select a bulk action first.')) ?> };
    }

    if (count < 1) {
      return { ok:false, message: <?= json_encode(__('Select at least one category.')) ?> };
    }

    if (action === 'delete') {
      return {
        ok: true,
        variant: 'danger',
        title: <?= json_encode(__('Delete selected categories')) ?>,
        message: <?= json_encode(__('')) ?> + count + '<?=__(' categories will be moved to trash. Continue?')?>',
        confirmText: <?= json_encode(__('Yes, delete')) ?>
      };
    }

    if (action === 'change_parent') {
      return {
        ok: true,
        variant: 'warning',
        title: <?= json_encode(__('Change parent category')) ?>,
        message: <?= json_encode(__('Change parent of ')) ?> + count + '<?=__(' categories?')?>',
        confirmText: <?= json_encode(__('Yes, change')) ?>
      };
    }

    return {
      ok: true,
      variant: 'warning',
      title: <?= json_encode(__('Confirm bulk action')) ?>,
      message: <?= json_encode(__('Execute action for ')) ?> + count + '<?=__(' categories?')?>',
      confirmText: <?= json_encode(__('Proceed')) ?>
    };
  }

  if (selectAll) {
    selectAll.addEventListener('change', function(){
      const checked = !!this.checked;
      document.querySelectorAll('.bulkCheckboxCategory').forEach(function(cb){
        cb.checked = checked;
      });
      updateSelectionCount();
    });
  }

  document.querySelectorAll('.bulkCheckboxCategory').forEach(function(cb){
    cb.addEventListener('change', updateSelectionCount);
  });
  updateSelectionCount();

  if (bulkAction) {
    const toggleBulkExtras = function(){
      const active = bulkAction.value === 'change_parent';
      if (bulkParentOption) bulkParentOption.hidden = !active;
      if (bulkOptionsPanel) bulkOptionsPanel.hidden = !active;
    };
    bulkAction.addEventListener('change', toggleBulkExtras);
    toggleBulkExtras();
  }

  document.querySelectorAll('.js-category-delete').forEach(function(btn){
    btn.addEventListener('click', function(){
      const id = this.getAttribute('data-id') || '';
      const name = this.getAttribute('data-name') || '<?=__('this category')?>';
      const returnTo = this.getAttribute('data-return-to') || '';

      ask('danger', {
        title: <?= json_encode(__('Delete confirmation')) ?>,
        message: <?= json_encode(__('Delete category "')) ?> + name + '<?=__('"? Category will be moved to trash.')?>',
        confirmText: <?= json_encode(__('Yes, delete')) ?>,
        cancelText: <?= json_encode(__('Cancel')) ?>
      }).then(function(ok){
        if (!ok) return;
        if (!deleteForm || !deleteIdInput) return;
        deleteIdInput.value = id;
        if (deleteReturnTo) deleteReturnTo.value = returnTo;
        deleteForm.submit();
      });
    });
  });

  if (bulkForm) {
    let bulkConfirmed = false;

    bulkForm.addEventListener('submit', function(ev){
      if (bulkConfirmed) {
        bulkConfirmed = false;
        return;
      }

      ev.preventDefault();
      const summary = getBulkSummary();

      if (!summary.ok) {
        toast('error', summary.message, <?= json_encode(__('Bulk action failed')) ?>);
        return;
      }

      ask(summary.variant || 'warning', {
        title: summary.title,
        message: summary.message,
        confirmText: summary.confirmText || '<?=__('Continue')?>',
        cancelText: <?= json_encode(__('Cancel')) ?>
      }).then(function(ok){
        if (!ok) return;
        bulkConfirmed = true;
        bulkForm.submit();
      });
    });
  }
})();
</script>

<style>
.cat-indent{
  display:inline-flex;
  align-items:center;
  width:32px;
  justify-content:center;
  margin-right:4px;
  opacity:.85;
}
.cat-level-0{ font-weight:600; color:#1f3a5f; }
.cat-level-1{ margin-left:18px; color:#2c5282; }
.cat-level-2{ margin-left:36px; color:#4a5568; }
.cat-level-3{ margin-left:54px; color:#6b7280; }
.theme-dark .cat-level-0{ color:var(--adam-text); }
.theme-dark .cat-level-1{ color:var(--adam-muted); }
.theme-dark .cat-level-2{ color:var(--adam-muted); opacity:.8; }
.theme-dark .cat-level-3{ color:var(--adam-muted); opacity:.65; }
tbody tr:hover .cat-indent{ opacity:1; }

</style>
