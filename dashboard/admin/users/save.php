<?php
// /adiwira/admin/users/save.php
declare(strict_types=1);

if (!defined('DASHBOARD_CONTEXT') && !defined('ADAM_THEME')) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/../_guard.php';
require_once __DIR__ . '/../_notify.php';

[$uid, $role] = adiwira_require_role($pdo, ['admin'], false);

$errors = [];

$base = ADMIN_BASE_PATH;
$redirect_url = $base . '/?page=admin/users/index';

$return_to = function_exists('adiwira_safe_return_to')
    ? adiwira_safe_return_to((string)($_REQUEST['return_to'] ?? ''), $redirect_url)
    : $redirect_url;

$id = (int)($_GET['id'] ?? ($_POST['id'] ?? 0));
$editing = $id > 0;

$user = null;
if ($editing) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id AND is_deleted = 0 LIMIT 1");
    $stmt->execute([':id' => $id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        http_response_code(404);
        echo '<p>' . __('User not found.') . '</p>';
        return;
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
    $role_input = trim((string)($_POST['role'] ?? 'author'));
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

    if (!in_array($role_input, ['author','editor','admin'], true)) {
        $role_input = 'author';
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

    if (empty($errors)) {
        if ($editing) {
            $sql = "UPDATE users 
                    SET email = :email,
                        username = :username,
                        name = :name,
                        bio = :bio,
                        phone = :phone,
                        role = :role,
                        img = :img,
                        updated_at = NOW()
                    WHERE id = :id
                    LIMIT 1";
            $params = [
                ':email' => $email,
                ':username' => $username ?: null,
                ':name'  => $name ?: null,
                ':bio'   => $bio,
                ':phone' => $phone,
                ':role'  => $role_input,
                ':img'   => $img_url ?: null,
                ':id'    => $id,
            ];

            if ($plain_password !== '') {
                $hash = password_hash($plain_password, PASSWORD_DEFAULT);
                $sql = "UPDATE users 
                        SET email = :email,
                            username = :username,
                            name = :name,
                            bio = :bio,
                            phone = :phone,
                            role = :role,
                            password = :password,
                            img = :img,
                            updated_at = NOW()
                        WHERE id = :id
                        LIMIT 1";
                $params[':password'] = $hash;
            }

            $stmtUpd = $pdo->prepare($sql);
            if ($stmtUpd->execute($params)) {
                if ($id === $uid) {
                    $_SESSION['user_role'] = $role_input;
                }
                adiwira_redirect_with_flash($return_to, 'success', __('User updated successfully.'));
            } else {
                $errors[] = __('Failed to update user.');
            }
        } else {
            if ($plain_password === '') {
                $errors[] = __('Password harus diisi untuk membuat user baru.');
            } else {
                $hash = password_hash($plain_password, PASSWORD_DEFAULT);
                $stmtIns = $pdo->prepare("
                    INSERT INTO users
                    (email, username, password, name, bio, phone, role, img, is_locked, created_at, updated_at)
                    VALUES
                    (:email, :username, :password, :name, :bio, :phone, :role, :img, 0, NOW(), NOW())
                ");
                $ok = $stmtIns->execute([
                    ':email' => $email,
                    ':username' => $username ?: null,
                    ':password' => $hash,
                    ':name' => $name ?: null,
                    ':bio' => $bio,
                    ':phone' => $phone,
                    ':role' => $role_input,
                    ':img' => $img_url ?: null,
                ]);

                if ($ok) {
                    adiwira_redirect_with_flash($return_to, 'success', __('New user created successfully.'));
                } else {
                    $errors[] = __('Failed to create new user.');
                }
            }
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
        <div class="profile-avatar" style="width:120px;height:120px;">
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
          <input type="email" name="email" value="<?= htmlspecialchars($_POST['email'] ?? $user['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>" style="width:100%;padding:.5rem;margin-top:.4rem;border:1px solid #ddd;border-radius:6px">
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

        <label><?=_e('Password')?> <?= $editing ? '<small style="color:#888">' . __('(leave blank to keep current)') . '</small>' : '<small style="color:red">*</small>' ?><br>
          <span class="pw-wrap">
            <input type="password" name="password" autocomplete="new-password" style="width:100%;padding:.5rem;margin-top:.4rem;border:1px solid #ddd;border-radius:6px;padding-right:2.2rem">
            <button type="button" class="pw-toggle" data-toggle="password" aria-label="<?=_e('Show password')?>">
              <?= svg_ico('eye', '', ['class' => 'lucide-icon']) ?>
            </button>
          </span>
        </label>

        <label><?=_e('Role')?><br>
          <?php $curRole = $_POST['role'] ?? $user['role'] ?? 'author'; ?>
          <select name="role" style="width:100%;padding:.45rem;margin-top:.4rem;border:1px solid #ddd;border-radius:6px">
            <option value="author" <?= $curRole === 'author' ? 'selected' : '' ?>>author</option>
            <option value="editor" <?= $curRole === 'editor' ? 'selected' : '' ?>>editor</option>
            <option value="admin" <?= $curRole === 'admin' ? 'selected' : '' ?>>admin</option>
          </select>
        </label>

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
    openMediaSelector({ url: '<?= ADMIN_BASE_PATH ?>/admin/modal_img/list_modal.php?embedded=1' })
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