<?php
declare(strict_types=1);

// /adiwira/admin/themes/index.php
if (!defined('DASHBOARD_CONTEXT') && !defined('ADAM_THEME')) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/../_guard.php';
require_once __DIR__ . '/../_notify.php';

[$uid, $role] = adiwira_require_editorial($pdo, false);
$isAdmin = ($role === 'admin');

$page_toasts = function_exists('adiwira_collect_query_toasts')
    ? adiwira_collect_query_toasts()
    : [];

$filter_status = (string)($_GET['status'] ?? '');
$search        = trim((string)($_GET['q'] ?? ''));

$page_num = max(1, (int)($_GET['p'] ?? 1));
$per_page = 10;
$offset   = ($page_num - 1) * $per_page;

$where  = ["p.is_deleted = 0", "p.type = 'theme'"];
$params = [];

if (!$isAdmin) {
    $where[] = "p.created_by = :uid";
    $params[':uid'] = $uid;
}

if ($filter_status !== '') {
    $where[] = "p.status = :status";
    $params[':status'] = $filter_status;
}

if ($search !== '') {
    $where[] = "(p.title LIKE :search OR p.slug LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}

$where_sql = implode(' AND ', $where);

$count_sql = "SELECT COUNT(*) FROM posts p WHERE $where_sql";
$countStmt = $pdo->prepare($count_sql);
foreach ($params as $k => $v) {
    $countStmt->bindValue($k, $v);
}
$countStmt->execute();
$total = (int)$countStmt->fetchColumn();
$pages = max(1, (int)ceil($total / $per_page));

$sql = "
  SELECT p.id, p.title, p.slug, p.status, p.created_at, p.updated_at, p.created_by
  FROM posts p
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

$base = rtrim(str_replace('\\','/', dirname($_SERVER['SCRIPT_NAME'])), '/');

$currentQuery = $_GET;
$currentQuery['page'] = 'admin/themes/index';
$currentReturnTo = $base . '/index.php?' . http_build_query($currentQuery);

$addHref = $base . '/index.php?' . http_build_query([
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
  <h2>Themes / Partials</h2>

  <form method="get" style="margin-bottom:1rem;display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;">
    <input type="hidden" name="page" value="admin/themes/index">
    <input type="text" name="q" placeholder="Cari judul atau slug..." value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>" style="padding:.4rem;min-width:200px">

    <label style="display:flex;align-items:center;gap:.5rem">
      <span>Status:</span>
      <select name="status" style="padding:.4rem;">
        <option value="">-- Semua Status --</option>
        <option value="draft" <?= $filter_status === 'draft' ? 'selected' : '' ?>>Draft</option>
        <option value="published" <?= $filter_status === 'published' ? 'selected' : '' ?>>Published</option>
        <option value="private" <?= $filter_status === 'private' ? 'selected' : '' ?>>Private</option>
      </select>
    </label>

    <button type="submit" class="adam-button">Terapkan</button>
    <a href="<?= htmlspecialchars($base . '/index.php?page=admin/themes/index', ENT_QUOTES, 'UTF-8') ?>" class="adam-cancle">Reset</a>
  </form>

  <p style="margin-bottom:1rem">
    <a class="adam-button" href="<?= htmlspecialchars($addHref, ENT_QUOTES, 'UTF-8') ?>">+ Tambah Theme Partial</a>

    <?php if ($isAdmin): ?>
      &nbsp;&nbsp;
      <a class="adam-att" href="<?= htmlspecialchars($base . '/index.php?page=admin/bin/theme/index', ENT_QUOTES, 'UTF-8') ?>">🗑️ Trash</a>
    <?php endif; ?>
  </p>

  <?php if ($isAdmin): ?>
    <form id="themesBulkForm" method="post" action="<?= htmlspecialchars($base . '/admin/themes/bulk_action.php', ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="return_to" value="<?= htmlspecialchars($currentReturnTo, ENT_QUOTES, 'UTF-8') ?>">

      <div style="display:flex;gap:.5rem;align-items:center;margin-bottom:.75rem;flex-wrap:wrap;">
        <label style="display:flex;align-items:center;gap:.4rem;">
          <input type="checkbox" id="selectAllThemes"> Pilih semua di halaman
        </label>

        <select id="bulkActionThemes" name="action" style="padding:.4rem;">
          <option value="">-- Bulk action --</option>
          <option value="delete">Hapus</option>
          <option value="change_status">Ubah Status</option>
        </select>

        <select id="bulkStatusThemes" name="status" style="padding:.4rem;display:none;">
          <option value="draft">Draft</option>
          <option value="published">Published</option>
          <option value="private">Private</option>
        </select>

        <button type="submit" class="adam-button">Terapkan</button>
        <small style="color:var(--adam-muted);margin-left:.5rem;">Bulk hanya mempengaruhi item yang dicentang.</small>
      </div>
  <?php endif; ?>

  <div class="adam-table-wrapper">
    <table class="adam-table" style="margin-top:.5rem;">
      <thead>
        <tr>
          <?php if ($isAdmin): ?><th style="width:40px"></th><?php endif; ?>
          <th>Nama</th>
          <th>Slug</th>
          <th>Status</th>
          <th>Dibuat</th>
          <th style="width:160px">Aksi</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($themes)): ?>
          <tr><td colspan="<?= $isAdmin ? 6 : 5 ?>" style="padding:1rem;">Belum ada theme partial.</td></tr>
        <?php else: ?>
          <?php foreach ($themes as $t): ?>
            <?php
              $status = strtolower(trim((string)($t['status'] ?? 'unknown')));
              $statusClass = in_array($status, ['published','draft','private'], true) ? $status : 'unknown';

              $icons = [
                'published' => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false"><path d="M20 6L9 17l-5-5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
                'draft'     => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false"><path d="M3 21v-3l11-11 3 3L6 21H3z" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>',
                'private'   => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false"><rect x="3" y="11" width="18" height="10" rx="2" stroke="currentColor" stroke-width="1.5"/><path d="M7 11V8a5 5 0 0 1 10 0v3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>',
                'unknown'   => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.4"/><path d="M9.5 9a2.5 2.5 0 1 1 5 1c0 1.5-1.5 1.75-1.5 2.5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/><circle cx="12" cy="17.2" r="0.6" fill="currentColor"/></svg>',
              ];
              $iconSvg = $icons[$statusClass] ?? $icons['unknown'];

              $editHref = $base . '/index.php?' . http_build_query([
                  'page'      => 'admin/themes/edit',
                  'id'        => (int)$t['id'],
                  'return_to' => $currentReturnTo,
              ]);
            ?>
            <tr class="adam-row">
              <?php if ($isAdmin): ?>
                <td style="text-align:center;">
                  <input type="checkbox" class="bulkCheckboxTheme" name="ids[]" value="<?= (int)$t['id'] ?>">
                </td>
              <?php endif; ?>

              <td>
                <a class="adam-link" href="<?= htmlspecialchars('/' . rawurlencode((string)$t['slug']) . '/', ENT_QUOTES, 'UTF-8') ?>">
                  <?= htmlspecialchars((string)($t['title'] ?? '-'), ENT_QUOTES, 'UTF-8') ?>
                </a>
              </td>

              <td><?= htmlspecialchars((string)($t['slug'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>

              <td>
                <span class="adam-status <?= htmlspecialchars($statusClass, ENT_QUOTES, 'UTF-8') ?>"
                      role="status"
                      aria-label="<?= htmlspecialchars(ucfirst($status), ENT_QUOTES, 'UTF-8') ?>">
                  <span class="adam-status-icon"><?= $iconSvg ?></span>
                  <span class="adam-status-text"><?= htmlspecialchars(ucfirst($status), ENT_QUOTES, 'UTF-8') ?></span>
                </span>
              </td>

              <td><?= htmlspecialchars(function_exists('format_date_ddmmyyyy_time_bracket') ? format_date_ddmmyyyy_time_bracket((string)$t['created_at']) : (string)$t['created_at'], ENT_QUOTES, 'UTF-8') ?></td>

              <td>
                <a class="adam-ubah" href="<?= htmlspecialchars($editHref, ENT_QUOTES, 'UTF-8') ?>">Edit</a>

                <?php if ($isAdmin): ?>
                  &nbsp;<span class="muted-divider">|</span>&nbsp;
                  <button type="button"
                          class="adam-hapus js-theme-delete"
                          data-id="<?= (int)$t['id'] ?>"
                          data-title="<?= htmlspecialchars((string)($t['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                          data-return-to="<?= htmlspecialchars($currentReturnTo, ENT_QUOTES, 'UTF-8') ?>">
                    Hapus
                  </button>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <?php if ($isAdmin): ?>
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
        $link = $base . '/index.php?' . http_build_query($query);
      ?>
        <?php if ($i === $page_num): ?>
          <strong><?= $i ?></strong>
        <?php else: ?>
          <a href="<?= htmlspecialchars($link, ENT_QUOTES, 'UTF-8') ?>"><?= $i ?></a>
        <?php endif; ?>
      <?php endforeach; ?>
    </nav>
  <?php endif; ?>

  <?php if ($isAdmin): ?>
    <form id="newnotif-theme-delete-form" method="post" action="<?= htmlspecialchars($base . '/admin/themes/delete.php', ENT_QUOTES, 'UTF-8') ?>" style="display:none;">
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

<?php if ($isAdmin): ?>
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
    return Promise.resolve(window.confirm(opts.message || 'Lanjutkan aksi ini?'));
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
      return { ok:false, message:'Pilih bulk action terlebih dahulu.' };
    }

    if (count < 1) {
      return { ok:false, message:'Pilih minimal satu theme partial.' };
    }

    if (action === 'delete') {
      return {
        ok: true,
        variant: 'danger',
        title: 'Hapus theme partial terpilih',
        message: 'Sebanyak ' + count + ' theme partial akan dipindahkan ke trash. Lanjutkan?',
        confirmText: 'Ya, hapus'
      };
    }

    if (action === 'change_status') {
      const status = bulkStatus ? bulkStatus.value : 'draft';
      return {
        ok: true,
        variant: 'warning',
        title: 'Ubah status theme partial',
        message: 'Ubah status ' + count + ' theme partial menjadi "' + status + '"?',
        confirmText: 'Ya, ubah'
      };
    }

    return {
      ok: true,
      variant: 'warning',
      title: 'Konfirmasi bulk action',
      message: 'Jalankan aksi untuk ' + count + ' theme partial?',
      confirmText: 'Lanjutkan'
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
      const title = this.getAttribute('data-title') || 'theme partial ini';
      const returnTo = this.getAttribute('data-return-to') || '';

      ask('danger', {
        title: 'Konfirmasi hapus',
        message: 'Hapus theme partial "' + title + '"? Item akan dipindahkan ke trash.',
        confirmText: 'Ya, hapus',
        cancelText: 'Batal'
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
        toast('error', summary.message, 'Bulk action gagal');
        return;
      }

      ask(summary.variant || 'warning', {
        title: summary.title,
        message: summary.message,
        confirmText: summary.confirmText || 'Lanjutkan',
        cancelText: 'Batal'
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