<?php
// /adiwira/admin/users/?
require_once __DIR__ . '/../_deny.php';

if (!defined('DASHBOARD_CONTEXT') && !defined('ADAM_THEME')) {
    adiwira_admin_404();
}

require_once __DIR__ . '/../_guard.php';
require_once __DIR__ . '/../_notify.php';

[$uid, $sessionRole] = adiwira_require_role($pdo, ['admin'], false);

$page_toasts = function_exists('adiwira_collect_query_toasts')
    ? adiwira_collect_query_toasts()
    : [];

$filter_role   = trim((string)($_GET['role'] ?? ''));
$filter_status = trim((string)($_GET['lock'] ?? ''));
$search        = trim((string)($_GET['q'] ?? ''));

$page = max(1, (int)($_GET['p'] ?? 1));
$perPage = 10;
$offset = ($page - 1) * $perPage;

$where = ["is_deleted = 0"];
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
    $where[] = "(name LIKE :s OR email LIKE :s OR username LIKE :s)";
    $params[':s'] = '%' . $search . '%';
}

$where_sql = implode(' AND ', $where);

$countSql = "SELECT COUNT(*) FROM users WHERE $where_sql";
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
$pages = max(1, (int)ceil($total / max(1, $perPage)));
if ($page > $pages) $page = $pages;

$sql = "SELECT id, email, username, name, role, img, bio, phone, created_at, is_locked
        FROM users
        WHERE $where_sql
        ORDER BY id DESC
        LIMIT :limit OFFSET :offset";
$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':limit', (int)$perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
$stmt->execute();
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

$allRoles = ['author','editor','admin'];
$base = ADMIN_BASE_PATH;

$currentQuery = $_GET;
$currentQuery['page'] = 'admin/users/index';
$returnTo = $base . '/?' . http_build_query($currentQuery);

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
            if (is_int($items[$i]) && !in_array($items[$i], [1, 2, $total - 1, $total], true)) {
                array_splice($items, $i, 1);
                break;
            }
        }
    }

    return $items;
}
$paging_items = build_pagination_items($page, $pages, 9);
?>
<section class="adam-card">
  <h2><?= _e('User Management') ?></h2>

  <form method="get" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;margin-bottom:1rem;">
    <input type="hidden" name="page" value="admin/users/index">

    <input type="text" name="q" placeholder="<?= _e('Search name, email or username...') ?>"
           value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>"
           style="padding:.4rem;min-width:220px">

    <select name="role" style="padding:.4rem;">
      <option value=""><?= _e('-- All Roles --') ?></option>
      <?php foreach ($allRoles as $r): ?>
        <option value="<?= htmlspecialchars($r, ENT_QUOTES, 'UTF-8') ?>" <?= $filter_role === $r ? 'selected' : '' ?>>
          <?= htmlspecialchars(ucfirst($r), ENT_QUOTES, 'UTF-8') ?>
        </option>
      <?php endforeach; ?>
    </select>

    <select name="lock" style="padding:.4rem;">
      <option value=""><?= _e('-- All Status --') ?></option>
      <option value="locked" <?= $filter_status === 'locked' ? 'selected' : '' ?>>Locked / Pending</option>
      <option value="unlocked" <?= $filter_status === 'unlocked' ? 'selected' : '' ?>>Unlocked / Approved</option>
    </select>

    <button class="adam-button" type="submit"><?= _e('Apply') ?></button>
    <a class="adam-cancle" href="<?= htmlspecialchars($base . '/?page=admin/users/index', ENT_QUOTES, 'UTF-8') ?>">Reset</a>

    <div style="margin-left:auto">
      <a class="adam-button" href="<?= htmlspecialchars($base . '/?page=admin/users/save&return_to=' . urlencode($returnTo), ENT_QUOTES, 'UTF-8') ?>">+ Tambah User</a>
    </div>
  </form>

  <form id="bulkForm" method="post" action="<?= htmlspecialchars($base . '/admin/users/bulk_action.php', ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="return_to" value="<?= htmlspecialchars($returnTo, ENT_QUOTES, 'UTF-8') ?>">

    <div style="display:flex;gap:.5rem;align-items:center;margin-bottom:.5rem;flex-wrap:wrap;">
      <label style="display:flex;align-items:center;gap:.4rem;">
        <input type="checkbox" id="selectAll"> Pilih semua di halaman
      </label>

      <select id="bulkAction" name="action" style="padding:.4rem;">
        <option value="">-- Bulk action --</option>
        <option value="change_role"><?= _e('Change Role') ?></option>
        <option value="lock">Lock</option>
        <option value="unlock">Unlock / Approve</option>
        <option value="delete">Hapus (soft)</option>
      </select>

      <select id="bulkRole" name="role" style="padding:.4rem;display:none;">
        <?php foreach ($allRoles as $r): ?>
          <option value="<?= htmlspecialchars($r, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(ucfirst($r), ENT_QUOTES, 'UTF-8') ?></option>
        <?php endforeach; ?>
      </select>

      <button type="submit" class="adam-button"><?= _e('Apply') ?></button>
      <small style="color:var(--adam-muted);margin-left:.5rem;"><?= _e('Bulk only affects checked users.') ?></small>
    </div>

    <div class="adam-table-wrapper">
      <table class="adam-table" style="width:100%;border-collapse:collapse">
        <thead>
          <tr style="text-align:left;border-bottom:1px solid #e6e6e6">
            <th style="width:44px"></th>
            <th>Avatar</th>
            <th><?= _e('Name') ?></th>
            <th>Email / Username</th>
            <th>Role</th>
            <th>Status</th>
            <th>Bio</th>
            <th>Phone</th>
            <th><?= _e('Registered') ?></th>
            <th><?= _e('Actions') ?></th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($users)): ?>
            <tr><td colspan="10" style="padding:1rem;"><?= _e('No users yet.') ?></td></tr>
          <?php else: ?>
            <?php foreach ($users as $u):
              $img = !empty($u['img']) ? $u['img'] : '/static/img/person.svg';
              $isSelf = ((int)$u['id'] === $uid);
              $isLocked = (int)($u['is_locked'] ?? 0) === 1;
              $nameRaw = (string)($u['name'] ?? ($u['email'] ?? ''));
              $name = htmlspecialchars($u['name'] ?? '-', ENT_QUOTES, 'UTF-8');
              $username = $u['username'] ?? '';
              $toggleFormId = 'toggle-lock-form-' . (int)$u['id'];
            ?>
            <tr style="border-bottom:1px solid #f3f3f3">
              <td style="text-align:center">
                <?php if (!$isSelf): ?>
                  <input type="checkbox" class="bulkCheckbox" name="ids[]" value="<?= (int)$u['id'] ?>">
                <?php else: ?>
                  &mdash;
                <?php endif; ?>
              </td>

              <td style="width:56px">
                <img src="<?= htmlspecialchars($img, ENT_QUOTES, 'UTF-8') ?>" alt="" style="height:40px;width:40px;object-fit:cover;border-radius:6px">
              </td>

              <?php if (!empty($username)): ?>
                <td>
                  <a href="<?= htmlspecialchars('/author/' . rawurlencode($username), ENT_QUOTES, 'UTF-8') ?>"
                     class="adam-link"
                     title="Lihat profil <?= $name ?>"
                     target="_blank"
                     rel="noopener noreferrer"><?= $name ?></a>
                </td>
              <?php else: ?>
                <td><?= $name ?></td>
              <?php endif; ?>

              <td>
                <?= htmlspecialchars($u['email'] ?? '-', ENT_QUOTES, 'UTF-8') ?><br>
                <small style="color:#666"><?= htmlspecialchars($u['username'] ?? '-', ENT_QUOTES, 'UTF-8') ?></small>
              </td>

              <td><?= htmlspecialchars($u['role'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>

              <td>
                <?php if ($isLocked): ?>
                  <span style="display:inline-block;padding:.22rem .55rem;border-radius:999px;background:#fff1f2;color:#b42318;border:1px solid #fecdd3;font-size:12px;font-weight:700;">Locked / Pending</span>
                <?php else: ?>
                  <span style="display:inline-block;padding:.22rem .55rem;border-radius:999px;background:#ecfdf3;color:#027a48;border:1px solid #abefc6;font-size:12px;font-weight:700;">Unlocked / Approved</span>
                <?php endif; ?>
              </td>

              <td style="max-width:220px;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;">
                <?= htmlspecialchars($u['bio'] ?? '-', ENT_QUOTES, 'UTF-8') ?>
              </td>

              <td><?= htmlspecialchars($u['phone'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= htmlspecialchars($u['created_at'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>

              <td>
                <a class="adam-ubah" href="<?= htmlspecialchars($base . '/?page=admin/users/save&id=' . (int)$u['id'] . '&return_to=' . urlencode($returnTo), ENT_QUOTES, 'UTF-8') ?>">Edit</a>

                <?php if (!$isSelf): ?>
                  &nbsp;|&nbsp;
                  <button type="button"
                          class="<?= $isLocked ? 'adam-ubah' : 'adam-att' ?> js-user-toggle-lock"
                          data-form-id="<?= htmlspecialchars($toggleFormId, ENT_QUOTES, 'UTF-8') ?>"
                          data-name="<?= htmlspecialchars($nameRaw, ENT_QUOTES, 'UTF-8') ?>"
                          data-mode="<?= $isLocked ? 'unlock' : 'lock' ?>"
                          style="background:none;border:0;padding:0;cursor:pointer;">
                    <?= $isLocked ? 'Approve' : 'Lock' ?>
                  </button>

                  &nbsp;|&nbsp;
                  <button type="button"
                          class="adam-hapus js-user-delete"
                          data-id="<?= (int)$u['id'] ?>"
                          data-name="<?= htmlspecialchars($nameRaw, ENT_QUOTES, 'UTF-8') ?>"
                          data-return-to="<?= htmlspecialchars($returnTo, ENT_QUOTES, 'UTF-8') ?>">
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
  </form>

  <?php foreach ($users as $u):
    $isSelf = ((int)$u['id'] === $uid);
    if ($isSelf) continue;
    $isLocked = (int)($u['is_locked'] ?? 0) === 1;
    $toggleFormId = 'toggle-lock-form-' . (int)$u['id'];
  ?>
    <form id="<?= htmlspecialchars($toggleFormId, ENT_QUOTES, 'UTF-8') ?>"
          method="post"
          action="<?= htmlspecialchars($base . '/admin/users/toggle_lock.php', ENT_QUOTES, 'UTF-8') ?>"
          style="display:none;">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
      <input type="hidden" name="mode" value="<?= $isLocked ? 'unlock' : 'lock' ?>">
      <input type="hidden" name="return_to" value="<?= htmlspecialchars($returnTo, ENT_QUOTES, 'UTF-8') ?>">
    </form>
  <?php endforeach; ?>

  <?php if ($pages > 1): ?>
    <nav class="adam-pagination" style="margin-top:1rem;">
      <?php foreach ($paging_items as $item):
        if ($item === '...') { echo '<span class="dots">…</span> '; continue; }
        $i = (int)$item;
        $q = $_GET;
        $q['p'] = $i;
        $link = $base . '/?' . http_build_query($q);
      ?>
        <?php if ($i === $page): ?>
          <strong><?= $i ?></strong>
        <?php else: ?>
          <a href="<?= htmlspecialchars($link, ENT_QUOTES, 'UTF-8') ?>"><?= $i ?></a>
        <?php endif; ?>
      <?php endforeach; ?>
    </nav>
  <?php endif; ?>

  <form id="newnotif-user-delete-form"
        method="post"
        action="<?= htmlspecialchars($base . '/admin/users/delete.php', ENT_QUOTES, 'UTF-8') ?>"
        style="display:none;">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="id" id="newnotif-user-delete-id">
    <input type="hidden" name="return_to" id="newnotif-user-delete-return-to" value="<?= htmlspecialchars($returnTo, ENT_QUOTES, 'UTF-8') ?>">
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
  const bulkRole = document.getElementById('bulkRole');
  const deleteForm = document.getElementById('newnotif-user-delete-form');
  const deleteIdInput = document.getElementById('newnotif-user-delete-id');
  const deleteReturnTo = document.getElementById('newnotif-user-delete-return-to');

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
    if (bulkRole) bulkRole.style.display = (v === 'change_role') ? 'inline-block' : 'none';
  }

  function checkedCount(){
    return document.querySelectorAll('.bulkCheckbox:checked').length;
  }

  function getBulkSummary(){
    const action = bulkAction ? bulkAction.value : '';
    const count = checkedCount();

    if (!action) {
      return { ok:false, message: <?= json_encode(__('Select a bulk action first.')) ?> };
    }

    if (count < 1) {
      return { ok:false, message: <?= json_encode(__('Select at least one user.')) ?> };
    }

    if (action === 'delete') {
      return {
        ok:true,
        variant:'danger',
        title: <?= json_encode(__('Delete selected users')) ?>,
        message: <?= json_encode(__('')) ?> + count + ' user akan dipindahkan ke trash. Lanjutkan?',
        confirmText: <?= json_encode(__('Yes, delete')) ?>
      };
    }

    if (action === 'change_role') {
      const role = bulkRole ? bulkRole.value : 'author';
      return {
        ok:true,
        variant:'warning',
        title: <?= json_encode(__('Change user role')) ?>,
        message: <?= json_encode(__('Change role of ')) ?> + count + ' user menjadi "' + role + '"?',
        confirmText: <?= json_encode(__('Yes, change')) ?>
      };
    }

    if (action === 'lock') {
      return {
        ok:true,
        variant:'warning',
        title:'Lock user',
        message:'Lock ' + count + ' user terpilih?',
        confirmText: <?= json_encode(__('Yes, lock')) ?>
      };
    }

    if (action === 'unlock') {
      return {
        ok:true,
        variant:'warning',
        title:'Approve / unlock user',
        message:'Approve / unlock ' + count + ' user terpilih?',
        confirmText: <?= json_encode(__('Yes, unlock')) ?>
      };
    }

    return {
      ok:true,
      variant:'warning',
      title: <?= json_encode(__('Confirm bulk action')) ?>,
      message: <?= json_encode(__('Execute action for ')) ?> + count + ' user?',
      confirmText: <?= json_encode(__('Proceed')) ?>
    };
  }

  if (selectAll) {
    selectAll.addEventListener('change', function(){
      const checked = !!this.checked;
      document.querySelectorAll('.bulkCheckbox').forEach(function(cb){
        cb.checked = checked;
      });
    });
  }

  if (bulkAction) {
    bulkAction.addEventListener('change', toggleBulkExtras);
    toggleBulkExtras();
  }

  document.querySelectorAll('.js-user-delete').forEach(function(btn){
    btn.addEventListener('click', function(){
      const id = this.getAttribute('data-id') || '';
      const name = this.getAttribute('data-name') || 'user ini';
      const returnTo = this.getAttribute('data-return-to') || '';

      ask('danger', {
        title: <?= json_encode(__('Delete confirmation')) ?>,
        message: 'Hapus user "' + name + '"? User akan dipindahkan ke trash.',
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

  document.querySelectorAll('.js-user-toggle-lock').forEach(function(btn){
    btn.addEventListener('click', function(){
      const formId = this.getAttribute('data-form-id') || '';
      const mode = this.getAttribute('data-mode') || 'lock';
      const name = this.getAttribute('data-name') || 'user ini';
      const form = formId ? document.getElementById(formId) : null;
      if (!form) return;

      const isUnlock = mode === 'unlock';
      ask('warning', {
        title: isUnlock ? 'Approve / unlock user' : 'Lock user',
        message: isUnlock
          ? 'Approve / unlock user "' + name + '"?'
          : 'Lock user "' + name + '"?',
        confirmText: isUnlock ? 'Ya, unlock' : 'Ya, lock',
        cancelText: <?= json_encode(__('Cancel')) ?>
      }).then(function(ok){
        if (!ok) return;
        form.submit();
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
        confirmText: summary.confirmText || 'Lanjutkan',
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