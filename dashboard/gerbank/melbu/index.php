<?php
declare(strict_types=1);

// /adiwira/gerbank/melbu/?
// DB-backed login (email-based) — stateless CSRF, session baru dibuat hanya saat login_user()

require_once dirname(__DIR__, 3) . '/app/bootstrap_core.php';
require_once BACKEND_PATH . '/helpers/auth_helpers.php';

// ---------- config from DB settings ----------
$loginPath = function_exists('get_login_path') ? get_login_path($pdo) : 'adiwira/gerbank/melbu';
$recaptchaEnabled = function_exists('settings_get') ? (settings_get($pdo, 'recaptcha_enabled', '0') ?? '0') === '1' : false;
$bfMaxAttempts = (int)(function_exists('settings_get') ? (settings_get($pdo, 'bruteforce_max_attempts', '5') ?? '5') : '5');
$bfBlockMinutes = (int)(function_exists('settings_get') ? (settings_get($pdo, 'bruteforce_block_minutes', '15') ?? '15') : '15');

// ---------- path guard: 404 jika path tidak cocok ----------
if (!function_exists('auth_path_matches') || !auth_path_matches($loginPath)) {
    http_response_code(404);
    require dirname(__DIR__, 3) . '/app/frontend_404.php';
    exit;
}

// ---------- helper ----------
if (!function_exists('melbu_verify_recaptcha')) {
    function melbu_verify_recaptcha(string $secret, string $response, string $ip): bool
    {
        $secret = trim($secret);
        $response = trim($response);
        $ip = trim($ip);

        if ($secret === '' || $response === '') {
            return false;
        }

        $url = 'https://www.google.com/recaptcha/api/siteverify'
            . '?secret=' . urlencode($secret)
            . '&response=' . urlencode($response)
            . '&remoteip=' . urlencode($ip);

        $ctx = stream_context_create([
            'http' => [
                'method'  => 'GET',
                'timeout' => 8,
                'header'  => "Accept: application/json\r\n",
            ],
        ]);

        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) {
            return false;
        }

        $json = json_decode($raw, true);
        return !empty($json['success']);
    }
}

if (!function_exists('melbu_fetch_user_by_email')) {
    function melbu_fetch_user_by_email(PDO $pdo, string $email): ?array
    {
        $stmt = $pdo->prepare("
            SELECT id, email, password, name, role, is_deleted, is_locked
            FROM users
            WHERE email = :email
            LIMIT 1
        ");
        $stmt->execute([':email' => $email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

// ---------- config: DB settings with env fallback ----------
$RECAPTCHA_SITEKEY = (string)(settings_get($pdo, 'recaptcha_sitekey', '') ?? '');
if ($RECAPTCHA_SITEKEY === '') {
    $RECAPTCHA_SITEKEY = (string)(getenv('RECAPTCHA_SITEKEY') ?: '');
}
$RECAPTCHA_SECRET = (string)(settings_get($pdo, 'recaptcha_secret', '') ?? '');
if ($RECAPTCHA_SECRET === '') {
    $RECAPTCHA_SECRET = (string)(getenv('RECAPTCHA_SECRET') ?: '');
}
$WHATSAPP_HELP_URL = 'https://wa.me/6289514787832';

// helper: record failed attempt with configurable block
if (!function_exists('melbu_record_failed')) {
    function melbu_record_failed(PDO $pdo, string $email, string $ip, int $maxAttempts, int $blockMinutes): int {
        $stmt = $pdo->prepare("SELECT id, attempts FROM login_attempts WHERE email = ? AND ip_address = ? LIMIT 1");
        $stmt->execute([$email, $ip]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $attempts = (int)$row['attempts'] + 1;
            $blocked_until = $attempts >= $maxAttempts
                ? date("Y-m-d H:i:s", time() + $blockMinutes * 60)
                : null;
            $upd = $pdo->prepare("UPDATE login_attempts SET attempts = ?, last_attempt = NOW(), blocked_until = ? WHERE id = ?");
            $upd->execute([$attempts, $blocked_until, $row['id']]);
            return $attempts;
        }
        $ins = $pdo->prepare("INSERT INTO login_attempts (email, ip_address, attempts, last_attempt, blocked_until) VALUES (?, ?, 1, NOW(), NULL)");
        $ins->execute([$email, $ip]);
        return 1;
    }
}

// stateless csrf token untuk public form
$csrf_value = csrf_token();

$ip = (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
$errors = [];
$info = null;
$show_captcha = false;
$show_help = false;
$attempts = 0;
$captcha_threshold = max(1, (int)ceil($bfMaxAttempts * 0.6)); // captcha muncul setelah 60% dari max

// input
$email = mb_strtolower(trim((string)($_POST['email'] ?? '')), 'UTF-8');
$password = (string)($_POST['password'] ?? '');
$captcha_response = trim((string)($_POST['g-recaptcha-response'] ?? ''));

// ---------- guard jika sudah login ----------
if (is_logged_in()) {
    $activeUser = null;

    try {
        $uid = (int)($_SESSION['user_id'] ?? 0);
        if ($uid > 0) {
            $stmt = $pdo->prepare("
                SELECT id, email, role, is_deleted, is_locked
                FROM users
                WHERE id = :id
                LIMIT 1
            ");
            $stmt->execute([':id' => $uid]);
            $activeUser = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }
    } catch (Throwable $e) {
        $activeUser = null;
    }

    if (
        $activeUser &&
        (int)($activeUser['is_deleted'] ?? 0) === 0 &&
        (int)($activeUser['is_locked'] ?? 0) === 0
    ) {
        header('Location: ' . get_admin_path($pdo) . '/');
        exit;
    }

    // session ada tapi user sudah tidak aktif / invalid → paksa logout
    logout_user();
    $info = 'Sesi sebelumnya sudah tidak valid. Silakan login kembali.';
}

// ---------- ambil record attempt ----------
$attempt = null;
if ($email !== '') {
    $attempt = get_login_attempt($pdo, $email, $ip);
    if ($attempt) {
        $attempts = (int)($attempt['attempts'] ?? 0);
        if ($attempts >= $captcha_threshold) {
            $show_captcha = true;
            $show_help = true;
        }
    }
}

// ---------- handle POST ----------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    // csrf
    $postedCsrf = (string)($_POST['csrf_token'] ?? '');
    if ($postedCsrf === '' || !csrf_check($postedCsrf)) {
        $errors[] = 'Form tidak valid (CSRF check gagal).';
    }

    // basic validation
    if ($email === '') {
        $errors[] = 'Email wajib diisi.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Format email tidak valid.';
    }

    if ($password === '') {
        $errors[] = 'Password wajib diisi.';
    }

    // refresh attempt state
    $attempt = ($email !== '') ? get_login_attempt($pdo, $email, $ip) : null;
    $attempts = (int)($attempt['attempts'] ?? 0);

    if ($attempts >= $captcha_threshold) {
        $show_captcha = true;
        $show_help = true;
    }

    // blocked?
    if ($attempt && is_blocked($attempt)) {
        $blockedUntil = (string)($attempt['blocked_until'] ?? '');
        $remain_seconds = 0;

        if ($blockedUntil !== '') {
            $ts = strtotime($blockedUntil);
            if ($ts !== false) {
                $remain_seconds = max(0, $ts - time());
            }
        }

        $remain_min = max(1, (int)ceil($remain_seconds / 60));
        $errors[] = "Terlalu banyak percobaan. Akun/IP diblokir sementara. Coba lagi dalam {$remain_min} menit.";
    }

    // captcha jika diperlukan
    if (empty($errors) && $show_captcha && $recaptchaEnabled) {
        if ($RECAPTCHA_SITEKEY === '' || $RECAPTCHA_SECRET === '') {
            $errors[] = 'CAPTCHA belum dikonfigurasi. Hubungi admin.';
            $show_help = true;
        } elseif ($captcha_response === '') {
            $errors[] = 'Silakan isi CAPTCHA.';
        } else {
            $verified = melbu_verify_recaptcha($RECAPTCHA_SECRET, $captcha_response, $ip);

            if (!$verified) {
                if ($email !== '') {
                    $attempts = melbu_record_failed($pdo, $email, $ip, $bfMaxAttempts, $bfBlockMinutes);
                }
                $errors[] = 'CAPTCHA tidak valid.';
            }
        }
    }

    // credentials
    if (empty($errors)) {
        $user = melbu_fetch_user_by_email($pdo, $email);

        $userExists = is_array($user);
        $passwordOk = false;

        if ($userExists) {
            $hash = (string)($user['password'] ?? '');
            if ($hash !== '' && password_verify($password, $hash)) {
                $passwordOk = true;
            }
        }

        if (!$userExists || !$passwordOk) {
            if ($email !== '') {
                $attempts = melbu_record_failed($pdo, $email, $ip, $bfMaxAttempts, $bfBlockMinutes);
            } else {
                $attempts = 0;
            }

            if ($attempts >= $bfMaxAttempts) {
                $errors[] = 'Terlalu banyak percobaan. Akun/IP diblokir sementara selama ' . $bfBlockMinutes . ' menit.';
            } else {
                $remaining = max(0, $bfMaxAttempts - $attempts);
                $errors[] = 'Email atau password salah. Sisa percobaan sebelum blokir: ' . $remaining . '.';
            }
        } else {
            // password benar, cek status akun
            if ((int)($user['is_deleted'] ?? 0) === 1) {
                $errors[] = 'Akun tidak tersedia.';
                $show_help = true;
            } elseif ((int)($user['is_locked'] ?? 0) === 1) {
                $errors[] = 'Akun belum aktif atau sedang dikunci.';
                $show_help = true;
            } else {
                if ($email !== '') {
                    reset_login_attempts($pdo, $email, $ip);
                }

                login_user((int)$user['id'], (string)$user['email']);
                header('Location: ' . get_admin_path($pdo) . '/');
                exit;
            }
        }
    }
}

// refresh flags after POST
$attempt = ($email !== '') ? get_login_attempt($pdo, $email, $ip) : $attempt;
$attempts = (int)($attempt['attempts'] ?? $attempts);
$show_captcha = $show_captcha || ($attempts >= $captcha_threshold);
$show_help = $show_help || ($attempts >= $captcha_threshold);
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Login</title>
<?php if ($recaptchaEnabled && $RECAPTCHA_SITEKEY !== ''): ?>
<script src="https://www.google.com/recaptcha/api.js" async defer></script>
<?php endif; ?>
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
      <div class="error" role="alert"><?php echo implode('<br>', array_map(static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'), $errors)); ?></div>
    <?php endif; ?>

    <?php if (!empty($info)): ?>
      <div class="info"><?php echo htmlspecialchars($info, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <form method="post" action="">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_value, ENT_QUOTES, 'UTF-8'); ?>">

      <label for="email">Email</label>
      <input
        id="email"
        name="email"
        type="email"
        value="<?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?>"
        required
        autocomplete="email"
        autofocus
      >

      <label for="password">Password</label>
      <input
        id="password"
        name="password"
        type="password"
        required
        autocomplete="current-password"
      >

      <?php if ($show_captcha && $recaptchaEnabled && $RECAPTCHA_SITEKEY !== ''): ?>
        <div style="margin-top:12px;">
          <div class="g-recaptcha" data-sitekey="<?php echo htmlspecialchars($RECAPTCHA_SITEKEY, ENT_QUOTES, 'UTF-8'); ?>"></div>
        </div>
      <?php endif; ?>

      <button type="submit">Login</button>
    </form>

    <?php if ($show_help): ?>
      <div class="help">
        <small class="muted">Butuh bantuan setelah beberapa kali percobaan?</small>
        <a href="<?php echo htmlspecialchars($WHATSAPP_HELP_URL, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">Hubungi Admin via WhatsApp</a>
      </div>
    <?php endif; ?>

    <small class="muted" style="margin-top:14px;display:block;text-align:center;">.</small>
  </main>
</body>
</html>