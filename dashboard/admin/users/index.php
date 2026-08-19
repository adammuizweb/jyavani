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

$sql = "SELECT id, email, username, name, role, is_site_owner, img, phone, created_at, is_locked,
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
<section class="adam-card users-page">
  <div class="toolbar-top users-toolbar">
    <div class="users-heading">
      <h2 class="page-heading"><?= _e('User Management') ?></h2>
      <p><?= _e('Site Owner is the highest-trust account. It can manage roles, permissions, Core updates, plugins, and other Site Owners.') ?></p>
    </div>

  <form method="get" class="toolbar-filter users-filter">
    <input type="hidden" name="page" value="admin/users/index">

    <input type="text" name="q" placeholder="<?= _e('Search name, email or username...') ?>"
           value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>"
           class="inp users-search">

    <select name="role" class="inp">
      <option value=""><?= _e('-- All Roles --') ?></option>
      <?php foreach ($allRoles as $r): ?>
        <option value="<?= htmlspecialchars((string)$r['slug'], ENT_QUOTES, 'UTF-8') ?>" <?= $filter_role === (string)$r['slug'] ? 'selected' : '' ?>>
          <?= htmlspecialchars((string)$r['name'], ENT_QUOTES, 'UTF-8') ?>
        </option>
      <?php endforeach; ?>
    </select>

    <select name="lock" class="inp">
      <option value=""><?= _e('-- All Status --') ?></option>
      <option value="locked" <?= $filter_status === 'locked' ? 'selected' : '' ?>><?= _e('Locked') ?></option>
      <option value="unlocked" <?= $filter_status === 'unlocked' ? 'selected' : '' ?>><?= _e('Active') ?></option>
    </select>

    <button class="adam-button" type="submit"><?= _e('Apply') ?></button>
    <a class="adam-cancle" href="<?= htmlspecialchars($base . '/?page=admin/users/index', ENT_QUOTES, 'UTF-8') ?>"><?=_e('Reset')?></a>

  </form>

    <?php if ($canCreateUsers): ?>
      <a class="adam-button toolbar-add" href="<?= htmlspecialchars($base . '/?page=admin/users/save&return_to=' . urlencode($returnTo), ENT_QUOTES, 'UTF-8') ?>"><?= _e('+ Add User') ?></a>
    <?php endif; ?>
  </div>

  <form id="bulkForm" method="post" action="<?= htmlspecialchars($base . '/admin/users/bulk_action.php', ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="return_to" value="<?= htmlspecialchars($returnTo, ENT_QUOTES, 'UTF-8') ?>">

    <div class="bulk-bar users-bulk-bar">
      <label class="check-row">
        <input type="checkbox" id="selectAll"> <?=_e('Select all on this page')?>
      </label>

      <select id="bulkAction" name="action" class="inp">
        <option value=""><?=_e('-- Bulk action --')?></option>
        <?php if ($canBulkAssign): ?><option value="change_role"><?= _e('Replace All Roles') ?></option><?php endif; ?>
        <?php if ($canBulkLock): ?><option value="lock"><?=_e('Lock')?></option>
        <option value="unlock"><?=_e('Unlock / Approve')?></option><?php endif; ?>
        <?php if ($canBulkDelete): ?><option value="delete"><?=_e('Delete (soft)')?></option><?php endif; ?>
      </select>

      <select id="bulkRole" name="role_id" class="inp" style="display:none;">
        <?php foreach ($allRoles as $r): ?>
          <option value="<?= (int)$r['id'] ?>"><?= htmlspecialchars((string)$r['name'], ENT_QUOTES, 'UTF-8') ?></option>
        <?php endforeach; ?>
      </select>

      <button type="submit" class="adam-button"><?= _e('Apply') ?></button>
      <small class="adam-muted"><?= _e('Bulk only affects checked users.') ?></small>

      <div class="cols-toggle ml-auto">
        <button type="button" class="cols-toggle-btn js-user-cols-toggle" title="<?= _e('Columns') ?>" aria-expanded="false"><?= svg_ico('columns-2') ?></button>
        <div class="cols-dropdown users-cols-dropdown">
          <label class="cols-opt"><input type="checkbox" data-col="col-user-contact" checked> <?= _e('Contact') ?></label>
          <label class="cols-opt"><input type="checkbox" data-col="col-user-role" checked> <?= _e('Role') ?></label>
          <label class="cols-opt"><input type="checkbox" data-col="col-user-status" checked> <?= _e('Status') ?></label>
          <label class="cols-opt"><input type="checkbox" data-col="col-user-phone"> <?= _e('Phone') ?></label>
          <label class="cols-opt"><input type="checkbox" data-col="col-user-registered" checked> <?= _e('Registered') ?></label>
        </div>
      </div>
    </div>

    <div class="adam-table-wrapper">
      <table class="adam-table users-table">
        <thead>
          <tr>
            <th class="th-narrow"></th>
            <th><?= _e('Name') ?></th>
            <th class="col-user-contact"><?= _e('Contact') ?></th>
            <th class="col-user-role"><?= _e('Role') ?></th>
            <th class="col-user-status"><?= _e('Status') ?></th>
            <th class="col-user-phone col-hidden"><?= _e('Phone') ?></th>
            <th class="col-user-registered"><?= _e('Registered') ?></th>
            <th class="users-actions-heading"><span class="sr-only"><?= _e('Actions') ?></span></th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($users)): ?>
            <tr><td colspan="8" class="empty-state"><?= _e('No users yet.') ?></td></tr>
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
              $roleNames = array_values(array_filter(array_map('trim', explode(',', (string)($u['role_names'] ?? '')))));
              $canChangeSiteOwner = $canManageSiteOwners && !$isSelf && !$isLocked;
              $hasUserActions = $canEditUser || $canChangeSiteOwner || (!$isSelf && ($canLockUser || $canDeleteUser));
            ?>
            <tr class="adam-row">
              <td class="td-center">
                <?php if ($canSelectUser): ?>
                  <input type="checkbox" class="bulkCheckbox" name="ids[]" value="<?= (int)$u['id'] ?>">
                <?php else: ?>
                  &mdash;
                <?php endif; ?>
              </td>

              <td>
                <div class="user-identity">
                  <img src="<?= htmlspecialchars($img, ENT_QUOTES, 'UTF-8') ?>" alt="" class="user-avatar">
                  <div class="user-identity-copy">
                    <?php if (!empty($username)): ?>
                      <a href="<?= htmlspecialchars('/author/' . rawurlencode($username), ENT_QUOTES, 'UTF-8') ?>"
                         class="user-name"
                         title="<?= __('View profile of') ?> <?= $name ?>"
                         target="_blank"
                         rel="noopener noreferrer"><?= $name ?></a>
                    <?php else: ?>
                      <span class="user-name"><?= $name ?></span>
                    <?php endif; ?>
                    <span class="user-id">#<?= (int)$u['id'] ?></span>
                    <?php if ($isSiteOwner): ?>
                      <span class="user-owner-badge"><?= svg_ico('shield-check') ?> <?= _e('Site Owner') ?></span>
                    <?php endif; ?>
                  </div>
                </div>
              </td>

              <td class="col-user-contact">
                <a class="user-email" href="mailto:<?= htmlspecialchars((string)($u['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($u['email'] ?? '-', ENT_QUOTES, 'UTF-8') ?></a>
                <span class="user-username"><?= $username !== '' ? '@' . htmlspecialchars((string)$username, ENT_QUOTES, 'UTF-8') : '-' ?></span>
              </td>

              <td class="col-user-role">
                <div class="user-role-list">
                  <?php if ($roleNames === []): ?>
                    <span class="user-role-empty">-</span>
                  <?php else: foreach ($roleNames as $roleName): ?>
                    <span class="user-role-badge"><?= htmlspecialchars($roleName, ENT_QUOTES, 'UTF-8') ?></span>
                  <?php endforeach; endif; ?>
                </div>
              </td>

              <td class="col-user-status">
                <?php if ($isLocked): ?>
                  <span class="user-status-badge is-locked"><?= svg_ico('lock') ?> <?= _e('Locked') ?></span>
                <?php else: ?>
                  <span class="user-status-badge is-active"><span class="user-status-dot" aria-hidden="true"></span><?= _e('Active') ?></span>
                <?php endif; ?>
              </td>

              <td class="col-user-phone col-hidden"><?= htmlspecialchars($u['phone'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
              <td class="col-user-registered" title="<?= htmlspecialchars((string)($u['created_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                <?= htmlspecialchars(format_date_ddmmyyyy($u['created_at'] ?? null), ENT_QUOTES, 'UTF-8') ?>
              </td>

              <td class="users-actions-cell">
                <div class="user-actions">
                  <button type="button" class="user-actions-toggle" aria-haspopup="menu" aria-expanded="false" aria-label="<?= htmlspecialchars(__('Actions') . ': ' . $nameRaw, ENT_QUOTES, 'UTF-8') ?>" <?= $hasUserActions ? '' : 'disabled' ?>>
                    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="5" cy="12" r="1.8"/><circle cx="12" cy="12" r="1.8"/><circle cx="19" cy="12" r="1.8"/></svg>
                  </button>
                  <?php if ($hasUserActions): ?>
                    <div class="user-actions-menu" role="menu" hidden>
                      <?php if ($canEditUser): ?>
                        <a role="menuitem" href="<?= htmlspecialchars($base . '/?page=admin/users/save&id=' . (int)$u['id'] . '&return_to=' . urlencode($returnTo), ENT_QUOTES, 'UTF-8') ?>"><?= svg_ico('pen') ?> <?= _e('Edit') ?></a>
                      <?php endif; ?>

                      <?php if ($canChangeSiteOwner): ?>
                        <button type="button" role="menuitem" class="js-site-owner"
                                data-id="<?= (int)$u['id'] ?>"
                                data-name="<?= htmlspecialchars($nameRaw, ENT_QUOTES, 'UTF-8') ?>"
                                data-mode="<?= $isSiteOwner ? 'revoke' : 'grant' ?>">
                          <?= svg_ico('shield-check') ?> <?= $isSiteOwner ? __('Revoke Site Owner') : __('Grant Site Owner') ?>
                        </button>
                      <?php endif; ?>

                      <?php if (!$isSelf && $canLockUser): ?>
                        <button type="button" role="menuitem" class="js-user-toggle-lock"
                                data-form-id="<?= htmlspecialchars($toggleFormId, ENT_QUOTES, 'UTF-8') ?>"
                                data-name="<?= htmlspecialchars($nameRaw, ENT_QUOTES, 'UTF-8') ?>"
                                data-mode="<?= $isLocked ? 'unlock' : 'lock' ?>">
                          <?= svg_ico('lock') ?> <?= $isLocked ? __('Approve') : __('Lock') ?>
                        </button>
                      <?php endif; ?>

                      <?php if (!$isSelf && $canDeleteUser): ?>
                        <button type="button" role="menuitem" class="js-user-delete is-danger"
                                data-id="<?= (int)$u['id'] ?>"
                                data-name="<?= htmlspecialchars($nameRaw, ENT_QUOTES, 'UTF-8') ?>"
                                data-return-to="<?= htmlspecialchars($returnTo, ENT_QUOTES, 'UTF-8') ?>">
                          <?= svg_ico('trash-2') ?> <?= _e('Delete') ?>
                        </button>
                      <?php endif; ?>
                    </div>
                  <?php endif; ?>
                </div>
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
  const userColsToggle = document.querySelector('.js-user-cols-toggle');
  const userColsDropdown = document.querySelector('.users-cols-dropdown');
  const userColCheckboxes = userColsDropdown ? userColsDropdown.querySelectorAll('input[data-col]') : [];

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

  (function setupUserColumns(){
    const storageKey = 'users_columns';

    function loadState(){
      try {
        const saved = localStorage.getItem(storageKey);
        return saved ? JSON.parse(saved) : null;
      } catch (error) {
        return null;
      }
    }

    function saveState(state){
      try { localStorage.setItem(storageKey, JSON.stringify(state)); }
      catch (error) {}
    }

    function setColumn(column, visible){
      document.querySelectorAll('.' + column).forEach(function(element){
        element.classList.toggle('col-hidden', !visible);
      });
    }

    const saved = loadState();
    userColCheckboxes.forEach(function(checkbox){
      const column = checkbox.getAttribute('data-col');
      const visible = saved && Object.prototype.hasOwnProperty.call(saved, column)
        ? saved[column] !== false
        : checkbox.checked;
      checkbox.checked = visible;
      setColumn(column, visible);
      checkbox.addEventListener('change', function(){
        setColumn(column, this.checked);
        const state = loadState() || {};
        state[column] = this.checked;
        saveState(state);
      });
    });

    userColsToggle?.addEventListener('click', function(event){
      event.stopPropagation();
      const open = !userColsDropdown?.classList.contains('open');
      userColsDropdown?.classList.toggle('open', open);
      this.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
    userColsDropdown?.addEventListener('click', function(event){ event.stopPropagation(); });
    document.addEventListener('click', function(){
      userColsDropdown?.classList.remove('open');
      userColsToggle?.setAttribute('aria-expanded', 'false');
    });
  })();

  function closeUserActionMenus(except){
    document.querySelectorAll('.user-actions-menu').forEach(function(menu){
      if (menu === except) return;
      menu.hidden = true;
      menu.closest('.user-actions')?.querySelector('.user-actions-toggle')?.setAttribute('aria-expanded', 'false');
    });
  }

  document.querySelectorAll('.user-actions-toggle:not(:disabled)').forEach(function(toggle){
    toggle.addEventListener('click', function(event){
      event.stopPropagation();
      const menu = this.parentElement?.querySelector('.user-actions-menu');
      if (!menu) return;
      const willOpen = menu.hidden;
      closeUserActionMenus(willOpen ? menu : null);
      menu.hidden = !willOpen;
      this.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
      if (!willOpen) return;

      menu.style.visibility = 'hidden';
      const triggerRect = this.getBoundingClientRect();
      const menuRect = menu.getBoundingClientRect();
      const left = Math.max(12, Math.min(window.innerWidth - menuRect.width - 12, triggerRect.right - menuRect.width));
      const top = triggerRect.bottom + menuRect.height + 12 <= window.innerHeight
        ? triggerRect.bottom + 6
        : Math.max(12, triggerRect.top - menuRect.height - 6);
      menu.style.left = left + 'px';
      menu.style.top = top + 'px';
      menu.style.visibility = 'visible';
    });
  });
  document.querySelectorAll('.user-actions-menu').forEach(function(menu){
    menu.addEventListener('click', function(){ closeUserActionMenus(); });
  });
  document.addEventListener('click', function(){ closeUserActionMenus(); });
  window.addEventListener('resize', function(){ closeUserActionMenus(); });
  window.addEventListener('scroll', function(){ closeUserActionMenus(); }, true);

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
    if (ev.key === 'Escape') closeUserActionMenus();
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
