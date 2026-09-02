<?php
declare(strict_types=1);

// /adiwira/admin/themes/?
require_once __DIR__ . '/../_deny.php';

if (!defined('DASHBOARD_CONTEXT') && !defined('ADAM_THEME')) {
    adiwira_admin_404();
}

require_once __DIR__ . '/../_guard.php';
require_once __DIR__ . '/../_notify.php';

[$uid] = adiwira_require_permission_scope($pdo, 'core.theme_content.read', false);
$readCondition = authorization_owner_scope_condition($pdo, $uid, 'core.theme_content.read', 'p.created_by', 'theme_content_read');
if ($readCondition === null) adiwira_render_404();

$page_toasts = function_exists('adiwira_collect_query_toasts')
    ? adiwira_collect_query_toasts()
    : [];

$filter_status = (string)($_GET['status'] ?? '');
$search        = trim((string)($_GET['q'] ?? ''));

$page_num = max(1, (int)($_GET['p'] ?? 1));
$per_page = 10;
$offset   = ($page_num - 1) * $per_page;

$where  = ["p.is_deleted = 0", "p.type = 'theme'"];
$where[] = '(' . $readCondition['sql'] . ')';
$params = $readCondition['params'];
$listContext = ['type' => 'theme', 'status' => $filter_status, 'search' => $search];
$statusExpression = apply_filters('post_list_status_expression', 'p.status', $listContext);
if (!is_string($statusExpression) || trim($statusExpression) === '' || str_contains($statusExpression, ';')) {
    $statusExpression = 'p.status';
}

if ($filter_status !== '') {
    $where[] = "({$statusExpression}) = :status";
    $params[':status'] = $filter_status;
}

if ($search !== '') {
    $searchCondition = apply_filters('post_list_search_condition', '(p.title LIKE :search OR p.slug LIKE :search OR cr.path LIKE :search)', $listContext);
    if (!is_string($searchCondition) || trim($searchCondition) === '' || str_contains($searchCondition, ';')) {
        $searchCondition = '(p.title LIKE :search OR p.slug LIKE :search OR cr.path LIKE :search)';
    }
    $where[] = "({$searchCondition})";
    $params[':search'] = '%' . $search . '%';
}

$where_sql = implode(' AND ', $where);
$listJoin = apply_filters('post_list_join', '', $where_sql, $listContext);
if (!is_string($listJoin) || str_contains($listJoin, ';')) $listJoin = '';

$count_sql = "SELECT COUNT(DISTINCT p.id) FROM posts p LEFT JOIN content_routes cr ON cr.post_id = p.id AND cr.locale = '' AND cr.canonical_slot = 1 $listJoin WHERE $where_sql";
$countStmt = $pdo->prepare($count_sql);
foreach ($params as $k => $v) {
    $countStmt->bindValue($k, $v);
}
$countStmt->execute();
$total = (int)$countStmt->fetchColumn();
$pages = max(1, (int)ceil($total / $per_page));
$listSelect = apply_filters('post_list_select', '', $where_sql, $listContext);
if (!is_string($listSelect) || str_contains($listSelect, ';')) $listSelect = '';

$sql = "
  SELECT p.id, p.title, p.slug, p.slug AS internal_slug, p.status, ({$statusExpression}) AS editor_status,
    p.created_at, p.updated_at, p.created_by, cr.path AS public_path
    $listSelect
  FROM posts p
  LEFT JOIN content_routes cr ON cr.post_id = p.id AND cr.locale = '' AND cr.canonical_slot = 1
  $listJoin
  WHERE $where_sql
  ORDER BY p.created_at DESC
  LIMIT :limit OFFSET :offset
";
$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$themes = $stmt->fetchAll(PDO::FETCH_ASSOC);
$filteredThemes = apply_filters('post_list_rows', $themes, $listContext);
if (is_array($filteredThemes)) $themes = $filteredThemes;

$base = ADMIN_BASE_PATH;
$canCreate = user_can($pdo, $uid, 'core.theme_content.create');
$canBulk = user_permission_scope($pdo, $uid, 'core.theme_content.update') !== null
    || user_permission_scope($pdo, $uid, 'core.theme_content.delete') !== null;
$canOpenTrash = user_permission_scope($pdo, $uid, 'core.theme_content.restore') !== null
    || user_permission_scope($pdo, $uid, 'core.theme_content.purge') !== null;

$currentQuery = $_GET;
$currentQuery['page'] = 'admin/themes/index';
$currentReturnTo = $base . '/?' . http_build_query($currentQuery);

$addHref = $base . '/?' . http_build_query([
    'page'      => 'admin/themes/add',
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
                if (is_int($items[$i]) && !in_array($items[$i], [1, 2, $total - 1, $total], true)) {
                    array_splice($items, $i, 1);
                    break;
                }
            }
        }

        return $items;
    }
}
$paging_items = build_pagination_items($page_num, $pages, 9);
?>

<section class="adam-card">
  <div class="toolbar-top">
    <h2 class="page-heading"><?=_e('Themes / Partials')?></h2>

    <form method="get" class="toolbar-filter">
      <input type="hidden" name="page" value="admin/themes/index">
      <input type="text" name="q" placeholder="<?= _e('Search title, internal slug, or public path...') ?>" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>" class="inp">

      <select name="status" class="inp">
        <option value=""><?= _e('-- All Status --') ?></option>
        <option value="draft" <?= $filter_status === 'draft' ? 'selected' : '' ?>><?=_e('Draft')?></option>
        <option value="published" <?= $filter_status === 'published' ? 'selected' : '' ?>><?=_e('Published')?></option>
        <option value="private" <?= $filter_status === 'private' ? 'selected' : '' ?>><?=_e('Private')?></option>
      </select>

      <button type="submit" class="adam-button"><?= _e('Apply') ?></button>
      <a href="<?= htmlspecialchars($base . '/?page=admin/themes/index', ENT_QUOTES, 'UTF-8') ?>" class="adam-cancle"><?=_e('Reset')?></a>
    </form>

    <?php if ($canCreate): ?><a class="adam-button toolbar-add" href="<?= htmlspecialchars($addHref, ENT_QUOTES, 'UTF-8') ?>"><?=_e('+ Add Theme Partial')?></a><?php endif; ?>
    <?php if ($canOpenTrash): ?>
      <a class="adam-att toolbar-trash" href="<?= htmlspecialchars($base . '/?page=admin/bin/theme/index', ENT_QUOTES, 'UTF-8') ?>"><?= svg_ico('trash-2') ?> <?=_e('Trash')?></a>
    <?php endif; ?>
  </div>

  <?php if ($canBulk): ?>
    <form id="themesBulkForm" method="post" action="<?= htmlspecialchars($base . '/admin/themes/bulk_action.php', ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="return_to" value="<?= htmlspecialchars($currentReturnTo, ENT_QUOTES, 'UTF-8') ?>">

      <div class="bulk-bar">
        <label class="check-row">
          <input type="checkbox" id="selectAllThemes"> <?=_e('Select all on page')?>
        </label>

        <select id="bulkActionThemes" name="action" class="inp">
          <option value=""><?=_e('-- Bulk action --')?></option>
          <option value="delete"><?= _e('Delete') ?></option>
          <option value="change_status"><?= _e('Change Status') ?></option>
        </select>

        <select id="bulkStatusThemes" name="status" class="inp hide">
          <option value="draft"><?=_e('Draft')?></option>
          <option value="published"><?=_e('Published')?></option>
          <option value="private"><?=_e('Private')?></option>
        </select>

        <button type="submit" class="adam-button"><?= _e('Apply') ?></button>
        <small class="adam-muted" style="margin-left:.5rem;"><?= _e('Bulk only affects checked items.') ?></small>

        <div class="cols-toggle ml-auto">
          <button type="button" class="cols-toggle-btn" title="<?=_e('Columns')?>"><?= svg_ico('columns-2') ?></button>
          <div class="cols-dropdown">
            <label class="cols-opt"><input type="checkbox" data-col="col-slug" checked> <?=_e('Internal slug')?></label>
            <label class="cols-opt"><input type="checkbox" data-col="col-public-path" checked> <?=_e('Public path')?></label>
            <label class="cols-opt"><input type="checkbox" data-col="col-status" checked> <?=_e('Status')?></label>
            <label class="cols-opt"><input type="checkbox" data-col="col-created" checked> <?=_e('Created')?></label>
          </div>
        </div>
      </div>
  <?php endif; ?>

  <div class="adam-table-wrapper">
    <table class="adam-table mt-8">
      <thead>
        <tr>
          <?php if ($canBulk): ?><th class="th-narrow"></th><?php endif; ?>
          <th><?= _e('Name') ?></th>
          <th class="col-slug"><?=_e('Internal slug')?></th>
          <th class="col-public-path"><?=_e('Public path')?></th>
          <th class="col-status"><?=_e('Status')?></th>
          <th class="col-created"><?= _e('Created') ?></th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($themes)): ?>
          <tr class="empty-state"><td colspan="<?= $canBulk ? 6 : 5 ?>"><?=_e('No theme partials.')?></td></tr>
        <?php else: ?>
          <?php foreach ($themes as $t): ?>
            <?php
              $status = strtolower(trim((string)($t['editor_status'] ?? $t['status'] ?? 'unknown')));
              $statusClass = in_array($status, ['published','draft','private'], true) ? $status : 'unknown';
              $ownerId = (int)($t['created_by'] ?? 0);
              $canUpdateTheme = user_can($pdo, $uid, 'core.theme_content.update', ['owner_id' => $ownerId]);
              $canDeleteTheme = user_can($pdo, $uid, 'core.theme_content.delete', ['owner_id' => $ownerId]);

              $icons = [
                'published' => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false"><path d="M20 6L9 17l-5-5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
                'draft'     => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false"><path d="M3 21v-3l11-11 3 3L6 21H3z" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>',
                'private'   => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false"><rect x="3" y="11" width="18" height="10" rx="2" stroke="currentColor" stroke-width="1.5"/><path d="M7 11V8a5 5 0 0 1 10 0v3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>',
                'unknown'   => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.4"/><path d="M9.5 9a2.5 2.5 0 1 1 5 1c0 1.5-1.5 1.75-1.5 2.5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/><circle cx="12" cy="17.2" r="0.6" fill="currentColor"/></svg>',
              ];
              $iconSvg = $icons[$statusClass] ?? $icons['unknown'];

              $editHref = $base . '/?' . http_build_query([
                  'page'      => 'admin/themes/edit',
                  'id'        => (int)$t['id'],
                  'return_to' => $currentReturnTo,
              ]);
              $titleHref = trim((string)($t['display_permalink'] ?? ''));
              if ($titleHref === '') {
                  $titleHref = function_exists('get_post_permalink') ? get_post_permalink($t) : '/' . rawurlencode((string)$t['slug']) . '/';
              }
            ?>
            <tr class="adam-row">
              <?php if ($canBulk): ?>
                <td class="td-center">
                  <?php if ($canUpdateTheme || $canDeleteTheme): ?><input type="checkbox" class="bulkCheckboxTheme" name="ids[]" value="<?= (int)$t['id'] ?>"><?php else: ?>&mdash;<?php endif; ?>
                </td>
              <?php endif; ?>

              <td>
                <div class="title-wrap">
                  <a class="adam-link--full" href="<?= htmlspecialchars($titleHref, ENT_QUOTES, 'UTF-8') ?>"
                     title="<?= htmlspecialchars((string)($t['title'] ?? '-'), ENT_QUOTES, 'UTF-8') ?>">
                    <?= htmlspecialchars((string)($t['title'] ?? '-'), ENT_QUOTES, 'UTF-8') ?>
                  </a>
                  <?= apply_filters('post_list_title_after', '', $t) ?>
                  <div class="row-actions">
                    <a class="adam-ubah" href="<?= htmlspecialchars($editHref, ENT_QUOTES, 'UTF-8') ?>"><?= $canUpdateTheme ? svg_ico('pen', '', ['class' => 'lucide-icon']) : '' ?><?= htmlspecialchars($canUpdateTheme ? __('Edit') : __('View'), ENT_QUOTES, 'UTF-8') ?></a>
                    <?php if ($canDeleteTheme): ?>
                      <span class="muted-divider">|</span>
                      <button type="button"
                              class="adam-hapus js-theme-delete"
                              data-id="<?= (int)$t['id'] ?>"
                              data-title="<?= htmlspecialchars((string)($t['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                              data-return-to="<?= htmlspecialchars($currentReturnTo, ENT_QUOTES, 'UTF-8') ?>">
                        <?= svg_ico('trash-2', '', ['class' => 'lucide-icon']) ?><?=_e('Delete')?>
                      </button>
                    <?php endif; ?>
                  </div>
                </div>
              </td>

              <td class="col-slug"><?= htmlspecialchars((string)($t['internal_slug'] ?? $t['slug'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
              <td class="col-public-path"><?= ($t['public_path'] ?? '') !== '' ? '<code>/' . htmlspecialchars((string)$t['public_path'], ENT_QUOTES, 'UTF-8') . '/</code>' : '<span class="adam-muted">' . __('Assignment only') . '</span>' ?></td>

              <td class="col-status">
                <span class="adam-status <?= htmlspecialchars($statusClass, ENT_QUOTES, 'UTF-8') ?>"
                      role="status"
                      aria-label="<?= htmlspecialchars(__(ucfirst($status)), ENT_QUOTES, 'UTF-8') ?>">
                  <span class="adam-status-icon"><?= $iconSvg ?></span>
                  <span class="adam-status-text"><?= htmlspecialchars(__(ucfirst($status)), ENT_QUOTES, 'UTF-8') ?></span>
                </span>
              </td>

              <td class="col-created"><?= htmlspecialchars(function_exists('format_date_ddmmyyyy_time_bracket') ? format_date_ddmmyyyy_time_bracket((string)$t['created_at']) : (string)$t['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <?php if ($canBulk): ?>
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

  <?php if ($canBulk): ?>
    <form id="newnotif-theme-delete-form" method="post" action="<?= htmlspecialchars($base . '/admin/themes/delete.php', ENT_QUOTES, 'UTF-8') ?>" class="hide">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="id" id="newnotif-theme-delete-id">
      <input type="hidden" name="return_to" id="newnotif-theme-delete-return-to" value="<?= htmlspecialchars($currentReturnTo, ENT_QUOTES, 'UTF-8') ?>">
    </form>
  <?php endif; ?>
</section>

<?php
if (!empty($page_toasts) && function_exists('adiwira_bootstrap_toasts_script')) {
    echo adiwira_bootstrap_toasts_script($page_toasts);
}
?>

<?php if ($canBulk): ?>
<script>
(function(){
  const selectAll = document.getElementById('selectAllThemes');
  const bulkForm = document.getElementById('themesBulkForm');
  const bulkAction = document.getElementById('bulkActionThemes');
  const bulkStatus = document.getElementById('bulkStatusThemes');
  const deleteForm = document.getElementById('newnotif-theme-delete-form');
  const deleteIdInput = document.getElementById('newnotif-theme-delete-id');
  const deleteReturnTo = document.getElementById('newnotif-theme-delete-return-to');

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
  }

  function checkedCount(){
    return document.querySelectorAll('.bulkCheckboxTheme:checked').length;
  }

  function getBulkSummary(){
    const action = bulkAction ? bulkAction.value : '';
    const count = checkedCount();

    if (!action) {
      return { ok:false, message: <?= json_encode(__('Select a bulk action first.')) ?> };
    }

    if (count < 1) {
      return { ok:false, message:'<?=__('Select at least one theme partial.')?>' };
    }

    if (action === 'delete') {
      return {
        ok: true,
        variant: 'danger',
        title: '<?=__('Delete selected theme partials')?>',
        message: <?= json_encode(__('')) ?> + count + '<?=__(' theme partials will be moved to trash. Continue?')?>',
        confirmText: <?= json_encode(__('Yes, delete')) ?>
      };
    }

    if (action === 'change_status') {
      const status = bulkStatus ? bulkStatus.value : 'draft';
      return {
        ok: true,
        variant: 'warning',
        title: '<?=__('Change theme partial status')?>',
        message: <?= json_encode(__('Change status of ')) ?> + count + '<?=__(' theme partials to')?> "' + status + '"?',
        confirmText: <?= json_encode(__('Yes, change')) ?>
      };
    }

    return {
      ok: true,
      variant: 'warning',
      title: <?= json_encode(__('Confirm bulk action')) ?>,
        message: <?= json_encode(__('Execute action for ')) ?> + count + '<?=__(' theme partials?')?>',
      confirmText: <?= json_encode(__('Proceed')) ?>
    };
  }

  if (selectAll) {
    selectAll.addEventListener('change', function(){
      const checked = !!this.checked;
      document.querySelectorAll('.bulkCheckboxTheme').forEach(function(cb){
        cb.checked = checked;
      });
    });
  }

  if (bulkAction) {
    bulkAction.addEventListener('change', toggleBulkExtras);
    toggleBulkExtras();
  }

  document.querySelectorAll('.js-theme-delete').forEach(function(btn){
    btn.addEventListener('click', function(){
      const id = this.getAttribute('data-id') || '';
      const title = this.getAttribute('data-title') || '<?=__('this theme partial')?>';
      const returnTo = this.getAttribute('data-return-to') || '';

      ask('danger', {
        title: <?= json_encode(__('Delete confirmation')) ?>,
        message: '<?=__('Delete theme partial')?> "' + title + '"? <?=__('Item will be moved to trash.')?>',
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

  /* ── Column visibility toggle ── */
  (function(){
    const STORAGE_KEY = 'themes_columns';
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

    var saved = loadColState();
    if (saved) applyColState(saved);

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
<?php endif; ?>
