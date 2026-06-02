<?php
declare(strict_types=1);

// /adiwira/admin/bin/photo/?
require_once __DIR__ . '/../../_deny.php';

if (!defined('DASHBOARD_CONTEXT') && !defined('ADAM_THEME')) {
    adiwira_admin_404();
}

require_once __DIR__ . '/../../_guard.php';
require_once __DIR__ . '/../../_notify.php';

// bin photo tetap admin-only
[$uid, $role] = adiwira_require_role($pdo, ['admin'], false);

// fallback bila masih ada route lama kirim ?msg= / ?err=
$page_toasts = function_exists('adiwira_collect_query_toasts')
    ? adiwira_collect_query_toasts()
    : [];

// filters
$filter_status = (string)($_GET['status'] ?? '');
$search        = trim((string)($_GET['q'] ?? ''));

// pagination
$page_num = max(1, (int)($_GET['p'] ?? 1));
$per_page = 20;
$offset   = ($page_num - 1) * $per_page;

// where
$where = ["p.is_deleted = 1", "p.type = 'photo'"];
$params = [];

if ($filter_status !== '') {
    $where[] = "p.status = :status";
    $params[':status'] = $filter_status;
}

if ($search !== '') {
    $where[] = "(p.title LIKE :search OR p.slug LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}

$where_sql = implode(' AND ', $where);

// count
$count_sql = "SELECT COUNT(*) FROM posts p WHERE $where_sql";
$totalStmt = $pdo->prepare($count_sql);
$totalStmt->execute($params);
$total = (int)$totalStmt->fetchColumn();
$pages = max(1, (int)ceil($total / $per_page));

// data
$sql = "
SELECT
  p.id, p.title, p.slug, p.status, p.thumbnail, p.created_at, p.deleted_at,
  u.name AS created_by,
  u.username AS author_username,
  (
    SELECT COUNT(*)
    FROM post_media_items pm
    WHERE pm.post_id = p.id
  ) AS media_count
FROM posts p
LEFT JOIN users u ON u.id = p.created_by
WHERE $where_sql
ORDER BY p.deleted_at DESC, p.id DESC
LIMIT :limit OFFSET :offset
";
$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$photos = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

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
$canBulk = true;

$currentQuery = $_GET;
$currentQuery['page'] = 'admin/bin/photo/index';
$currentReturnTo = $base . '/?' . http_build_query($currentQuery);
?>

<section class="adam-card">
  <h2>Bin / Trash — Photo Posts</h2>

  <form method="get" style="margin-bottom:1rem;display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;">
    <input type="hidden" name="page" value="admin/bin/photo/index">

    <input type="text" name="q" placeholder="Cari judul atau slug..."
      value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>"
      style="padding:.4rem;min-width:220px">

    <select name="status" style="padding:.4rem;">
      <option value="">-- Semua Status --</option>
      <option value="draft" <?= $filter_status === 'draft' ? 'selected' : '' ?>>Draft</option>
      <option value="published" <?= $filter_status === 'published' ? 'selected' : '' ?>>Published</option>
      <option value="private" <?= $filter_status === 'private' ? 'selected' : '' ?>>Private</option>
    </select>

    <button type="submit" class="adam-button">Terapkan</button>
    <a href="<?= htmlspecialchars($base . '/?page=admin/bin/photo/index', ENT_QUOTES, 'UTF-8') ?>" class="adam-cancle">Reset</a>

    <span style="margin-left:auto;color:var(--adam-muted);">
      Total trash: <strong><?= (int)$total ?></strong>
    </span>
  </form>

  <form id="binPhotoBulkForm" method="post" action="<?= htmlspecialchars($base . '/admin/bin/photo/bulk_action.php', ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="return_to" value="<?= htmlspecialchars($currentReturnTo, ENT_QUOTES, 'UTF-8') ?>">

    <div style="display:flex;gap:.5rem;align-items:center;margin-bottom:.6rem;flex-wrap:wrap;">
      <label style="display:flex;align-items:center;gap:.4rem;">
        <input type="checkbox" id="selectAllBinPhoto"> Pilih semua di halaman
      </label>

      <select id="bulkActionBinPhoto" name="action" style="padding:.4rem;">
        <option value="">-- Bulk action --</option>
        <option value="restore">Restore</option>
        <option value="delete_permanent">Hapus Permanen</option>
      </select>

      <button type="submit" class="adam-button">Terapkan</button>
      <small style="color:var(--adam-muted);">Bulk hanya mempengaruhi item yang dicentang.</small>
    </div>

    <div class="adam-table-wrapper">
      <table class="adam-table" style="margin-top:.5rem;">
        <thead>
          <tr>
            <th style="width:40px"></th>
            <th>Foto</th>
            <th>Judul</th>
            <th>Status</th>
            <th>Jumlah Foto</th>
            <th>Deleted</th>
            <th>Penulis</th>
            <th>Aksi</th>
          </tr>
        </thead>
        <tbody>
        <?php if (empty($photos)): ?>
          <tr><td colspan="8" style="padding:1rem;">Trash photo post kosong.</td></tr>
        <?php else: ?>
          <?php foreach ($photos as $p): ?>
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
              $thumb = trim((string)($p['thumbnail'] ?? ''));
            ?>
            <tr class="adam-row">
              <td style="text-align:center;">
                <input type="checkbox" class="bulkCheckboxBinPhoto" name="ids[]" value="<?= (int)$p['id'] ?>">
              </td>

              <td style="width:72px;">
                <?php if ($thumb !== ''): ?>
                  <img src="<?= htmlspecialchars($thumb, ENT_QUOTES, 'UTF-8') ?>"
                       alt=""
                       style="width:56px;height:56px;object-fit:cover;border-radius:10px;border:1px solid var(--adam-border);display:block;">
                <?php else: ?>
                  <div style="width:56px;height:56px;border-radius:10px;border:1px solid var(--adam-border);background:var(--adam-surface-3);"></div>
                <?php endif; ?>
              </td>

              <td>
                <div style="font-weight:600;">
                  <?= htmlspecialchars((string)($p['title'] ?? '-'), ENT_QUOTES, 'UTF-8') ?>
                </div>
                <div style="color:var(--adam-muted);font-size:.85rem;">
                  /<?= htmlspecialchars((string)($p['slug'] ?? ''), ENT_QUOTES, 'UTF-8') ?>/
                </div>
              </td>

              <td>
                <span class="adam-status <?= htmlspecialchars($statusClass, ENT_QUOTES, 'UTF-8') ?>"
                      role="status" aria-label="<?= htmlspecialchars(ucfirst($status), ENT_QUOTES, 'UTF-8') ?>">
                  <span class="adam-status-icon"><?= $iconSvg ?></span>
                  <span class="adam-status-text"><?= htmlspecialchars(ucfirst($status), ENT_QUOTES, 'UTF-8') ?></span>
                </span>
              </td>

              <td><?= (int)($p['media_count'] ?? 0) ?></td>

              <td><?= htmlspecialchars(!empty($p['deleted_at']) ? format_date_ddmmyyyy_time_bracket((string)$p['deleted_at']) : '-', ENT_QUOTES, 'UTF-8') ?></td>

              <td>
                <?php
                  $authorName = $p['created_by'] ?? '-';
                  $authorUsername = $p['author_username'] ?? '';
                  if ($authorUsername !== '') {
                    $authorHref = '/author/' . rawurlencode($authorUsername) . '/';
                    echo '<a class="adam-penulis" href="' . htmlspecialchars($authorHref, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars((string)$authorName, ENT_QUOTES, 'UTF-8') . '</a>';
                  } else {
                    echo htmlspecialchars((string)$authorName, ENT_QUOTES, 'UTF-8');
                  }
                ?>
              </td>

              <td>
                <button type="button"
                        class="adam-link-button js-bin-photo-restore"
                        data-id="<?= (int)$p['id'] ?>"
                        data-title="<?= htmlspecialchars((string)($p['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                        data-return-to="<?= htmlspecialchars($currentReturnTo, ENT_QUOTES, 'UTF-8') ?>">
                  Restore
                </button>

                &nbsp;<span class="muted-divider">|</span>&nbsp;

                <button type="button"
                        class="adam-link-button js-bin-photo-delete-permanent"
                        data-id="<?= (int)$p['id'] ?>"
                        data-title="<?= htmlspecialchars((string)($p['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                        data-return-to="<?= htmlspecialchars($currentReturnTo, ENT_QUOTES, 'UTF-8') ?>">
                  Hapus Permanen
                </button>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </form>

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
        $query['page'] = 'admin/bin/photo/index';
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

  <form id="bin-photo-restore-form" method="post" action="<?= htmlspecialchars($base . '/admin/bin/photo/restore.php', ENT_QUOTES, 'UTF-8') ?>" style="display:none;">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="id" id="bin-photo-restore-id">
    <input type="hidden" name="return_to" id="bin-photo-restore-return-to" value="<?= htmlspecialchars($currentReturnTo, ENT_QUOTES, 'UTF-8') ?>">
  </form>

  <form id="bin-photo-delete-form" method="post" action="<?= htmlspecialchars($base . '/admin/bin/photo/delete_permanent.php', ENT_QUOTES, 'UTF-8') ?>" style="display:none;">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="id" id="bin-photo-delete-id">
    <input type="hidden" name="return_to" id="bin-photo-delete-return-to" value="<?= htmlspecialchars($currentReturnTo, ENT_QUOTES, 'UTF-8') ?>">
  </form>
</section>

<?php
if (!empty($page_toasts) && function_exists('adiwira_bootstrap_toasts_script')) {
    echo adiwira_bootstrap_toasts_script($page_toasts);
}
?>

<script>
(function(){
  const selectAll = document.getElementById('selectAllBinPhoto');
  const bulkForm = document.getElementById('binPhotoBulkForm');
  const bulkAction = document.getElementById('bulkActionBinPhoto');

  const restoreForm = document.getElementById('bin-photo-restore-form');
  const restoreId = document.getElementById('bin-photo-restore-id');
  const restoreReturnTo = document.getElementById('bin-photo-restore-return-to');

  const deleteForm = document.getElementById('bin-photo-delete-form');
  const deleteId = document.getElementById('bin-photo-delete-id');
  const deleteReturnTo = document.getElementById('bin-photo-delete-return-to');

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

  function checkedCount(){
    return document.querySelectorAll('.bulkCheckboxBinPhoto:checked').length;
  }

  function getBulkSummary(){
    const action = bulkAction ? bulkAction.value : '';
    const count = checkedCount();

    if (!action) {
      return { ok:false, message:'Pilih bulk action terlebih dahulu.' };
    }

    if (count < 1) {
      return { ok:false, message:'Pilih minimal satu photo post.' };
    }

    if (action === 'restore') {
      return {
        ok: true,
        variant: 'warning',
        title: 'Restore photo post terpilih',
        message: 'Sebanyak ' + count + ' photo post akan direstore dari trash. Lanjutkan?',
        confirmText: 'Ya, restore'
      };
    }

    if (action === 'delete_permanent') {
      return {
        ok: true,
        variant: 'danger',
        title: 'Hapus permanen photo post',
        message: 'Sebanyak ' + count + ' photo post akan dihapus permanen. Relasi kategori dan item medianya juga akan dibersihkan.',
        confirmText: 'Ya, hapus permanen'
      };
    }

    return {
      ok: false,
      message: 'Aksi bulk tidak dikenal.'
    };
  }

  if (selectAll) {
    selectAll.addEventListener('change', function(){
      const checked = !!this.checked;
      document.querySelectorAll('.bulkCheckboxBinPhoto').forEach(function(cb){
        cb.checked = checked;
      });
    });
  }

  document.querySelectorAll('.js-bin-photo-restore').forEach(function(btn){
    btn.addEventListener('click', function(){
      const id = this.getAttribute('data-id') || '';
      const title = this.getAttribute('data-title') || 'photo post ini';
      const returnTo = this.getAttribute('data-return-to') || '';

      ask('warning', {
        title: 'Restore photo post',
        message: 'Restore photo post "' + title + '" dari trash?',
        confirmText: 'Ya, restore',
        cancelText: 'Batal'
      }).then(function(ok){
        if (!ok) return;
        if (!restoreForm || !restoreId) return;
        restoreId.value = id;
        if (restoreReturnTo) restoreReturnTo.value = returnTo;
        restoreForm.submit();
      });
    });
  });

  document.querySelectorAll('.js-bin-photo-delete-permanent').forEach(function(btn){
    btn.addEventListener('click', function(){
      const id = this.getAttribute('data-id') || '';
      const title = this.getAttribute('data-title') || 'photo post ini';
      const returnTo = this.getAttribute('data-return-to') || '';

      ask('danger', {
        title: 'Hapus permanen',
        message: 'Hapus permanen photo post "' + title + '"? Relasi kategori dan item medianya akan ikut dibersihkan. Aksi ini tidak bisa dibatalkan.',
        confirmText: 'Ya, hapus permanen',
        cancelText: 'Batal'
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