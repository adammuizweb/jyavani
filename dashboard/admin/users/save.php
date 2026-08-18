<?php
// /adiwira/admin/users/save.php
declare(strict_types=1);

if (!defined('DASHBOARD_CONTEXT') && !defined('ADAM_THEME')) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/../_guard.php';
require_once __DIR__ . '/../_notify.php';

[$uid, $role] = adiwira_require_login($pdo, false);

$errors = [];

$base = ADMIN_BASE_PATH;
$redirect_url = $base . '/?page=admin/users/index';

$return_to = function_exists('adiwira_safe_return_to')
    ? adiwira_safe_return_to((string)($_REQUEST['return_to'] ?? ''), $redirect_url)
    : $redirect_url;

$id = (int)($_GET['id'] ?? ($_POST['id'] ?? 0));
$editing = $id > 0;

$user = null;
$currentActor = authorization_actor($pdo, $uid);
$actorIsSiteOwner = $currentActor !== null && $currentActor['is_site_owner'] === true;
if ($editing) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id AND is_deleted = 0 LIMIT 1");
    $stmt->execute([':id' => $id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        http_response_code(404);
        echo '<p>' . __('User not found.') . '</p>';
        return;
    }
    if ((int)($user['is_site_owner'] ?? 0) === 1 && !$actorIsSiteOwner) {
        adiwira_render_404();
    }
}
$targetIsSiteOwner = $editing && (int)($user['is_site_owner'] ?? 0) === 1;
$credentialsProtected = $targetIsSiteOwner || ($editing && $id === $uid);
if ($editing) {
    if (!user_can($pdo, $uid, 'core.users.update', ['owner_id' => $id])) {
        adiwira_render_404();
    }
} elseif (!$actorIsSiteOwner || !user_can($pdo, $uid, 'core.users.create')) {
    adiwira_render_404();
}
$availableRoles = $pdo->query(
    'SELECT id, slug, name, description, authority_rank, is_system
     FROM roles
     ORDER BY authority_rank DESC, name ASC'
)->fetchAll(PDO::FETCH_ASSOC);
$availableRoleIds = array_map('intval', array_column($availableRoles, 'id'));
$assignedRoleIds = [];
if ($editing) {
    $assignedStmt = $pdo->prepare(
        'SELECT role_id FROM user_roles
         WHERE user_id = :user_id AND (expires_at IS NULL OR expires_at > NOW())
         ORDER BY role_id'
    );
    $assignedStmt->execute([':user_id' => $id]);
    $assignedRoleIds = array_map('intval', $assignedStmt->fetchAll(PDO::FETCH_COLUMN));
} else {
    foreach ($availableRoles as $availableRole) {
        if ((string)$availableRole['slug'] === 'author') {
            $assignedRoleIds = [(int)$availableRole['id']];
            break;
        }
    }
}

$displayImg = '/static/img/jyavani.svg';
if ($editing && !empty($user['img'])) {
    $displayImg = $user['img'];
}

$initial_bio = $user['bio'] ?? '';
$initial_phone = $user['phone'] ?? '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $token = (string)($_POST['csrf_token'] ?? '');
    if (!adiwira_csrf_validate($token)) {
        $errors[] = __('Invalid CSRF token.');
    }

    $email = trim((string)($_POST['email'] ?? ''));
    $username = trim((string)($_POST['username'] ?? ''));
    $name = trim((string)($_POST['name'] ?? ''));
    $plain_password = trim((string)($_POST['password'] ?? ''));
    $password_confirm = trim((string)($_POST['password_confirm'] ?? ''));
    $postedRoleIds = $_POST['role_ids'] ?? [];
    $selectedRoleIds = is_array($postedRoleIds)
        ? array_values(array_unique(array_filter(array_map('intval', $postedRoleIds), static fn(int $roleId): bool => $roleId > 0)))
        : [];
    if ($targetIsSiteOwner || !$actorIsSiteOwner) {
        $selectedRoleIds = $assignedRoleIds;
    }
    if ($credentialsProtected) {
        $email = (string)($user['email'] ?? '');
        $plain_password = '';
        $password_confirm = '';
    }
    $img_url = trim((string)($_POST['img_url'] ?? ''));
    $bio   = trim((string)($_POST['bio'] ?? ''));
    $phone = trim((string)($_POST['phone'] ?? ''));

    $bio   = $bio !== '' ? $bio : null;
    $phone = $phone !== '' ? $phone : null;

    $initial_bio = $_POST['bio'] ?? $initial_bio;
    $initial_phone = $_POST['phone'] ?? $initial_phone;

    if ($email === '') $errors[] = __('Email is required.');
    if ($name === '') $errors[] = __('Name is required.');

    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = __('Invalid email format.');
    }

    if ($username === '') {
        $errors[] = __('Username cannot be empty.');
    } else {
        if (!preg_match('/^[a-zA-Z0-9._-]{3,32}$/', $username)) {
            $errors[] = __('Username can only contain letters, numbers, dots, underscores, hyphens; length 3-32.');
        }
    }

    if (empty($errors)) {
        $sql = "SELECT id FROM users WHERE username = :username AND is_deleted = 0";
        $params = [':username' => $username];
        if ($editing) {
            $sql .= " AND id != :id";
            $params[':id'] = $id;
        }
        $sql .= " LIMIT 1";
        $stmtCheckU = $pdo->prepare($sql);
        $stmtCheckU->execute($params);
        if ($stmtCheckU->fetch()) {
            $errors[] = __('Username already taken by another user.');
        }
    }

    if ($phone !== null && $phone !== '') {
        if (!preg_match('/^[0-9+\-\s]{6,20}$/', $phone)) {
            $errors[] = __('Invalid phone number format.');
        }
    }

    if ($selectedRoleIds === []) {
        $errors[] = __('Select at least one role.');
    } elseif (array_diff($selectedRoleIds, $availableRoleIds) !== []) {
        $errors[] = __('An unknown role was selected.');
    } elseif ($actorIsSiteOwner && !authorization_actor_can_assign_roles($pdo, $uid, $selectedRoleIds)) {
        $errors[] = __('You cannot assign a role above your authority rank.');
    }

    if ($plain_password !== '') {
        if (strlen($plain_password) < 6) {
            $errors[] = __('New password must be at least 6 characters.');
        } elseif ($plain_password !== $password_confirm) {
            $errors[] = __('Password confirmation does not match.');
        }
    }

    if (empty($errors)) {
        $sql = "SELECT id FROM users WHERE email = :email AND is_deleted = 0";
        $params = [':email' => $email];
        if ($editing) {
            $sql .= " AND id != :id";
            $params[':id'] = $id;
        }
        $sql .= " LIMIT 1";
        $stmt2 = $pdo->prepare($sql);
        $stmt2->execute($params);
        if ($stmt2->fetch()) {
            $errors[] = __('Email already in use by another user.');
        }
    }

    if (!$editing && $plain_password === '') {
        $errors[] = __('Password harus diisi untuk membuat user baru.');
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            $targetUserId = $id;
            $assignRoles = $actorIsSiteOwner;
            if ($assignRoles && !authorization_lock_site_owner_actor($pdo, $uid)) {
                throw new RuntimeException('Site Owner authorization changed.');
            }

            if ($editing) {
                $activeOwnerIds = array_map('intval', $pdo->query(
                    'SELECT id
                     FROM users
                     WHERE is_site_owner = 1 AND is_deleted = 0 AND is_locked = 0
                     FOR UPDATE'
                )->fetchAll(PDO::FETCH_COLUMN));
                $targetLock = $pdo->prepare(
                    'SELECT id, is_site_owner FROM users WHERE id = :id AND is_deleted = 0 FOR UPDATE'
                );
                $targetLock->execute([':id' => $id]);
                $lockedTarget = $targetLock->fetch(PDO::FETCH_ASSOC);
                if (!$lockedTarget) {
                    throw new RuntimeException('User is no longer available.');
                }
                if (((int)$lockedTarget['is_site_owner'] === 1) !== $targetIsSiteOwner) {
                    throw new RuntimeException('Site Owner state changed; reload before editing.');
                }
                if ((int)$lockedTarget['is_site_owner'] === 1 && !in_array($uid, $activeOwnerIds, true)) {
                    throw new RuntimeException('Only a Site Owner can update this account.');
                }
                if (!user_can($pdo, $uid, 'core.users.update', ['owner_id' => $id])) {
                    throw new RuntimeException('User update permission changed.');
                }
                if ((int)$lockedTarget['is_site_owner'] === 1) {
                    $assignRoles = false;
                }

                $sql = "UPDATE users
                        SET email = :email,
                            username = :username,
                            name = :name,
                            bio = :bio,
                            phone = :phone,
                            img = :img,
                            updated_at = NOW()
                        WHERE id = :id
                        LIMIT 1";
                $params = [
                    ':email' => $email,
                    ':username' => $username ?: null,
                    ':name' => $name ?: null,
                    ':bio' => $bio,
                    ':phone' => $phone,
                    ':img' => $img_url ?: null,
                    ':id' => $id,
                ];

                if ($plain_password !== '') {
                    $sql = "UPDATE users
                            SET email = :email,
                                username = :username,
                                name = :name,
                                bio = :bio,
                                phone = :phone,
                                password = :password,
                                img = :img,
                                updated_at = NOW()
                            WHERE id = :id
                            LIMIT 1";
                    $params[':password'] = password_hash($plain_password, PASSWORD_DEFAULT);
                }

                $stmtUpd = $pdo->prepare($sql);
                if (!$stmtUpd->execute($params)) {
                    throw new RuntimeException('User update failed.');
                }
            } else {
                $stmtIns = $pdo->prepare("
                    INSERT INTO users
                    (email, username, password, name, bio, phone, role, img, is_locked, created_at, updated_at)
                    VALUES
                    (:email, :username, :password, :name, :bio, :phone, 'none', :img, 0, NOW(), NOW())
                ");
                if (!$stmtIns->execute([
                    ':email' => $email,
                    ':username' => $username ?: null,
                    ':password' => password_hash($plain_password, PASSWORD_DEFAULT),
                    ':name' => $name ?: null,
                    ':bio' => $bio,
                    ':phone' => $phone,
                    ':img' => $img_url ?: null,
                ])) {
                    throw new RuntimeException('User insert failed.');
                }
                $targetUserId = (int)$pdo->lastInsertId();
            }

            if ($assignRoles) {
                if (!authorization_actor_can_assign_roles($pdo, $uid, $selectedRoleIds)) {
                    throw new RuntimeException('Role assignment exceeds actor authority.');
                }
                if (!authorization_assign_roles($pdo, $targetUserId, $selectedRoleIds, $uid)) {
                    throw new RuntimeException('Role assignment failed.');
                }
                if (!authorization_audit(
                    $pdo,
                    'role.assigned',
                    $uid,
                    $targetUserId,
                    'user',
                    (string)$targetUserId,
                    ['role_ids' => $selectedRoleIds]
                )) {
                    throw new RuntimeException('Role assignment audit failed.');
                }
            }

            $pdo->commit();
            if ($targetUserId === $uid) {
                $legacyRoleStmt = $pdo->prepare('SELECT role FROM users WHERE id = :id');
                $legacyRoleStmt->execute([':id' => $targetUserId]);
                $_SESSION['user_role'] = (string)($legacyRoleStmt->fetchColumn() ?: 'none');
            }
            adiwira_redirect_with_flash(
                $return_to,
                'success',
                $editing ? __('User updated successfully.') : __('New user created successfully.')
            );
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[users/save] ' . $e->getMessage());
            $errors[] = $editing ? __('Failed to update user.') : __('Failed to create new user.');
        }
    }
}
?>

<section class="adam-card">
  <h2 class="edit-heading"><?= $editing ? _e('Edit User') : _e('Add User') ?></h2>

  <form method="post" novalidate id="user-save-form">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="id" value="<?= $editing ? (int)$user['id'] : 0 ?>">
    <input type="hidden" name="return_to" value="<?= htmlspecialchars($return_to, ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="img_url" id="inp_img_url" value="<?= htmlspecialchars($_POST['img_url'] ?? ($user['img'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">

    <div class="profile-layout">
      <div class="profile-photo">
        <div class="profile-avatar">
          <div id="upload-loader"
               style="display:none;position:absolute;inset:0;background:rgba(255,255,255,0.8);align-items:center;justify-content:center;z-index:2;">
            <div style="width:34px;height:34px;border-radius:50%;border:4px solid rgba(0,0,0,0.12);border-top-color:#3478f6;animation:spin 1s linear infinite"></div>
          </div>
          <img id="preview-img"
               src="<?= htmlspecialchars($displayImg, ENT_QUOTES, 'UTF-8') ?>"
               alt="Avatar">
          <style>@keyframes spin { to { transform: rotate(360deg); } }</style>
        </div>
      </div>

      <div class="profile-meta">
        <?php if ($editing): ?>
          <div class="profile-status">
            <?php if ((int)($user['is_locked'] ?? 0) === 1): ?>
              <span class="status-badge status-locked"><?=_e('Locked / Pending')?></span>
            <?php else: ?>
              <span class="status-badge status-unlocked"><?=_e('Unlocked / Approved')?></span>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <div class="profile-actions">
          <button type="button"
                  id="btn-open-media-for-profile"
                  class="adam-button"><?=_e('Gallery')?></button>

          <button type="button"
                  id="thumbnail-clear"
                  class="adam-hapus"><?=_e('Clear')?></button>
        </div>

        <button type="button"
                id="btn-view-profile"
                class="adam-ubah"><?=_e('View Profile')?></button>
      </div>

      <div class="profile-fields">
        <label><?=_e('Email')?><br>
          <?php if ($credentialsProtected): ?><input type="hidden" name="email" value="<?= htmlspecialchars((string)($user['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"><?php endif; ?>
          <input type="email" name="<?= $credentialsProtected ? 'protected_email' : 'email' ?>" value="<?= htmlspecialchars($_POST['email'] ?? $user['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>" <?= $credentialsProtected ? 'disabled' : '' ?> style="width:100%;padding:.5rem;margin-top:.4rem;border:1px solid #ddd;border-radius:6px;<?= $credentialsProtected ? 'background:#f2f4f7' : '' ?>">
        </label>

        <label><?=_e('Username')?><br>
          <input type="text" name="username" id="inp_username" value="<?= htmlspecialchars($_POST['username'] ?? $user['username'] ?? '', ENT_QUOTES, 'UTF-8') ?>" style="width:100%;padding:.5rem;margin-top:.4rem;border:1px solid #ddd;border-radius:6px">
          <div style="font-size:12px;color:#666;margin-top:6px"><?=_e('Unique username (alphanumeric, underscore, dot). Length 3-32 characters.')?></div>
        </label>

        <label><?=_e('Name')?><br>
          <input type="text" name="name" value="<?= htmlspecialchars($_POST['name'] ?? $user['name'] ?? '', ENT_QUOTES, 'UTF-8') ?>" style="width:100%;padding:.5rem;margin-top:.4rem;border:1px solid #ddd;border-radius:6px">
        </label>

        <label><?=_e('Phone')?><br>
          <input type="text" name="phone" value="<?= htmlspecialchars($_POST['phone'] ?? $user['phone'] ?? $initial_phone, ENT_QUOTES, 'UTF-8') ?>" placeholder="+62xxxxxxxxx" style="width:100%;padding:.5rem;margin-top:.4rem;border:1px solid #ddd;border-radius:6px">
        </label>

        <label><?=_e('Bio')?><br>
          <textarea name="bio" rows="4" style="width:100%;padding:.5rem;margin-top:.4rem;border:1px solid #ddd;border-radius:6px"><?= htmlspecialchars($_POST['bio'] ?? $user['bio'] ?? $initial_bio, ENT_QUOTES, 'UTF-8') ?></textarea>
        </label>

        <?php if (!$credentialsProtected): ?>
        <label><?=_e('Password')?> <?= $editing ? '<small style="color:#888">' . __('(leave blank to keep current)') . '</small>' : '<small style="color:red">*</small>' ?><br>
          <span class="pw-wrap">
            <input type="password" name="password" autocomplete="new-password" style="width:100%;padding:.5rem;margin-top:.4rem;border:1px solid #ddd;border-radius:6px;padding-right:2.2rem">
            <button type="button" class="pw-toggle" data-toggle="password" aria-label="<?=_e('Show password')?>">
              <?= svg_ico('eye', '', ['class' => 'lucide-icon']) ?>
            </button>
          </span>
        </label>
        <label><?=_e('Confirm Password')?><br>
          <span class="pw-wrap">
            <input type="password" name="password_confirm" autocomplete="new-password" style="width:100%;padding:.5rem;margin-top:.4rem;border:1px solid #ddd;border-radius:6px;padding-right:2.2rem">
            <button type="button" class="pw-toggle" data-toggle="password_confirm" aria-label="<?=_e('Show password')?>">
              <?= svg_ico('eye', '', ['class' => 'lucide-icon']) ?>
            </button>
          </span>
        </label>
        <?php else: ?>
          <div style="padding:.7rem;border:1px solid #e4e7ec;border-radius:7px;color:#667085"><?= $targetIsSiteOwner
              ? _e('Site Owner email and password can only be changed from their own Profile with current-password verification.')
              : _e('Change your own email or password from Profile with current-password verification.') ?></div>
        <?php endif; ?>

        <?php
          $displayRoleIds = isset($_POST['role_ids']) && is_array($_POST['role_ids'])
              ? array_map('intval', $_POST['role_ids'])
              : $assignedRoleIds;
          $rolesProtected = $targetIsSiteOwner || !$actorIsSiteOwner;
          if ($rolesProtected) $displayRoleIds = $assignedRoleIds;
        ?>
        <fieldset style="border:1px solid #ddd;border-radius:8px;padding:.85rem;margin:0;">
          <legend style="font-weight:700;padding:0 .35rem"><?= _e('Roles') ?></legend>
          <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:.55rem;">
            <?php foreach ($availableRoles as $availableRole):
              $availableRoleId = (int)$availableRole['id'];
              $checked = in_array($availableRoleId, $displayRoleIds, true);
            ?>
              <label style="display:flex;gap:.55rem;align-items:flex-start;padding:.65rem;border:1px solid #e4e7ec;border-radius:7px;cursor:<?= $rolesProtected ? 'default' : 'pointer' ?>;">
                <input type="checkbox" name="role_ids[]" value="<?= $availableRoleId ?>" <?= $checked ? 'checked' : '' ?> <?= $rolesProtected ? 'disabled' : '' ?>>
                <span style="display:flex;flex-direction:column;gap:.15rem;min-width:0;">
                  <strong><?= htmlspecialchars((string)$availableRole['name'], ENT_QUOTES, 'UTF-8') ?></strong>
                  <code style="font-size:11px;color:#667085;overflow-wrap:anywhere"><?= htmlspecialchars((string)$availableRole['slug'], ENT_QUOTES, 'UTF-8') ?></code>
                  <?php if (!empty($availableRole['description'])): ?><small style="color:#667085"><?= htmlspecialchars((string)$availableRole['description'], ENT_QUOTES, 'UTF-8') ?></small><?php endif; ?>
                </span>
              </label>
              <?php if ($rolesProtected && $checked): ?>
                <input type="hidden" name="role_ids[]" value="<?= $availableRoleId ?>">
              <?php endif; ?>
            <?php endforeach; ?>
          </div>
          <?php if ($targetIsSiteOwner): ?>
            <div style="font-size:12px;color:#666;margin-top:7px"><?= _e('Site Owner role assignments are protected. Revoke Site Owner access before changing roles.') ?></div>
          <?php elseif (!$actorIsSiteOwner): ?>
            <div style="font-size:12px;color:#666;margin-top:7px"><?= _e('Only a Site Owner can change role assignments.') ?></div>
          <?php else: ?>
            <div style="font-size:12px;color:#666;margin-top:7px"><a href="<?= htmlspecialchars($base . '/?page=admin/users/roles/index', ENT_QUOTES, 'UTF-8') ?>"><?= _e('Manage Roles & Permissions') ?></a></div>
          <?php endif; ?>
        </fieldset>

        <div style="margin-top:1.5rem;">
          <button type="submit" class="adam-button"><?= $editing ? _e('Save Changes') : _e('Create User') ?></button>
          <a href="<?= htmlspecialchars($return_to, ENT_QUOTES, 'UTF-8') ?>" class="adam-cancle" style="margin-left:10px"><?=_e('Cancel')?></a>
        </div>
      </div>

      <div id="upload-error" class="profile-error" style="display:none;"></div>
    </div>

    <div id="media-single-panel"
         style="margin-top:12px;border:1px solid #eee;padding:10px;border-radius:6px;display:none;background:#fff;max-width:320px;text-align:left;">
      <div id="media-single-content"><?=_e('Click on an image in Media to view details & edit.')?></div>
    </div>
  </form>
</section>

<?php
if (!empty($errors) && function_exists('adiwira_bootstrap_toasts_script')) {
    $items = array_map(static fn($msg) => ['type' => 'error', 'message' => (string)$msg], $errors);
    echo adiwira_bootstrap_toasts_script($items);
}
?>
<script src="/static/js/add/modal-helpers.js"></script>
<script src="/static/js/add/media-selector.js"></script>
<script>
(function(){
  const form = document.getElementById('user-save-form');
  const imgInput = document.getElementById('inp_img_url');
  const previewImg = document.getElementById('preview-img');
  const clearBtn = document.getElementById('thumbnail-clear');
  const galleryBtn = document.getElementById('btn-open-media-for-profile');
  const defaultAvatar = <?= json_encode('/static/img/jyavani.svg') ?>;
  const uploadError = document.getElementById('upload-error');

  function setAvatar(url){
    if (previewImg) {
      previewImg.src = (String(url || '').trim() !== '') ? String(url).trim() : defaultAvatar;
    }
    if (imgInput) {
      imgInput.value = (String(url || '').trim() !== '') ? String(url).trim() : defaultAvatar;
    }
    if (uploadError) {
      uploadError.style.display = 'none';
      uploadError.innerText = '';
    }
  }

  // sinkronisasi awal
  setAvatar(<?= json_encode($_POST['img_url'] ?? ($user['img'] ?? $displayImg)) ?>);

  /* ---------- gallery ---------- */
  galleryBtn?.addEventListener('click', function(){
    openMediaSelector({ url: '<?= ADMIN_BASE_PATH ?>/admin/modal_img/index.php?embedded=1' })
      .then(function(detail){
        const m = (typeof normalizeMedia === 'function') ? normalizeMedia(detail) : (detail || null);
        if (!m || !m.url) return;
        setAvatar(m.url);
      })
      .catch(function(err){
        console.error('media selector error', err);
        if (window.NewNotifToast && typeof window.NewNotifToast.show === 'function') {
          window.NewNotifToast.show({
            type: 'error',
            title: 'Gallery',
            message: '<?=__('Failed to select media.')?>'
          });
        } else {
          alert('<?=__('Failed to select media.')?>');
        }
      });
  });

  /* ---------- clear -> default avatar ---------- */
  clearBtn?.addEventListener('click', function(e){
    e.preventDefault();
    setAvatar(defaultAvatar);
  });

  /* ---------- confirm save ---------- */
  if (form) {
    let confirmed = false;

    function askWarning(opts){
      if (window.NewNotifConfirm && typeof window.NewNotifConfirm.warning === 'function') {
        return window.NewNotifConfirm.warning(opts);
      }
      return Promise.resolve(window.confirm(opts.message || '<?=__('Continue this action?')?>'));
    }

    form.addEventListener('submit', function(ev){
      if (confirmed) {
        confirmed = false;
        return;
      }

      ev.preventDefault();
      askWarning({
        title: <?= json_encode($editing ? __('Save user changes') : __('Create new user')) ?>,
        message: <?= json_encode($editing ? __('User changes will be saved. Continue?') : __('New user will be created. Continue?')) ?>,
        confirmText: <?= json_encode($editing ? __('Yes, save') : __('Yes, create')) ?>,
        cancelText: <?= json_encode(__('Cancel')) ?>
      }).then(function(ok){
        if (!ok) return;
        confirmed = true;
        form.submit();
      });
    });
  }

  /* ---------- lihat profile ---------- */
  document.getElementById('btn-view-profile')?.addEventListener('click', function () {
    const usernameFromServer = <?= json_encode($user['username'] ?? '') ?>;
    const usernameField = document.getElementById('inp_username');
    const username = usernameFromServer || (usernameField ? usernameField.value : '');

    if (!username) {
      if (window.NewNotifToast && typeof window.NewNotifToast.show === 'function') {
        window.NewNotifToast.show({
          type: 'error',
          title: 'Username',
          message: '<?=__('Username not available.')?>'
        });
      } else {
        alert('<?=__('Username not available.')?>');
      }
      return;
    }

    const url = '/author/' + encodeURIComponent(username);
    window.open(url, '_blank');
  });

  document.querySelectorAll('.pw-toggle').forEach(function(btn){
    btn.addEventListener('click', function(){
      var wrap = this.closest('.pw-wrap');
      if (!wrap) return;
      var input = wrap.querySelector('input');
      if (!input) return;
      var isPassword = input.getAttribute('type') === 'password';
      input.setAttribute('type', isPassword ? 'text' : 'password');
      this.innerHTML = isPassword
        ? <?= json_encode(svg_ico('eye-off', '', ['class' => 'lucide-icon'])) ?>
        : <?= json_encode(svg_ico('eye', '', ['class' => 'lucide-icon'])) ?>;
    });
  });
})();
</script>
