<?php
// /adiwira/gerbank/melbu/index.php
// DB-backed login (email-based) — uses stateless CSRF tokens (no session created until login_user())

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../../../bootstrap_core.php'; // provides $pdo and already includes session.php and defines BACKEND_PATH

// Use stateless CSRF (csrf_token() wraps stateless implementation)
$csrf_value = csrf_token();

// 🚧 Guard: jika sudah login, langsung ke dashboard
if (is_logged_in()) {
    header('Location: /adiwira/');
    exit;
}

require_once BACKEND_PATH . '/helpers/auth_helpers.php'; // get_login_attempt(), record_failed_attempt(), reset_login_attempts(), is_blocked()

// Config / keys (from .env)
$RECAPTCHA_SITEKEY = (string)(getenv('RECAPTCHA_SITEKEY') ?: '');
$RECAPTCHA_SECRET  = (string)(getenv('RECAPTCHA_SECRET') ?: '');
$WHATSAPP_HELP_URL = 'https://wa.me/6289514787832';

$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$errors = [];
$info = null;
$show_captcha = false;
$show_help = false;
$attempts = 0;

// Use POST values if present
$email = trim((string)($_POST['email'] ?? ''));
$password = (string)($_POST['password'] ?? '');
$captcha_response = (string)($_POST['g-recaptcha-response'] ?? '');

// Get current attempt record (if email provided)
$attempt = null;
if ($email !== '') {
    $attempt = get_login_attempt($pdo, $email, $ip);
    if ($attempt) {
        $attempts = (int)($attempt['attempts'] ?? 0);
        if ($attempts >= 3) {
            $show_captcha = true;
            $show_help = true;
        }
    }
}

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // validate CSRF using stateless checker
    if (!isset($_POST['csrf_token']) || !csrf_check($_POST['csrf_token'])) {
        $errors[] = 'Form tidak valid (CSRF check gagal).';
    }

    // Basic validation
    if ($email === '') {
        $errors[] = 'Email wajib diisi.';
    }
    if ($password === '') {
        $errors[] = 'Password wajib diisi.';
    }

    // Re-evaluate attempt record
    $attempt = ($email !== '') ? get_login_attempt($pdo, $email, $ip) : null;
    $attempts = (int)($attempt['attempts'] ?? 0);
    if ($attempts >= 3) {
        $show_captcha = true;
        $show_help = true;
    }

    // If blocked, stop early
    if ($attempt && is_blocked($attempt)) {
        $remain_seconds = max(0, strtotime($attempt['blocked_until']) - time());
        $remain_min = ceil($remain_seconds / 60);
        $errors[] = "Terlalu banyak percobaan. Akun/IP diblokir sementara. Coba lagi dalam {$remain_min} menit.";
    }

    // If captcha is required, validate it
    if (empty($errors) && $show_captcha) {
        if ($captcha_response === '') {
            $errors[] = 'Silakan isi CAPTCHA.';
        } else {
            // verify remote (handle network failure conservatively)
            $verify_payload = @file_get_contents(
                "https://www.google.com/recaptcha/api/siteverify?secret=" . urlencode($RECAPTCHA_SECRET)
                . "&response=" . urlencode($captcha_response)
                . "&remoteip=" . urlencode($ip)
            );
            $verified = false;
            if ($verify_payload !== false) {
                $json = json_decode($verify_payload, true);
                $verified = !empty($json['success']);
            }
            if (!$verified) {
                // count as failed attempt
                if ($email !== '') {
                    $attempts = record_failed_attempt($pdo, $email, $ip);
                }
                $errors[] = 'CAPTCHA tidak valid.';
            }
        }
    }

    // If still no errors, check credentials
    if (empty($errors)) {
        // Fetch user by email and not deleted
        $stmt = $pdo->prepare("SELECT id, email, password, name, is_deleted FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        $password_ok = false;
        if ($user && (int)($user['is_deleted'] ?? 0) === 0) {
            $hash = $user['password'] ?? null;
            if ($hash && password_verify($password, $hash)) {
                $password_ok = true;
            }
        }

        if (!$password_ok) {
            // record failed attempt (only if email provided)
            if ($email !== '') {
                $attempts = record_failed_attempt($pdo, $email, $ip);
            } else {
                $attempts = 0;
            }

            if ($attempts >= 5) {
                $errors[] = 'Terlalu banyak percobaan. Akun diblokir selama 15 menit.';
            } else {
                $remaining = 5 - $attempts;
                $errors[] = 'Email atau password salah. Sisa percobaan sebelum blokir: ' . $remaining . '.';
            }

        } else {
            // success: reset attempts and call login_user() to create session & send cookie
            if ($email !== '') reset_login_attempts($pdo, $email, $ip);

            // call login_user($user_id, $email) — session.php will create session server-side and send cookie
            $uid = (int)$user['id'];
            login_user($uid, $user['email'] ?? null);

            // Redirect to dashboard
            header('Location: /adiwira/');
            exit;
        }
    }
}

// Recompute UI flags for rendering (in case attempts changed)
$attempt = ($email !== '') ? get_login_attempt($pdo, $email, $ip) : $attempt;
$attempts = (int)($attempt['attempts'] ?? $attempts);
$show_captcha = $show_captcha || ($attempts >= 3);
$show_help = $show_help || ($attempts >= 3);
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Login</title>
<script src="https://www.google.com/recaptcha/api.js" async defer></script>
<style>
  body { font-family: system-ui, -apple-system, Roboto, 'Segoe UI', Arial; background:#f6f8fa; }
  .card { width:360px; margin:60px auto; background:#fff; padding:20px; border-radius:8px; box-shadow:0 8px 30px rgba(20,30,50,0.06); }
  .error { background:#fff1f0; color:#8a1f1f; padding:10px; border-radius:6px; margin-bottom:12px; }
  .info { background:#eef7ff; color:#064e9b; padding:10px; border-radius:6px; margin-bottom:12px; }
  label { font-size:13px; color:#333; display:block; margin-top:8px; }
  input { width:100%; padding:10px; margin-top:6px; border:1px solid #d7dbe6; border-radius:6px; box-sizing:border-box; }
  button { width:100%; padding:10px; margin-top:12px; background:#0b76ef; color:#fff; border:none; border-radius:8px; font-weight:600; cursor:pointer; }
  .help { text-align:center; margin-top:12px; font-size:14px; }
  .help a { color:#0a7d0a; font-weight:700; text-decoration:none; }
  small.muted { display:block; margin-top:10px; color:#666; font-size:13px; text-align:center; }
</style>
</head>
<body>
  <main class="card" role="main" aria-labelledby="login-title">
    <h2 id="login-title" style="margin:0 0 10px 0;">Masuk</h2>

    <?php if (!empty($errors)): ?>
      <div class="error" role="alert"><?php echo implode('<br>', array_map('htmlspecialchars', $errors)); ?></div>
    <?php endif; ?>

    <?php if (!empty($info)): ?>
      <div class="info"><?php echo htmlspecialchars($info); ?></div>
    <?php endif; ?>

    <form method="post" action="">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_value); ?>">
      <label for="email">Email</label>
      <input id="email" name="email" type="email" value="<?php echo htmlspecialchars($email); ?>" required autocomplete="email" autofocus>

      <label for="password">Password</label>
      <input id="password" name="password" type="password" required autocomplete="current-password">

      <?php if ($show_captcha): ?>
        <div style="margin-top:12px;">
          <div class="g-recaptcha" data-sitekey="<?php echo htmlspecialchars($RECAPTCHA_SITEKEY); ?>"></div>
        </div>
      <?php endif; ?>

      <button type="submit">Login</button>
    </form>

    <?php if ($show_help): ?>
      <div class="help">
        <small class="muted">Butuh bantuan setelah beberapa kali percobaan?</small>
        <a href="<?php echo htmlspecialchars($WHATSAPP_HELP_URL); ?>" target="_blank" rel="noopener">Hubungi Admin via WhatsApp</a>
      </div>
    <?php endif; ?>

    <small class="muted" style="margin-top:14px;display:block;text-align:center;">.</small>
  </main>
</body>
</html>
