<?php
declare(strict_types=1);

// /adiwira/admin/bin/users/?
require_once __DIR__ . '/../../_deny.php';

if (!defined('DASHBOARD_CONTEXT') && !defined('ADAM_THEME')) {
    adiwira_admin_404();
}

require_once __DIR__ . '/../../_guard.php';
require_once __DIR__ . '/../../_notify.php';

// bin users hanya admin
[$uid, $role] = adiwira_require_role($pdo, ['admin'], false);

// fallback bila masih ada route lama kirim ?msg= / ?err=
$page_toasts = function_exists('adiwira_collect_query_toasts')
    ? adiwira_collect_query_toasts()
    : [];

// filters
$filter_role   = trim((string)($_GET['role'] ?? ''));
$filter_status = trim((string)($_GET['lock'] ?? '')); // locked | unlocked
$search        = trim((string)($_GET['q'] ?? ''));

// pagination
$page_num = max(1, (int)($_GET['p'] ?? 1));
$per_page = 15;
$offset   = ($page_num - 1) * $per_page;

// where
$where = ["is_deleted = 1"];
$params = [];

if ($filter_role !== '') {
    $where[] = "role = :role";
    $params[':role'] = $filter_role;
}

if ($filter_status === 'locked') {
    $where[] = "is_locked = 1";
} elseif ($filter_status === 'unlocked') {
    $where[] = "is_locked = 0";
}

if ($search !== '') {
    $where[] = "(name LIKE :search OR email LIKE :search OR username LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}

$where_sql = implode(' AND ', $where);

// count
$count_sql = "SELECT COUNT(*) FROM users WHERE $where_sql";
$totalStmt = $pdo->prepare($count_sql);
$totalStmt->execute($params);
$total = (int)$totalStmt->fetchColumn();
$pages = max(1, (int)ceil($total / $per_page));

if ($page_num > $pages) {
    $page_num = $pages;
    $offset = ($page_num - 1) * $per_page;
}

// data
$sql = "
    SELECT id, email, username, name, role, img, bio, phone, created_at, updated_at, is_locked
    FROM users
    WHERE $where_sql
    ORDER BY updated_at DESC, id DESC
    LIMIT :limit OFFSET :offset
";
$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$users = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

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
$allRoles = ['author','editor','admin'];
$base = ADMIN_BASE_PATH;
$canBulk = true;

$currentQuery = $_GET;
$currentQuery['page'] = 'admin/bin/users/index';
$currentReturnTo = $base . '/?' . http_build_query($currentQuery);
?>

<section class="adam-card">
  <h2>Bin / Trash — Users</h2>

  <form method="get" style="margin-bottom:1rem;display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;">
    <input type="hidden" name="page" value="admin/bin/users/index">

    <input type="text" name="q" placeholder="Cari nama, email atau username..."
      value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>"
      style="padding:.4rem;min-width:220px">

    <select name="role" style="padding:.4rem;">
      <option value="">-- Semua Role --</option>
      <?php foreach ($allRoles as $r): ?>
        <option value="<?= htmlspecialchars($r, ENT_QUOTES, 'UTF-8') ?>" <?= $filter_role === $r ? 'selected' : '' ?>>
          <?= htmlspecialchars(ucfirst($r), ENT_QUOTES, 'UTF-8') ?>
        </option>
      <?php endforeach; ?>
    </select>

    <select name="lock" style="padding:.4rem;">
      <option value="">-- Semua Status --</option>
      <option value="locked" <?= $filter_status === 'locked' ? 'selected' : '' ?>>Locked / Pending</option>
      <option value="unlocked" <?= $filter_status === 'unlocked' ? 'selected' : '' ?>>Unlocked / Approved</option>
    </select>

    <button type="submit" class="adam-button">Terapkan</button>
    <a href="<?= htmlspecialchars($base . '/?page=admin/bin/users/index', ENT_QUOTES, 'UTF-8') ?>" class="adam-cancle">Reset</a>

    <span style="margin-left:auto;color:var(--adam-muted);">
      Total trash: <strong><?= (int)$total ?></strong>
    </span>
  </form>

  <form id="binUsersBulkForm" method="post" action="<?= htmlspecialchars($base . '/admin/bin/users/bulk_action.php', ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="return_to" value="<?= htmlspecialchars($currentReturnTo, ENT_QUOTES, 'UTF-8') ?>">

    <div style="display:flex;gap:.5rem;align-items:center;margin-bottom:.6rem;flex-wrap:wrap;">
      <label style="display:flex;align-items:center;gap:.4rem;">
        <input type="checkbox" id="selectAllBinUsers"> Pilih semua di halaman
      </label>

      <select id="bulkActionBinUsers" name="action" style="padding:.4rem;">
        <option value="">-- Bulk action --</option>
        <option value="restore">Restore</option>
        <option value="delete_permanent">Hapus Permanen</option>
      </select>

      <button type="submit" class="adam-button">Terapkan</button>
      <small style="color:var(--adam-muted);">Bulk mempengaruhi user yang dicentang.</small>
    </div>

    <div class="adam-table-wrapper">
      <table class="adam-table" style="width:100%;border-collapse:collapse">
        <thead>
          <tr>
            <th style="width:40px"></th>
            <th>Avatar</th>
            <th>Nama</th>
            <th>Email / Username</th>
            <th>Role</th>
            <th>Status</th>
            <th>Phone</th>
            <th>Dihapus</th>
            <th>Aksi</th>
          </tr>
        </thead>
        <tbody>
        <?php if (empty($users)): ?>
          <tr><td colspan="9" style="padding:1rem;">Trash user kosong.</td></tr>
        <?php else: ?>
          <?php foreach ($users as $u): ?>
            <?php
              $img = !empty($u['img']) ? (string)$u['img'] : '/static/img/person.svg';
              $isLocked = (int)($u['is_locked'] ?? 0) === 1;
              $labelName = (string)($u['name'] ?? ($u['email'] ?? 'User'));
            ?>
            <tr class="adam-row">
              <td style="text-align:center;">
                <input type="checkbox" class="bulkCheckboxBinUsers" name="ids[]" value="<?= (int)$u['id'] ?>">
              </td>

              <td style="width:56px">
                <img src="<?= htmlspecialchars($img, ENT_QUOTES, 'UTF-8') ?>"
                     alt=""
                     style="height:40px;width:40px;object-fit:cover;border-radius:6px">
              </td>

              <td>
                <div style="font-weight:600;">
                  <?= htmlspecialchars((string)($u['name'] ?? '-'), ENT_QUOTES, 'UTF-8') ?>
                </div>
                <div style="color:var(--adam-muted);font-size:.85rem;">
                  #<?= (int)$u['id'] ?>
                </div>
              </td>

              <td>
                <?= htmlspecialchars((string)($u['email'] ?? '-'), ENT_QUOTES, 'UTF-8') ?><br>
                <small style="color:var(--adam-muted)"><?= htmlspecialchars((string)($u['username'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></small>
              </td>

              <td><?= htmlspecialchars((string)($u['role'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>

              <td>
                <?php if ($isLocked): ?>
                  <span style="display:inline-block;padding:.22rem .55rem;border-radius:999px;background:#fff1f2;color:#b42318;border:1px solid #fecdd3;font-size:12px;font-weight:700;">Locked / Pending</span>
                <?php else: ?>
                  <span style="display:inline-block;padding:.22rem .55rem;border-radius:999px;background:#ecfdf3;color:#027a48;border:1px solid #abefc6;font-size:12px;font-weight:700;">Unlocked / Approved</span>
                <?php endif; ?>
              </td>

              <td><?= htmlspecialchars((string)($u['phone'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>

              <td>
                <?= htmlspecialchars(format_date_ddmmyyyy_time_bracket((string)($u['updated_at'] ?? '')), ENT_QUOTES, 'UTF-8') ?>
              </td>

              <td>
                <button type="button"
                        class="adam-link-button js-bin-user-restore"
                        data-id="<?= (int)$u['id'] ?>"
                        data-title="<?= htmlspecialchars($labelName, ENT_QUOTES, 'UTF-8') ?>"
                        data-return-to="<?= htmlspecialchars($currentReturnTo, ENT_QUOTES, 'UTF-8') ?>">
                  Restore
                </button>

                &nbsp;<span class="muted-divider">|</span>&nbsp;

                <button type="button"
                        class="adam-link-button js-bin-user-delete-permanent"
                        data-id="<?= (int)$u['id'] ?>"
                        data-title="<?= htmlspecialchars($labelName, ENT_QUOTES, 'UTF-8') ?>"
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
        $query['page'] = 'admin/bin/users/index';
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

  <form id="bin-user-restore-form" method="post" action="<?= htmlspecialchars($base . '/admin/bin/users/restore.php', ENT_QUOTES, 'UTF-8') ?>" style="display:none;">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="id" id="bin-user-restore-id">
    <input type="hidden" name="return_to" id="bin-user-restore-return-to" value="<?= htmlspecialchars($currentReturnTo, ENT_QUOTES, 'UTF-8') ?>">
  </form>

  <form id="bin-user-delete-form" method="post" action="<?= htmlspecialchars($base . '/admin/bin/users/delete_permanent.php', ENT_QUOTES, 'UTF-8') ?>" style="display:none;">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="id" id="bin-user-delete-id">
    <input type="hidden" name="return_to" id="bin-user-delete-return-to" value="<?= htmlspecialchars($currentReturnTo, ENT_QUOTES, 'UTF-8') ?>">
  </form>
</section>

<?php
if (!empty($page_toasts) && function_exists('adiwira_bootstrap_toasts_script')) {
    echo adiwira_bootstrap_toasts_script($page_toasts);
}
?>

<script>
(function(){
  const selectAll = document.getElementById('selectAllBinUsers');
  const bulkForm = document.getElementById('binUsersBulkForm');
  const bulkAction = document.getElementById('bulkActionBinUsers');

  const restoreForm = document.getElementById('bin-user-restore-form');
  const restoreId = document.getElementById('bin-user-restore-id');
  const restoreReturnTo = document.getElementById('bin-user-restore-return-to');

  const deleteForm = document.getElementById('bin-user-delete-form');
  const deleteId = document.getElementById('bin-user-delete-id');
  const deleteReturnTo = document.getElementById('bin-user-delete-return-to');

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
    return document.querySelectorAll('.bulkCheckboxBinUsers:checked').length;
  }

  function getBulkSummary(){
    const action = bulkAction ? bulkAction.value : '';
    const count = checkedCount();

    if (!action) {
      return { ok:false, message:'Pilih bulk action terlebih dahulu.' };
    }

    if (count < 1) {
      return { ok:false, message:'Pilih minimal satu user.' };
    }

    if (action === 'restore') {
      return {
        ok: true,
        variant: 'warning',
        title: 'Restore user terpilih',
        message: 'Sebanyak ' + count + ' user akan direstore dari trash. Lanjutkan?',
        confirmText: 'Ya, restore'
      };
    }

    if (action === 'delete_permanent') {
      return {
        ok: true,
        variant: 'danger',
        title: 'Hapus permanen user',
        message: 'Sebanyak ' + count + ' user akan dihapus permanen. Aksi ini tidak bisa dibatalkan.',
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
      document.querySelectorAll('.bulkCheckboxBinUsers').forEach(function(cb){
        cb.checked = checked;
      });
    });
  }

  document.querySelectorAll('.js-bin-user-restore').forEach(function(btn){
    btn.addEventListener('click', function(){
      const id = this.getAttribute('data-id') || '';
      const title = this.getAttribute('data-title') || 'user ini';
      const returnTo = this.getAttribute('data-return-to') || '';

      ask('warning', {
        title: 'Restore user',
        message: 'Restore user "' + title + '" dari trash?',
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

  document.querySelectorAll('.js-bin-user-delete-permanent').forEach(function(btn){
    btn.addEventListener('click', function(){
      const id = this.getAttribute('data-id') || '';
      const title = this.getAttribute('data-title') || 'user ini';
      const returnTo = this.getAttribute('data-return-to') || '';

      ask('danger', {
        title: 'Hapus permanen',
        message: 'Hapus permanen user "' + title + '"? Aksi ini tidak bisa dibatalkan.',
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