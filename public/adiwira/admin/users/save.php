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
    $role = $stmtRole->fetchColumn() ?: 'author';
    $_SESSION['user_role'] = $role;
}

$role = strtolower(trim((string)$role));
if ($role !== 'admin') {
    http_response_code(403);
    echo '<p>Akses ditolak: menu users hanya untuk admin.</p>';
    exit;
}

$errors = [];
$success = null;

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
                if ($id === $uid) $_SESSION['user_role'] = $role_input;
                $success = 'User berhasil diperbarui.';
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
                    $success = 'User baru berhasil dibuat.';
                } else {
                    $errors[] = 'Gagal membuat user baru.';
                }
            }
        }
    }
}

$base_url_logic = rtrim(str_replace('\\','/', dirname($_SERVER['SCRIPT_NAME'])), '/');
$redirect_url = $base_url_logic . '/index.php?page=admin/users/index';
?>

<?php if ($success): ?>
<div id="successModal" class="adam-modal" aria-hidden="false" style="display:flex;">
  <div class="adam-modal-card adam-modal--success" role="dialog" aria-modal="true" tabindex="-1">
    <h3 class="adam-modal-title">✅ User Berhasil Disimpan!</h3>
    <p class="adam-modal-desc">🥳 <?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></p>
  </div>
</div>
<script>
  setTimeout(() => {
    window.location.href = "<?= htmlspecialchars($redirect_url, ENT_QUOTES, 'UTF-8') ?>";
  }, 1200);
</script>
<?php exit; endif; ?>

<section class="adam-card">
  <h2><?= $editing ? 'Edit User' : 'Tambah User' ?></h2>

  <?php if ($errors): ?>
    <div class="adam-alert error" style="margin-bottom:1rem;">
      <?php foreach ($errors as $e): ?>
        <div><?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?></div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <form method="post" novalidate>
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="id" value="<?= $editing ? (int)$user['id'] : 0 ?>">
    <input type="hidden" name="img_url" id="inp_img_url" value="<?= htmlspecialchars($_POST['img_url'] ?? ($user['img'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">

    <div style="display:flex; flex-wrap:wrap; gap:2rem;">
      <div style="flex: 0 0 120px; text-align:center;">
        <div style="width:120px; height:120px; border-radius:50%; overflow:hidden; background:#f0f0f0; margin-bottom:10px; border:2px solid #ddd; position:relative;">
          <div id="upload-loader" style="display:none; position:absolute; inset:0; background:rgba(255,255,255,0.8); align-items:center; justify-content:center; z-index:2;">
            <div style="width:34px;height:34px;border-radius:50%;border:4px solid rgba(0,0,0,0.12);border-top-color:#3478f6;animation:spin 1s linear infinite"></div>
          </div>
          <style>@keyframes spin { to { transform: rotate(360deg); } }</style>
          <img id="preview-img" src="<?= htmlspecialchars($displayImg, ENT_QUOTES, 'UTF-8') ?>" alt="Avatar" style="width:100%; height:100%; object-fit:cover;">
        </div>

        <div style="display:flex;gap:.5rem;align-items:center;justify-content:center;">
          <button type="button" id="btn-open-media-for-user" class="adam-button" style="padding:.35rem .6rem; font-size:1rem;">Gallery</button>
          <button type="button" id="thumbnail-clear" class="adam-hapus" style="padding:.35rem .6rem; font-size:0.85rem;">Clear</button>
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

        <button type="button" id="btn-view-profile" class="adam-ubah" style="padding:.35rem .6rem; font-size:0.85rem;margin-top:12px;">Lihat Profile</button>
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
          <a href="<?= htmlspecialchars($redirect_url, ENT_QUOTES, 'UTF-8') ?>" class="adam-cancle" style="margin-left:10px">Batal</a>
        </p>
      </div>
    </div>
  </form>
</section>

<script>
document.getElementById('btn-view-profile')?.addEventListener('click', function () {
  var usernameFromServer = <?= json_encode($user['username'] ?? '') ?>;
  var usernameField = document.getElementById('inp_username');
  var username = usernameFromServer || (usernameField ? usernameField.value : '');

  if (!username) {
    alert('Username tidak tersedia.');
    return;
  }

  var url = '/author/' + encodeURIComponent(username);
  window.open(url, '_blank');
});
</script>