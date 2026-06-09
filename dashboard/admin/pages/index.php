<?php
declare(strict_types=1);

// /adiwira/admin/pages/?
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
$params = [];

// author + editor hanya boleh lihat page miliknya sendiri
if (in_array($role, ['author', 'editor'], true)) {
    $where[] = "p.created_by = :uid";
    $params[':uid'] = $uid;
}

// filter status
if ($filter_status !== '') {
    $where[] = "p.status = :status";
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
    $where[] = "(p.title LIKE :search OR p.slug LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}

$where_sql = implode(' AND ', $where);

// total count
$count_sql = "SELECT COUNT(*) FROM posts p WHERE $where_sql";
$countStmt = $pdo->prepare($count_sql);
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
$pages = max(1, (int)ceil($total / $per_page));

// data
$sql = "
SELECT
  p.id, p.title, p.slug, p.status, p.created_at, p.updated_at,
  u.id AS author_id,
  u.username AS author_username,
  COALESCE(NULLIF(u.name, ''), NULLIF(u.username, ''), CAST(u.id AS CHAR)) AS author_name
FROM posts p
LEFT JOIN users u ON u.id = p.created_by
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

// authors dropdown
$authorsStmt = $pdo->query("
    SELECT id, name, username
    FROM users
    WHERE is_deleted = 0
      AND is_locked = 0
    ORDER BY name ASC, username ASC
");
$authors = $authorsStmt->fetchAll(PDO::FETCH_ASSOC);

// base
$base = ADMIN_BASE_PATH;

// bulk tersedia untuk semua role yang diizinkan
$canBulk = in_array($role, ['admin', 'editor', 'author'], true);

$currentQuery = $_GET;
$currentQuery['page'] = 'admin/pages/index';
$currentReturnTo = $base . '/?' . http_build_query($currentQuery);

$addHref = $base . '/?' . http_build_query([
    'page'      => 'admin/pages/halaman',
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
  <h2><?=_e('Pages')?></h2>

  <form method="get" style="margin-bottom:1rem;display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;">
    <input type="hidden" name="page" value="admin/pages/index">
    <input type="text" name="q" placeholder="<?= _e('Search title or slug...') ?>" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>" style="padding:.4rem;min-width:200px">

    <select name="status" style="padding:.4rem;">
      <option value=""><?= _e('-- All Status --') ?></option>
      <option value="draft" <?= $filter_status === 'draft' ? 'selected' : '' ?>>Draft</option>
      <option value="published" <?= $filter_status === 'published' ? 'selected' : '' ?>>Published</option>
      <option value="private" <?= $filter_status === 'private' ? 'selected' : '' ?>>Private</option>
    </select>

    <select name="author" style="padding:.4rem;">
      <option value=""><?= _e('-- All Authors --') ?></option>
      <?php foreach ($authors as $a):
        $label = $a['name'] ?: ($a['username'] ?: $a['id']);
      ?>
        <option value="<?= (int)$a['id'] ?>" <?= ((string)$filter_author === (string)$a['id']) ? 'selected' : '' ?>>
          <?= htmlspecialchars((string)$label, ENT_QUOTES, 'UTF-8') ?>
        </option>
      <?php endforeach; ?>
    </select>

    <button type="submit" class="adam-button"><?= _e('Apply') ?></button>
    <a href="<?= htmlspecialchars($base . '/?page=admin/pages/index', ENT_QUOTES, 'UTF-8') ?>" class="adam-cancle"><?=_e('Reset')?></a>
  </form>

  <p style="margin-bottom:1rem">
    <a class="adam-button" href="<?= htmlspecialchars($addHref, ENT_QUOTES, 'UTF-8') ?>"><?=_e('+ Add Page')?></a>
    <?php if ($role === 'admin') : ?>
      &nbsp;&nbsp;
      <a class="adam-att" href="<?= htmlspecialchars($base . '/?page=admin/bin/page/index', ENT_QUOTES, 'UTF-8') ?>"><?= svg_ico('trash-2', '', ['style' => 'width:14px;height:14px;vertical-align:middle;margin-right:3px']) ?> <?=_e('Trash')?></a>
    <?php endif; ?>
  </p>

  <?php if ($canBulk): ?>
  <form id="pagesBulkForm" method="post" action="<?= htmlspecialchars($base . '/admin/pages/bulk_action.php', ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="return_to" value="<?= htmlspecialchars($currentReturnTo, ENT_QUOTES, 'UTF-8') ?>">

    <div style="display:flex;gap:.5rem;align-items:center;margin-bottom:.5rem;flex-wrap:wrap;">
      <label style="display:flex;align-items:center;gap:.4rem;">
        <input type="checkbox" id="selectAllPages"> <?=_e('Select all on page')?>
      </label>

      <select id="bulkActionPages" name="action" style="padding:.4rem;">
        <option value=""><?=_e('-- Bulk action --')?></option>
        <option value="delete"><?= _e('Delete') ?></option>
        <option value="change_status"><?= _e('Change Status') ?></option>
        <?php if ($role === 'admin'): ?>
          <option value="change_author"><?= _e('Change Author') ?></option>
        <?php endif; ?>
      </select>

      <select id="bulkStatusPages" name="status" style="padding:.4rem;display:none;">
        <option value="draft"><?=_e('Draft')?></option>
        <option value="published"><?=_e('Published')?></option>
        <option value="private"><?=_e('Private')?></option>
      </select>

      <?php if ($role === 'admin'): ?>
      <select id="bulkAuthorPages" name="author_id" style="padding:.4rem;display:none;">
        <option value=""><?= _e('-- Select Author --') ?></option>
        <?php foreach ($authors as $a):
          $label = $a['name'] ?: ($a['username'] ?: $a['id']);
        ?>
          <option value="<?= (int)$a['id'] ?>"><?= htmlspecialchars((string)$label, ENT_QUOTES, 'UTF-8') ?></option>
        <?php endforeach; ?>
      </select>
      <?php endif; ?>

      <button type="submit" class="adam-button"><?= _e('Apply') ?></button>
      <small style="color:var(--adam-muted);margin-left:.5rem;"><?= _e('Bulk only affects checked items.') ?></small>
    </div>

    <div class="adam-table-wrapper">
      <table class="adam-table" style="margin-top:.5rem;">
        <thead>
          <tr>
            <th style="width:40px"></th>
            <th><?= _e('Title') ?></th>
            <th><?=_e('Slug')?></th>
            <th><?=_e('Status')?></th>
            <th><?= _e('Created') ?></th>
            <th><?= _e('Author') ?></th>
            <th><?= _e('Actions') ?></th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($pages_list)): ?>
            <tr><td colspan="7" style="padding:1rem;"><?= _e('No pages found.') ?></td></tr>
          <?php else: ?>
            <?php foreach ($pages_list as $p): ?>
              <?php
                $status = strtolower(trim((string)($p['status'] ?? 'unknown')));
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
                  <input type="checkbox" class="bulkCheckboxPage" name="ids[]" value="<?= (int)$p['id'] ?>">
                </td>

                <td>
                  <a class="adam-link" href="<?= htmlspecialchars($titleHref, ENT_QUOTES, 'UTF-8') ?>"
                     title="<?= htmlspecialchars((string)($p['title'] ?? '-'), ENT_QUOTES, 'UTF-8') ?>">
                    <?= htmlspecialchars((string)($p['title'] ?? '-'), ENT_QUOTES, 'UTF-8') ?>
                  </a>
                </td>

                <td><?= htmlspecialchars((string)($p['slug'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>

                <td>
                  <span class="adam-status <?= htmlspecialchars($statusClass, ENT_QUOTES, 'UTF-8') ?>"
                        role="status" aria-label="<?= htmlspecialchars($status === 'published' ? __('Published') : ($status === 'draft' ? __('Draft') : ($status === 'private' ? __('Private') : ucfirst($status))), ENT_QUOTES, 'UTF-8') ?>">
                    <span class="adam-status-icon"><?= $iconSvg ?></span>
                    <span class="adam-status-text"><?= htmlspecialchars($status === 'published' ? __('Published') : ($status === 'draft' ? __('Draft') : ($status === 'private' ? __('Private') : ucfirst($status))), ENT_QUOTES, 'UTF-8') ?></span>
                  </span>
                </td>

                <td><?= htmlspecialchars(format_date_ddmmyyyy_time_bracket($p['created_at']), ENT_QUOTES, 'UTF-8') ?></td>

                <td>
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

                <td>
                  <a class="adam-ubah" href="<?= htmlspecialchars($editHref, ENT_QUOTES, 'UTF-8') ?>"><?= svg_ico('pen', '', ['style' => 'width:12px;height:12px;vertical-align:middle;margin-right:2px']) ?><?=_e('Edit')?></a>
                  &nbsp;<span class="muted-divider">|</span>&nbsp;
                  <button type="button"
                          class="adam-hapus js-page-delete"
                          data-id="<?= (int)$p['id'] ?>"
                          data-title="<?= htmlspecialchars((string)($p['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                          data-return-to="<?= htmlspecialchars($currentReturnTo, ENT_QUOTES, 'UTF-8') ?>">
                    <?= svg_ico('trash-2', '', ['style' => 'width:12px;height:12px;vertical-align:middle;margin-right:2px']) ?><?=_e('Delete')?>
                  </button>
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
  const deleteForm = document.getElementById('newnotif-pages-delete-form');
  const deleteIdInput = document.getElementById('newnotif-pages-delete-id');
  const deleteReturnTo = document.getElementById('newnotif-pages-delete-return-to');

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
        message: <?= json_encode(__('')) ?> + count + ' halaman akan dipindahkan ke trash. Lanjutkan?',
        confirmText: <?= json_encode(__('Yes, delete')) ?>
      };
    }

    if (action === 'change_status') {
      const status = bulkStatus ? bulkStatus.value : 'draft';
      return {
        ok: true,
        variant: 'warning',
        title: <?= json_encode(__('Change page status')) ?>,
        message: <?= json_encode(__('Change status of ')) ?> + count + ' halaman menjadi "' + status + '"?',
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
        message: <?= json_encode(__('Change author of ')) ?> + count + ' halaman menjadi "' + authorLabel + '"?',
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
    });
  }

  if (bulkAction) {
    bulkAction.addEventListener('change', toggleBulkExtras);
    toggleBulkExtras();
  }

  document.querySelectorAll('.js-page-delete').forEach(function(btn){
    btn.addEventListener('click', function(){
      const id = this.getAttribute('data-id') || '';
      const title = this.getAttribute('data-title') || 'halaman ini';
      const returnTo = this.getAttribute('data-return-to') || '';

      ask('danger', {
        title: <?= json_encode(__('Delete confirmation')) ?>,
        message: <?= json_encode(__('Delete page "')) ?> + title + '"? Halaman akan dipindahkan ke trash.',
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