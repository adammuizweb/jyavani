<?php
declare(strict_types=1);

// lokasi file: /adiwira/admin/profile/?
require_once __DIR__ . '/../_deny.php';

if (!defined('DASHBOARD_CONTEXT') && !defined('ADAM_THEME')) {
    adiwira_admin_404();
}

require_once __DIR__ . '/../_guard.php';
require_once __DIR__ . '/../_notify.php';

[$uid, $role] = adiwira_require_permission($pdo, 'core.profile.manage', false);

if (!function_exists('profile_safe_redirect')) {
    function profile_safe_redirect(string $url): void
    {
        if (!headers_sent()) {
            header('Location: ' . $url);
            exit;
        }

        echo '<!doctype html><html><head><meta charset="utf-8">';
        echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
        echo '<script>window.location.href=' . json_encode($url) . ';</script>';
        echo '<noscript><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"></noscript>';
        echo '</head><body></body></html>';
        exit;
    }
}

if (!function_exists('profile_redirect_with_flash')) {
    function profile_redirect_with_flash(string $type, string $message, string $url): void
    {
        if (function_exists('adiwira_redirect_with_flash')) {
            adiwira_redirect_with_flash($url, $type, $message);
            exit;
        }

        $_SESSION['flash'] = $_SESSION['flash'] ?? [];
        $_SESSION['flash'][] = [
            'type' => $type,
            'text' => $message,
        ];

        profile_safe_redirect($url);
    }
}

$errors = [];

$stmtUser = $pdo->prepare("
    SELECT *
    FROM users
    WHERE id = :id
      AND is_deleted = 0
    LIMIT 1
");
$stmtUser->execute([':id' => $uid]);
$user = $stmtUser->fetch(PDO::FETCH_ASSOC) ?: null;

if (!$user) {
    if (function_exists('logout_user')) {
        logout_user();
    } else {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }
    profile_safe_redirect('/login.php');
}
$currentUserIsSiteOwner = (int)($user['is_site_owner'] ?? 0) === 1;

$base = ADMIN_BASE_PATH;
$self_url = $base . '/?page=admin/profile/index';
$dashboard_url = $base . '/?page=admin/settings/index';

$initialBio = (string)($user['bio'] ?? '');
$initialPhone = (string)($user['phone'] ?? '');

$displayName = user_avatar_display_name([
    'name' => $_POST['name'] ?? ($user['name'] ?? ''),
    'username' => $user['username'] ?? '',
    'email' => $_POST['email'] ?? ($user['email'] ?? ''),
], __('User'));
$displayImg = user_avatar_image_url(array_key_exists('img_url', $_POST)
    ? $_POST['img_url']
    : ($user['img'] ?? ''));

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $token = (string)($_POST['csrf_token'] ?? '');
    if (!adiwira_csrf_validate($token)) {
        $errors[] = __('Invalid CSRF token.');
    }

    $action = (string)($_POST['action'] ?? '');

    if ($action === 'delete_account' && empty($errors)) {
        $password_confirm = (string)($_POST['del_password'] ?? '');
        $blockedUntil = (int)($_SESSION['profile_reauth_blocked_until'] ?? 0);

        if ($currentUserIsSiteOwner) {
            $errors[] = __('A Site Owner cannot delete their own account. Revoke Site Owner access from another Site Owner account first.');
        } elseif ($blockedUntil > time()) {
            $errors[] = __('Too many password attempts. Try again later.');
        } elseif ($password_confirm === '') {
            $errors[] = __('Password is required to delete account.');
        } elseif (!password_verify($password_confirm, (string)($user['password'] ?? ''))) {
            $failures = (int)($_SESSION['profile_reauth_failures'] ?? 0) + 1;
            $_SESSION['profile_reauth_failures'] = $failures;
            if ($failures >= 5) {
                $_SESSION['profile_reauth_blocked_until'] = time() + 900;
                unset($_SESSION['profile_reauth_failures']);
            }
            usleep(250000);
            $errors[] = __('Wrong password, account deletion failed.');
        } else {
            unset($_SESSION['profile_reauth_failures'], $_SESSION['profile_reauth_blocked_until']);
            $deleteResult = authorization_change_user_status(
                $pdo,
                $uid,
                'delete',
                $uid,
                'user.self_deleted'
            );
            if ($deleteResult === 'last_site_owner') {
                $errors[] = __('The final active Site Owner cannot be deleted.');
            } elseif ($deleteResult !== 'ok') {
                $errors[] = __('Account deletion failed.');
            }

            if ($deleteResult === 'ok') {
                if (function_exists('logout_user')) {
                    logout_user();
                } else {
                    $_SESSION = [];
                    if (session_status() === PHP_SESSION_ACTIVE) {
                        session_destroy();
                    }
                }

                profile_safe_redirect('/?msg=account_deleted');
            }
        }
    } elseif ($action === 'save_profile' && empty($errors)) {
        $name   = trim((string)($_POST['name'] ?? ''));
        $email  = trim((string)($_POST['email'] ?? ''));
        $imgUrl = user_avatar_image_url($_POST['img_url'] ?? '');
        $currentPass = (string)($_POST['current_password'] ?? '');
        $pass   = trim((string)($_POST['password'] ?? ''));
        $pass2  = trim((string)($_POST['password_confirm'] ?? ''));
        $bio    = trim((string)($_POST['bio'] ?? ''));
        $phone  = trim((string)($_POST['phone'] ?? ''));

        $bioDb   = $bio !== '' ? $bio : null;
        $phoneDb = $phone !== '' ? $phone : null;

        $initialBio = (string)($_POST['bio'] ?? $initialBio);
        $initialPhone = (string)($_POST['phone'] ?? $initialPhone);

        if ($name === '') {
            $errors[] = __('Name is required.');
        }

        if ($email === '') {
            $errors[] = __('Email is required.');
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = __('Invalid email format.');
        }

        if ($phoneDb !== null && !preg_match('/^[0-9+\-\s]{6,20}$/', $phoneDb)) {
            $errors[] = __('Invalid phone number format.');
        }

        if ($pass !== '') {
            if (strlen($pass) < 6) {
                $errors[] = __('Password must be at least 6 characters.');
            }
            if ($pass !== $pass2) {
                $errors[] = __('Password confirmation does not match.');
            }
        }

        $sensitiveChange = $pass !== '' || $email !== (string)($user['email'] ?? '');
        if ($sensitiveChange) {
            $blockedUntil = (int)($_SESSION['profile_reauth_blocked_until'] ?? 0);
            if ($blockedUntil > time()) {
                $errors[] = __('Too many password attempts. Try again later.');
            } elseif ($currentPass === '') {
                $errors[] = __('Current password is required.');
            } elseif (!password_verify($currentPass, (string)($user['password'] ?? ''))) {
                $failures = (int)($_SESSION['profile_reauth_failures'] ?? 0) + 1;
                $_SESSION['profile_reauth_failures'] = $failures;
                if ($failures >= 5) {
                    $_SESSION['profile_reauth_blocked_until'] = time() + 900;
                    unset($_SESSION['profile_reauth_failures']);
                }
                usleep(250000);
                $errors[] = __('Current password is incorrect.');
            } else {
                unset($_SESSION['profile_reauth_failures'], $_SESSION['profile_reauth_blocked_until']);
            }
        }

        if ($email !== (string)($user['email'] ?? '') && empty($errors)) {
            $stmtCheck = $pdo->prepare("
                SELECT id
                FROM users
                WHERE email = :email
                  AND id != :id
                  AND is_deleted = 0
                LIMIT 1
            ");
            $stmtCheck->execute([
                ':email' => $email,
                ':id'    => $uid,
            ]);

            if ($stmtCheck->fetch()) {
                $errors[] = __('Email already used by another user.');
            }
        }

        if (empty($errors)) {
            $sql = "
                UPDATE users
                SET name = :name,
                    email = :email,
                    img = :img,
                    bio = :bio,
                    phone = :phone,
                    updated_at = NOW()
            ";

            $params = [
                ':name'  => $name,
                ':email' => $email,
                ':img'   => $imgUrl !== '' ? $imgUrl : null,
                ':bio'   => $bioDb,
                ':phone' => $phoneDb,
                ':id'    => $uid,
            ];

            if ($pass !== '') {
                $sql .= ", password = :password";
                $params[':password'] = password_hash($pass, PASSWORD_DEFAULT);
            }

            $sql .= " WHERE id = :id LIMIT 1";

            $stmtUpd = $pdo->prepare($sql);
            if ($stmtUpd->execute($params)) {
                $_SESSION['user_name'] = $name;
                $_SESSION['user_email'] = $email;

                $user['name']  = $name;
                $user['email'] = $email;
                $user['img']   = $imgUrl;
                $user['bio']   = $bioDb ?? '';
                $user['phone'] = $phoneDb ?? '';
                do_action('profile_after_save', (int)$user['id'], $pdo, $_POST);

                profile_redirect_with_flash('success', __('Profile updated successfully.'), $self_url);
            } else {
                $errors[] = __('Failed to save to database.');
            }
        }
    }
}
?>

<section class="adam-card user-editor profile-editor">
  <h2 class="edit-heading"><?=_e('Edit My Profile')?></h2>

  <form method="post" novalidate id="profile-save-form" data-unsaved-guard<?= (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'save_profile' && $errors) ? ' data-unsaved-guard-initial-dirty' : '' ?>>
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="action" value="save_profile">
    <input type="hidden" name="img_url" id="inp_img_url" value="<?= htmlspecialchars($displayImg, ENT_QUOTES, 'UTF-8') ?>">

    <div class="profile-layout">
      <div class="profile-photo">
        <div class="profile-avatar">
          <div id="upload-loader" class="profile-loader">
            <span><?=_e('Uploading...')?></span>
          </div>
          <?= user_avatar_html($displayImg, $displayName, [
              'image_class' => 'profile-avatar-image',
              'fallback_class' => 'profile-avatar-initial',
              'image_attributes' => ['id' => 'preview-img'],
              'fallback_attributes' => ['id' => 'preview-initial'],
              'alt' => $displayName,
          ]) ?>
        </div>
      </div>

      <div class="profile-meta">
        <div class="profile-status">
          <?php if ((int)($user['is_locked'] ?? 0) === 0): ?>
            <span class="status-badge status-unlocked">
              <?= svg_ico('circle-check') ?>
              <span><?=_e('Unlocked / Approved')?></span>
            </span>
          <?php else: ?>
            <span class="status-badge status-locked">
              <?= svg_ico('lock') ?>
              <span><?=_e('Locked / Pending')?></span>
            </span>
          <?php endif; ?>
        </div>

        <div class="profile-actions">
          <label class="profile-direct-upload" hidden>
            <?=_e('Upload')?>
            <input type="file" id="file-uploader" accept="image/png, image/jpeg, image/webp">
          </label>

          <button type="button"
                  id="btn-open-media-for-profile"
                  class="profile-action profile-action--primary">
            <?= svg_ico('image') ?>
            <span><?=_e('Gallery')?></span>
          </button>

          <button type="button"
                  id="thumbnail-clear"
                  class="profile-action profile-action--danger"
                  aria-label="<?= htmlspecialchars(__('Clear'), ENT_QUOTES, 'UTF-8') ?>"
                  title="<?= htmlspecialchars(__('Clear'), ENT_QUOTES, 'UTF-8') ?>">
            <?= svg_ico('trash-2') ?>
            <span class="profile-action-label--compact"><?=_e('Clear')?></span>
          </button>
        </div>

        <button type="button"
                id="btn-view-profile"
                class="profile-action profile-action--secondary">
          <?= svg_ico('external-link') ?>
          <span><?=_e('View Profile')?></span>
        </button>
      </div>

      <div class="profile-fields">
        <div class="profile-details-grid">
          <label class="profile-field">
            <span class="profile-field-label"><?=_e('Full Name')?></span>
            <input class="adam-input user-form-control"
                   type="text"
                   name="name"
                   id="profile-name-input"
                   value="<?= htmlspecialchars($_POST['name'] ?? ($user['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
          </label>

          <label class="profile-field">
            <span class="profile-field-label"><?=_e('Email (Login)')?></span>
            <input class="adam-input user-form-control"
                   type="email"
                   name="email"
                   value="<?= htmlspecialchars($_POST['email'] ?? ($user['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
          </label>

          <label class="profile-field">
            <span class="profile-field-label"><?=_e('Username')?></span>
            <input class="adam-input user-form-control"
                   type="text"
                   id="inp_username"
                   value="<?= htmlspecialchars($_POST['username'] ?? ($user['username'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                   disabled>
          </label>

          <label class="profile-field">
            <span class="profile-field-label"><?=_e('Phone')?></span>
            <input class="adam-input user-form-control"
                   type="text"
                   name="phone"
                   value="<?= htmlspecialchars($_POST['phone'] ?? ($user['phone'] ?? $initialPhone), ENT_QUOTES, 'UTF-8') ?>"
                   placeholder="+62xxxxxxxxx">
          </label>

          <label class="profile-field profile-field--wide">
            <span class="profile-field-label"><?=_e('Bio / About Me')?></span>
            <textarea class="adam-input user-form-control"
                      name="bio"
                      rows="4"><?= htmlspecialchars($_POST['bio'] ?? ($user['bio'] ?? $initialBio), ENT_QUOTES, 'UTF-8') ?></textarea>
          </label>
        </div>
        <?php do_action('profile_after_fields', $user, $pdo); ?>

        <section class="profile-password-section" aria-labelledby="profile-password-heading">
          <div class="profile-section-heading">
            <span class="profile-section-icon"><?= svg_ico('lock') ?></span>
            <div>
              <div class="profile-section-title-row">
                <h3 id="profile-password-heading"><?=_e('Change Password')?></h3>
                <span class="profile-optional-badge"><?=_e('Optional')?></span>
              </div>
              <p><?= _e('Enter your current password to change your email or password.') ?></p>
            </div>
          </div>

          <div class="profile-password-grid">
            <label class="profile-field">
              <span class="profile-field-label"><?= _e('Current Password') ?></span>
              <span class="pw-wrap">
                <input class="adam-input user-form-control"
                       type="password"
                       name="current_password"
                       autocomplete="current-password"
                       data-unsaved-guard-ignore>
                <button type="button" class="pw-toggle" data-toggle="current_password" aria-label="<?= _e('Show password') ?>">
                  <?= svg_ico('eye', '', ['class' => 'lucide-icon']) ?>
                </button>
              </span>
            </label>

            <label class="profile-field">
              <span class="profile-field-label"><?=_e('New Password')?></span>
              <span class="pw-wrap">
                <input class="adam-input user-form-control"
                       type="password"
                       name="password"
                       autocomplete="new-password">
                <button type="button" class="pw-toggle" data-toggle="password" aria-label="<?=_e('Show password')?>">
                  <?= svg_ico('eye', '', ['class' => 'lucide-icon']) ?>
                </button>
              </span>
            </label>

            <label class="profile-field">
              <span class="profile-field-label"><?=_e('Confirm Password')?></span>
              <span class="pw-wrap">
                <input class="adam-input user-form-control"
                       type="password"
                       name="password_confirm"
                       autocomplete="new-password">
                <button type="button" class="pw-toggle" data-toggle="password_confirm" aria-label="<?=_e('Show password')?>">
                  <?= svg_ico('eye', '', ['class' => 'lucide-icon']) ?>
                </button>
              </span>
            </label>
          </div>
        </section>

        <div class="profile-form-actions">
          <button type="submit" class="adam-button profile-save-button">
            <?= svg_ico('save') ?>
            <span><?=_e('Save Changes')?></span>
          </button>
          <a href="<?= htmlspecialchars($dashboard_url, ENT_QUOTES, 'UTF-8') ?>" class="adam-cancle profile-back-button">
            <?= svg_ico('arrow-left') ?>
            <span><?=_e('Back')?></span>
          </a>
        </div>
      </div>

      <div id="upload-error" class="profile-error"></div>
    </div>
  </form>
</section>

<section class="adam-card profile-danger-zone">
  <div class="profile-danger-heading">
    <span class="profile-danger-icon"><?= svg_ico('alert-triangle') ?></span>
    <div>
      <h3><?=_e('Danger Zone')?></h3>
  <?php if ($currentUserIsSiteOwner): ?>
      <p><?= _e('A Site Owner cannot delete their own account. Revoke Site Owner access from another Site Owner account first.') ?></p>
  <?php else: ?>
      <p><?= _e('This account will be deleted and you will be logged out. Continue?') ?></p>
  <?php endif; ?>
    </div>
  </div>
  <?php if (!$currentUserIsSiteOwner): ?>
  <button type="button" id="btn-open-delete-account-modal" class="profile-danger-button">
    <?= svg_ico('trash-2') ?>
    <span><?=_e('Delete My Account')?></span>
  </button>
  <?php endif; ?>
</section>

<?php if (!$currentUserIsSiteOwner): ?>
<div id="deleteModal"
     class="adam-modal profile-delete-modal"
     role="dialog"
     aria-modal="true"
     aria-hidden="true"
     aria-labelledby="profile-delete-modal-title"
     aria-describedby="profile-delete-modal-description">
  <div class="adam-modal__panel profile-delete-modal-panel user-editor" tabindex="-1">
    <div class="profile-delete-modal-heading">
      <span class="profile-danger-icon"><?= svg_ico('alert-triangle') ?></span>
      <div>
        <h3 id="profile-delete-modal-title"><?=_e('Confirm Deletion')?></h3>
        <p id="profile-delete-modal-description"><?= _e('This account will be deleted and you will be logged out. Continue?') ?></p>
      </div>
    </div>

    <form method="post" id="profile-delete-form" data-unsaved-guard>
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="action" value="delete_account">

      <label class="profile-field profile-delete-password-field">
        <span class="profile-field-label"><?=_e('Your Password')?></span>
        <span class="pw-wrap">
          <input class="adam-input user-form-control"
                 type="password"
                 id="del_password"
                 name="del_password"
                 autocomplete="off"
                 required>
          <button type="button" class="pw-toggle" data-toggle="del_password" aria-label="<?=_e('Show password')?>">
            <?= svg_ico('eye', '', ['class' => 'lucide-icon']) ?>
          </button>
        </span>
      </label>

      <div class="profile-delete-modal-actions">
        <button type="button" id="btn-close-delete-account-modal" class="profile-modal-button profile-modal-button--secondary">
          <?=_e('Cancel')?>
        </button>
        <button type="submit" class="profile-modal-button profile-modal-button--danger">
          <?= svg_ico('trash-2') ?>
          <span><?=_e('Delete')?></span>
        </button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php
if (!empty($errors) && function_exists('adiwira_bootstrap_toasts_script')) {
    $items = array_map(
        static fn($msg) => ['type' => 'error', 'message' => (string)$msg],
        $errors
    );
    echo adiwira_bootstrap_toasts_script($items);
}
?>
<script src="/static/js/add/modal-helpers.js"></script>
<script src="/static/js/add/media-selector.js"></script>
<script>
(function(){
  function toast(type, title, message){
    if (window.NewNotifToast && typeof window.NewNotifToast.show === 'function') {
      window.NewNotifToast.show({ type: type, title: title, message: message });
      return;
    }
    alert(message);
  }

  function askWarning(opts){
    if (window.NewNotifConfirm && typeof window.NewNotifConfirm.warning === 'function') {
      return window.NewNotifConfirm.warning(opts);
    }
    return Promise.resolve(window.confirm(opts.message || <?= json_encode(__('Proceed with this action?')) ?>));
  }

  function askDanger(opts){
    if (window.NewNotifConfirm && typeof window.NewNotifConfirm.danger === 'function') {
      return window.NewNotifConfirm.danger(opts);
    }
    return Promise.resolve(window.confirm(opts.message || <?= json_encode(__('Proceed with this action?')) ?>));
  }

  const previewImg = document.getElementById('preview-img');
  const previewInitial = document.getElementById('preview-initial');
  const imgInput = document.getElementById('inp_img_url');
  const nameInput = document.getElementById('profile-name-input');
  const uploadError = document.getElementById('upload-error');
  const uploadLoader = document.getElementById('upload-loader');
  const fileUploader = document.getElementById('file-uploader');
  const clearBtn = document.getElementById('thumbnail-clear');
  const galleryBtn = document.getElementById('btn-open-media-for-profile');
  const profileSaveForm = document.getElementById('profile-save-form');
  const profileDeleteForm = document.getElementById('profile-delete-form');
  const deleteModal = document.getElementById('deleteModal');
  const openDeleteBtn = document.getElementById('btn-open-delete-account-modal');
  const closeDeleteBtn = document.getElementById('btn-close-delete-account-modal');
  const deletePasswordInput = document.getElementById('del_password');
  let deleteModalReturnFocus = null;

  function unsavedGuard(){
    return window.ADIWIRA && window.ADIWIRA.unsavedGuard;
  }

  function currentAvatarInitial(){
    const name = String(nameInput ? nameInput.value : '').trim();
    return Array.from(name || '?')[0].toLocaleUpperCase();
  }

  function setPreview(src){
    if (!previewImg) return;
    const url = String(src || '').trim();
    if (previewInitial) previewInitial.textContent = currentAvatarInitial();
    if (url !== '') {
      previewImg.hidden = false;
      if (previewInitial) previewInitial.hidden = true;
      previewImg.src = url;
      return;
    }
    previewImg.hidden = true;
    previewImg.removeAttribute('src');
    if (previewInitial) previewInitial.hidden = false;
  }

  function openDeleteModal(){
    if (!deleteModal) return;
    deleteModalReturnFocus = document.activeElement;
    deleteModal.classList.add('is-open');
    deleteModal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('profile-delete-modal-open');
    setTimeout(function(){
      try { deletePasswordInput && deletePasswordInput.focus(); } catch(e){}
    }, 0);
  }

  function closeDeleteModal(){
    if (!deleteModal) return;
    const guard = unsavedGuard();
    function close(){
      profileDeleteForm?.reset();
      if (guard && typeof guard.markSaved === 'function') guard.markSaved(null, null, profileDeleteForm);
      deleteModal.classList.remove('is-open');
      deleteModal.setAttribute('aria-hidden', 'true');
      document.body.classList.remove('profile-delete-modal-open');
      try { deleteModalReturnFocus && deleteModalReturnFocus.focus(); } catch(e){}
      deleteModalReturnFocus = null;
    }
    if (!guard || typeof guard.confirmDiscardForm !== 'function') {
      close();
      return;
    }
    guard.confirmDiscardForm(profileDeleteForm).then(function(confirmed){
      if (confirmed) close();
    });
  }

  document.getElementById('btn-view-profile')?.addEventListener('click', function () {
    const usernameFromServer = <?= json_encode($user['username'] ?? '') ?>;
    const usernameField = document.getElementById('inp_username');
    const username = usernameFromServer || (usernameField ? usernameField.value : '');

    if (!username) {
      toast('error', 'Username', <?= json_encode(__('Username not available.')) ?>);
      return;
    }

    const url = '/author/' + encodeURIComponent(username);
    window.open(url, '_blank');
  });

  if (nameInput) {
    nameInput.addEventListener('input', function(){
      if (previewInitial) previewInitial.textContent = currentAvatarInitial();
      if (!imgInput || String(imgInput.value || '').trim() !== '') return;
      setPreview('');
    });
  }

  galleryBtn?.addEventListener('click', function(){
    if (typeof openMediaSelector !== 'function') {
      toast('error', 'Gallery', <?= json_encode(__('Media selector not available yet.')) ?>);
      return;
    }

    openMediaSelector({ url: '<?= ADMIN_BASE_PATH ?>/admin/modal_img/index.php?embedded=1' })
      .then(function(detail){
        const m = (typeof normalizeMedia === 'function') ? normalizeMedia(detail) : (detail || null);
        if (!m || !m.url) return;
        setPreview(m.url);
        if (imgInput) imgInput.value = m.url;
        if (uploadError) {
          uploadError.style.display = 'none';
          uploadError.innerText = '';
        }
      })
      .catch(function(err){
        console.error('media selector error', err);
        toast('error', 'Gallery', <?= json_encode(__('Failed to select media.')) ?>);
      });
  });

  clearBtn?.addEventListener('click', function(){
    if (imgInput) imgInput.value = '';
    setPreview('');
    if (uploadError) {
      uploadError.style.display = 'none';
      uploadError.innerText = '';
    }
  });

  fileUploader?.addEventListener('change', function() {
    const file = this.files && this.files[0];
    if (!file) return;

    if (uploadError) {
      uploadError.style.display = 'none';
      uploadError.innerText = '';
    }
    if (uploadLoader) uploadLoader.style.display = 'flex';

    const formData = new FormData();
    formData.append('image', file);

    fetch('<?= ADMIN_BASE_PATH ?>/admin/upload_image.php', {
      method: 'POST',
      body: formData,
      credentials: 'include'
    })
    .then(function(response){ return response.json(); })
    .then(function(data){
      if (uploadLoader) uploadLoader.style.display = 'none';

      if (data.success) {
        setPreview(data.url);
        if (imgInput) imgInput.value = data.url;
      } else {
        if (uploadError) {
          uploadError.innerText = data.error || <?= json_encode(__('Failed to upload image.')) ?>;
          uploadError.style.display = 'block';
        }
      }
    })
    .catch(function(error){
      if (uploadLoader) uploadLoader.style.display = 'none';
      if (uploadError) {
        uploadError.innerText = <?= json_encode(__('A network error occurred.')) ?>;
        uploadError.style.display = 'block';
      }
      console.error('Error:', error);
    });
  });

  let saveConfirmed = false;
  profileSaveForm?.addEventListener('submit', function(ev){
    if (saveConfirmed) {
      saveConfirmed = false;
      return;
    }

    ev.preventDefault();
    askWarning({
      title: <?= json_encode(__('Save profile changes')) ?>,
      message: <?= json_encode(__('Profile changes will be saved. Continue?')) ?>,
      confirmText: <?= json_encode(__('Yes, save')) ?>,
      cancelText: <?= json_encode(__('Cancel')) ?>
    }).then(function(ok){
      if (!ok) return;
      saveConfirmed = true;
      const guard = unsavedGuard();
      if (guard && typeof guard.allowNavigation === 'function') guard.allowNavigation();
      profileSaveForm.submit();
    });
  });

  openDeleteBtn?.addEventListener('click', function(){
    openDeleteModal();
  });

  closeDeleteBtn?.addEventListener('click', function(){
    closeDeleteModal();
  });

  deleteModal?.addEventListener('click', function(ev){
    if (ev.target === deleteModal) closeDeleteModal();
  });

  document.addEventListener('keydown', function(ev){
    if (!deleteModal || !deleteModal.classList.contains('is-open')) return;
    if (ev.key === 'Escape') {
      ev.preventDefault();
      closeDeleteModal();
      return;
    }
    if (ev.key !== 'Tab') return;
    const focusable = Array.from(deleteModal.querySelectorAll('button:not([disabled]), input:not([disabled]), [href], [tabindex]:not([tabindex="-1"])'));
    if (focusable.length === 0) return;
    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    if (ev.shiftKey && document.activeElement === first) {
      ev.preventDefault();
      last.focus();
    } else if (!ev.shiftKey && document.activeElement === last) {
      ev.preventDefault();
      first.focus();
    }
  });

  let deleteConfirmed = false;
  profileDeleteForm?.addEventListener('submit', function(ev){
    if (deleteConfirmed) {
      deleteConfirmed = false;
      return;
    }

    ev.preventDefault();

    const pwd = String(deletePasswordInput?.value || '').trim();
    if (pwd === '') {
      toast('error', <?= json_encode(__('Delete account')) ?>, <?= json_encode(__('Password is required.')) ?>);
      try { deletePasswordInput && deletePasswordInput.focus(); } catch(e){}
      return;
    }

    askDanger({
      title: <?= json_encode(__('Delete my account')) ?>,
      message: <?= json_encode(__('This account will be deleted and you will be logged out. Continue?')) ?>,
      confirmText: <?= json_encode(__('Yes, delete account')) ?>,
      cancelText: <?= json_encode(__('Cancel')) ?>
    }).then(function(ok){
      if (!ok) return;
      const guard = unsavedGuard();
      const confirmProfileDiscard = guard && typeof guard.confirmDiscardForm === 'function'
        ? guard.confirmDiscardForm(profileSaveForm)
        : Promise.resolve(true);
      confirmProfileDiscard.then(function(confirmed){
        if (!confirmed) return;
        deleteConfirmed = true;
        if (guard && typeof guard.allowNavigation === 'function') guard.allowNavigation();
        profileDeleteForm.submit();
      });
    });
    });
  })();

  document.querySelectorAll('.pw-toggle').forEach(function(btn){
    btn.addEventListener('click', function(){
      var wrap = this.closest('.pw-wrap');
      if (!wrap) return;
      var input = wrap.querySelector('input');
      if (!input) return;
      var isPassword = input.getAttribute('type') === 'password';
      input.setAttribute('type', isPassword ? 'text' : 'password');
      this.setAttribute('aria-label', isPassword ? <?= json_encode(__('Hide password')) ?> : <?= json_encode(__('Show password')) ?>);
      this.innerHTML = isPassword
        ? <?= json_encode(svg_ico('eye-off', '', ['class' => 'lucide-icon'])) ?>
        : <?= json_encode(svg_ico('eye', '', ['class' => 'lucide-icon'])) ?>;
    });
  });
</script>
