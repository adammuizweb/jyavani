<?php
// /adiwira/admin/profile/index.php

if (!defined('DASHBOARD_CONTEXT')) {
    define('DASHBOARD_CONTEXT', true);
}
require_once __DIR__ . '/../../bootstrap.php';

// Cek Login
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['user_id'])) {
    http_response_code(403);
    echo '<p>Akses ditolak: belum login.</p>';
    exit;
}

$uid = (int)$_SESSION['user_id'];
$errors = [];
$success_msg = '';

// Ambil Data User
$stmtUser = $pdo->prepare("SELECT * FROM users WHERE id = :id AND is_deleted = 0 LIMIT 1");
$stmtUser->execute([':id' => $uid]);
$user = $stmtUser->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    session_destroy();
    header("Location: /login.php");
    exit;
}

// Default Image (Menggunakan UI Avatars jika img kosong/tidak valid)
// Ini memperbaiki masalah 404 default-user.png
$displayImg = $user['img'];
if (empty($displayImg)) {
    $initials = urlencode($user['name']);
    $displayImg = "https://ui-avatars.com/api/?name={$initials}&background=random&color=fff";
}

// default bio & phone values (untuk form)
// pastikan bukan null agar value string aman ditampilkan
$initial_bio = $user['bio'] ?? '';
$initial_phone = $user['phone'] ?? '';

// --- LOGIC HANDLER (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!csrf_check($token)) {
        $errors[] = 'CSRF token tidak valid.';
    }

    $action = $_POST['action'] ?? '';

    // === DELETE ACCOUNT ===
    if ($action === 'delete_account' && empty($errors)) {
        $password_confirm = $_POST['del_password'] ?? '';
        if (!password_verify($password_confirm, $user['password'])) {
            $errors[] = 'Password salah, akun gagal dihapus.';
        } else {
            $stmtDel = $pdo->prepare("UPDATE users SET is_deleted = 1, updated_at = NOW() WHERE id = :id");
            $stmtDel->execute([':id' => $uid]);
            session_destroy();
            header("Location: /index.php?msg=account_deleted");
            exit;
        }
    }

    // === SAVE PROFILE ===
    elseif ($action === 'save_profile' && empty($errors)) {
        $name    = trim((string)($_POST['name'] ?? ''));
        $email   = trim((string)($_POST['email'] ?? ''));
        $imgUrl  = trim((string)($_POST['img_url'] ?? '')); // Menerima URL string dari AJAX
        $pass    = $_POST['password'] ?? '';
        $pass2   = $_POST['password_confirm'] ?? '';

        // new fields
        $bio     = trim((string)($_POST['bio'] ?? ''));
        $phone   = trim((string)($_POST['phone'] ?? ''));

        // normalisasi: kosong -> null (untuk DB)
        $bio_db   = $bio !== '' ? $bio : null;
        $phone_db = $phone !== '' ? $phone : null;

        // update initial values for re-render when validation fails
        $initial_bio = $_POST['bio'] ?? $initial_bio;
        $initial_phone = $_POST['phone'] ?? $initial_phone;

        if ($name === '')  $errors[] = 'Nama tidak boleh kosong.';
        if ($email === '') $errors[] = 'Email tidak boleh kosong.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Format email tidak valid.';

        // Validasi Phone sederhana (boleh diubah sesuai kebutuhan)
        if ($phone_db !== null && $phone_db !== '') {
            if (!preg_match('/^[0-9+\-\s]{6,20}$/', $phone_db)) {
                $errors[] = 'Format nomor telepon tidak valid.';
            }
        }

        // Validasi Password
        if (!empty($pass)) {
            if (strlen($pass) < 6) $errors[] = 'Password minimal 6 karakter.';
            if ($pass !== $pass2)  $errors[] = 'Konfirmasi password tidak cocok.';
        }

        // Cek Unik Email
        if ($email !== $user['email'] && empty($errors)) {
            $stmtCheck = $pdo->prepare("SELECT id FROM users WHERE email = :email AND id != :id AND is_deleted = 0 LIMIT 1");
            $stmtCheck->execute([':email' => $email, ':id' => $uid]);
            if ($stmtCheck->fetch()) {
                $errors[] = 'Email sudah digunakan pengguna lain.';
            }
        }

        if (empty($errors)) {
            // Update Query (termasuk bio & phone)
            $sql = "UPDATE users SET name = :name, email = :email, img = :img, bio = :bio, phone = :phone, updated_at = NOW()";
            $params = [
                ':name'  => $name ?: null,
                ':email' => $email,
                ':img'   => $imgUrl ?: null, // Simpan NULL jika kosong
                ':bio'   => $bio_db,
                ':phone' => $phone_db,
                ':id'    => $uid
            ];

            if (!empty($pass)) {
                $sql .= ", password = :password";
                $params[':password'] = password_hash($pass, PASSWORD_DEFAULT);
            }

            $sql .= " WHERE id = :id";

            $stmtUpd = $pdo->prepare($sql);
            if ($stmtUpd->execute($params)) {
                // Update Session & Variable Tampilan
                $_SESSION['user_name'] = $name;
                $user['name']  = $name;
                $user['email'] = $email;
                $user['img']   = $imgUrl;
                $user['bio']   = $bio_db ?? '';
                $user['phone'] = $phone_db ?? '';
                $displayImg    = $imgUrl ?: "https://ui-avatars.com/api/?name=" . urlencode($name) . "&background=random&color=fff";
                $success_msg   = 'Profil berhasil diperbarui.';
            } else {
                $errors[] = 'Gagal menyimpan ke database.';
            }
        }
    }
}

$base = rtrim(str_replace('\\','/', dirname($_SERVER['SCRIPT_NAME'])), '/');
?>

<?php if ($success_msg): ?>
    <div id="successModal" style="position:fixed;inset:0;background:rgba(0,0,0,0.4);display:flex;align-items:center;justify-content:center;z-index:4000;">
      <div style="background:#fff;padding:1.5rem 2rem;border-radius:8px;max-width:360px;width:90%;box-shadow:0 4px 16px rgba(0,0,0,0.2);text-align:center;">
        <h3 style="margin-top:0;color:#246;">✅ Berhasil</h3>
        <p><?= htmlspecialchars($success_msg, ENT_QUOTES, 'UTF-8') ?></p>
        <button type="button" onclick="document.getElementById('successModal').remove()" class="adam-button">OK</button>
      </div>
    </div>
<?php endif; ?>

<section class="adam-card">
    <h2>Edit Profil Saya</h2>

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
        <input type="hidden" name="action" value="save_profile">
        
        <input type="hidden" name="img_url" id="inp_img_url" value="<?= htmlspecialchars($user['img'] ?? '', ENT_QUOTES, 'UTF-8') ?>">

        <div style="display:flex; flex-wrap:wrap; gap: 2rem;">
            
<!-- Ganti blok avatar / uploader lama dengan ini -->
<div style="flex: 0 0 150px; text-align:center;">
  <div style="width:150px; height:150px; border-radius:50%; overflow:hidden; background:#f0f0f0; margin-bottom:10px; border:2px solid #ddd; position:relative;">
      <div id="upload-loader" style="display:none; position:absolute; inset:0; background:rgba(255,255,255,0.8); align-items:center; justify-content:center; z-index:2;">
          <span style="font-size:0.8rem; font-weight:bold; color:#555;">Uploading...</span>
      </div>

      <img id="preview-img" src="<?= htmlspecialchars($displayImg, ENT_QUOTES, 'UTF-8') ?>" alt="Profile" style="width:100%; height:100%; object-fit:cover;">
  </div>

  <div style="display:flex;gap:.5rem;align-items:center;justify-content:center;">
  <label class="adam-button" style="cursor:pointer; display:none; font-size:0.85rem; padding: 5px 10px;">
      Upload
      <input type="file" id="file-uploader" accept="image/png, image/jpeg, image/webp" style="display:none;">
  </label>

  <button type="button" id="btn-open-media-for-profile" class="adam-button" style="padding:.35rem .6rem; font-size:1rem;">Gallery</button>

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

  <div id="upload-error" style="color:red; font-size:0.75rem; margin-top:5px; display:none;"></div>
</div>

<script>
/* ---------- modal + media selector helpers (declare only if missing) ---------- */
(function(){
  // injectHtmlWithScriptsTo (used by modal fetch)
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

  // modal open/close
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

  // normalizeMedia helper (matches modal_img detail shape)
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

  // openMediaSelector: returns Promise resolving to detail or null
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
})();

/* ---------- handler: pilih dari media ---------- */
document.getElementById('btn-open-media-for-profile').addEventListener('click', function(){
  if (typeof openMediaSelector !== 'function') {
    alert('Media selector belum tersedia.');
    return;
  }
  openMediaSelector({ url: '/adiwira/admin/modal_img/index.php?embedded=1' })
    .then(function(detail){
      const m = (typeof normalizeMedia === 'function') ? normalizeMedia(detail) : (detail || null);
      if (!m || !m.url) return;
      // update preview & hidden input
      document.getElementById('preview-img').src = m.url;
      document.getElementById('inp_img_url').value = m.url;
    }).catch(function(err){
      console.error('media selector error', err);
      alert('Gagal memilih media.');
    });
});

/* ---------- handler: clear ---------- */
document.getElementById('thumbnail-clear').addEventListener('click', function(){
  document.getElementById('preview-img').src = 'https://ui-avatars.com/api/?name=' + encodeURIComponent(document.querySelector('input[name="name"]').value || '') + '&background=random&color=fff';
  document.getElementById('inp_img_url').value = '';
  document.getElementById('upload-error').style.display = 'none';
});

/* ---------- handler: upload file (tetap ada) ---------- */
document.getElementById('file-uploader').addEventListener('change', function() {
  const file = this.files[0];
  if (!file) return;

  const errBox = document.getElementById('upload-error');
  const loader = document.getElementById('upload-loader');
  errBox.style.display = 'none';
  errBox.innerText = '';

  loader.style.display = 'flex';

  const formData = new FormData();
  formData.append('image', file);

  fetch('/adiwira/admin/upload_image.php', {
      method: 'POST',
      body: formData,
      credentials: 'include'
  })
  .then(response => response.json())
  .then(data => {
      loader.style.display = 'none';

      if (data.success) {
          document.getElementById('preview-img').src = data.url;
          document.getElementById('inp_img_url').value = data.url;
      } else {
          errBox.innerText = data.error || 'Gagal upload gambar.';
          errBox.style.display = 'block';
      }
  })
  .catch(error => {
      loader.style.display = 'none';
      errBox.innerText = 'Terjadi kesalahan jaringan.';
      errBox.style.display = 'block';
      console.error('Error:', error);
  });
});
</script>


            <div style="flex:1; min-width: 280px;">
                <label>Nama Lengkap<br>
                    <input type="text" name="name" value="<?= htmlspecialchars($_POST['name'] ?? $user['name'], ENT_QUOTES, 'UTF-8') ?>" style="width:100%;padding:.5rem;margin-top:.4rem;border:1px solid #ddd;border-radius:6px">
                </label>
                
                <div style="margin-top:1rem;"></div>

                <label>Email (Login)<br>
                    <input type="email" name="email" value="<?= htmlspecialchars($_POST['email'] ?? $user['email'], ENT_QUOTES, 'UTF-8') ?>" style="width:100%;padding:.5rem;margin-top:.4rem;border:1px solid #ddd;border-radius:6px">
                </label>
                
                <!-- tambahkan ini di dekat field Nama Lengkap -->
<label>Username<br>
  <input type="text" id="inp_username"
    value="<?= htmlspecialchars($_POST['username'] ?? ($user['username'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
    disabled
    style="width:100%;padding:.5rem;margin-top:.4rem;border:1px solid #ddd;border-radius:6px">
</label>


                <!-- Phone -->
                <label style="margin-top:1rem;">Telepon<br>
                    <input type="text" name="phone" value="<?= htmlspecialchars($_POST['phone'] ?? $user['phone'] ?? $initial_phone, ENT_QUOTES, 'UTF-8') ?>" placeholder="+62xxxxxxxxx" style="width:100%;padding:.5rem;margin-top:.4rem;border:1px solid #ddd;border-radius:6px">
                </label>

                <!-- Bio -->
                <label style="margin-top:1rem;">Bio / Tentang Saya<br>
                    <textarea name="bio" rows="4" style="width:100%;padding:.5rem;margin-top:.4rem;border:1px solid #ddd;border-radius:6px"><?= htmlspecialchars($_POST['bio'] ?? $user['bio'] ?? $initial_bio, ENT_QUOTES, 'UTF-8') ?></textarea>
                </label>

                <hr style="margin: 1.5rem 0; border:0; border-top:1px solid #eee;">
                <p style="font-size:0.9rem; color:#666; margin-bottom:1rem;"><strong>Ganti Password</strong> (Opsional)</p>

                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:1rem;">
                    <label>Password Baru<br>
                        <input type="password" name="password" autocomplete="new-password" style="width:95%;padding:.5rem;margin-top:.4rem;border:1px solid #ddd;border-radius:6px">
                    </label>
                    <label>Konfirmasi Password<br>
                        <input type="password" name="password_confirm" autocomplete="new-password" style="width:95%;padding:.5rem;margin-top:.4rem;border:1px solid #ddd;border-radius:6px">
                    </label>
                </div>

                <div style="margin-top:2rem;">
                    <button type="submit" class="adam-button">Simpan Perubahan</button>
                    <a href="/adiwira/dashboard.php" class="adam-cancle" style="margin-left:10px;">Kembali</a>
                </div>
            </div>
        </div>
    </form>
</section>

<section class="adam-card" style="margin-top: 2rem; border-top: 4px solid #e74c3c;">
    <h3 style="color:#c0392b;">Zona Bahaya</h3>
    <button type="button" onclick="document.getElementById('deleteModal').style.display='flex'" style="background:#e74c3c; color:white; border:none; padding:10px 20px; border-radius:6px; cursor:pointer;">
        Hapus Akun Saya
    </button>
</section>

<div id="deleteModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); align-items:center; justify-content:center; z-index:5000;">
    <div style="background:#fff; padding:2rem; border-radius:8px; max-width:400px; width:90%;">
        <h3 style="margin-top:0; color:#c0392b;">Konfirmasi Hapus</h3>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="delete_account">
            <input type="password" name="del_password" placeholder="Password Anda" required style="width:100%; padding:.5rem; border:1px solid #ddd; border-radius:6px; margin-bottom:1rem;">
            <div style="text-align:right; gap:10px; display:flex; justify-content:flex-end;">
                <button type="button" onclick="document.getElementById('deleteModal').style.display='none'" style="background:#ccc; border:none; padding:8px 15px; border-radius:4px; cursor:pointer;">Batal</button>
                <button type="submit" style="background:#e74c3c; color:#fff; border:none; padding:8px 15px; border-radius:4px; cursor:pointer;">Hapus</button>
            </div>
        </form>
    </div>
</div>

<script>
document.getElementById('file-uploader').addEventListener('change', function() {
    const file = this.files[0];
    if (!file) return;

    // Reset error
    const errBox = document.getElementById('upload-error');
    const loader = document.getElementById('upload-loader');
    errBox.style.display = 'none';
    errBox.innerText = '';

    // Show Loader
    loader.style.display = 'flex';

    // Prepare FormData
    const formData = new FormData();
    formData.append('image', file);

    // Kirim ke endpoint upload_image.php milikmu
    fetch('/adiwira/admin/upload_image.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        loader.style.display = 'none';
        
        if (data.success) {
            // 1. Update Preview Gambar
            document.getElementById('preview-img').src = data.url;
            
            // 2. Masukkan URL ke input hidden agar ikut tersimpan saat form disubmit
            document.getElementById('inp_img_url').value = data.url;
        } else {
            // Tampilkan error dari PHP
            errBox.innerText = data.error || 'Gagal upload gambar.';
            errBox.style.display = 'block';
        }
    })
    .catch(error => {
        loader.style.display = 'none';
        errBox.innerText = 'Terjadi kesalahan jaringan.';
        errBox.style.display = 'block';
        console.error('Error:', error);
    });
});
</script>
