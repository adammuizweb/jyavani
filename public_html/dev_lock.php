<?php
// dev_lock.php — isolated dev environment gate (robust)
// Usage: define('DEV_LOCK_ENABLED', true); before require_once 'dev_lock.php'
// Or set DEV_LOCK_ENABLED=1 in environment (.env) if you load env earlier.

// --- detect enabled flag from multiple sources
$__dev_flag = null;
if (defined('DEV_LOCK_ENABLED')) {
    $__dev_flag = (DEV_LOCK_ENABLED === true);
} elseif (getenv('DEV_LOCK_ENABLED') !== false) {
    $v = strtolower((string)getenv('DEV_LOCK_ENABLED'));
    $__dev_flag = in_array($v, ['1','true','yes'], true);
} elseif (isset($DEV_LOCK_ENABLED)) {
    $__dev_flag = ($DEV_LOCK_ENABLED === true);
} else {
    $__dev_flag = false;
}

if (!$__dev_flag) {
    // not enabled — silently allow
    return;
}

// Config
$DEV_PASSWORD = 'adam123';
$LOCK_SESSION_NAME = 'DEVLOCK';
$LOCK_LIFETIME     = 12 * 60 * 60; // 12 jam

// For visibility during debugging
error_log('dev_lock: enabled -> engaging gate');

// Make sure we don't conflict with other session code: use own name and cookie params
session_name($LOCK_SESSION_NAME);

// set cookie params *before* session_start()
session_set_cookie_params([
    'lifetime' => $LOCK_LIFETIME,
    'path'     => '/',
    'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    'httponly' => true,
    'samesite' => 'Lax',
]);

// start isolated session
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
    error_log('dev_lock: session started id=' . session_id());
}

// if unlocked already, just close and continue
if (!empty($_SESSION['unlocked']) && $_SESSION['unlocked'] === true) {
    session_write_close();
    error_log('dev_lock: already unlocked');
    return;
}

// handle POST unlock
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pw = (string)($_POST['pw'] ?? '');
    if ($pw !== '' && hash_equals($DEV_PASSWORD, $pw)) {
        $_SESSION['unlocked'] = true;
        session_write_close();
        error_log('dev_lock: unlocked, redirecting');
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    } else {
        $error = 'Password salah!';
        // keep session open only to show form, then close
        session_write_close();
    }
}

// render form and exit (block the rest)
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Maintenance / Dev gate</title>
<style>
body { font-family: Arial; background:#111; color:#eee; display:flex; justify-content:center; align-items:center; height:100vh; }
form { background:#222; padding:30px; border-radius:12px; width:320px; }
input[type=password]{ width:100%; padding:10px; margin-top:10px; border-radius:6px; border:none; }
button{ margin-top:15px; width:100%; padding:10px; border-radius:6px; border:none; background:#FFD800; font-weight:bold; cursor:pointer; }
.small { font-size:13px; color:#ccc; margin-top:8px; text-align:center; }
</style>
</head>
<body>
<form method="post" autocomplete="off">
    <h3>Masukkan Password</h3>
    <?php if (!empty($error)) echo "<p style='color:#ff6666'>$error</p>"; ?>
    <input type="password" name="pw" autofocus>
    <button>Masuk</button>
    <div class="small">Dev lock aktif — hanya untuk pengembangan</div>
</form>
</body>
</html>
<?php
exit;
