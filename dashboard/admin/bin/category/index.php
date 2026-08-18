<?php
declare(strict_types=1);

// /adiwira/admin/bin/category/?
require_once __DIR__ . '/../../_deny.php';

if (!defined('DASHBOARD_CONTEXT') && !defined('ADAM_THEME')) {
    adiwira_admin_404();
}

require_once __DIR__ . '/../../_guard.php';
require_once __DIR__ . '/../../_notify.php';

[$uid] = adiwira_require_login($pdo, false);
$restoreCondition = authorization_owner_scope_condition(
    $pdo,
    $uid,
    'core.categories.restore',
    'c.created_by',
    'category_restore'
);
$purgeCondition = authorization_owner_scope_condition(
    $pdo,
    $uid,
    'core.categories.purge',
    'c.created_by',
    'category_purge'
);
if ($restoreCondition === null && $purgeCondition === null) {
    adiwira_render_404();
}
$accessConditions = array_values(array_filter([$restoreCondition, $purgeCondition]));
$accessSql = implode(' OR ', array_map(static fn(array $condition): string => '(' . $condition['sql'] . ')', $accessConditions));
$accessParams = [];
foreach ($accessConditions as $condition) {
    $accessParams = array_merge($accessParams, $condition['params']);
}

// fallback bila masih ada route lama kirim ?msg= / ?err=
$page_toasts = function_exists('adiwira_collect_query_toasts')
    ? adiwira_collect_query_toasts()
    : [];

// filters
$search        = trim((string)($_GET['q'] ?? ''));
$filter_parent = (int)($_GET['parent'] ?? 0);
$filter_author = (int)($_GET['author'] ?? 0);

// pagination
$page_num = max(1, (int)($_GET['p'] ?? 1));
$per_page = 20;
$offset   = ($page_num - 1) * $per_page;

// where
$where  = ["c.is_deleted = 1"];
$where[] = '(' . $accessSql . ')';
$params = $accessParams;

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

// count
$count_sql = "SELECT COUNT(*) FROM categories c WHERE $where_sql";
$totalStmt = $pdo->prepare($count_sql);
$totalStmt->execute($params);
$total = (int)$totalStmt->fetchColumn();
$pages = max(1, (int)ceil($total / $per_page));

// data
$sql = "
SELECT
  c.id, c.name, c.slug, c.parent_id, c.created_by, c.created_at, c.deleted_at,
  COALESCE(NULLIF(u.name,''), NULLIF(u.username,''), CAST(u.id AS CHAR)) AS created_by_label,
  p2.name AS parent_name,
  p2.created_by AS parent_created_by
FROM categories c
LEFT JOIN categories p2 ON p2.id = c.parent_id
LEFT JOIN users u ON u.id = c.created_by
WHERE $where_sql
ORDER BY c.deleted_at DESC, c.id DESC
LIMIT :limit OFFSET :offset
";
$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$cats = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

// parent options (deleted + active agar filter nyaman)
$optStmt = $pdo->prepare("
    SELECT c.id, c.name, c.parent_id, c.is_deleted, c.created_by,
           COALESCE(NULLIF(u.name,''), NULLIF(u.username,''), CAST(u.id AS CHAR)) AS created_by_label
    FROM categories c
    LEFT JOIN users u ON u.id = c.created_by
    WHERE c.is_deleted = 1
      AND ($accessSql)
    ORDER BY COALESCE(c.parent_id,0) ASC, c.name ASC
");
$optStmt->execute($accessParams);
$optAll = $optStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$children = [];
$byId = [];
foreach ($optAll as $r) {
    $id = (int)$r['id'];
    $pid = (int)($r['parent_id'] ?? 0);
    $byId[$id] = $r;
    $children[$pid][] = $id;
}
$visibleOptionIds = array_fill_keys(array_keys($byId), true);
foreach ($children as $parentId => $childIds) {
    if ($parentId > 0 && !isset($visibleOptionIds[$parentId])) {
        $children[0] = array_merge($children[0] ?? [], $childIds);
        unset($children[$parentId]);
    }
}

$parentOptions = [];
$seen = [];

$buildOpt = function($pid, $depth) use (&$buildOpt, &$children, &$byId, &$parentOptions, &$seen) {
    if (empty($children[$pid])) return;

    foreach ($children[$pid] as $cid) {
        if (isset($seen[$cid])) continue;
        $seen[$cid] = true;

        $r = $byId[$cid];
        $suffix = ((int)($r['is_deleted'] ?? 0) === 1) ? ' (trash)' : '';
        $label = str_repeat('— ', $depth) . ($r['name'] ?? '-') . $suffix;

        $parentOptions[] = [
            'id'    => $cid,
            'label' => $label,
        ];

        $buildOpt($cid, $depth + 1);
    }
};
$buildOpt(0, 0);

// Creator filters only expose identities attached to authorized trash rows.
$authors = [];
foreach ($optAll as $category) {
    $creatorId = (int)($category['created_by'] ?? 0);
    if ($creatorId <= 0 || isset($authors[$creatorId])) continue;
    $authors[$creatorId] = [
        'id' => $creatorId,
        'label' => (string)($category['created_by_label'] ?? $creatorId),
    ];
}
usort($authors, static fn(array $a, array $b): int => strcasecmp($a['label'], $b['label']));

if (!function_exists('build_pagination_items')) {
    function build_pagination_items(int $current, int $total, int $max_visible = 9): array {
        if ($total <= $max_visible) return range(1, $total);

        $items = [];
        $reserved = 6;
        $middle_slots = max(1, $max_visible - $reserved);
        $half = (int)floor($middle_slots / 2);

        $start = max(3, $current - $half);
        $end   = min($total - 2, $current + $half);

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

$base = ADMIN_BASE_PATH;
$canRestore = $restoreCondition !== null;
$canPurge = $purgeCondition !== null;
$canBulk = $canRestore || $canPurge;

$currentQuery = $_GET;
$currentQuery['page'] = 'admin/bin/category/index';
$currentReturnTo = $base . '/?' . http_build_query($currentQuery);
?>

<section class="adam-card">
  <h2><?=_e('Bin / Trash — Categories')?></h2>

  <form method="get" style="margin-bottom:1rem;display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;">
    <input type="hidden" name="page" value="admin/bin/category/index">

    <input type="text" name="q" placeholder="<?=_e('Search name/slug...')?>" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>" style="padding:.4rem;min-width:220px">

    <select name="parent" style="padding:.4rem;">
      <option value="0"><?= _e('-- All Parents --') ?></option>
      <?php foreach ($parentOptions as $opt): ?>
        <option value="<?= (int)$opt['id'] ?>" <?= $filter_parent === (int)$opt['id'] ? 'selected' : '' ?>>
          <?= htmlspecialchars((string)$opt['label'], ENT_QUOTES, 'UTF-8') ?>
        </option>
      <?php endforeach; ?>
    </select>

      <select name="author" style="padding:.4rem;">
        <option value="0"><?= _e('-- All Creators --') ?></option>
        <?php foreach ($authors as $a): ?>
          <option value="<?= (int)$a['id'] ?>" <?= $filter_author === (int)$a['id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars((string)$a['label'], ENT_QUOTES, 'UTF-8') ?>
          </option>
        <?php endforeach; ?>
      </select>

    <button type="submit" class="adam-button"><?= _e('Apply') ?></button>
    <a href="<?= htmlspecialchars($base . '/?page=admin/bin/category/index', ENT_QUOTES, 'UTF-8') ?>" class="adam-cancle"><?=_e('Reset')?></a>

    <span style="margin-left:auto;color:var(--adam-muted);">
      <?=_e('Total trash:')?> <strong><?= (int)$total ?></strong>
    </span>
  </form>

  <?php if ($canBulk): ?>
    <form id="binCategoryBulkForm" method="post" action="<?= htmlspecialchars($base . '/admin/bin/category/bulk_action.php', ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="return_to" value="<?= htmlspecialchars($currentReturnTo, ENT_QUOTES, 'UTF-8') ?>">

      <div style="display:flex;gap:.5rem;align-items:center;margin-bottom:.6rem;flex-wrap:wrap;">
        <label style="display:flex;align-items:center;gap:.4rem;">
          <input type="checkbox" id="selectAllBinCategory"> <?=_e('Select all on page')?>
        </label>

        <select id="bulkActionBinCategory" name="action" style="padding:.4rem;">
          <option value=""><?=_e('-- Bulk action --')?></option>
          <?php if ($canRestore): ?><option value="restore"><?=_e('Restore')?></option><?php endif; ?>
          <?php if ($canPurge): ?><option value="delete_permanent"><?=_e('Delete Permanently')?></option><?php endif; ?>
        </select>

        <button type="submit" class="adam-button"><?= _e('Apply') ?></button>
        <small style="color:var(--adam-muted);"><?= _e('Bulk only affects checked items.') ?></small>
      </div>

      <div class="adam-table-wrapper">
        <table class="adam-table" style="margin-top:.5rem;">
          <thead>
            <tr>
              <th style="width:40px"></th>
              <th><?= _e('Name') ?></th>
              <th><?=_e('Slug')?></th>
              <th><?=_e('Parent')?></th>
              <th><?=_e('Deleted')?></th>
              <th><?=_e('Creator')?></th>
              <th><?= _e('Actions') ?></th>
            </tr>
          </thead>
          <tbody>
          <?php if (empty($cats)): ?>
            <tr><td colspan="7" style="padding:1rem;"><?=_e('Trash is empty.')?></td></tr>
          <?php else: ?>
            <?php foreach ($cats as $c):
              $categoryOwnerId = (int)($c['created_by'] ?? 0);
              $canRestoreCategory = user_can($pdo, $uid, 'core.categories.restore', ['owner_id' => $categoryOwnerId]);
              $canPurgeCategory = user_can($pdo, $uid, 'core.categories.purge', ['owner_id' => $categoryOwnerId]);
              $canReadParent = (int)($c['parent_id'] ?? 0) > 0
                && user_can($pdo, $uid, 'core.categories.read', ['owner_id' => (int)($c['parent_created_by'] ?? 0)]);
            ?>
              <tr class="adam-row">
                <td style="text-align:center;">
                  <?php if ($canRestoreCategory || $canPurgeCategory): ?><input type="checkbox" class="bulkCheckboxBinCategory" name="ids[]" value="<?= (int)$c['id'] ?>"><?php else: ?>&mdash;<?php endif; ?>
                </td>
                <td style="font-weight:600;"><?= htmlspecialchars((string)($c['name'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string)($c['slug'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($canReadParent ? (string)($c['parent_name'] ?? '-') : '-', ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars(!empty($c['deleted_at']) ? format_date_ddmmyyyy_time_bracket((string)$c['deleted_at']) : '-', ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string)($c['created_by_label'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                <td>
                  <?php if ($canRestoreCategory): ?><button type="button"
                          class="adam-link-button js-bin-category-restore"
                          data-id="<?= (int)$c['id'] ?>"
                          data-title="<?= htmlspecialchars((string)($c['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                          data-return-to="<?= htmlspecialchars($currentReturnTo, ENT_QUOTES, 'UTF-8') ?>">
                    <?= svg_ico('rotate-ccw', '', ['style' => 'width:12px;height:12px;vertical-align:middle;margin-right:2px']) ?><?=_e('Restore')?>
                  </button><?php endif; ?>
                  <?php if ($canRestoreCategory && $canPurgeCategory): ?>&nbsp;<span class="muted-divider">|</span>&nbsp;<?php endif; ?>
                  <?php if ($canPurgeCategory): ?><button type="button"
                          class="adam-link-button js-bin-category-delete-permanent"
                          data-id="<?= (int)$c['id'] ?>"
                          data-title="<?= htmlspecialchars((string)($c['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                          data-return-to="<?= htmlspecialchars($currentReturnTo, ENT_QUOTES, 'UTF-8') ?>">
                    <?= svg_ico('trash-2', '', ['style' => 'width:12px;height:12px;vertical-align:middle;margin-right:2px']) ?><?=_e('Delete Permanently')?>
                  </button><?php endif; ?>
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
    <nav class="adam-pagination" style="margin-top:1rem;">
      <?php foreach ($paging_items as $item):
        if ($item === '...') {
          echo '<span class="dots">…</span> ';
          continue;
        }
        $i = (int)$item;
        $query = $_GET;
        $query['p'] = $i;
        $query['page'] = 'admin/bin/category/index';
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

  <?php if ($canRestore): ?><form id="bin-category-restore-form" method="post" action="<?= htmlspecialchars($base . '/admin/bin/category/restore.php', ENT_QUOTES, 'UTF-8') ?>" style="display:none;">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="id" id="bin-category-restore-id">
    <input type="hidden" name="return_to" id="bin-category-restore-return-to" value="<?= htmlspecialchars($currentReturnTo, ENT_QUOTES, 'UTF-8') ?>">
  </form><?php endif; ?>

  <?php if ($canPurge): ?><form id="bin-category-delete-form" method="post" action="<?= htmlspecialchars($base . '/admin/bin/category/delete_permanent.php', ENT_QUOTES, 'UTF-8') ?>" style="display:none;">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="id" id="bin-category-delete-id">
    <input type="hidden" name="return_to" id="bin-category-delete-return-to" value="<?= htmlspecialchars($currentReturnTo, ENT_QUOTES, 'UTF-8') ?>">
  </form><?php endif; ?>
</section>

<?php
if (!empty($page_toasts) && function_exists('adiwira_bootstrap_toasts_script')) {
    echo adiwira_bootstrap_toasts_script($page_toasts);
}
?>

<script>
(function(){
  const selectAll = document.getElementById('selectAllBinCategory');
  const bulkForm = document.getElementById('binCategoryBulkForm');
  const bulkAction = document.getElementById('bulkActionBinCategory');

  const restoreForm = document.getElementById('bin-category-restore-form');
  const restoreId = document.getElementById('bin-category-restore-id');
  const restoreReturnTo = document.getElementById('bin-category-restore-return-to');

  const deleteForm = document.getElementById('bin-category-delete-form');
  const deleteId = document.getElementById('bin-category-delete-id');
  const deleteReturnTo = document.getElementById('bin-category-delete-return-to');

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
    return Promise.resolve(window.confirm(opts.message || '<?=__('Continue this action?')?>'));
  }

  function checkedCount(){
    return document.querySelectorAll('.bulkCheckboxBinCategory:checked').length;
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

    if (action === 'restore') {
      return {
        ok: true,
        variant: 'warning',
        title: '<?=__('Restore selected categories')?>',
        message: count + <?= json_encode(__(' category(ies) will be restored from trash. Parents still in trash will be detached automatically.')) ?>,
        confirmText: '<?=__('Yes, restore')?>'
      };
    }

    if (action === 'delete_permanent') {
      return {
        ok: true,
        variant: 'danger',
        title: '<?=__('Delete permanently categories')?>',
        message: count + <?= json_encode(__(' category(ies) will be permanently deleted. This will fail if any category still has subcategories.')) ?>,
        confirmText: '<?=__('Yes, delete permanently')?>'
      };
    }

    return {
      ok: false,
      message: <?= json_encode(__('Unknown bulk action.')) ?>
    };
  }

  if (selectAll) {
    selectAll.addEventListener('change', function(){
      const checked = !!this.checked;
      document.querySelectorAll('.bulkCheckboxBinCategory').forEach(function(cb){
        cb.checked = checked;
      });
    });
  }

  document.querySelectorAll('.js-bin-category-restore').forEach(function(btn){
    btn.addEventListener('click', function(){
      const id = this.getAttribute('data-id') || '';
      const title = this.getAttribute('data-title') || '<?=__('this category')?>';
      const returnTo = this.getAttribute('data-return-to') || '';

      ask('warning', {
        title: '<?=__('Restore category')?>',
        message: '<?=__('Restore category')?> "' + title + '"? <?=__('If its parent is still in trash, it will be detached automatically.')?>',
        confirmText: '<?=__('Yes, restore')?>',
        cancelText: <?= json_encode(__('Cancel')) ?>
      }).then(function(ok){
        if (!ok) return;
        if (!restoreForm || !restoreId) return;
        restoreId.value = id;
        if (restoreReturnTo) restoreReturnTo.value = returnTo;
        restoreForm.submit();
      });
    });
  });

  document.querySelectorAll('.js-bin-category-delete-permanent').forEach(function(btn){
    btn.addEventListener('click', function(){
      const id = this.getAttribute('data-id') || '';
      const title = this.getAttribute('data-title') || '<?=__('this category')?>';
      const returnTo = this.getAttribute('data-return-to') || '';

      ask('danger', {
        title: '<?=__('Delete permanently')?>',
        message: '<?=__('Permanently delete category')?> "' + title + '"? <?=__('This action cannot be undone and will be rejected if it still has subcategories.')?>',
        confirmText: '<?=__('Yes, delete permanently')?>',
        cancelText: <?= json_encode(__('Cancel')) ?>
      }).then(function(ok){
        if (!ok) return;
        if (!deleteForm || !deleteId) return;
        deleteId.value = id;
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
