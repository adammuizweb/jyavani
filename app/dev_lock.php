<?php
declare(strict_types=1);

function jy_dev_lock_environment_value(string $key): ?string
{
    $value = getenv($key);
    return is_string($value) ? trim($value) : null;
}

function jy_dev_lock_load_environment(string $projectRoot): void
{
    $configuredBackend = jy_dev_lock_environment_value('BACKEND_PATH');
    $backend = $configuredBackend !== null && $configuredBackend !== ''
        ? $configuredBackend
        : $projectRoot . '/cfg';
    $environmentFile = rtrim($backend, '/\\') . '/.env';
    $loader = rtrim($backend, '/\\') . '/env.php';
    if (!is_file($environmentFile) || !is_file($loader)) return;

    require_once $loader;
    if (function_exists('load_env')) load_env($environmentFile);
}

/** @return true|false|null Null is an invalid configured value. */
function jy_dev_lock_enabled_value(?string $value): ?bool
{
    if ($value === null) return false;
    $normalized = strtolower(trim($value));
    if (in_array($normalized, ['', '0', 'false', 'no', 'off'], true)) return false;
    if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) return true;
    return null;
}

function jy_dev_lock_headers(): void
{
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Robots-Tag: noindex, nofollow, noarchive');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
    header('Content-Type: text/html; charset=utf-8');
}

function jy_dev_lock_unavailable(): never
{
    http_response_code(503);
    jy_dev_lock_headers();
    header('Retry-After: 300');
    echo '<!doctype html><html lang="en"><meta charset="utf-8">'
        . '<meta name="robots" content="noindex,nofollow,noarchive">'
        . '<title>Service Unavailable</title><p>Service temporarily unavailable.</p></html>';
    exit;
}

function jy_dev_lock_enforce(string $projectRoot): void
{
    jy_dev_lock_load_environment($projectRoot);
    $enabled = jy_dev_lock_enabled_value(jy_dev_lock_environment_value('DEV_LOCK_ENABLED'));
    if ($enabled === false) return;
    if ($enabled === null) jy_dev_lock_unavailable();

    $passwordHash = jy_dev_lock_environment_value('DEV_LOCK_PASSWORD_HASH');
    if ($passwordHash === null || $passwordHash === '' || password_get_info($passwordHash)['algo'] === null) {
        jy_dev_lock_unavailable();
    }

    $secure = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    if (session_status() === PHP_SESSION_ACTIVE) jy_dev_lock_unavailable();
    session_name('JYDEVLOCK');
    session_set_cookie_params([
        'lifetime' => 43200,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    if (!@session_start()) jy_dev_lock_unavailable();

    if (($_SESSION['jy_dev_lock_unlocked'] ?? false) === true) {
        session_write_close();
        return;
    }

    jy_dev_lock_headers();

    $now = time();
    $attempts = is_array($_SESSION['jy_dev_lock_attempts'] ?? null) ? $_SESSION['jy_dev_lock_attempts'] : [];
    $attempts = array_values(array_filter($attempts, static fn(mixed $at): bool => is_int($at) && $at > $now - 300));
    $csrf = is_string($_SESSION['jy_dev_lock_csrf'] ?? null) ? $_SESSION['jy_dev_lock_csrf'] : bin2hex(random_bytes(32));
    $_SESSION['jy_dev_lock_csrf'] = $csrf;
    $error = '';

    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
        $submittedToken = is_string($_POST['csrf'] ?? null) ? $_POST['csrf'] : '';
        $submittedPassword = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        $allowed = count($attempts) < 10;
        if ($allowed && hash_equals($csrf, $submittedToken) && password_verify($submittedPassword, $passwordHash)) {
            session_regenerate_id(true);
            $_SESSION = ['jy_dev_lock_unlocked' => true];
            session_write_close();
            header('Location: /', true, 303);
            exit;
        }
        $attempts[] = $now;
        $_SESSION['jy_dev_lock_attempts'] = $attempts;
        $error = 'Unable to unlock this environment.';
    }

    http_response_code(503);
    header('Retry-After: 300');
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'HEAD') {
        session_write_close();
        exit;
    }
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow,noarchive">
<title>Development Environment</title>
<style>
*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;background:#111827;color:#f9fafb;font-family:system-ui,sans-serif}.gate{width:min(92vw,380px);padding:2rem;border:1px solid #374151;border-radius:16px;background:#1f2937;box-shadow:0 24px 80px #0008}h1{margin:0 0 .5rem;font-size:1.35rem}p{color:#d1d5db;line-height:1.5}.error{color:#fca5a5}label{display:block;margin-top:1.25rem;font-weight:600}input{width:100%;margin-top:.5rem;padding:.75rem;border:1px solid #4b5563;border-radius:8px;background:#111827;color:#fff}button{width:100%;margin-top:1rem;padding:.75rem;border:0;border-radius:8px;background:#facc15;color:#111827;font-weight:700;cursor:pointer}
</style>
</head>
<body>
<main class="gate">
<h1>Development Environment</h1>
<p>This site is restricted while changes are being verified.</p>
<?php if ($error !== ''): ?><p class="error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
<form method="post" action="/" autocomplete="off">
<input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
<label for="password">Access password</label>
<input id="password" type="password" name="password" required autofocus>
<button type="submit">Unlock</button>
</form>
</main>
</body>
</html>
<?php
    session_write_close();
    exit;
}
