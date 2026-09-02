<?php
declare(strict_types=1);

// /adiwira/admin/pages/?
require_once __DIR__ . '/../_deny.php';

if (!defined('DASHBOARD_CONTEXT') && !defined('ADAM_THEME')) {
    adiwira_admin_404();
}

require_once __DIR__ . '/../_guard.php';
require_once __DIR__ . '/../_notify.php';

[$uid] = adiwira_require_permission_scope($pdo, 'core.pages.read', false);
$readCondition = authorization_owner_scope_condition($pdo, $uid, 'core.pages.read', 'p.created_by', 'page_list_read');
if ($readCondition === null) adiwira_render_404();

// dukung query toast lama bila masih ada route lama yang kirim ?msg= / ?err=
$page_toasts = function_exists('adiwira_collect_query_toasts')
    ? adiwira_collect_query_toasts()
    : [];

// filter
$filter_status = (string)($_GET['status'] ?? '');
$filter_author = (string)($_GET['author'] ?? '');
$search        = trim((string)($_GET['q'] ?? ''));

// pagination
$page_num = max(1, (int)($_GET['p'] ?? 1));
$per_page = 10;
$offset   = ($page_num - 1) * $per_page;

// base where
$where = ["p.is_deleted = 0", "p.type = 'page'"];
$where[] = '(' . $readCondition['sql'] . ')';
$params = $readCondition['params'];
$listContext = ['type' => 'page', 'status' => $filter_status, 'search' => $search];
$statusExpression = apply_filters('post_list_status_expression', 'p.status', $listContext);
if (!is_string($statusExpression) || trim($statusExpression) === '' || str_contains($statusExpression, ';')) {
    $statusExpression = 'p.status';
}

// filter status
if ($filter_status !== '') {
    $where[] = "({$statusExpression}) = :status";
    $params[':status'] = $filter_status;
}

// filter author
if ($filter_author !== '') {
    $aid = (int)$filter_author;
    if ($aid > 0) {
        $where[] = "p.created_by = :author_id";
        $params[':author_id'] = $aid;
    }
}

// search
if ($search !== '') {
    $searchCondition = apply_filters('post_list_search_condition', '(p.title LIKE :search OR p.slug LIKE :search)', $listContext);
    if (!is_string($searchCondition) || trim($searchCondition) === '' || str_contains($searchCondition, ';')) {
        $searchCondition = '(p.title LIKE :search OR p.slug LIKE :search)';
    }
    $where[] = "({$searchCondition})";
    $params[':search'] = '%' . $search . '%';
}

$where_sql = implode(' AND ', $where);
$listJoin = apply_filters('post_list_join', '', $where_sql, $listContext);
if (!is_string($listJoin) || str_contains($listJoin, ';')) $listJoin = '';

// total count
$count_sql = "SELECT COUNT(DISTINCT p.id) FROM posts p $listJoin WHERE $where_sql";
$countStmt = $pdo->prepare($count_sql);
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
$pages = max(1, (int)ceil($total / $per_page));

// data
$listSelect = apply_filters('post_list_select', '', $where_sql, $listContext);
if (!is_string($listSelect) || str_contains($listSelect, ';')) $listSelect = '';

$sql = "
SELECT
  p.id, p.title, p.slug, p.status, p.created_at, p.updated_at, p.created_by AS owner_id,
  ({$statusExpression}) AS editor_status,
  u.id AS author_id,
  u.username AS author_username,
  COALESCE(NULLIF(u.name, ''), NULLIF(u.username, ''), CAST(u.id AS CHAR)) AS author_name
  $listSelect
FROM posts p
LEFT JOIN users u ON u.id = p.created_by
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
$pages_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

$readScope = user_permission_scope($pdo, $uid, 'core.pages.read');
$canCreate = user_can($pdo, $uid, 'core.pages.create');
$canUpdate = user_permission_scope($pdo, $uid, 'core.pages.update') !== null;
$canTrash = user_permission_scope($pdo, $uid, 'core.pages.trash') !== null;
$canPublish = user_permission_scope($pdo, $uid, 'core.pages.publish') !== null;
$canChangeOwner = user_permission_scope($pdo, $uid, 'core.pages.change_owner') !== null;
$canChangeDates = user_permission_scope($pdo, $uid, 'core.pages.change_dates') !== null;
$canOpenTrash = user_permission_scope($pdo, $uid, 'core.pages.restore') !== null
    || user_permission_scope($pdo, $uid, 'core.pages.purge') !== null;
$canBulk = $canUpdate || $canTrash || $canChangeOwner || $canChangeDates;

$filterAuthorsStmt = $pdo->prepare(
    "SELECT DISTINCT u.id, u.name, u.username
     FROM posts p
     JOIN users u ON u.id = p.created_by
     WHERE p.type = 'page' AND p.is_deleted = 0
       AND (" . $readCondition['sql'] . ")
     ORDER BY u.name ASC, u.username ASC"
);
$filterAuthorsStmt->execute($readCondition['params']);
$filterAuthors = $filterAuthorsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
$authors = [];
if ($canChangeOwner) {
    $authorsStmt = $pdo->query("SELECT id, name, username FROM users WHERE is_deleted = 0 AND is_locked = 0 ORDER BY name ASC, username ASC");
    $authors = array_values(array_filter($authorsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [], static fn(array $author): bool =>
        user_can($pdo, $uid, 'core.pages.change_owner', ['owner_id' => (int)$author['id']])));
}

// base
$base = ADMIN_BASE_PATH;

$currentQuery = $_GET;
$currentQuery['page'] = 'admin/pages/index';
$currentReturnTo = $base . '/?' . http_build_query($currentQuery);

$addHref = $base . '/?' . http_build_query([
    'page'      => 'admin/pages/add',
    'return_to' => $currentReturnTo,
]);

/** helper pagination */
if (!function_exists('build_pagination_items')) {
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

        if ($start > 3) $items[] = '...';

        for ($i = $start; $i <= $end; $i++) {
            $items[] = $i;
        }

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
    <h2 class="page-heading"><?=_e('Pages')?></h2>

    <form method="get" class="toolbar-filter">
      <input type="hidden" name="page" value="admin/pages/index">
      <input type="text" name="q" placeholder="<?= _e('Search…') ?>" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>" class="inp">

      <select name="status" class="inp">
        <option value=""><?= _e('All Status') ?></option>
        <option value="draft" <?= $filter_status === 'draft' ? 'selected' : '' ?>><?=_e('Draft')?></option>
        <option value="published" <?= $filter_status === 'published' ? 'selected' : '' ?>><?=_e('Published')?></option>
        <option value="private" <?= $filter_status === 'private' ? 'selected' : '' ?>><?=_e('Private')?></option>
      </select>

      <?php if ($readScope !== 'own'): ?><select name="author" class="inp">
        <option value=""><?= _e('All Authors') ?></option>
        <?php foreach ($filterAuthors as $a):
          $label = $a['name'] ?: ($a['username'] ?: $a['id']);
        ?>
          <option value="<?= (int)$a['id'] ?>" <?= ((string)$filter_author === (string)$a['id']) ? 'selected' : '' ?>>
            <?= htmlspecialchars((string)$label, ENT_QUOTES, 'UTF-8') ?>
          </option>
        <?php endforeach; ?>
      </select><?php endif; ?>

      <button type="submit" class="adam-button"><?= _e('Apply') ?></button>
      <a href="<?= htmlspecialchars($base . '/?page=admin/pages/index', ENT_QUOTES, 'UTF-8') ?>" class="adam-cancle"><?=_e('Reset')?></a>
    </form>

    <?php if ($canCreate): ?><a class="adam-button toolbar-add" href="<?= htmlspecialchars($addHref, ENT_QUOTES, 'UTF-8') ?>"><?=_e('+ Add Page')?></a><?php endif; ?>
    <?php if ($canOpenTrash) : ?>
      <a class="adam-att toolbar-trash" href="<?= htmlspecialchars($base . '/?page=admin/bin/page/index', ENT_QUOTES, 'UTF-8') ?>"><?= svg_ico('trash-2') ?> <?=_e('Trash')?></a>
    <?php endif; ?>
  </div>

  <?php if ($canBulk): ?><form id="pagesBulkForm" method="post" action="<?= htmlspecialchars($base . '/admin/pages/bulk_action.php', ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="return_to" value="<?= htmlspecialchars($currentReturnTo, ENT_QUOTES, 'UTF-8') ?>">

  <?php endif; ?>
    <?php if ($canBulk): ?><div class="bulk-bar">
      <label class="check-row">
        <input type="checkbox" id="selectAllPages"> <?=_e('Select all on page')?>
      </label>

      <select id="bulkActionPages" name="action" class="inp">
        <option value=""><?=_e('-- Bulk action --')?></option>
        <?php if ($canTrash): ?><option value="delete"><?= _e('Delete') ?></option><?php endif; ?>
        <?php if ($canUpdate): ?><option value="change_status"><?= _e('Change Status') ?></option><?php endif; ?>
        <?php if ($canChangeOwner): ?><option value="change_author"><?= _e('Change Author') ?></option><?php endif; ?>
        <?php if ($canChangeDates): ?><option value="change_date"><?= _e('Change Date') ?></option><?php endif; ?>
      </select>

      <select id="bulkStatusPages" name="status" class="inp" style="display:none;">
        <option value="draft"><?=_e('Draft')?></option>
        <?php if ($canPublish): ?><option value="published"><?=_e('Published')?></option>
        <option value="private"><?=_e('Private')?></option><?php endif; ?>
      </select>

      <?php if ($canChangeOwner): ?>
      <select id="bulkAuthorPages" name="author_id" class="inp" style="display:none;">
        <option value=""><?= _e('-- Select Author --') ?></option>
        <?php foreach ($authors as $a):
          $label = $a['name'] ?: ($a['username'] ?: $a['id']);
        ?>
          <option value="<?= (int)$a['id'] ?>"><?= htmlspecialchars((string)$label, ENT_QUOTES, 'UTF-8') ?></option>
        <?php endforeach; ?>
      </select>
      <?php endif; ?>

      <?php if ($canChangeDates): ?>
      <div id="bulkDatesPanelPages" class="date-panel" style="display:none;">
        <label class="date-label">
          <?= _e('Created at') ?>
          <input type="datetime-local" id="bulkCreatedAtPages" name="created_at" class="inp">
        </label>
        <label class="date-label">
          <?= _e('Updated at') ?>
          <input type="datetime-local" id="bulkUpdatedAtPages" name="updated_at" class="inp">
        </label>
      </div>
      <?php endif; ?>

      <button type="submit" class="adam-button"><?= _e('Apply') ?></button>
      <small class="adam-muted" style="margin-left:.5rem;"><?= _e('Bulk only affects checked items.') ?></small>
      <span id="bulkSelectionCount" class="bulk-selection-count" hidden>
        <span class="bsc-number">0</span>
        <span class="bsc-label"><?= _e('Page Selected') ?></span>
      </span>

      <div class="cols-toggle ml-auto">
        <button type="button" class="cols-toggle-btn" title="<?=_e('Columns')?>"><?= svg_ico('columns-2') ?></button>
        <div class="cols-dropdown">
          <label class="cols-opt"><input type="checkbox" data-col="col-slug" checked> <?=_e('Slug')?></label>
          <label class="cols-opt"><input type="checkbox" data-col="col-status" checked> <?=_e('Status')?></label>
          <label class="cols-opt"><input type="checkbox" data-col="col-created" checked> <?=_e('Created')?></label>
          <label class="cols-opt"><input type="checkbox" data-col="col-author" checked> <?=_e('Author')?></label>
        </div>
      </div>
    </div><?php endif; ?>

    <div class="adam-table-wrapper">
      <table class="adam-table" style="margin-top:.5rem;">
      <thead>
        <tr>
          <th class="th-narrow"></th>
          <th><?= _e('Title') ?></th>
          <th class="col-slug"><?=_e('Slug')?></th>
          <th class="col-status"><?=_e('Status')?></th>
          <th class="col-created"><?= _e('Created') ?></th>
          <th class="col-author"><?= _e('Author') ?></th>
        </tr>
      </thead>
        <tbody>
          <?php if (empty($pages_list)): ?>
            <tr><td colspan="6" style="padding:1rem;"><?= _e('No pages found.') ?></td></tr>
          <?php else: ?>
            <?php foreach ($pages_list as $p): ?>
              <?php
                $pageOwnerId = (int)($p['owner_id'] ?? 0);
                $status = strtolower(trim((string)($p['editor_status'] ?? $p['status'] ?? 'unknown')));
                $canPublishPage = user_can($pdo, $uid, 'core.pages.publish', ['owner_id' => $pageOwnerId]);
                $canUpdatePage = user_can($pdo, $uid, 'core.pages.update', ['owner_id' => $pageOwnerId])
                    && ($status === 'draft' || $canPublishPage);
                $canTrashPage = user_can($pdo, $uid, 'core.pages.trash', ['owner_id' => $pageOwnerId]);
                $canSelectPage = $canTrashPage
                    || user_can($pdo, $uid, 'core.pages.update', ['owner_id' => $pageOwnerId])
                    || user_can($pdo, $uid, 'core.pages.change_owner', ['owner_id' => $pageOwnerId])
                    || user_can($pdo, $uid, 'core.pages.change_dates', ['owner_id' => $pageOwnerId]);
                $statusClass = in_array($status, ['published','draft','private'], true) ? $status : 'unknown';

                $icons = [
                  'published' => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false"><path d="M20 6L9 17l-5-5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
                  'draft'     => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false"><path d="M3 21v-3l11-11 3 3L6 21H3z" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>',
                  'private'   => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false"><rect x="3" y="11" width="18" height="10" rx="2" stroke="currentColor" stroke-width="1.5"/><path d="M7 11V8a5 5 0 0 1 10 0v3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>',
                  'unknown'   => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.4"/><path d="M9.5 9a2.5 2.5 0 1 1 5 1c0 1.5-1.5 1.75-1.5 2.5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/><circle cx="12" cy="17.2" r="0.6" fill="currentColor"/></svg>',
                ];
                $iconSvg = $icons[$statusClass] ?? $icons['unknown'];

                $titleHref = (function_exists('get_page_permalink') && !empty($p['slug']))
                    ? get_page_permalink($p)
                    : (($p['slug'] ?? '') !== '' ? '/' . rawurlencode((string)$p['slug']) . '/' : '#');

                $editHref = $base . '/?' . http_build_query([
                    'page'      => 'admin/pages/edit',
                    'id'        => (int)$p['id'],
                    'return_to' => $currentReturnTo,
                ]);
              ?>
              <tr class="adam-row">
                <td style="text-align:center;">
                  <?php if ($canBulk && $canSelectPage): ?><input type="checkbox" class="bulkCheckboxPage" name="ids[]" value="<?= (int)$p['id'] ?>"><?php else: ?>&mdash;<?php endif; ?>
                </td>

                <td>
                  <div class="title-wrap">
                    <?php
                      $titleHref = (function_exists('get_page_permalink') && !empty($p['slug']))
                          ? get_page_permalink($p)
                          : (($p['slug'] ?? '') !== '' ? '/' . rawurlencode((string)$p['slug']) . '/' : '#');

                      $editHref = $base . '/?' . http_build_query([
                          'page'      => 'admin/pages/edit',
                          'id'        => (int)$p['id'],
                          'return_to' => $currentReturnTo,
                      ]);
                    ?>
                    <a class="adam-link--full" href="<?= htmlspecialchars($titleHref, ENT_QUOTES, 'UTF-8') ?>"
                       title="<?= htmlspecialchars((string)($p['title'] ?? '-'), ENT_QUOTES, 'UTF-8') ?>">
                      <?= htmlspecialchars((string)($p['title'] ?? '-'), ENT_QUOTES, 'UTF-8') ?>
                    </a>
                    <?= apply_filters('post_list_title_after', '', $p) ?>
                    <div class="row-actions">
                      <?php if ($canUpdatePage): ?><a class="adam-ubah" href="<?= htmlspecialchars($editHref, ENT_QUOTES, 'UTF-8') ?>"><?= svg_ico('pen', '', ['class' => 'lucide-icon']) ?><?=_e('Edit')?></a><?php endif; ?>
                      <?php if ($canUpdatePage && $canTrashPage): ?><span class="muted-divider">|</span><?php endif; ?>
                      <?php if ($canTrashPage): ?><button type="button"
                              class="adam-hapus js-page-delete"
                              data-id="<?= (int)$p['id'] ?>"
                              data-title="<?= htmlspecialchars((string)($p['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                              data-return-to="<?= htmlspecialchars($currentReturnTo, ENT_QUOTES, 'UTF-8') ?>">
                        <?= svg_ico('trash-2', '', ['class' => 'lucide-icon']) ?><?=_e('Delete')?>
                      </button><?php endif; ?>
                    </div>
                  </div>
                </td>

                <td class="col-slug"><?= htmlspecialchars((string)($p['slug'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>

                <td class="col-status">
                  <span class="adam-status <?= htmlspecialchars($statusClass, ENT_QUOTES, 'UTF-8') ?>"
                        role="status" aria-label="<?= htmlspecialchars(__(ucfirst($status)), ENT_QUOTES, 'UTF-8') ?>">
                    <span class="adam-status-icon"><?= $iconSvg ?></span>
                    <span class="adam-status-text"><?= htmlspecialchars(__(ucfirst($status)), ENT_QUOTES, 'UTF-8') ?></span>
                  </span>
                </td>

                <td class="col-created"><?= htmlspecialchars(format_date_ddmmyyyy_time_bracket($p['created_at']), ENT_QUOTES, 'UTF-8') ?></td>

                <td class="col-author">
                  <?php
                    $authorName = $p['author_name'] ?? '-';
                    $authorUsername = trim((string)($p['author_username'] ?? ''));
                    if ($authorUsername !== '') {
                      $authorHref = '/author/' . rawurlencode($authorUsername) . '/';
                      echo '<a class="adam-penulis" href="' . htmlspecialchars($authorHref, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars((string)$authorName, ENT_QUOTES, 'UTF-8') . '</a>';
                    } else {
                      echo htmlspecialchars((string)$authorName, ENT_QUOTES, 'UTF-8');
                    }
                  ?>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  <?php if ($canBulk): ?></form><?php endif; ?>

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

  <form id="newnotif-pages-delete-form" method="post" action="<?= htmlspecialchars($base . '/admin/pages/delete.php', ENT_QUOTES, 'UTF-8') ?>" style="display:none;">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="id" id="newnotif-pages-delete-id">
    <input type="hidden" name="return_to" id="newnotif-pages-delete-return-to" value="<?= htmlspecialchars($currentReturnTo, ENT_QUOTES, 'UTF-8') ?>">
  </form>
</section>

<?php
if (!empty($page_toasts) && function_exists('adiwira_bootstrap_toasts_script')) {
    echo adiwira_bootstrap_toasts_script($page_toasts);
}
?>

<script>
(function(){
  const selectAll = document.getElementById('selectAllPages');
  const bulkForm = document.getElementById('pagesBulkForm');
  const bulkAction = document.getElementById('bulkActionPages');
  const bulkStatus = document.getElementById('bulkStatusPages');
  const bulkAuthor = document.getElementById('bulkAuthorPages');
  const bulkDatesPanel = document.getElementById('bulkDatesPanelPages');
  const bulkCreatedAt = document.getElementById('bulkCreatedAtPages');
  const bulkUpdatedAt = document.getElementById('bulkUpdatedAtPages');
  const deleteForm = document.getElementById('newnotif-pages-delete-form');
  const deleteIdInput = document.getElementById('newnotif-pages-delete-id');
  const deleteReturnTo = document.getElementById('newnotif-pages-delete-return-to');
  const bulkSelectionCount = document.getElementById('bulkSelectionCount');

  function updateSelectionCount(){
    if (!bulkSelectionCount) return;
    const count = document.querySelectorAll('.bulkCheckboxPage:checked').length;
    const numEl = bulkSelectionCount.querySelector('.bsc-number');
    const labelEl = bulkSelectionCount.querySelector('.bsc-label');
    if (numEl) numEl.textContent = String(count);
    if (labelEl) labelEl.textContent = (count === 1 ? <?= json_encode(__('Page Selected')) ?> : <?= json_encode(__('Pages Selected')) ?>);
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
    return Promise.resolve(window.confirm(opts.message || '<?=__('Continue this action?')?>'));
  }

  function toggleBulkExtras(){
    const v = bulkAction ? bulkAction.value : '';
    if (bulkStatus) bulkStatus.style.display = (v === 'change_status') ? 'inline-block' : 'none';
    if (bulkAuthor) bulkAuthor.style.display = (v === 'change_author') ? 'inline-block' : 'none';
    if (bulkDatesPanel) bulkDatesPanel.style.display = (v === 'change_date') ? 'inline-flex' : 'none';
  }

  function checkedCount(){
    return document.querySelectorAll('.bulkCheckboxPage:checked').length;
  }

  function getBulkSummary(){
    const action = bulkAction ? bulkAction.value : '';
    const count = checkedCount();

    if (!action) {
      return { ok:false, message: <?= json_encode(__('Select a bulk action first.')) ?> };
    }

    if (count < 1) {
      return { ok:false, message: <?= json_encode(__('Select at least one page.')) ?> };
    }

    if (action === 'delete') {
      return {
        ok: true,
        variant: 'danger',
        title: <?= json_encode(__('Delete selected pages')) ?>,
        message: <?= json_encode(__('')) ?> + count + <?= json_encode(__(' pages will be moved to trash. Continue?')) ?>,
        confirmText: <?= json_encode(__('Yes, delete')) ?>
      };
    }

    if (action === 'change_status') {
      const status = bulkStatus ? bulkStatus.value : 'draft';
      return {
        ok: true,
        variant: 'warning',
        title: <?= json_encode(__('Change page status')) ?>,
        message: <?= json_encode(__('Change status of ')) ?> + count + <?= json_encode(__(' pages to')) ?> + ' "' + status + '"?',
        confirmText: <?= json_encode(__('Yes, change')) ?>
      };
    }

    if (action === 'change_author') {
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
        title: <?= json_encode(__('Change page author')) ?>,
        message: <?= json_encode(__('Change author of ')) ?> + count + <?= json_encode(__(' pages to')) ?> + ' "' + authorLabel + '"?',
        confirmText: <?= json_encode(__('Yes, change')) ?>
      };
    }

    if (action === 'change_date') {
      const createdAt = bulkCreatedAt ? bulkCreatedAt.value : '';
      const updatedAt = bulkUpdatedAt ? bulkUpdatedAt.value : '';
      if (!createdAt && !updatedAt) {
        return { ok:false, message: <?= json_encode(__('Please enter at least one date.')) ?> };
      }
      return {
        ok: true,
        variant: 'warning',
        title: <?= json_encode(__('Change page date')) ?>,
        message: <?= json_encode(__('Change date of ')) ?> + count + <?= json_encode(__(' pages to')) ?> + ' ' + <?= json_encode(__('Created')) ?> + ': ' + (createdAt || '-') + ', ' + <?= json_encode(__('Updated')) ?> + ': ' + (updatedAt || '-') + ' ' + <?= json_encode(__('Continue?')) ?>,
        confirmText: <?= json_encode(__('Yes, change')) ?>
      };
    }

    return {
      ok: true,
      variant: 'warning',
      title: <?= json_encode(__('Confirm bulk action')) ?>,
      message: <?= json_encode(__('Execute action for ')) ?> + count + ' halaman?',
      confirmText: <?= json_encode(__('Proceed')) ?>
    };
  }

  if (selectAll) {
    selectAll.addEventListener('change', function(){
      const checked = !!this.checked;
      document.querySelectorAll('.bulkCheckboxPage').forEach(function(cb){
        cb.checked = checked;
      });
      updateSelectionCount();
    });
  }

  document.querySelectorAll('.bulkCheckboxPage').forEach(function(cb){
    cb.addEventListener('change', updateSelectionCount);
  });

  if (bulkAction) {
    bulkAction.addEventListener('change', toggleBulkExtras);
    toggleBulkExtras();
  }

  updateSelectionCount();

  document.querySelectorAll('.js-page-delete').forEach(function(btn){
    btn.addEventListener('click', function(){
      const id = this.getAttribute('data-id') || '';
      const title = this.getAttribute('data-title') || '<?=__('this page')?>';
      const returnTo = this.getAttribute('data-return-to') || '';

      ask('danger', {
        title: <?= json_encode(__('Delete confirmation')) ?>,
        message: <?= json_encode(__('Delete page "')) ?> + title + <?= json_encode(__('"? Page will be moved to trash.')) ?>,
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
    const STORAGE_KEY = 'pages_columns';
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
