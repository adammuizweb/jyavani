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
$password = trim((string)($_POST['password'] ?? ''));
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
    $info = __('Previous session invalid. Please log in again.');
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
        $errors[] = __('Invalid form (CSRF check failed).');
    }

    // basic validation
    if ($email === '') {
        $errors[] = __('Email is required.');
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = __('Invalid email format.');
    }

    if ($password === '') {
        $errors[] = __('Password is required.');
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
        $errors[] = __("Too many attempts. Account/IP temporarily blocked. Try again in {$remain_min} minutes.");
    }

    // captcha jika diperlukan
    if (empty($errors) && $show_captcha && $recaptchaEnabled) {
        if ($RECAPTCHA_SITEKEY === '' || $RECAPTCHA_SECRET === '') {
            $errors[] = __('CAPTCHA not configured. Contact admin.');
            $show_help = true;
        } elseif ($captcha_response === '') {
            $errors[] = __('Please fill in the CAPTCHA.');
        } else {
            $verified = melbu_verify_recaptcha($RECAPTCHA_SECRET, $captcha_response, $ip);

            if (!$verified) {
                if ($email !== '') {
                    $attempts = melbu_record_failed($pdo, $email, $ip, $bfMaxAttempts, $bfBlockMinutes);
                }
                $errors[] = __('Invalid CAPTCHA.');
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
                $errors[] = __('Too many attempts. Account/IP temporarily blocked for ') . $bfBlockMinutes . __(' minutes.');
            } else {
                $remaining = max(0, $bfMaxAttempts - $attempts);
                $errors[] = __('Incorrect email or password. Remaining attempts before block: ') . $remaining . '.';
            }
        } else {
            // password benar, cek status akun
            if ((int)($user['is_deleted'] ?? 0) === 1) {
                $errors[] = __('Account unavailable.');
                $show_help = true;
            } elseif ((int)($user['is_locked'] ?? 0) === 1) {
                $errors[] = __('Account not yet active or is locked.');
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

$siteTitle = (string)(settings_get($pdo, 'site_title', 'Jyavani CMS') ?? 'Jyavani CMS');
$loginTitle = (string)apply_filters('jy_login_title', __('Sign In'), $pdo);
$loginLogoUrl = (string)apply_filters('jy_login_logo_url', '/static/img/jyavani.svg', $pdo);
$loginLogoLink = (string)apply_filters('jy_login_logo_link', '/', $pdo);
?>
<!doctype html>
<html lang="<?=h(get_locale())?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="color-scheme" content="light">
<title><?=h($loginTitle)?> &lsaquo; <?=h($siteTitle)?></title>
<link rel="icon" href="<?=h($loginLogoUrl)?>" type="image/svg+xml">
<link rel="stylesheet" href="/static/dashboard/css/login.css">
<?php if ($recaptchaEnabled && $RECAPTCHA_SITEKEY !== ''): ?>
<script src="https://www.google.com/recaptcha/api.js" async defer></script>
<?php endif; ?>
<?php do_action('jy_login_head', $pdo); ?>
</head>
<body class="jy-login-page">
  <main class="jy-login" role="main" aria-labelledby="login-title">
    <header class="jy-login__brand">
      <a class="jy-login__logo-link" href="<?=h($loginLogoLink)?>" aria-label="<?=h(__('Back to website'))?>">
        <img class="jy-login__logo" src="<?=h($loginLogoUrl)?>" alt="<?=h(__('Jyavani CMS'))?>">
      </a>
      <span class="jy-login__product"><?=h(__('Jyavani CMS'))?></span>
    </header>

    <section class="jy-login__card">
      <div class="jy-login__intro">
        <p class="jy-login__eyebrow"><?=h($siteTitle)?></p>
        <h1 id="login-title"><?=h($loginTitle)?></h1>
        <p><?=h(__('Use your account to access the dashboard.'))?></p>
      </div>

    <?php if (!empty($errors)): ?>
      <div class="jy-login__notice jy-login__notice--error" role="alert"><?php echo implode('<br>', array_map(static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'), $errors)); ?></div>
    <?php endif; ?>

    <?php if (!empty($info)): ?>
      <div class="jy-login__notice jy-login__notice--info" role="status"><?php echo htmlspecialchars($info, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <?php do_action('jy_login_before_form', $pdo); ?>

    <form class="jy-login__form" method="post" action="">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_value, ENT_QUOTES, 'UTF-8'); ?>">

      <label for="email"><?php _e('Email'); ?></label>
      <input
        id="email"
        name="email"
        type="email"
        value="<?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?>"
        required
        autocomplete="email"
        autofocus
        inputmode="email"
      >

      <label for="password"><?php _e('Password'); ?></label>
      <div class="jy-login__password">
        <input
          id="password"
          name="password"
          type="password"
          required
          autocomplete="current-password"
        >
        <button class="jy-login__password-toggle" type="button" aria-label="<?=h(__('Show password'))?>" aria-controls="password" aria-pressed="false">
          <svg aria-hidden="true" viewBox="0 0 24 24" width="20" height="20"><path d="M2.2 12s3.5-6 9.8-6 9.8 6 9.8 6-3.5 6-9.8 6-9.8-6-9.8-6Zm9.8 3.2a3.2 3.2 0 1 0 0-6.4 3.2 3.2 0 0 0 0 6.4Z"/></svg>
        </button>
      </div>

      <?php if ($show_captcha && $recaptchaEnabled && $RECAPTCHA_SITEKEY !== ''): ?>
        <div class="jy-login__captcha">
          <div class="g-recaptcha" data-sitekey="<?php echo htmlspecialchars($RECAPTCHA_SITEKEY, ENT_QUOTES, 'UTF-8'); ?>"></div>
        </div>
      <?php endif; ?>

      <?php do_action('jy_login_form', $pdo); ?>

      <button class="jy-login__submit" type="submit"><?php _e('Login'); ?></button>
    </form>

    <?php do_action('jy_login_after_form', $pdo); ?>

    <?php if ($show_help): ?>
      <div class="jy-login__help">
        <small><?php _e('Need help after several attempts?'); ?></small>
        <a href="<?php echo htmlspecialchars($WHATSAPP_HELP_URL, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener"><?php _e('Contact Admin via WhatsApp'); ?></a>
      </div>
    <?php endif; ?>

      <a class="jy-login__back" href="<?=h($loginLogoLink)?>">
        <span aria-hidden="true">&larr;</span> <?=h(__('Back to website'))?>
      </a>
    </section>

    <footer class="jy-login__footer">
      <span><?=h(__('Powered by Jyavani CMS'))?></span>
      <?php do_action('jy_login_footer', $pdo); ?>
    </footer>
  </main>
  <script>
  (() => {
    const toggle = document.querySelector('.jy-login__password-toggle');
    const password = document.getElementById('password');
    if (!toggle || !password) return;
    toggle.addEventListener('click', () => {
      const reveal = password.type === 'password';
      password.type = reveal ? 'text' : 'password';
      toggle.setAttribute('aria-pressed', reveal ? 'true' : 'false');
      toggle.setAttribute('aria-label', reveal ? <?=json_encode(__('Hide password'))?> : <?=json_encode(__('Show password'))?>);
      password.focus();
    });
  })();
  </script>
</body>
</html>
