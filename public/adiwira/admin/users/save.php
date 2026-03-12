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

$base = rtrim(str_replace('\\','/', dirname($_SERVER['SCRIPT_NAME'])), '/');
$redirect_url = $base . '/index.php?page=admin/users/index';

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
        echo '<p>User tidak ditemukan.</p>';
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
        $errors[] = 'CSRF token tidak valid.';
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

    if ($email === '') $errors[] = 'Email tidak boleh kosong.';
    if ($name === '') $errors[] = 'Nama tidak boleh kosong.';

    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Format email tidak valid.';
    }

    if ($username === '') {
        $errors[] = 'Username tidak boleh kosong.';
    } else {
        if (!preg_match('/^[a-zA-Z0-9._-]{3,32}$/', $username)) {
            $errors[] = 'Username hanya boleh berisi huruf, angka, titik, underscore, strip; panjang 3-32.';
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
            $errors[] = 'Username sudah digunakan oleh user lain.';
        }
    }

    if ($phone !== null && $phone !== '') {
        if (!preg_match('/^[0-9+\-\s]{6,20}$/', $phone)) {
            $errors[] = 'Format nomor telepon tidak valid.';
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
            $errors[] = 'Email sudah dipakai oleh user lain.';
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
                adiwira_redirect_with_flash($return_to, 'success', 'User berhasil diperbarui.');
            } else {
                $errors[] = 'Gagal memperbarui user.';
            }
        } else {
            if ($plain_password === '') {
                $errors[] = 'Password harus diisi untuk membuat user baru.';
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
                    adiwira_redirect_with_flash($return_to, 'success', 'User baru berhasil dibuat.');
                } else {
                    $errors[] = 'Gagal membuat user baru.';
                }
            }
        }
    }
}
?>

<section class="adam-card">
  <h2><?= $editing ? 'Edit User' : 'Tambah User' ?></h2>

  <form method="post" novalidate id="user-save-form">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="id" value="<?= $editing ? (int)$user['id'] : 0 ?>">
    <input type="hidden" name="return_to" value="<?= htmlspecialchars($return_to, ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="img_url" id="inp_img_url" value="<?= htmlspecialchars($_POST['img_url'] ?? ($user['img'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">

    <div style="display:flex; flex-wrap:wrap; gap:2rem;">

    <div style="flex: 0 0 120px; text-align:center;">
  <div id="thumbnail-preview"
       style="width:120px;height:120px;border-radius:50%;overflow:hidden;background:#f0f0f0;margin-bottom:10px;border:2px solid #ddd;position:relative;">
    <div id="upload-loader"
         style="display:none;position:absolute;inset:0;background:rgba(255,255,255,0.8);align-items:center;justify-content:center;z-index:2;">
      <div style="width:34px;height:34px;border-radius:50%;border:4px solid rgba(0,0,0,0.12);border-top-color:#3478f6;animation:spin 1s linear infinite"></div>
    </div>
    <style>@keyframes spin { to { transform: rotate(360deg); } }</style>

<img id="preview-img"
     src="<?= htmlspecialchars($displayImg, ENT_QUOTES, 'UTF-8') ?>"
     alt="Avatar"
     style="width:100%;height:100%;object-fit:cover;display:block;">
  </div>

  <div style="display:flex;gap:.5rem;align-items:center;justify-content:center;">
<button type="button"
        id="btn-open-media-for-profile"
        class="adam-button"
        style="padding:.35rem .6rem; font-size:1rem;">
  Gallery
</button>

    <button type="button"
            id="thumbnail-clear"
            class="adam-hapus"
            style="padding:.35rem .6rem; font-size:0.85rem;">
      Clear
    </button>
  </div>

  <div id="media-single-panel"
       style="margin-top:12px;border:1px solid #eee;padding:10px;border-radius:6px;display:none;background:#fff;max-width:320px;text-align:left;">
    <div id="media-single-content">Klik gambar pada Media untuk melihat detail & edit.</div>
  </div>

  <?php if ($editing): ?>
    <div style="margin-top:12px;font-size:12px;">
      <?php if ((int)($user['is_locked'] ?? 0) === 1): ?>
        <span style="display:inline-block;padding:.22rem .55rem;border-radius:999px;background:#fff1f2;color:#b42318;border:1px solid #fecdd3;font-size:12px;font-weight:700;">Locked / Pending</span>
      <?php else: ?>
        <span style="display:inline-block;padding:.22rem .55rem;border-radius:999px;background:#ecfdf3;color:#027a48;border:1px solid #abefc6;font-size:12px;font-weight:700;">Unlocked / Approved</span>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <button type="button"
          id="btn-view-profile"
          class="adam-ubah"
          style="padding:.35rem .6rem; font-size:0.85rem;margin-top:12px;">
    Lihat Profile
  </button>

  <div id="upload-error" style="color:red; font-size:0.7rem; margin-top:5px; display:none;"></div>
</div>

      <div style="flex:1; min-width:250px;">
        <label>Email<br>
          <input type="email" name="email" value="<?= htmlspecialchars($_POST['email'] ?? $user['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>" style="width:100%;padding:.5rem;margin-top:.4rem;border:1px solid #ddd;border-radius:6px">
        </label>

        <label>Username<br>
          <input type="text" name="username" id="inp_username" value="<?= htmlspecialchars($_POST['username'] ?? $user['username'] ?? '', ENT_QUOTES, 'UTF-8') ?>" style="width:100%;padding:.5rem;margin-top:.4rem;border:1px solid #ddd;border-radius:6px">
          <div style="font-size:12px;color:#666;margin-top:6px">Username unik (alphanumeric, underscore, titik). Panjang 3-32 karakter.</div>
        </label>

        <label>Nama<br>
          <input type="text" name="name" value="<?= htmlspecialchars($_POST['name'] ?? $user['name'] ?? '', ENT_QUOTES, 'UTF-8') ?>" style="width:100%;padding:.5rem;margin-top:.4rem;border:1px solid #ddd;border-radius:6px">
        </label>

        <label>Telepon<br>
          <input type="text" name="phone" value="<?= htmlspecialchars($_POST['phone'] ?? $user['phone'] ?? $initial_phone, ENT_QUOTES, 'UTF-8') ?>" placeholder="+62xxxxxxxxx" style="width:100%;padding:.5rem;margin-top:.4rem;border:1px solid #ddd;border-radius:6px">
        </label>

        <label>Bio<br>
          <textarea name="bio" rows="4" style="width:100%;padding:.5rem;margin-top:.4rem;border:1px solid #ddd;border-radius:6px"><?= htmlspecialchars($_POST['bio'] ?? $user['bio'] ?? $initial_bio, ENT_QUOTES, 'UTF-8') ?></textarea>
        </label>

        <label>Password <?= $editing ? '<small style="color:#888">(kosongkan jika tetap)</small>' : '<small style="color:red">*</small>' ?><br>
          <input type="password" name="password" autocomplete="new-password" style="width:100%;padding:.5rem;margin-top:.4rem;border:1px solid #ddd;border-radius:6px">
        </label>

        <label>Role<br>
          <?php $curRole = $_POST['role'] ?? $user['role'] ?? 'author'; ?>
          <select name="role" style="width:100%;padding:.45rem;margin-top:.4rem;border:1px solid #ddd;border-radius:6px">
            <option value="author" <?= $curRole === 'author' ? 'selected' : '' ?>>author</option>
            <option value="editor" <?= $curRole === 'editor' ? 'selected' : '' ?>>editor</option>
            <option value="admin" <?= $curRole === 'admin' ? 'selected' : '' ?>>admin</option>
          </select>
        </label>

        <p style="margin-top:1.5rem">
          <button type="submit" class="adam-button"><?= $editing ? 'Simpan Perubahan' : 'Buat User' ?></button>
          <a href="<?= htmlspecialchars($return_to, ENT_QUOTES, 'UTF-8') ?>" class="adam-cancle" style="margin-left:10px">Batal</a>
        </p>
      </div>
    </div>
  </form>
</section>

<?php
if (!empty($errors) && function_exists('adiwira_bootstrap_toasts_script')) {
    $items = array_map(static fn($msg) => ['type' => 'error', 'message' => (string)$msg], $errors);
    echo adiwira_bootstrap_toasts_script($items);
}
?>

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

  /* ---------- modal + media selector helpers (declare only if missing) ---------- */
  if (!window.injectHtmlWithScriptsTo) {
    window.injectHtmlWithScriptsTo = function(container, html) {
      try {
        const parser = new DOMParser();
        const doc = parser.parseFromString(html, 'text/html');
        let bodyHtml = '';
        Array.from(doc.body.childNodes).forEach(function(n){
          if (n.nodeName && n.nodeName.toLowerCase() === 'script') return;
          bodyHtml += n.outerHTML || n.textContent || '';
        });
        container.innerHTML = bodyHtml;

        const scripts = doc.querySelectorAll('script');
        const externals = [];
        scripts.forEach(function(s){
          if (s.src) externals.push(s.src);
        });

        externals.forEach(function(src){
          if (!document.querySelector('script[src="' + src + '"]')) {
            const el = document.createElement('script');
            el.src = src;
            el.async = false;
            document.head.appendChild(el);
          }
        });

        scripts.forEach(function(s){
          if (!s.src) {
            const el = document.createElement('script');
            el.text = s.textContent;
            document.body.appendChild(el);
          }
        });
      } catch (err) {
        console.error('injectHtmlWithScriptsTo error', err);
        if (container) container.innerHTML = '<div style="color:#c00">Gagal memuat konten.</div>';
      }
    };
  }

  if (!window.adamModalOpen) {
    window.adamModalOpen = function(url, opts){
      opts = opts || {};
      if (document.getElementById('adam-modal-backdrop')) return;

      const bd = document.createElement('div');
      bd.id = 'adam-modal-backdrop';
      bd.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.45);display:flex;align-items:center;justify-content:center;z-index:99999;padding:16px';

      const box = document.createElement('div');
      box.id = 'adam-modal-box';
      const maxWidth = opts.maxWidth || '900px';
      box.style.cssText = 'background:#fff;border-radius:8px;max-width:' + maxWidth + ';width:100%;max-height:90vh;overflow:auto;box-shadow:0 8px 32px rgba(0,0,0,.25);position:relative';

      const closeBtn = document.createElement('button');
      closeBtn.type = 'button';
      closeBtn.innerText = '×';
      closeBtn.style.cssText = 'position:absolute;right:10px;top:8px;border:0;background:transparent;font-size:20px;cursor:pointer';
      closeBtn.addEventListener('click', function(){ window.adamModalClose(); });

      const content = document.createElement('div');
      content.id = 'adam-modal-content';
      content.style.padding = '14px';

      box.appendChild(closeBtn);
      box.appendChild(content);
      bd.appendChild(box);
      document.body.appendChild(bd);

      fetch(url, { credentials: 'include' })
        .then(function(r){
          if (!r.ok) throw new Error('HTTP ' + r.status);
          return r.text();
        })
        .then(function(html){
          injectHtmlWithScriptsTo(content, html);
        })
        .catch(function(err){
          content.innerHTML = '<div style="color:#c00">Gagal memuat modal.</div>';
          console.error(err);
        });

      bd.addEventListener('click', function(ev){
        if (ev.target === bd) window.adamModalClose();
      });

      function onKey(e){
        if (e.key === 'Escape') window.adamModalClose();
      }
      document.addEventListener('keydown', onKey);
      bd.__esc = onKey;
      return bd;
    };

    window.adamModalClose = function(){
      const bd = document.getElementById('adam-modal-backdrop');
      if (!bd) return;
      if (bd.__esc) document.removeEventListener('keydown', bd.__esc);
      bd.parentNode.removeChild(bd);
    };
  }

  if (!window.normalizeMedia) {
    window.normalizeMedia = function(detail) {
      if (!detail) return null;
      var m = (detail.media && typeof detail.media === 'object') ? detail.media : detail;
      return {
        id: (m.id != null) ? (parseInt(m.id,10) || null) : null,
        url: (m.url != null) ? String(m.url || '') : '',
        title: (m.title != null) ? String(m.title || '') : '',
        alt: (m.alt != null) ? String(m.alt || '') : '',
        caption: (m.caption != null) ? String(m.caption || '') : '',
        credit: (m.credit != null) ? String(m.credit || '') : ''
      };
    };
  }

  if (!window.openMediaSelector) {
    window.openMediaSelector = function(opts) {
      opts = opts || {};
      const url = opts.url || '/adiwira/admin/modal_img/index.php?embedded=1';

      return new Promise(function(resolve){
        let resolved = false;

        function cleanup() {
          document.removeEventListener('media:insert', onInsert);
          window.removeEventListener('message', onMessage);
        }

        function onInsert(e) {
          if (resolved) return;
          resolved = true;
          cleanup();
          try { if (typeof window.adamModalClose === 'function') window.adamModalClose(); } catch(_e){}
          resolve(e && e.detail ? e.detail : null);
        }

        function onMessage(ev) {
          try {
            if (!ev.data) return;
            if (ev.data.type === 'media:insert') {
              if (resolved) return;
              resolved = true;
              cleanup();
              try { if (typeof window.adamModalClose === 'function') window.adamModalClose(); } catch(_e){}
              resolve(ev.data.detail || null);
            }
          } catch(_e){}
        }

        document.addEventListener('media:insert', onInsert);
        window.addEventListener('message', onMessage);

        try {
          if (typeof window.adamModalOpen === 'function') {
            window.adamModalOpen(url, opts);
          } else {
            window.open(url, '_blank');
          }
        } catch(_e) {
          try { window.open(url, '_blank'); } catch(__e){}
        }

        const iv = setInterval(function(){
          const b = document.getElementById('adam-modal-backdrop');
          if (!b) {
            clearInterval(iv);
            if (!resolved) {
              cleanup();
              resolve(null);
            }
          }
        }, 200);
      });
    };
  }

  /* ---------- gallery ---------- */
  galleryBtn?.addEventListener('click', function(){
    openMediaSelector({ url: '/adiwira/admin/modal_img/index.php?embedded=1' })
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
            message: 'Gagal memilih media.'
          });
        } else {
          alert('Gagal memilih media.');
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
      return Promise.resolve(window.confirm(opts.message || 'Lanjutkan aksi ini?'));
    }

    form.addEventListener('submit', function(ev){
      if (confirmed) {
        confirmed = false;
        return;
      }

      ev.preventDefault();
      askWarning({
        title: <?= json_encode($editing ? 'Simpan perubahan user' : 'Buat user baru') ?>,
        message: <?= json_encode($editing ? 'Perubahan user ini akan disimpan. Lanjutkan?' : 'User baru akan dibuat. Lanjutkan?') ?>,
        confirmText: <?= json_encode($editing ? 'Ya, simpan' : 'Ya, buat') ?>,
        cancelText: 'Batal'
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
          message: 'Username tidak tersedia.'
        });
      } else {
        alert('Username tidak tersedia.');
      }
      return;
    }

    const url = '/author/' + encodeURIComponent(username);
    window.open(url, '_blank');
  });
})();
</script>