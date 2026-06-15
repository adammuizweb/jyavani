<?php
declare(strict_types=1);

// lokasi file: /adiwira/admin/profile/?
require_once __DIR__ . '/../_deny.php';

if (!defined('DASHBOARD_CONTEXT') && !defined('ADAM_THEME')) {
    adiwira_admin_404();
}

require_once __DIR__ . '/../_guard.php';
require_once __DIR__ . '/../_notify.php';

[$uid, $role] = adiwira_require_login($pdo, false);

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

$base = ADMIN_BASE_PATH;
$self_url = $base . '/?page=admin/profile/index';
$dashboard_url = $base . '/?page=admin/settings/index';

$initialBio = (string)($user['bio'] ?? '');
$initialPhone = (string)($user['phone'] ?? '');

$postedImg = trim((string)($_POST['img_url'] ?? ''));
$displayImg = $postedImg !== '' ? $postedImg : (string)($user['img'] ?? '');

if ($displayImg === '') {
    $initials = urlencode((string)($user['name'] ?? 'User'));
    $displayImg = "https://ui-avatars.com/api/?name={$initials}&background=random&color=fff";
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $token = (string)($_POST['csrf_token'] ?? '');
    if (!adiwira_csrf_validate($token)) {
        $errors[] = __('Invalid CSRF token.');
    }

    $action = (string)($_POST['action'] ?? '');

    if ($action === 'delete_account' && empty($errors)) {
        $password_confirm = (string)($_POST['del_password'] ?? '');

        if ($password_confirm === '') {
            $errors[] = __('Password is required to delete account.');
        } elseif (!password_verify($password_confirm, (string)($user['password'] ?? ''))) {
            $errors[] = __('Wrong password, account deletion failed.');
        } else {
            $stmtDel = $pdo->prepare("
                UPDATE users
                SET is_deleted = 1,
                    updated_at = NOW()
                WHERE id = :id
                LIMIT 1
            ");
            $stmtDel->execute([':id' => $uid]);

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
    } elseif ($action === 'save_profile' && empty($errors)) {
        $name   = trim((string)($_POST['name'] ?? ''));
        $email  = trim((string)($_POST['email'] ?? ''));
        $imgUrl = trim((string)($_POST['img_url'] ?? ''));
        $pass   = (string)($_POST['password'] ?? '');
        $pass2  = (string)($_POST['password_confirm'] ?? '');
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

                profile_redirect_with_flash('success', __('Profile updated successfully.'), $self_url);
            } else {
                $errors[] = __('Failed to save to database.');
            }
        }
    }
}
?>

<section class="adam-card">
  <h2 class="edit-heading"><?=_e('Edit My Profile')?></h2>

  <form method="post" novalidate id="profile-save-form">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="action" value="save_profile">
    <input type="hidden" name="img_url" id="inp_img_url" value="<?= htmlspecialchars($_POST['img_url'] ?? ($user['img'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">

    <div class="profile-layout">
      <div class="profile-photo">
        <div class="profile-avatar">
          <div id="upload-loader" class="profile-loader">
            <span><?=_e('Uploading...')?></span>
          </div>
          <img id="preview-img"
               src="<?= htmlspecialchars($displayImg, ENT_QUOTES, 'UTF-8') ?>"
               alt="Profile">
        </div>
      </div>

      <div class="profile-meta">
        <div class="profile-status">
          <?php if ((int)($user['is_locked'] ?? 0) === 0): ?>
            <span class="status-badge status-unlocked"><?=_e('Unlocked')?></span> / <?=_e('Approved')?>
          <?php else: ?>
            <span class="status-badge status-locked"><?=_e('Locked')?></span>
          <?php endif; ?>
        </div>

        <div class="profile-actions">
          <label class="adam-button" style="cursor:pointer;display:none;font-size:.85rem;padding:5px 10px;">
            <?=_e('Upload')?>
            <input type="file" id="file-uploader" accept="image/png, image/jpeg, image/webp" style="display:none;">
          </label>

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
        <label><?=_e('Full Name')?><br>
          <input type="text"
                 name="name"
                 id="profile-name-input"
                 value="<?= htmlspecialchars($_POST['name'] ?? ($user['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                 style="width:100%;padding:.5rem;margin-top:.4rem;border:1px solid #ddd;border-radius:6px">
        </label>

        <div style="margin-top:1rem;"></div>

        <label><?=_e('Email (Login)')?><br>
          <input type="email"
                 name="email"
                 value="<?= htmlspecialchars($_POST['email'] ?? ($user['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                 style="width:100%;padding:.5rem;margin-top:.4rem;border:1px solid #ddd;border-radius:6px">
        </label>

        <label><?=_e('Username')?><br>
          <input type="text"
                 id="inp_username"
                 value="<?= htmlspecialchars($_POST['username'] ?? ($user['username'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                 disabled
                 style="width:100%;padding:.5rem;margin-top:.4rem;border:1px solid #ddd;border-radius:6px">
        </label>

        <label style="margin-top:1rem;"><?=_e('Phone')?><br>
          <input type="text"
                 name="phone"
                 value="<?= htmlspecialchars($_POST['phone'] ?? ($user['phone'] ?? $initialPhone), ENT_QUOTES, 'UTF-8') ?>"
                 placeholder="+62xxxxxxxxx"
                 style="width:100%;padding:.5rem;margin-top:.4rem;border:1px solid #ddd;border-radius:6px">
        </label>

        <label style="margin-top:1rem;"><?=_e('Bio / About Me')?><br>
          <textarea name="bio"
                    rows="4"
                    style="width:100%;padding:.5rem;margin-top:.4rem;border:1px solid #ddd;border-radius:6px"><?= htmlspecialchars($_POST['bio'] ?? ($user['bio'] ?? $initialBio), ENT_QUOTES, 'UTF-8') ?></textarea>
        </label>

        <hr style="margin:1.5rem 0; border:0; border-top:1px solid #eee;">

        <p style="font-size:0.9rem; color:#666; margin-bottom:1rem;"><strong><?=_e('Change Password')?></strong> (<?=_e('Optional')?>)</p>

        <div style="display:grid; grid-template-columns:1fr 1fr; gap:1rem;">
          <label><?=_e('New Password')?><br>
            <span class="pw-wrap">
              <input type="password"
                     name="password"
                     autocomplete="new-password"
                     style="width:95%;padding:.5rem;margin-top:.4rem;border:1px solid #ddd;border-radius:6px;padding-right:2.2rem">
              <button type="button" class="pw-toggle" data-toggle="password" aria-label="<?=_e('Show password')?>">
                <?= svg_ico('eye', '', ['class' => 'lucide-icon']) ?>
              </button>
            </span>
          </label>

          <label><?=_e('Confirm Password')?><br>
            <span class="pw-wrap">
              <input type="password"
                     name="password_confirm"
                     autocomplete="new-password"
                     style="width:95%;padding:.5rem;margin-top:.4rem;border:1px solid #ddd;border-radius:6px;padding-right:2.2rem">
              <button type="button" class="pw-toggle" data-toggle="password_confirm" aria-label="<?=_e('Show password')?>">
                <?= svg_ico('eye', '', ['class' => 'lucide-icon']) ?>
              </button>
            </span>
          </label>
        </div>

        <div style="margin-top:2rem;">
          <button type="submit" class="adam-button"><?=_e('Save Changes')?></button>
          <a href="<?= htmlspecialchars($dashboard_url, ENT_QUOTES, 'UTF-8') ?>" class="adam-cancle" style="margin-left:10px;"><?=_e('Back')?></a>
        </div>
      </div>

      <div id="upload-error" class="profile-error" style="display:none;"></div>
    </div>
  </form>
</section>

<section class="adam-card" style="margin-top:2rem; border-top:4px solid #e74c3c;">
  <h3 style="color:#c0392b;"><?=_e('Danger Zone')?></h3>
  <button type="button"
          id="btn-open-delete-account-modal"
          style="background:#e74c3c; color:white; border:none; padding:10px 20px; border-radius:6px; cursor:pointer;">
    <?=_e('Delete My Account')?>
  </button>
</section>

<div id="deleteModal"
     style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); align-items:center; justify-content:center; z-index:5000;">
  <div style="background:#fff; padding:2rem; border-radius:8px; max-width:400px; width:90%; position:relative;">
    <h3 style="margin-top:0; color:#c0392b;"><?=_e('Confirm Deletion')?></h3>

    <form method="post" id="profile-delete-form">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="action" value="delete_account">

      <input type="password"
             id="del_password"
             name="del_password"
             placeholder="<?=_e('Your Password')?>"
             required
             style="width:100%; padding:.5rem; border:1px solid #ddd; border-radius:6px; margin-bottom:1rem;">

      <div style="text-align:right; gap:10px; display:flex; justify-content:flex-end;">
        <button type="button"
                id="btn-close-delete-account-modal"
                style="background:#ccc; border:none; padding:8px 15px; border-radius:4px; cursor:pointer;">
          <?=_e('Cancel')?>
        </button>
        <button type="submit"
                style="background:#e74c3c; color:#fff; border:none; padding:8px 15px; border-radius:4px; cursor:pointer;">
          <?=_e('Delete')?>
        </button>
      </div>
    </form>
  </div>
</div>

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

  function currentDefaultAvatar(){
    const name = (nameInput ? nameInput.value : '') || '';
    return 'https://ui-avatars.com/api/?name=' + encodeURIComponent(name) + '&background=random&color=fff';
  }

  function setPreview(src){
    if (!previewImg) return;
    previewImg.src = src || currentDefaultAvatar();
  }

  function openDeleteModal(){
    if (!deleteModal) return;
    deleteModal.style.display = 'flex';
    setTimeout(function(){
      try { deletePasswordInput && deletePasswordInput.focus(); } catch(e){}
    }, 0);
  }

  function closeDeleteModal(){
    if (!deleteModal) return;
    deleteModal.style.display = 'none';
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
      if (!imgInput || String(imgInput.value || '').trim() !== '') return;
      setPreview(currentDefaultAvatar());
    });
  }

  galleryBtn?.addEventListener('click', function(){
    if (typeof openMediaSelector !== 'function') {
      toast('error', 'Gallery', <?= json_encode(__('Media selector not available yet.')) ?>);
      return;
    }

    openMediaSelector({ url: '<?= ADMIN_BASE_PATH ?>/admin/modal_img/list_modal.php?embedded=1' })
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
    setPreview(currentDefaultAvatar());
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
    if (ev.key === 'Escape' && deleteModal && deleteModal.style.display === 'flex') {
      closeDeleteModal();
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
      deleteConfirmed = true;
      profileDeleteForm.submit();
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