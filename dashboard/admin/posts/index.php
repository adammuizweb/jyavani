<?php
// /adiwira/admin/posts/?
require_once __DIR__ . '/../_deny.php';

if (!defined('DASHBOARD_CONTEXT') && !defined('ADAM_THEME')) {
    adiwira_admin_404();
}

require_once __DIR__ . '/../_guard.php';
require_once __DIR__ . '/../_notify.php';

[$uid, $role] = adiwira_require_role($pdo, ['author', 'editor', 'admin'], false);

// dukung query toast lama bila masih ada route lama yang kirim ?msg= / ?err=
$page_toasts = function_exists('adiwira_collect_query_toasts')
    ? adiwira_collect_query_toasts()
    : [];

$filter_status   = $_GET['status'] ?? '';
$filter_category = $_GET['category'] ?? '';
$search          = trim($_GET['q'] ?? '');

$page_num = max(1, (int)($_GET['p'] ?? 1));
$per_page = 15;
$offset   = ($page_num - 1) * $per_page;

$where = ["p.is_deleted = 0", "p.type = 'article'"];
$params = [];

if (in_array($role, ['author','editor'], true)) {
    $where[] = "p.created_by = :uid";
    $params[':uid'] = $uid;
}

if ($filter_status !== '') {
    $where[] = "p.status = :status";
    $params[':status'] = $filter_status;
}

if ($filter_category !== '') {
    $where[] = "c.id = :category_id";
    $params[':category_id'] = $filter_category;
}

if ($search !== '') {
    $where[] = "(p.title LIKE :search OR p.slug LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}

$where_sql = implode(' AND ', $where);

$count_sql = "
SELECT COUNT(DISTINCT p.id)
FROM posts p
LEFT JOIN post_categories pc ON pc.post_id = p.id
LEFT JOIN categories c ON c.id = pc.category_id AND c.is_deleted = 0
WHERE $where_sql
";
$totalStmt = $pdo->prepare($count_sql);
$totalStmt->execute($params);
$total = (int)$totalStmt->fetchColumn();
$pages = max(1, (int)ceil($total / $per_page));

$listSelect = apply_filters('post_list_select', '', $where_sql);
$listJoin = apply_filters('post_list_join', '', $where_sql);

$sql = "
SELECT
  p.id, p.title, p.slug, p.status, p.created_at,
  u.name AS created_by,
  u.username AS author_username,
  GROUP_CONCAT(DISTINCT c.name SEPARATOR ', ') AS categories,
  GROUP_CONCAT(DISTINCT c.id SEPARATOR ',') AS category_ids
  $listSelect
FROM posts p
LEFT JOIN post_categories pc ON pc.post_id = p.id
LEFT JOIN categories c ON c.id = pc.category_id AND c.is_deleted = 0
LEFT JOIN users u ON u.id = p.created_by
$listJoin
WHERE $where_sql
GROUP BY p.id
ORDER BY p.created_at DESC
LIMIT :limit OFFSET :offset
";
$stmt = $pdo->prepare($sql);
foreach ($params as $key => $val) {
    $stmt->bindValue($key, $val);
}
$stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

$allCatsStmt = $pdo->query("SELECT id, slug, parent_id, name FROM categories WHERE is_deleted = 0");
$allCatsRows = $allCatsStmt->fetchAll(PDO::FETCH_ASSOC);
$catsMap = [];
foreach ($allCatsRows as $r) {
    $catsMap[(int)$r['id']] = [
        'slug' => $r['slug'],
        'parent_id' => $r['parent_id'] === null ? null : (int)$r['parent_id'],
        'name' => $r['name'],
    ];
}

$categoryPathCache = [];
function build_category_path(array $catsMap, int $catId, array &$cache) : ?string {
    if (isset($cache[$catId])) {
        return $cache[$catId];
    }

    if (!isset($catsMap[$catId])) {
        $cache[$catId] = null;
        return null;
    }

    $segments = [];
    $cur = $catId;
    $seen = [];
    while ($cur !== null && isset($catsMap[$cur]) && !in_array($cur, $seen, true)) {
        $seen[] = $cur;
        array_unshift($segments, $catsMap[$cur]['slug']);
        $cur = $catsMap[$cur]['parent_id'] ?? null;
    }

    if (empty($segments)) {
        $cache[$catId] = null;
        return null;
    }

    $path = implode('/', $segments);
    $cache[$catId] = $path;
    return $path;
}

if (!function_exists('cat_hue')) {
    function cat_hue(int $id): int {
        return ($id * 57) % 360;
    }
}

$catStmt = $pdo->query("SELECT id, name FROM categories WHERE is_deleted = 0 ORDER BY name ASC");
$categories = $catStmt->fetchAll(PDO::FETCH_ASSOC);

$authorsStmt = $pdo->query("
    SELECT id, name, username
    FROM users
    WHERE is_deleted = 0
      AND is_locked = 0
    ORDER BY name ASC, username ASC
");
$authors = $authorsStmt->fetchAll(PDO::FETCH_ASSOC);

$base = ADMIN_BASE_PATH;
$_catPath = function_exists('get_category_path') ? get_category_path($pdo) : 'category';
$catBase = $_catPath !== '' ? '/' . $_catPath . '/' : '/';
$canBulk = in_array($role, ['admin','editor','author'], true);

$currentQuery = $_GET;
$currentQuery['page'] = 'admin/posts/index';
$currentReturnTo = $base . '/?' . http_build_query($currentQuery);
$addHref = $base . '/?' . http_build_query([
    'page' => 'admin/posts/add',
    'return_to' => $currentReturnTo,
]);

function build_pagination_items(int $current, int $total, int $max_visible = 9): array {
    if ($total <= $max_visible) {
        return range(1, $total);
    }

    $items = [];
    $reserved = 6;
    $middle_slots = max(1, $max_visible - $reserved);

    $half = (int)floor($middle_slots / 2);
    $start = max(3, $current - $half);
    $end = min($total - 2, $current + $half);

    if ($start === 3) {
        $end = min($total - 2, $start + $middle_slots - 1);
    }
    if ($end === $total - 2) {
        $start = max(3, $end - $middle_slots + 1);
    }

    $items[] = 1;
    $items[] = 2;

    if ($start > 3) {
        $items[] = '...';
    }

    for ($i = $start; $i <= $end; $i++) {
        $items[] = $i;
    }

    if ($end < $total - 2) {
        $items[] = '...';
    }

    $items[] = $total - 1;
    $items[] = $total;

    while (count($items) > $max_visible) {
        for ($i = 0; $i < count($items); $i++) {
            if (is_int($items[$i]) && $items[$i] !== 1 && $items[$i] !== 2 && $items[$i] !== $total - 1 && $items[$i] !== $total) {
                array_splice($items, $i, 1);
                break;
            }
        }
    }

    return $items;
}

$paging_items = build_pagination_items($page_num, $pages, 9);
?>

<section class="adam-card">
  <div class="toolbar-top">
    <h2 class="page-heading"><?=_e('Post')?></h2>

    <form method="get" class="toolbar-filter">
      <input type="hidden" name="page" value="admin/posts/index">
      <input type="text" name="q" placeholder="<?= _e('Search…') ?>" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>" class="inp">

      <select name="status" class="inp">
        <option value=""><?= _e('All Status') ?></option>
        <option value="draft" <?= $filter_status==='draft'?'selected':'' ?>><?=_e('Draft')?></option>
        <option value="published" <?= $filter_status==='published'?'selected':'' ?>><?=_e('Published')?></option>
        <option value="private" <?= $filter_status==='private'?'selected':'' ?>><?=_e('Private')?></option>
      </select>

      <select name="category" class="inp">
        <option value=""><?= _e('All Categories') ?></option>
        <?php foreach ($categories as $cat): ?>
          <option value="<?= (int)$cat['id'] ?>" <?= $filter_category==$cat['id']?'selected':'' ?>>
            <?= htmlspecialchars($cat['name'], ENT_QUOTES, 'UTF-8') ?>
          </option>
        <?php endforeach; ?>
      </select>

      <button type="submit" class="adam-button"><?= _e('Apply') ?></button>
      <a href="<?= htmlspecialchars($base . '/?page=admin/posts/index', ENT_QUOTES, 'UTF-8') ?>" class="adam-cancle"><?=_e('Reset')?></a>
    </form>

    <a class="adam-button toolbar-add" href="<?= htmlspecialchars($addHref, ENT_QUOTES, 'UTF-8') ?>"><?=_e('+ Add Article')?></a>
    <?php if ($role === 'admin') : ?>
      <a class="adam-att toolbar-trash" href="<?= htmlspecialchars($base . '/?page=admin/bin/article/index', ENT_QUOTES, 'UTF-8') ?>"><?= svg_ico('trash-2') ?> <?=_e('Trash')?></a>
    <?php endif; ?>
  </div>

  <?php if ($canBulk): ?>
  <form id="bulkForm" method="post" action="<?= htmlspecialchars($base . '/admin/posts/bulk_action.php', ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="return_to" value="<?= htmlspecialchars($currentReturnTo, ENT_QUOTES, 'UTF-8') ?>">

    <div class="bulk-bar">
      <label class="check-row">
        <input type="checkbox" id="selectAll"> <?=_e('Select all on page')?>
      </label>

      <select id="bulkAction" name="action" class="inp">
        <option value=""><?=_e('-- Bulk action --')?></option>
        <option value="delete"><?= _e('Delete') ?></option>
        <option value="change_status"><?= _e('Change Status') ?></option>
        <option value="change_categories"><?= _e('Manage Categories') ?></option>
        <?php if ($role === 'admin'): ?>
          <option value="change_author"><?= _e('Change Author') ?></option>
          <option value="change_date"><?= _e('Change Date') ?></option>
        <?php endif; ?>
      </select>

      <select id="bulkStatus" name="status" class="inp" style="display:none;">
        <option value="draft"><?=_e('Draft')?></option>
        <option value="published"><?=_e('Published')?></option>
        <option value="private"><?=_e('Private')?></option>
      </select>

      <?php if ($role === 'admin'): ?>
      <select id="bulkAuthor" name="author_id" class="inp" style="display:none;">
        <option value=""><?= _e('-- Select Author --') ?></option>
        <?php foreach ($authors as $a):
          $label = $a['name'] ?: ($a['username'] ?: $a['id']);
        ?>
          <option value="<?= (int)$a['id'] ?>"><?= htmlspecialchars((string)$label, ENT_QUOTES, 'UTF-8') ?></option>
        <?php endforeach; ?>
      </select>
      <?php endif; ?>

      <select id="bulkCatMode" name="cat_mode" class="inp" style="display:none;">
        <option value="add"><?= _e('Add') ?></option>
        <option value="remove"><?= _e('Delete') ?></option>
        <option value="toggle"><?=_e('Toggle')?></option>
      </select>

      <div id="bulkCategoriesPanel" class="cat-panel" style="display:none;">
        <?php foreach ($categories as $cat): ?>
          <label class="nested-label">
            <input type="checkbox" name="categories[]" value="<?= (int)$cat['id'] ?>">
            <?= htmlspecialchars($cat['name'], ENT_QUOTES, 'UTF-8') ?>
          </label>
        <?php endforeach; ?>
      </div>

      <?php if ($role === 'admin'): ?>
      <div id="bulkDatesPanel" class="date-panel" style="display:none;">
        <label class="date-label">
          <?= _e('Created at') ?>
          <input type="datetime-local" id="bulkCreatedAt" name="created_at" class="inp">
        </label>
        <label class="date-label">
          <?= _e('Updated at') ?>
          <input type="datetime-local" id="bulkUpdatedAt" name="updated_at" class="inp">
        </label>
      </div>
      <?php endif; ?>

      <button type="submit" class="adam-button"><?= _e('Apply') ?></button>
      <small class="adam-muted" style="margin-left:.5rem;"><?= _e('Bulk only affects checked items.') ?></small>
      <span id="bulkSelectionCount" class="bulk-selection-count" hidden>
        <span class="bsc-number">0</span>
        <span class="bsc-label"><?= _e('Post Selected') ?></span>
      </span>

      <div class="cols-toggle ml-auto">
        <button type="button" class="cols-toggle-btn" title="<?=_e('Columns')?>"><?= svg_ico('columns-2') ?></button>
        <div class="cols-dropdown">
          <label class="cols-opt"><input type="checkbox" data-col="col-status" checked> <?=_e('Status')?></label>
          <label class="cols-opt"><input type="checkbox" data-col="col-categories" checked> <?=_e('Categories')?></label>
          <label class="cols-opt"><input type="checkbox" data-col="col-created" checked> <?=_e('Created')?></label>
          <label class="cols-opt"><input type="checkbox" data-col="col-author" checked> <?=_e('Author')?></label>
        </div>
      </div>
    </div>

    <div class="adam-table-wrapper">
      <table class="adam-table mt-8">
        <thead>
          <tr>
            <th class="th-narrow"></th>
            <th><?= _e('Title') ?></th>
            <th class="col-status"><?=_e('Status')?></th>
            <th class="col-categories"><?= _e('Categories') ?></th>
            <th class="col-created"><?= _e('Created') ?></th>
            <th class="col-author"><?= _e('Author') ?></th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($posts)): ?>
            <tr><td class="empty-state" colspan="6"><?= _e('No articles found.') ?></td></tr>
          <?php else: ?>
            <?php foreach ($posts as $p): ?>
            <?php
              $status = strtolower(trim($p['status'] ?? 'unknown'));
              $statusClass = in_array($status, ['published','draft','private'], true) ? $status : 'unknown';
              $icons = [
                'published' => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false"><path d="M20 6L9 17l-5-5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
                'draft'     => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false"><path d="M3 21v-3l11-11 3 3L6 21H3z" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>',
                'private'   => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false"><rect x="3" y="11" width="18" height="10" rx="2" stroke="currentColor" stroke-width="1.5"/><path d="M7 11V8a5 5 0 0 1 10 0v3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>',
                'unknown'   => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.4"/><path d="M9.5 9a2.5 2.5 0 1 1 5 1c0 1.5-1.5 1.75-1.5 2.5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/><circle cx="12" cy="17.2" r="0.6" fill="currentColor"/></svg>',
              ];
              $iconSvg = $icons[$statusClass] ?? $icons['unknown'];

              $editHref = $base . '/?' . http_build_query([
                  'page' => 'admin/posts/edit',
                  'id' => (int)$p['id'],
                  'return_to' => $currentReturnTo,
              ]);
            ?>
            <tr class="adam-row">
              <td class="td-center">
                <input type="checkbox" class="bulkCheckbox" name="ids[]" value="<?= (int)$p['id'] ?>">
              </td>

              <td>
                <div class="title-wrap">
                  <?php
                    $titleHref = (function_exists('get_post_permalink') && !empty($p['slug']))
                      ? get_post_permalink($p)
                      : (($p['slug'] ?? '') !== '' ? '/' . rawurlencode((string)$p['slug']) . '/' : '#');
                  ?>
                  <a class="adam-link--full" href="<?= htmlspecialchars($titleHref, ENT_QUOTES, 'UTF-8') ?>"
                     title="<?= htmlspecialchars($p['title'], ENT_QUOTES, 'UTF-8') ?>">
                     <?= htmlspecialchars($p['title'], ENT_QUOTES, 'UTF-8') ?>
                  </a>
                  <?= apply_filters('post_list_title_after', '', $p) ?>
                  <div class="row-actions">
                    <a class="adam-ubah" href="<?= htmlspecialchars($editHref, ENT_QUOTES, 'UTF-8') ?>"><?= svg_ico('pen', '', ['class' => 'lucide-icon']) ?><?=_e('Edit')?></a>
                    <span class="muted-divider">|</span>
                    <button type="button"
                            class="adam-hapus js-post-delete"
                            data-id="<?= (int)$p['id'] ?>"
                            data-title="<?= htmlspecialchars($p['title'], ENT_QUOTES, 'UTF-8') ?>"
                            data-return-to="<?= htmlspecialchars($currentReturnTo, ENT_QUOTES, 'UTF-8') ?>">
                      <?= svg_ico('trash-2', '', ['class' => 'lucide-icon']) ?><?=_e('Delete')?>
                    </button>
                  </div>
                </div>
              </td>

              <td class="col-status">
                <span class="adam-status <?= htmlspecialchars($statusClass, ENT_QUOTES, 'UTF-8') ?>"
                      role="status" aria-label="<?= htmlspecialchars(ucfirst($status), ENT_QUOTES, 'UTF-8') ?>">
                  <span class="adam-status-icon"><?= $iconSvg ?></span>
                  <span class="adam-status-text"><?= htmlspecialchars(ucfirst($status), ENT_QUOTES, 'UTF-8') ?></span>
                </span>
              </td>

              <td class="col-categories">
                <?php
                  if (!empty($p['category_ids'])) {
                    $catIds = array_filter(array_map('trim', explode(',', $p['category_ids'])));
                    $catLinks = [];
                    foreach ($catIds as $cidRaw) {
                      $cid = (int)$cidRaw;
                      $catName = $catsMap[$cid]['name'] ?? '';
                      $catPath = build_category_path($catsMap, $cid, $categoryPathCache);

                      if ($catPath !== null && $catPath !== '') {
                        $catHref = $catBase . implode('/', array_map('rawurlencode', explode('/', $catPath))) . '/';
                      } else {
                        $catHref = '/admin/categories/view.php?id=' . $cid;
                      }

                      $hue = cat_hue($cid);
                      $catLinks[] = '<a class="adam-category" href="' . htmlspecialchars($catHref, ENT_QUOTES, 'UTF-8') . '"'
                                  . ' style="--cat-h:' . (int)$hue . ';"'
                                  . ' title="' . htmlspecialchars($catName, ENT_QUOTES, 'UTF-8') . '">'
                                  . htmlspecialchars($catName, ENT_QUOTES, 'UTF-8') . '</a>';
                    }
                    echo implode(' ', $catLinks);
                  } else {
                    echo '<span class="adam-category-empty">-</span>';
                  }
                ?>
              </td>

              <td class="col-created"><?= htmlspecialchars(format_date_ddmmyyyy_time_bracket($p['created_at']), ENT_QUOTES, 'UTF-8') ?></td>

              <td class="col-author">
                <?php
                  $authorName = $p['created_by'] ?? '-';
                  $authorUsername = $p['author_username'] ?? '';
                  if ($authorUsername !== '') {
                    $authorHref = '/author/' . rawurlencode($authorUsername) . '/';
                    echo '<a class="adam-penulis" href="' . htmlspecialchars($authorHref, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($authorName, ENT_QUOTES, 'UTF-8') . '</a>';
                  } else {
                    echo htmlspecialchars($authorName, ENT_QUOTES, 'UTF-8');
                  }
                ?>
              </td>
            </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </form>
  <?php endif; ?>

  <?php if ($pages > 1): ?>
    <nav class="adam-pagination pagination-wrap">
      <?php foreach ($paging_items as $item):
        if ($item === '...') {
          echo '<span class="dots">…</span> ';
          continue;
        }
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

  <form id="newnotif-delete-form" method="post" action="<?= htmlspecialchars($base . '/admin/posts/delete.php', ENT_QUOTES, 'UTF-8') ?>" style="display:none;">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="id" id="newnotif-delete-id">
    <input type="hidden" name="return_to" id="newnotif-delete-return-to" value="<?= htmlspecialchars($currentReturnTo, ENT_QUOTES, 'UTF-8') ?>">
  </form>
</section>

<?php
if (!empty($page_toasts) && function_exists('adiwira_bootstrap_toasts_script')) {
    echo adiwira_bootstrap_toasts_script($page_toasts);
}
?>

<script>
(function(){
  const selectAll = document.getElementById('selectAll');
  const bulkForm = document.getElementById('bulkForm');
  const bulkAction = document.getElementById('bulkAction');
  const bulkStatus = document.getElementById('bulkStatus');
  const bulkAuthor = document.getElementById('bulkAuthor');
  const bulkCatMode = document.getElementById('bulkCatMode');
  const bulkCategoriesPanel = document.getElementById('bulkCategoriesPanel');
  const bulkDatesPanel = document.getElementById('bulkDatesPanel');
  const bulkCreatedAt = document.getElementById('bulkCreatedAt');
  const bulkUpdatedAt = document.getElementById('bulkUpdatedAt');
  const bulkSelectionCount = document.getElementById('bulkSelectionCount');
  const deleteForm = document.getElementById('newnotif-delete-form');
  const deleteIdInput = document.getElementById('newnotif-delete-id');
  const deleteReturnTo = document.getElementById('newnotif-delete-return-to');

  function updateSelectionCount(){
    if (!bulkSelectionCount) return;
    const count = document.querySelectorAll('.bulkCheckbox:checked').length;
    const numEl = bulkSelectionCount.querySelector('.bsc-number');
    const labelEl = bulkSelectionCount.querySelector('.bsc-label');
    if (numEl) numEl.textContent = String(count);
    if (labelEl) labelEl.textContent = (count === 1 ? <?= json_encode(__('Post Selected')) ?> : <?= json_encode(__('Posts Selected')) ?>);
    bulkSelectionCount.hidden = (count === 0);
    if (count > 0) {
      bulkSelectionCount.classList.remove('is-pulse');
      void bulkSelectionCount.offsetWidth;
      bulkSelectionCount.classList.add('is-pulse');
    }
  }

  function toast(type, message, title){
    if (window.NewNotifToast && typeof window.NewNotifToast.show === 'function') {
      window.NewNotifToast.show({ type: type, title: title, message: message });
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
    return Promise.resolve(window.confirm(opts.message || '<?=__('Proceed with this action?')?>'));
  }

  function toggleBulkExtras(){
    const v = bulkAction ? bulkAction.value : '';
    if (bulkStatus) bulkStatus.style.display = (v === 'change_status') ? 'inline-block' : 'none';
    if (bulkAuthor) bulkAuthor.style.display = (v === 'change_author') ? 'inline-block' : 'none';
    if (bulkCatMode) bulkCatMode.style.display = (v === 'change_categories') ? 'inline-block' : 'none';
    if (bulkCategoriesPanel) bulkCategoriesPanel.style.display = (v === 'change_categories') ? 'block' : 'none';
    if (bulkDatesPanel) bulkDatesPanel.style.display = (v === 'change_date') ? 'inline-flex' : 'none';
  }

  function checkedCount(){
    return document.querySelectorAll('.bulkCheckbox:checked').length;
  }

  function checkedCategoryLabels(){
    return Array.from(document.querySelectorAll('#bulkCategoriesPanel input[type="checkbox"]:checked'))
      .map(function(el){
        const label = el.closest('label');
        return label ? label.textContent.trim() : '';
      })
      .filter(Boolean);
  }

  function getBulkSummary(){
    const action = bulkAction ? bulkAction.value : '';
    const count = checkedCount();

    if (!action) {
      return { ok:false, message: <?= json_encode(__('Select a bulk action first.')) ?> };
    }

    if (count < 1) {
      return { ok:false, message: <?= json_encode(__('Select at least one article.')) ?> };
    }

    if (action === 'delete') {
      return {
        ok: true,
        variant: 'danger',
        title: <?= json_encode(__('Delete selected articles')) ?>,
        message: <?= json_encode(__('')) ?> + count + '<?=__(' articles will be moved to trash. Continue?')?>',
        confirmText: <?= json_encode(__('Yes, delete')) ?>
      };
    }

    if (action === 'change_status') {
      const status = bulkStatus ? bulkStatus.value : 'draft';
      return {
        ok: true,
        variant: 'warning',
        title: <?= json_encode(__('Change article status')) ?>,
        message: <?= json_encode(__('Change status of ')) ?> + count + '<?=__(' articles to')?> "' + status + '"?',
        confirmText: <?= json_encode(__('Yes, change')) ?>
      };
    }

    if (action === 'change_categories') {
      const mode = bulkCatMode ? bulkCatMode.value : 'add';
      const names = checkedCategoryLabels();
      if (!names.length) {
        return { ok:false, message: <?= json_encode(__('Select at least one category for bulk categories.')) ?> };
      }

      const modeText = mode === 'remove' ? '<?=__('remove from')?>' : (mode === 'toggle' ? '<?=__('toggle on')?>' : '<?=__('add to')?>');
      const labelText = names.length > 3
        ? names.slice(0, 3).join(', ') + ' +' + (names.length - 3) + ' lagi'
        : names.join(', ');

      return {
        ok: true,
        variant: 'warning',
        title: <?= json_encode(__('Manage article categories')) ?>,
        message: '<?=__('Apply mode')?> "' + mode + '" <?=__('for')?> ' + count + ' <?=__('articles with categories:')?> ' + labelText + '. <?=__('Continue?')?>',
        confirmText: <?= json_encode(__('Yes, apply')) ?>
      };
    }

    if (action === 'change_author') {
      if (<?= json_encode($role !== 'admin') ?>) {
        return { ok:false, message: <?= json_encode(__('Access denied: only admin can change author.')) ?> };
      }
      const authorId = bulkAuthor ? bulkAuthor.value : '';
      const authorLabel = bulkAuthor && bulkAuthor.selectedIndex >= 0
        ? (bulkAuthor.options[bulkAuthor.selectedIndex].textContent || '').trim()
        : '';

      if (!authorId) {
        return { ok:false, message: <?= json_encode(__('Select an author first.')) ?> };
      }

      return {
        ok: true,
        variant: 'warning',
        title: <?= json_encode(__('Change article author')) ?>,
        message: <?= json_encode(__('Change author of ')) ?> + count + '<?=__(' articles to')?> "' + authorLabel + '"?',
        confirmText: <?= json_encode(__('Yes, change')) ?>
      };
    }

    if (action === 'change_date') {
      if (<?= json_encode($role !== 'admin') ?>) {
        return { ok:false, message: <?= json_encode(__('Access denied: only admin can change date.')) ?> };
      }
      const createdAt = bulkCreatedAt ? bulkCreatedAt.value : '';
      const updatedAt = bulkUpdatedAt ? bulkUpdatedAt.value : '';
      if (!createdAt && !updatedAt) {
        return { ok:false, message: <?= json_encode(__('Please enter at least one date.')) ?> };
      }
      return {
        ok: true,
        variant: 'warning',
        title: <?= json_encode(__('Change article date')) ?>,
        message: <?= json_encode(__('Change date of ')) ?> + count + <?= json_encode(__(' articles to')) ?> + ' ' + <?= json_encode(__('Created')) ?> + ': ' + (createdAt || '-') + ', ' + <?= json_encode(__('Updated')) ?> + ': ' + (updatedAt || '-') + ' ' + <?= json_encode(__('Continue?')) ?>,
        confirmText: <?= json_encode(__('Yes, change')) ?>
      };
    }

    return {
      ok: true,
      variant: 'warning',
      title: <?= json_encode(__('Confirm bulk action')) ?>,
        message: <?= json_encode(__('Execute action for ')) ?> + count + '<?=__(' articles?')?>',
      confirmText: <?= json_encode(__('Proceed')) ?>
    };
  }

  if (selectAll) {
    selectAll.addEventListener('change', function(){
      const checked = !!this.checked;
      document.querySelectorAll('.bulkCheckbox').forEach(function(cb){
        cb.checked = checked;
      });
      updateSelectionCount();
    });
  }

  document.querySelectorAll('.bulkCheckbox').forEach(function(cb){
    cb.addEventListener('change', updateSelectionCount);
  });

  if (bulkAction) {
    bulkAction.addEventListener('change', toggleBulkExtras);
    toggleBulkExtras();
  }

  updateSelectionCount();

  /* ── Column visibility toggle ── */
  (function(){
    const STORAGE_KEY = 'posts_columns';
    const toggleBtn = document.querySelector('.cols-toggle-btn');
    const dropdown = document.querySelector('.cols-dropdown');
    const checkboxes = dropdown ? dropdown.querySelectorAll('input[data-col]') : [];

    function loadColState(){
      try {
        const saved = localStorage.getItem(STORAGE_KEY);
        return saved ? JSON.parse(saved) : null;
      } catch(e){ return null; }
    }

    function saveColState(state){
      try { localStorage.setItem(STORAGE_KEY, JSON.stringify(state)); }
      catch(e){}
    }

    function applyColState(state){
      checkboxes.forEach(function(cb){
        const col = cb.getAttribute('data-col');
        const hidden = state && state[col] === false;
        cb.checked = !hidden;
        document.querySelectorAll('.' + col).forEach(function(el){
          el.classList.toggle('col-hidden', hidden);
        });
      });
    }

    /* restore saved state */
    var saved = loadColState();
    if (saved) applyColState(saved);

    /* toggle dropdown */
    if (toggleBtn && dropdown) {
      toggleBtn.addEventListener('click', function(e){
        e.stopPropagation();
        dropdown.classList.toggle('open');
      });
      document.addEventListener('click', function(){
        dropdown.classList.remove('open');
      });
      dropdown.addEventListener('click', function(e){
        e.stopPropagation();
      });
    }

    /* handle checkbox change */
    checkboxes.forEach(function(cb){
      cb.addEventListener('change', function(){
        var col = this.getAttribute('data-col');
        var hidden = !this.checked;
        document.querySelectorAll('.' + col).forEach(function(el){
          el.classList.toggle('col-hidden', hidden);
        });
        var state = loadColState() || {};
        state[col] = this.checked;
        saveColState(state);
      });
    });
  })();

  document.querySelectorAll('.js-post-delete').forEach(function(btn){
    btn.addEventListener('click', function(){
      const id = this.getAttribute('data-id') || '';
      const title = this.getAttribute('data-title') || '<?=__('this article')?>';
      const returnTo = this.getAttribute('data-return-to') || '';

      ask('danger', {
        title: <?= json_encode(__('Delete confirmation')) ?>,
        message: <?= json_encode(__('Delete article "')) ?> + title + '<?=__('"? Article will be moved to trash.')?>',
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