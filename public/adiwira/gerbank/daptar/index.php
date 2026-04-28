<?php
declare(strict_types=1);

  http_response_code(404);
  require __DIR__ . '/../../../frontend_404.php';
  exit;

// /adiwira/signup.php
require_once __DIR__ . '/../../bootstrap.php';

if (is_logged_in()) {
    header('Location: /adiwira/');
    exit;
}

// Pastikan ada session untuk CSRF public form
ensure_session_started(true);
if (empty($_COOKIE[session_name()]) && session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
    global $cookie_options;
    $setCookieOptions = [
        'path' => $cookie_options['path'] ?? '/',
        'domain' => $cookie_options['domain'] ?? null,
        'secure' => $cookie_options['secure'] ?? false,
        'httponly' => $cookie_options['httponly'] ?? true,
        'samesite' => $cookie_options['samesite'] ?? 'Lax',
    ];
    if (!empty($cookie_options['lifetime'])) {
        $setCookieOptions['expires'] = time() + (int)$cookie_options['lifetime'];
    }
    setcookie(session_name(), session_id(), $setCookieOptions);
    $_COOKIE[session_name()] = session_id();
    sess_dbg("signup.php: sent session cookie for CSRF");
}

$errors = [];
$success = null;

// daftar username terlarang
$reservedUsernames = ['admin','administrator','root','support','help','system','moderator'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string)($_POST['email'] ?? ''));
    $name  = trim((string)($_POST['name'] ?? ''));
    $pw    = (string)($_POST['password'] ?? '');
    $pw2   = (string)($_POST['password_confirm'] ?? '');
    $uname = trim((string)($_POST['username'] ?? ''));
    $token = (string)($_POST['csrf_token'] ?? '');

    // CSRF
    if (!csrf_check($token)) {
        $errors[] = 'CSRF token tidak valid';
    }

    // Email
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Email tidak valid';
    }

    // Username
    if ($uname === '') {
        $errors[] = 'Username wajib diisi';
    } else {
        $unameLower = mb_strtolower($uname, 'UTF-8');

        if (mb_strlen($unameLower, 'UTF-8') < 3 || mb_strlen($unameLower, 'UTF-8') > 100) {
            $errors[] = 'Username harus 3–100 karakter';
        }

        if (!preg_match('/^[a-z0-9._-]+$/', $unameLower)) {
            $errors[] = 'Username hanya boleh huruf, angka, titik, underscore, dan minus';
        }

        if (preg_match('/^[0-9]+$/', $unameLower)) {
            $errors[] = 'Username tidak boleh hanya angka';
        }

        if (in_array($unameLower, $reservedUsernames, true)) {
            $errors[] = 'Username tersebut tidak diperbolehkan';
        }

        if (empty($errors)) {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE username = :u LIMIT 1");
            $stmt->execute([':u' => $unameLower]);
            if ($stmt->fetch()) {
                $errors[] = 'Username sudah dipakai';
            }
        }
    }

    // Password
    if ($pw === '' || $pw !== $pw2) {
        $errors[] = 'Password kosong atau tidak cocok';
    }

    if (strlen($pw) < 8) {
        $errors[] = 'Password harus minimal 8 karakter';
    }

    if (empty($errors)) {
        $email_norm = mb_strtolower($email, 'UTF-8');
        $username_norm = mb_strtolower($uname, 'UTF-8');

        // cek email unik
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
        $stmt->execute([':email' => $email_norm]);
        if ($stmt->fetch()) {
            $errors[] = 'Email sudah terdaftar';
        } else {
            $hash = password_hash($pw, PASSWORD_DEFAULT);

            // user baru default author + locked
            $insert = $pdo->prepare("
                INSERT INTO users (
                    email, username, password, name,
                    role, is_deleted, is_locked,
                    created_at, updated_at
                )
                VALUES (
                    :email, :username, :password, :name,
                    'author', 0, 1,
                    NOW(), NOW()
                )
            ");

            $ok = $insert->execute([
                ':email'    => $email_norm,
                ':username' => $username_norm,
                ':password' => $hash,
                ':name'     => ($name !== '' ? $name : null),
            ]);

            if ($ok) {
                $success = 'Pendaftaran berhasil. Akun Anda sudah dibuat dan sedang menunggu persetujuan admin.';
                $_POST = []; // bersihkan form
            } else {
                $errors[] = 'Gagal menyimpan pengguna';
            }
        }
    }
}
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Signup — Adiwira</title>
<style>
body{font-family:system-ui,Segoe UI,Roboto,Helvetica,Arial;background:#f4f6f8;padding:2rem}
.container{max-width:520px;margin:0 auto;background:#fff;padding:1.5rem;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,.05)}
label{display:block;margin:.6rem 0 .2rem;font-weight:600}
input{width:100%;padding:.6rem;border:1px solid #ddd;border-radius:6px}
button{margin-top:1rem;padding:.6rem 1rem;border:0;background:#246;color:#fff;border-radius:6px}
.error{color:#b00020}
.success{color:#0a7a07}
.small{color:#666;font-size:.85rem}
</style>
</head>
<body>
<div class="container">
    <h1>Buat akun</h1>

    <?php if (!empty($errors)): ?>
        <div class="error">
            <ul>
            <?php foreach ($errors as $e): ?>
                <li><?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?></li>
            <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if (!empty($success)): ?>
        <div class="success"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <form method="post" novalidate>
        <label for="email">Email</label>
        <input id="email" name="email" type="email" required value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>">

        <label for="username">Username</label>
        <input id="username" name="username" type="text" required
               placeholder="mis. adam_wira"
               value="<?= htmlspecialchars($_POST['username'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
        <div class="small">3–100 karakter; huruf/angka/titik/underscore/minus; tidak boleh hanya angka.</div>

        <label for="name">Nama (opsional)</label>
        <input id="name" name="name" type="text" value="<?= htmlspecialchars($_POST['name'] ?? '', ENT_QUOTES, 'UTF-8') ?>">

        <label for="password">Password</label>
        <input id="password" name="password" type="password" required>

        <label for="password_confirm">Konfirmasi Password</label>
        <input id="password_confirm" name="password_confirm" type="password" required>

        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">

        <button type="submit">Daftar</button>
    </form>
</div>
</body>
</html>