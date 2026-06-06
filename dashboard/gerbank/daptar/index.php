<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/app/bootstrap_core.php';
require_once BACKEND_PATH . '/helpers/auth_helpers.php';

// ---------- config from DB settings ----------
$registerPath = function_exists('get_register_path') ? get_register_path($pdo) : 'adiwira/gerbank/daptar';
$registrationEnabled = function_exists('settings_get') ? (settings_get($pdo, 'registration_enabled', '0') ?? '0') === '1' : false;
$registrationApproval = function_exists('settings_get') ? (settings_get($pdo, 'registration_approval_required', '1') ?? '1') === '1' : true;
$recaptchaEnabled = function_exists('settings_get') ? (settings_get($pdo, 'recaptcha_enabled', '0') ?? '0') === '1' : false;

// ---------- path guard ----------
if (!function_exists('auth_path_matches') || !auth_path_matches($registerPath)) {
    http_response_code(404);
    require dirname(__DIR__, 3) . '/app/frontend_404.php';
    exit;
}

// ---------- guard: registration disabled ----------
if (!$registrationEnabled) {
    http_response_code(404);
    require dirname(__DIR__, 3) . '/app/frontend_404.php';
    exit;
}

$RECAPTCHA_SITEKEY = (string)(settings_get($pdo, 'recaptcha_sitekey', '') ?? '');
if ($RECAPTCHA_SITEKEY === '') {
    $RECAPTCHA_SITEKEY = (string)(getenv('RECAPTCHA_SITEKEY') ?: '');
}
$RECAPTCHA_SECRET = (string)(settings_get($pdo, 'recaptcha_secret', '') ?? '');
if ($RECAPTCHA_SECRET === '') {
    $RECAPTCHA_SECRET = (string)(getenv('RECAPTCHA_SECRET') ?: '');
}

if (is_logged_in()) {
    header('Location: ' . get_admin_path($pdo) . '/');
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
    $captcha_response = trim((string)($_POST['g-recaptcha-response'] ?? ''));

    // CSRF
    if (!csrf_check($token)) {
        $errors[] = __('Invalid CSRF token');
    }

    // reCAPTCHA (jika enabled)
    if ($recaptchaEnabled && $RECAPTCHA_SECRET !== '') {
        if ($captcha_response === '') {
            $errors[] = __('Please fill in the CAPTCHA.');
        } else {
            $url = 'https://www.google.com/recaptcha/api/siteverify'
                . '?secret=' . urlencode($RECAPTCHA_SECRET)
                . '&response=' . urlencode($captcha_response)
                . '&remoteip=' . urlencode($_SERVER['REMOTE_ADDR'] ?? '');
            $raw = @file_get_contents($url);
            $json = $raw ? json_decode($raw, true) : null;
            if (empty($json['success'])) {
                $errors[] = __('Invalid CAPTCHA.');
            }
        }
    }

    // Email
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = __('Invalid email');
    }

    // Username
    if ($uname === '') {
        $errors[] = __('Username is required');
    } else {
        $unameLower = mb_strtolower($uname, 'UTF-8');

        if (mb_strlen($unameLower, 'UTF-8') < 3 || mb_strlen($unameLower, 'UTF-8') > 100) {
            $errors[] = __('Username must be 3–100 characters');
        }

        if (!preg_match('/^[a-z0-9._-]+$/', $unameLower)) {
            $errors[] = __('Username can only contain letters, numbers, dots, underscores, and hyphens');
        }

        if (preg_match('/^[0-9]+$/', $unameLower)) {
            $errors[] = __('Username cannot be only numbers');
        }

        if (in_array($unameLower, $reservedUsernames, true)) {
            $errors[] = __('That username is not allowed');
        }

        if (empty($errors)) {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE username = :u LIMIT 1");
            $stmt->execute([':u' => $unameLower]);
            if ($stmt->fetch()) {
                $errors[] = __('Username already taken');
            }
        }
    }

    // Password
    if ($pw === '' || $pw !== $pw2) {
        $errors[] = __('Password is empty or does not match');
    }

    if (strlen($pw) < 8) {
        $errors[] = __('Password must be at least 8 characters');
    }

    if (empty($errors)) {
        $email_norm = mb_strtolower($email, 'UTF-8');
        $username_norm = mb_strtolower($uname, 'UTF-8');

        // cek email unik
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
        $stmt->execute([':email' => $email_norm]);
        if ($stmt->fetch()) {
            $errors[] = __('Email already registered');
        } else {
            $hash = password_hash($pw, PASSWORD_DEFAULT);

            $isLocked = $registrationApproval ? 1 : 0;

            $insert = $pdo->prepare("
                INSERT INTO users (
                    email, username, password, name,
                    role, is_deleted, is_locked,
                    created_at, updated_at
                )
                VALUES (
                    :email, :username, :password, :name,
                    'author', 0, :is_locked,
                    NOW(), NOW()
                )
            ");

            $ok = $insert->execute([
                ':email'    => $email_norm,
                ':username' => $username_norm,
                ':password' => $hash,
                ':name'     => ($name !== '' ? $name : null),
                ':is_locked' => $isLocked,
            ]);

            if ($ok) {
                $success = $registrationApproval
                    ? __('Registration successful. Your account has been created and is awaiting admin approval.')
                    : __('Registration successful. Please log in.');
                $_POST = []; // bersihkan form
            } else {
                $errors[] = __('Failed to save user');
            }
        }
    }
}
?>
<!doctype html>
<html lang="<?=h(get_locale())?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php _e('Sign Up — Adiwira'); ?></title>
<?php if ($recaptchaEnabled && $RECAPTCHA_SITEKEY !== ''): ?>
<script src="https://www.google.com/recaptcha/api.js" async defer></script>
<?php endif; ?>
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
    <h1><?php _e('Create Account'); ?></h1>

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
        <label for="email"><?php _e('Email'); ?></label>
        <input id="email" name="email" type="email" required value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>">

        <label for="username"><?php _e('Username'); ?></label>
        <input id="username" name="username" type="text" required
               placeholder="<?php echo __('e.g. adam_wira'); ?>"
               value="<?= htmlspecialchars($_POST['username'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
        <div class="small"><?php _e('3–100 characters; letters/numbers/dots/underscores/hyphens; cannot be only numbers.'); ?></div>

        <label for="name"><?php _e('Name (optional)'); ?></label>
        <input id="name" name="name" type="text" value="<?= htmlspecialchars($_POST['name'] ?? '', ENT_QUOTES, 'UTF-8') ?>">

        <label for="password"><?php _e('Password'); ?></label>
        <input id="password" name="password" type="password" required>

        <label for="password_confirm"><?php _e('Confirm Password'); ?></label>
        <input id="password_confirm" name="password_confirm" type="password" required>

        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">

        <?php if ($recaptchaEnabled && $RECAPTCHA_SITEKEY !== ''): ?>
        <div class="g-recaptcha" data-sitekey="<?= htmlspecialchars($RECAPTCHA_SITEKEY, ENT_QUOTES, 'UTF-8') ?>" style="margin-top:12px;"></div>
        <?php endif; ?>

        <button type="submit"><?php _e('Register'); ?></button>
    </form>
</div>
</body>
</html>