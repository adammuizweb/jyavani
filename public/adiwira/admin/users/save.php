<?php
// /adiwira/admin/users/save.php

if (!defined('DASHBOARD_CONTEXT')) define('DASHBOARD_CONTEXT', true);
require_once __DIR__ . '/../../bootstrap.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['user_id'])) {
    http_response_code(403);
    echo '<p>Akses ditolak: belum login.</p>';
    exit;
}

$uid = (int)$_SESSION['user_id'];
$role = $_SESSION['user_role'] ?? null;
if (!$role) {
    $stmtRole = $pdo->prepare("SELECT role FROM users WHERE id = :id AND is_deleted = 0 LIMIT 1");
    $stmtRole->execute([':id' => $uid]);
    $role = $stmtRole->fetchColumn();
    $_SESSION['user_role'] = $role;
}

$errors = [];
$success = null;

$id = (int)($_GET['id'] ?? ($_POST['id'] ?? 0));
$editing = $id > 0;

// fetch existing (if edit)
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

// hak akses: author hanya boleh edit dirinya sendiri
if ($editing && $role === 'author' && $id !== $uid) {
    http_response_code(403);
    echo '<p>Akses ditolak: kamu tidak boleh mengedit user ini.</p>';
    exit;
}

$displayImg = '/static/img/jyavani.svg'; // Fallback default lokal
if ($editing && !empty($user['img'])) {
    $displayImg = $user['img'];
}
// initialize bio/phone defaults for non-POST display (so form values work)
$initial_bio = $user['bio'] ?? '';
$initial_phone = $user['phone'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!csrf_check($token)) {
        $errors[] = 'CSRF token tidak valid.';
    }

    $email = trim((string)($_POST['email'] ?? ''));
    $username = trim((string)($_POST['username'] ?? ''));

    $name = trim((string)($_POST['name'] ?? ''));
    $plain_password = trim((string)($_POST['password'] ?? ''));
    $role_input = trim((string)($_POST['role'] ?? 'author'));
    
    // Ambil URL gambar dari input hidden (hasil AJAX)
    $img_url = trim((string)($_POST['img_url'] ?? ''));

    // Ambil bio & phone baru
    $bio   = trim((string)($_POST['bio'] ?? ''));
    $phone = trim((string)($_POST['phone'] ?? ''));

    // normalisasi: kosong -> null (untuk DB)
    $bio   = $bio !== '' ? $bio : null;
    $phone = $phone !== '' ? $phone : null;

    // update initial values for form re-render if validation fails
    $initial_bio = $_POST['bio'] ?? $initial_bio;
    $initial_phone = $_POST['phone'] ?? $initial_phone;

    if ($email === '') $errors[] = 'Email tidak boleh kosong.';
    if ($name === '') $errors[] = 'Nama tidak boleh kosong.';

    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Format email tidak valid.';
    }
    
    // username validation: 3-32 chars, letters/numbers/._-
if ($username === '') {
    // optional: jika ingin memaksa username pada create? Untuk edit mungkin boleh kosong? 
    // Lebih baik paksa non-empty:
    $errors[] = 'Username tidak boleh kosong.';
} else {
    if (!preg_match('/^[a-zA-Z0-9._-]{3,32}$/', $username)) {
        $errors[] = 'Username hanya boleh berisi huruf, angka, titik, underscore, strip; panjang 3-32.';
    }
}

// uniqueness check for username
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


    // Simple phone validation (boleh disesuaikan)
    if ($phone !== null && $phone !== '') {
        // menerima angka, spasi, tanda plus dan dash, panjang 6-20
        if (!preg_match('/^[0-9+\-\s]{6,20}$/', $phone)) {
            $errors[] = 'Format nomor telepon tidak valid.';
        }
    }

    // Role validation
    if ($role !== 'admin') {
        $role_input = $editing ? ($user['role'] ?? 'author') : 'author';
    } else {
        if (!in_array($role_input, ['author','editor','admin'], true)) {
            $role_input = 'author';
        }
    }

    // Cek email unik
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

    // Tidak ada lagi logika $_FILES di sini!

    if (empty($errors)) {
        if ($editing) {
            // --- UPDATE ---
            // Default update (without password)
// contoh update (sisipkan :username)
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
                if ($id === $uid) $_SESSION['user_role'] = $role_input;
                $success = "User berhasil diperbarui.";
            } else {
                $errors[] = 'Gagal memperbarui user.';
            }
        } else {
            // --- CREATE NEW ---
            if ($plain_password === '') {
                $errors[] = 'Password harus diisi untuk membuat user baru.';
            } else {
                $hash = password_hash($plain_password, PASSWORD_DEFAULT);
$stmtIns = $pdo->prepare("INSERT INTO users (email, username, password, name, bio, phone, role, img, created_at, updated_at) VALUES (:email, :username, :password, :name, :bio, :phone, :role, :img, NOW(), NOW())");
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
                    $success = "User baru berhasil dibuat.";
                } else {
                    $errors[] = 'Gagal membuat user baru.';
                }
            }
        }
    }
}

// base url logic
$base_url_logic = rtrim(str_replace('\\','/', dirname($_SERVER['SCRIPT_NAME'])), '/');
$redirect_url = $base_url_logic . '/index.php?page=admin/users/index';
?>

<?php if ($success): ?>
<div id="successModal" style="position:fixed;inset:0;background:rgba(0,0,0,0.4);display:flex;align-items:center;justify-content:center;z-index:4000;">
  <div style="background:#fff;padding:1.5rem 2rem;border-radius:8px;max-width:360px;width:90%;box-shadow:0 4px 16px rgba(0,0,0,0.2);text-align:center;">
    <h3 style="margin-top:0;color:#246;">✅ Berhasil</h3>
    <p>🥳 <?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></p>
  </div>
</div>
<script>
  setTimeout(() => { window.location.href = "<?= htmlspecialchars($redirect_url, ENT_QUOTES, 'UTF-8') ?>"; }, 1000);
</script>
<?php exit; endif; ?>


<section class="adam-card">
  <h2><?= $editing ? 'Edit User' : 'Tambah User' ?></h2>

  <?php if ($errors): ?>
    <div class="adam-error">
      <ul>
        <?php foreach ($errors as $e): ?>
          <li><?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <form method="post" novalidate>
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="id" value="<?= $editing ? (int)$user['id'] : 0 ?>">
    
    <input type="hidden" name="img_url" id="inp_img_url" value="<?= htmlspecialchars($_POST['img_url'] ?? ($user['img'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">

<div style="display:flex; flex-wrap:wrap; gap:2rem;">
        
<div style="flex: 0 0 120px; text-align:center;">
  <div style="width:120px; height:120px; border-radius:50%; overflow:hidden; background:#f0f0f0; margin-bottom:10px; border:2px solid #ddd; position:relative;">
       <div id="upload-loader" style="display:none; position:absolute; inset:0; background:rgba(255,255,255,0.8); display:flex; align-items:center; justify-content:center; z-index:2;">
  <!-- CSS spinner simpel -->
  <div style="width:34px;height:34px;border-radius:50%;border:4px solid rgba(0,0,0,0.12);border-top-color:#3478f6;animation:spin 1s linear infinite"></div>
</div>
<style>
@keyframes spin { to { transform: rotate(360deg); } }
</style>
      <img id="preview-img" src="<?= htmlspecialchars($displayImg, ENT_QUOTES, 'UTF-8') ?>" alt="Avatar" style="width:100%; height:100%; object-fit:cover;">
  </div>
  
<div style="display:flex;gap:.5rem;align-items:center;justify-content:center;">

  <button type="button" id="btn-open-media-for-user" class="adam-button" style="padding:.35rem .6rem; font-size:1rem;">Gallery</button>

  <button type="button" id="thumbnail-clear" class="adam-hapus" style="padding:.35rem .6rem; font-size:0.85rem;">Clear</button>

</div>
  <!-- Tombol baru: Lihat Profile -->
  <button type="button" id="btn-view-profile" class="adam-ubah" style="padding:.35rem .6rem; font-size:0.85rem;margin-top:12px;">Lihat Profile</button>
<script>
/* tombol lihat profile */
document.getElementById('btn-view-profile').addEventListener('click', function () {
  // Prioritaskan username dari PHP (aman), fallback ke input 'inp_username' kalau perlu
  var usernameFromServer = <?= json_encode($user['username'] ?? '') ?>;
  var usernameField = document.getElementById('inp_username');
  var username = usernameFromServer || (usernameField ? usernameField.value : '');

  if (!username) {
    alert('Username tidak tersedia.');
    return;
  }

  // Bangun URL rutenya: /web/author/(username)
  var url = '/author/' + encodeURIComponent(username);

  // Buka di tab baru — ubah ke location.href = url kalau mau buka di tab yang sama
  window.open(url, '_blank');
});
</script>


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

            <?php if ($role === 'admin'): ?>
              <label>Role<br>
                <select name="role" style="width:100%;padding:.45rem;margin-top:.4rem;border:1px solid #ddd;border-radius:6px">
                  <?php $curRole = $_POST['role'] ?? $user['role'] ?? 'author'; ?>
                  <option value="author" <?= $curRole === 'author' ? 'selected' : '' ?>>author</option>
                  <option value="editor" <?= $curRole === 'editor' ? 'selected' : '' ?>>editor</option>
                  <option value="admin" <?= $curRole === 'admin' ? 'selected' : '' ?>>admin</option>
                </select>
              </label>
            <?php endif; ?>

            <p style="margin-top:1.5rem">
              <button type="submit" class="adam-button"><?= $editing ? 'Simpan Perubahan' : 'Buat User' ?></button>
              <a href="<?= htmlspecialchars($redirect_url, ENT_QUOTES, 'UTF-8') ?>" class="adam-cancle" style="margin-left:10px">Batal</a>
            </p>
        </div>
    </div>
  </form>
</section>
<script>
(function(){
  // elemen
  const pickFromMediaBtn = document.getElementById('btn-open-media-for-user');
  const previewImg = document.getElementById('preview-img');
  const inpImgUrl = document.getElementById('inp_img_url');
  const errBox = document.getElementById('upload-error');
  const loader = document.getElementById('upload-loader');
  const clearBtn = document.getElementById('thumbnail-clear');

  if (loader) {
    loader.style.display = 'none';
    loader.setAttribute('aria-hidden', 'true');
  }

  // Minimal modal & media helpers (define only if missing)
  if (!window.injectHtmlWithScriptsTo) {
    window.injectHtmlWithScriptsTo = function(container, html) {
      try {
        const parser = new DOMParser();
        const doc = parser.parseFromString(html, 'text/html');
        let bodyHtml = '';
        Array.from(doc.body.childNodes).forEach(n => {
          if (n.nodeName && n.nodeName.toLowerCase() === 'script') return;
          bodyHtml += n.outerHTML || n.textContent;
        });
        container.innerHTML = bodyHtml;
        const scripts = doc.querySelectorAll('script');
        const externals = [];
        scripts.forEach(s => { if (s.src) externals.push(s.src); });
        externals.forEach(src => {
          if (!document.querySelector('script[src="' + src + '"]')) {
            const el = document.createElement('script');
            el.src = src;
            el.async = false;
            document.head.appendChild(el);
          }
        });
        scripts.forEach(s => {
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
      const maxWidth = (opts && opts.maxWidth) ? opts.maxWidth : '900px';
      box.style.cssText = 'background:#fff;border-radius:8px;max-width:' + maxWidth + ';width:100%;max-height:90vh;overflow:auto;box-shadow:0 8px 32px rgba(0,0,0,.25);position:relative';
      const closeBtn = document.createElement('button');
      closeBtn.type = 'button';
      closeBtn.innerText = '×';
      closeBtn.style.cssText = 'position:absolute;right:10px;top:8px;border:0;background:transparent;font-size:20px;cursor:pointer';
      closeBtn.addEventListener('click', function(){ window.adamModalClose(); });
      box.appendChild(closeBtn);
      const content = document.createElement('div');
      content.id = 'adam-modal-content';
      content.style.padding = '14px';
      box.appendChild(content);
      bd.appendChild(box);
      document.body.appendChild(bd);

      fetch(url, { credentials: 'include' })
        .then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.text(); })
        .then(html => injectHtmlWithScriptsTo(content, html))
        .catch(err => {
          content.innerHTML = '<div style="color:#c00">Gagal memuat modal.</div>';
          console.error(err);
        });

      bd.addEventListener('click', function(ev){ if (ev.target === bd) window.adamModalClose(); });
      function onKey(e){ if (e.key === 'Escape') window.adamModalClose(); }
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
        credit: (m.credit != null) ? String(m.credit) : ''
      };
    };
  }

  if (!window.openMediaSelector) {
    window.openMediaSelector = function(opts) {
      opts = opts || {};
      const url = opts.url || '/adiwira/admin/modal_img/index.php?embedded=1';
      return new Promise(function(resolve, reject){
        let resolved = false;
        function cleanup() {
          document.removeEventListener('media:insert', onInsert);
          window.removeEventListener('message', onMessage);
        }
        function onInsert(e) {
          if (resolved) return;
          resolved = true;
          cleanup();
          try { if (typeof window.adamModalClose === 'function') window.adamModalClose(); } catch(e){}
          resolve(e && e.detail ? e.detail : null);
        }
        function onMessage(ev) {
          try {
            if (!ev.data) return;
            if (ev.data.type === 'media:insert') {
              if (resolved) return;
              resolved = true;
              cleanup();
              try { if (typeof window.adamModalClose === 'function') window.adamModalClose(); } catch(e){}
              resolve(ev.data.detail || null);
            }
          } catch(e){}
        }
        document.addEventListener('media:insert', onInsert);
        window.addEventListener('message', onMessage);

        try {
          if (typeof window.adamModalOpen === 'function') {
            window.adamModalOpen(url, opts);
          } else {
            window.open(url, '_blank');
          }
        } catch(e){
          try { window.open(url, '_blank'); } catch(e){}
        }

        const iv = setInterval(function(){
          const b = document.getElementById('adam-modal-backdrop');
          if (!b) {
            clearInterval(iv);
            if (!resolved) { cleanup(); resolve(null); }
          }
        }, 200);
      });
    };
  }

  // Create initials image dataURL (canvas) - no external service
  function createInitialsDataUrl(name, size) {
    size = size || 120;
    const canvas = document.createElement('canvas');
    canvas.width = size;
    canvas.height = size;
    const ctx = canvas.getContext('2d');
    // simple deterministic pastel background based on name hash
    let hash = 0;
    for (let i = 0; i < name.length; i++) { hash = name.charCodeAt(i) + ((hash << 5) - hash); hash = hash & hash; }
    const hue = Math.abs(hash) % 360;
    const bg = 'hsl(' + hue + ', 40%, 85%)';
    const fg = '#333';
    ctx.fillStyle = bg;
    ctx.fillRect(0, 0, size, size);
    const initials = (name || '').split(/\s+/).filter(Boolean).slice(0,2).map(s => s[0].toUpperCase()).join('') || '?';
    ctx.fillStyle = fg;
    ctx.font = Math.floor(size * 0.42) + 'px sans-serif';
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    ctx.fillText(initials, size/2, size/2 + 1);
    return canvas.toDataURL('image/png');
  }

  // apply media to UI
  function applyMedia(m) {
    if (!m) return;
    const url = (m.url || '').toString();
    if (!url) return;
    if (previewImg) previewImg.src = url;
    if (inpImgUrl) {
      inpImgUrl.value = url;
      try { inpImgUrl.dispatchEvent(new Event('input',{bubbles:true})); } catch(e){}
      try { inpImgUrl.dispatchEvent(new Event('change',{bubbles:true})); } catch(e){}
    }
  }

  // pick from media (button)
  if (pickFromMediaBtn) {
    pickFromMediaBtn.addEventListener('click', function(ev){
      ev.preventDefault();
      if (typeof openMediaSelector !== 'function') {
        try { if (typeof window.adamModalOpen === 'function') window.adamModalOpen('/adiwira/admin/modal_img/index.php?embedded=1', { maxWidth:'900px' }); else window.open('/adiwira/admin/modal_img/index.php?embedded=1','_blank'); } catch(e){ window.open('/adiwira/admin/modal_img/index.php?embedded=1','_blank'); }
        return;
      }
      openMediaSelector({ url: '/adiwira/admin/modal_img/index.php?embedded=1' })
        .then(function(detail){
          const m = (typeof normalizeMedia === 'function') ? normalizeMedia(detail) : (detail && detail.media) ? detail.media : detail;
          if (!m || !m.url) return;
          applyMedia(m);
        })
        .catch(function(err){
          console.error('media selector error', err);
          alert('Gagal memilih media.');
        });
    });
  }

  // clear button => set to initials (if name) or default local image
  if (clearBtn) {
    clearBtn.addEventListener('click', function(){
      const nameVal = (document.querySelector('input[name="name"]') || { value: '' }).value || '';
      if (nameVal.trim() !== '') {
        const dataUrl = createInitialsDataUrl(nameVal.trim(), 240);
        if (previewImg) previewImg.src = dataUrl;
      } else {
        if (previewImg) previewImg.src = '/static/img/default-user.png';
      }
      if (inpImgUrl) {
        inpImgUrl.value = '';
        try { inpImgUrl.dispatchEvent(new Event('input',{bubbles:true})); } catch(e){}
        try { inpImgUrl.dispatchEvent(new Event('change',{bubbles:true})); } catch(e){}
      }
      if (errBox) { errBox.style.display = 'none'; errBox.innerText = ''; }
    });
  }

  // preview click opens media selector
  if (previewImg) {
    previewImg.style.cursor = 'pointer';
    previewImg.title = 'Klik untuk pilih dari Media';
    previewImg.addEventListener('click', function(){ if (pickFromMediaBtn) pickFromMediaBtn.click(); });
  }

})(); // IIFE end
</script>