<?php
// /adiwira/admin/users/?
require_once __DIR__ . '/../_deny.php';

if (!defined('DASHBOARD_CONTEXT') && !defined('ADAM_THEME')) {
    adiwira_admin_404();
}

require_once __DIR__ . '/../_guard.php';
require_once __DIR__ . '/../_notify.php';

[$uid, $sessionRole] = adiwira_require_permission($pdo, 'core.users.read', false);
$currentActor = authorization_actor($pdo, $uid);
$canManageSiteOwners = $currentActor !== null && $currentActor['is_site_owner'] === true;
$canCreateUsers = $canManageSiteOwners && user_can($pdo, $uid, 'core.users.create');
$canBulkAssign = $canManageSiteOwners;
$canBulkLock = user_permission_scope($pdo, $uid, 'core.users.lock') !== null;
$canBulkDelete = user_permission_scope($pdo, $uid, 'core.users.delete') !== null;

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
$allRoles = $pdo->query(
    'SELECT id, slug, name, authority_rank FROM roles ORDER BY authority_rank DESC, name ASC'
)->fetchAll(PDO::FETCH_ASSOC);

if ($filter_role !== '') {
    $where[] = "EXISTS (
        SELECT 1 FROM user_roles filter_ur
        JOIN roles filter_r ON filter_r.id = filter_ur.role_id
        WHERE filter_ur.user_id = users.id AND filter_r.slug = :role
          AND (filter_ur.expires_at IS NULL OR filter_ur.expires_at > NOW())
    )";
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

$sql = "SELECT id, email, username, name, role, is_site_owner, img, bio, phone, created_at, is_locked,
               (SELECT GROUP_CONCAT(r.name ORDER BY r.authority_rank DESC, r.name ASC SEPARATOR ', ')
                FROM user_roles ur JOIN roles r ON r.id = ur.role_id
                WHERE ur.user_id = users.id
                  AND (ur.expires_at IS NULL OR ur.expires_at > NOW())) AS role_names
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
        <option value="<?= htmlspecialchars((string)$r['slug'], ENT_QUOTES, 'UTF-8') ?>" <?= $filter_role === (string)$r['slug'] ? 'selected' : '' ?>>
          <?= htmlspecialchars((string)$r['name'], ENT_QUOTES, 'UTF-8') ?>
        </option>
      <?php endforeach; ?>
    </select>

    <select name="lock" style="padding:.4rem;">
      <option value=""><?= _e('-- All Status --') ?></option>
      <option value="locked" <?= $filter_status === 'locked' ? 'selected' : '' ?>><?=_e('Locked / Pending')?></option>
      <option value="unlocked" <?= $filter_status === 'unlocked' ? 'selected' : '' ?>><?=_e('Unlocked / Approved')?></option>
    </select>

    <button class="adam-button" type="submit"><?= _e('Apply') ?></button>
    <a class="adam-cancle" href="<?= htmlspecialchars($base . '/?page=admin/users/index', ENT_QUOTES, 'UTF-8') ?>"><?=_e('Reset')?></a>

    <?php if ($canCreateUsers): ?><div style="margin-left:auto">
      <a class="adam-button" href="<?= htmlspecialchars($base . '/?page=admin/users/save&return_to=' . urlencode($returnTo), ENT_QUOTES, 'UTF-8') ?>">+ Add User</a>
    </div><?php endif; ?>
  </form>

  <form id="bulkForm" method="post" action="<?= htmlspecialchars($base . '/admin/users/bulk_action.php', ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="return_to" value="<?= htmlspecialchars($returnTo, ENT_QUOTES, 'UTF-8') ?>">

    <div style="display:flex;gap:.5rem;align-items:center;margin-bottom:.5rem;flex-wrap:wrap;">
      <label style="display:flex;align-items:center;gap:.4rem;">
        <input type="checkbox" id="selectAll"> <?=_e('Select all on this page')?>
      </label>

      <select id="bulkAction" name="action" style="padding:.4rem;">
        <option value=""><?=_e('-- Bulk action --')?></option>
        <?php if ($canBulkAssign): ?><option value="change_role"><?= _e('Replace All Roles') ?></option><?php endif; ?>
        <?php if ($canBulkLock): ?><option value="lock"><?=_e('Lock')?></option>
        <option value="unlock"><?=_e('Unlock / Approve')?></option><?php endif; ?>
        <?php if ($canBulkDelete): ?><option value="delete"><?=_e('Delete (soft)')?></option><?php endif; ?>
      </select>

      <select id="bulkRole" name="role_id" style="padding:.4rem;display:none;">
        <?php foreach ($allRoles as $r): ?>
          <option value="<?= (int)$r['id'] ?>"><?= htmlspecialchars((string)$r['name'], ENT_QUOTES, 'UTF-8') ?></option>
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
            <th><?=_e('Avatar')?></th>
            <th><?= _e('Name') ?></th>
            <th><?=_e('Email / Username')?></th>
            <th><?=_e('Role')?></th>
            <th><?=_e('Status')?></th>
            <th><?=_e('Bio')?></th>
            <th><?=_e('Phone')?></th>
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
              $isSiteOwner = (int)($u['is_site_owner'] ?? 0) === 1;
              $nameRaw = (string)($u['name'] ?? ($u['email'] ?? ''));
              $name = htmlspecialchars($u['name'] ?? '-', ENT_QUOTES, 'UTF-8');
              $username = $u['username'] ?? '';
              $toggleFormId = 'toggle-lock-form-' . (int)$u['id'];
              $canMutateUser = !$isSiteOwner || $canManageSiteOwners;
              $canEditUser = $canMutateUser
                  && user_can($pdo, $uid, 'core.users.update', ['owner_id' => (int)$u['id']]);
              $canLockUser = $canMutateUser && user_can($pdo, $uid, 'core.users.lock', ['owner_id' => (int)$u['id']]);
              $canDeleteUser = $canMutateUser && user_can($pdo, $uid, 'core.users.delete', ['owner_id' => (int)$u['id']]);
              $canSelectUser = !$isSelf && !$isSiteOwner && ($canManageSiteOwners || $canLockUser || $canDeleteUser);
            ?>
            <tr style="border-bottom:1px solid #f3f3f3">
              <td style="text-align:center">
                <?php if ($canSelectUser): ?>
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
                     title="<?= __('View profile of') ?> <?= $name ?>"
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

              <td>
                <?= htmlspecialchars((string)($u['role_names'] ?? '-'), ENT_QUOTES, 'UTF-8') ?>
                <?php if ($isSiteOwner): ?>
                  <span style="display:inline-block;margin-left:.35rem;padding:.16rem .45rem;border-radius:999px;background:#eef2ff;color:#3730a3;border:1px solid #c7d2fe;font-size:11px;font-weight:700;"><?= _e('Site Owner') ?></span>
                <?php endif; ?>
              </td>

              <td>
                <?php if ($isLocked): ?>
                  <span style="display:inline-block;padding:.22rem .55rem;border-radius:999px;background:#fff1f2;color:#b42318;border:1px solid #fecdd3;font-size:12px;font-weight:700;"><?=_e('Locked / Pending')?></span>
                <?php else: ?>
                  <span style="display:inline-block;padding:.22rem .55rem;border-radius:999px;background:#ecfdf3;color:#027a48;border:1px solid #abefc6;font-size:12px;font-weight:700;"><?=_e('Unlocked / Approved')?></span>
                <?php endif; ?>
              </td>

              <td style="max-width:220px;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;">
                <?= htmlspecialchars($u['bio'] ?? '-', ENT_QUOTES, 'UTF-8') ?>
              </td>

              <td><?= htmlspecialchars($u['phone'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= htmlspecialchars($u['created_at'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>

              <td>
                <?php if ($canEditUser): ?>
                  <a class="adam-ubah" href="<?= htmlspecialchars($base . '/?page=admin/users/save&id=' . (int)$u['id'] . '&return_to=' . urlencode($returnTo), ENT_QUOTES, 'UTF-8') ?>"><?= svg_ico('pen', '', ['style' => 'width:12px;height:12px;vertical-align:middle;margin-right:2px']) ?><?=_e('Edit')?></a>
                <?php else: ?>
                  <span style="color:var(--adam-muted);font-size:12px"><?= $canMutateUser ? _e('No access') : _e('Protected') ?></span>
                <?php endif; ?>

                <?php if ($canManageSiteOwners && !$isSelf && !$isLocked): ?>
                  &nbsp;|&nbsp;
                  <button type="button"
                          class="adam-ubah js-site-owner"
                          data-id="<?= (int)$u['id'] ?>"
                          data-name="<?= htmlspecialchars($nameRaw, ENT_QUOTES, 'UTF-8') ?>"
                          data-mode="<?= $isSiteOwner ? 'revoke' : 'grant' ?>"
                          style="background:none;border:0;padding:0;cursor:pointer;color:inherit;">
                    <?= $isSiteOwner ? __('Revoke Site Owner') : __('Grant Site Owner') ?>
                  </button>
                <?php endif; ?>

                <?php if (!$isSelf && ($canLockUser || $canDeleteUser)): ?>
                  &nbsp;|&nbsp;
                  <?php if ($canLockUser): ?><button type="button"
                          class="<?= $isLocked ? 'adam-ubah' : 'adam-att' ?> js-user-toggle-lock"
                          data-form-id="<?= htmlspecialchars($toggleFormId, ENT_QUOTES, 'UTF-8') ?>"
                          data-name="<?= htmlspecialchars($nameRaw, ENT_QUOTES, 'UTF-8') ?>"
                          data-mode="<?= $isLocked ? 'unlock' : 'lock' ?>"
                          style="background:none;border:0;padding:0;cursor:pointer;color:inherit;">
                    <?= $isLocked ? __('Approve') : __('Lock') ?>
                  </button><?php endif; ?>

                  <?php if ($canDeleteUser): ?>&nbsp;|&nbsp;
                  <button type="button"
                          class="adam-hapus js-user-delete"
                          data-id="<?= (int)$u['id'] ?>"
                          data-name="<?= htmlspecialchars($nameRaw, ENT_QUOTES, 'UTF-8') ?>"
                          data-return-to="<?= htmlspecialchars($returnTo, ENT_QUOTES, 'UTF-8') ?>">
                    <?= svg_ico('trash-2', '', ['style' => 'width:12px;height:12px;vertical-align:middle;margin-right:2px']) ?><?=_e('Delete')?>
                  </button><?php endif; ?>
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

  <?php if ($canManageSiteOwners): ?>
    <div class="adam-modal" id="siteOwnerModal" style="display:none" aria-hidden="true">
      <div class="adam-modal__panel" style="max-width:440px" role="dialog" aria-modal="true" aria-labelledby="siteOwnerModalTitle">
        <div class="adam-modal__title" id="siteOwnerModalTitle"><?= _e('Change Site Owner access') ?></div>
        <div class="adam-modal__text" id="siteOwnerModalText" style="margin-bottom:1rem;line-height:1.5"></div>
        <form method="post" action="<?= htmlspecialchars($base . '/admin/users/site_owner.php', ENT_QUOTES, 'UTF-8') ?>" id="siteOwnerForm">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="id" id="siteOwnerUserId">
          <input type="hidden" name="mode" id="siteOwnerMode">
          <input type="hidden" name="return_to" value="<?= htmlspecialchars($returnTo, ENT_QUOTES, 'UTF-8') ?>">
          <label for="siteOwnerPassword" style="display:block;font-weight:600;margin-bottom:.45rem"><?= _e('Confirm your current password') ?></label>
          <input type="password" name="current_password" id="siteOwnerPassword" autocomplete="current-password" required style="width:100%;padding:.65rem;border:1px solid #d0d5dd;border-radius:7px;margin-bottom:1.25rem">
          <div class="adam-modal__actions" style="display:flex;gap:.5rem;justify-content:flex-end">
            <button type="button" class="adam-cancle" id="siteOwnerCancel"><?= _e('Cancel') ?></button>
            <button type="submit" class="adam-button" id="siteOwnerSubmit"><?= _e('Continue') ?></button>
          </div>
        </form>
      </div>
    </div>
  <?php endif; ?>
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
  const siteOwnerModal = document.getElementById('siteOwnerModal');
  const siteOwnerText = document.getElementById('siteOwnerModalText');
  const siteOwnerUserId = document.getElementById('siteOwnerUserId');
  const siteOwnerMode = document.getElementById('siteOwnerMode');
  const siteOwnerPassword = document.getElementById('siteOwnerPassword');
  const siteOwnerSubmit = document.getElementById('siteOwnerSubmit');
  const siteOwnerCancel = document.getElementById('siteOwnerCancel');

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
        message: <?= json_encode(__('')) ?> + count + <?= json_encode(__(' user(s) will be moved to trash. Continue?')) ?>,
        confirmText: <?= json_encode(__('Yes, delete')) ?>
      };
    }

    if (action === 'change_role') {
      const role = bulkRole && bulkRole.selectedOptions.length ? bulkRole.selectedOptions[0].textContent : '';
      return {
        ok:true,
        variant:'warning',
        title: <?= json_encode(__('Change user role')) ?>,
        message: <?= json_encode(__('Change role of ')) ?> + count + <?= json_encode(__(' user(s) to')) ?> + ' "' + role + '"?',
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
      const name = this.getAttribute('data-name') || '<?=__('this user')?>';
      const returnTo = this.getAttribute('data-return-to') || '';

      ask('danger', {
        title: <?= json_encode(__('Delete confirmation')) ?>,
        message: '<?=__('Delete user')?> "' + name + '"? <?=__('User will be moved to trash.')?>',
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
      const name = this.getAttribute('data-name') || '<?=__('this user')?>';
      const form = formId ? document.getElementById(formId) : null;
      if (!form) return;

      const isUnlock = mode === 'unlock';
      ask('warning', {
        title: isUnlock ? <?= json_encode(__('Approve / unlock user')) ?> : <?= json_encode(__('Lock user')) ?>,
        message: isUnlock
          ? <?= json_encode(__('Approve / unlock user "')) ?> + name + '"?' 
          : <?= json_encode(__('Lock user "')) ?> + name + '"?',
        confirmText: isUnlock ? '<?=__('Yes, unlock')?>' : '<?=__('Yes, lock')?>',
        cancelText: <?= json_encode(__('Cancel')) ?>
      }).then(function(ok){
        if (!ok) return;
        form.submit();
      });
    });
  });

  function closeSiteOwnerModal(){
    if (!siteOwnerModal) return;
    siteOwnerModal.style.display = 'none';
    siteOwnerModal.setAttribute('aria-hidden', 'true');
    if (siteOwnerPassword) siteOwnerPassword.value = '';
  }

  document.querySelectorAll('.js-site-owner').forEach(function(btn){
    btn.addEventListener('click', function(){
      if (!siteOwnerModal || !siteOwnerUserId || !siteOwnerMode) return;
      const mode = this.getAttribute('data-mode') === 'revoke' ? 'revoke' : 'grant';
      const id = this.getAttribute('data-id') || '';
      const name = this.getAttribute('data-name') || <?= json_encode(__('this user')) ?>;
      siteOwnerUserId.value = id;
      siteOwnerMode.value = mode;
      if (siteOwnerText) {
        siteOwnerText.textContent = mode === 'grant'
          ? <?= json_encode(__('Grant Site Owner access to')) ?> + ' "' + name + '"?'
          : <?= json_encode(__('Revoke Site Owner access from')) ?> + ' "' + name + '"?';
      }
      if (siteOwnerSubmit) {
        siteOwnerSubmit.textContent = mode === 'grant'
          ? <?= json_encode(__('Grant access')) ?>
          : <?= json_encode(__('Revoke access')) ?>;
      }
      siteOwnerModal.style.display = 'flex';
      siteOwnerModal.setAttribute('aria-hidden', 'false');
      window.setTimeout(function(){ siteOwnerPassword?.focus(); }, 0);
    });
  });

  siteOwnerCancel?.addEventListener('click', closeSiteOwnerModal);
  siteOwnerModal?.addEventListener('click', function(ev){
    if (ev.target === siteOwnerModal) closeSiteOwnerModal();
  });
  document.addEventListener('keydown', function(ev){
    if (ev.key === 'Escape' && siteOwnerModal?.style.display === 'flex') {
      closeSiteOwnerModal();
    }
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
