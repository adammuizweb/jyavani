<?php
// session.php — centralized session + stateless CSRF helpers
declare(strict_types=1);

// --- Read & normalize environment
$SESSION_NAME = getenv('SESSION_NAME') ?: 'jyavani';
$SESSION_LIFETIME = (int)(getenv('SESSION_LIFETIME') ?: 7 * 24 * 60 * 60);
$SESSION_IDLE_TIMEOUT = (int)(getenv('SESSION_IDLE_TIMEOUT') ?: 30 * 60);

$SESSION_COOKIE_PATH = getenv('SESSION_COOKIE_PATH') ?: '/';
$raw_domain = getenv('SESSION_COOKIE_DOMAIN');
$SESSION_COOKIE_DOMAIN = ($raw_domain !== false && $raw_domain !== '') ? $raw_domain : null;

$SESSION_COOKIE_SAMESITE = getenv('SESSION_COOKIE_SAMESITE') ?: 'Lax';
$FORCE_HTTPS = (bool)(getenv('FORCE_HTTPS') && in_array(strtolower(getenv('FORCE_HTTPS')), ['1','true','yes']));
$SESSION_DEBUG = (getenv('SESSION_DEBUG') === '1');
$ALLOW_INSECURE = (getenv('SESSION_ALLOW_INSECURE_COOKIES') === '1');
$SESSION_PHP_COOKIE_DISABLED = (getenv('SESSION_PHP_COOKIE_DISABLED') === '1');

$SESSION_SECRET = getenv('SESSION_SECRET') ?: '';

// --- Decide secure flag & cookie options
$secure_cookie = $FORCE_HTTPS && !$ALLOW_INSECURE;
if ($SESSION_DEBUG && $ALLOW_INSECURE) $secure_cookie = false;

$cookie_options = [
    'lifetime' => (int)$SESSION_LIFETIME,
    'path'     => $SESSION_COOKIE_PATH,
    'domain'   => $SESSION_COOKIE_DOMAIN,
    'secure'   => (bool)$secure_cookie,
    'httponly' => true,
    'samesite' => $SESSION_COOKIE_SAMESITE,
];

// set PHP session name (canonical)
session_name($SESSION_NAME);

// Hardening
@ini_set('session.use_strict_mode', '1');
@ini_set('session.cookie_httponly', '1');
@ini_set('session.gc_maxlifetime', (string)$SESSION_LIFETIME);

// Respect toggle for PHP auto cookie handling
if ($SESSION_PHP_COOKIE_DISABLED) {
    @ini_set('session.use_cookies', '0');
} else {
    @ini_set('session.use_cookies', '1');
}
@ini_set('session.use_only_cookies', '1');

// small debug helper
if (!function_exists('sess_dbg')) {
    function sess_dbg(string $msg): void {
        global $SESSION_DEBUG;
        if ($SESSION_DEBUG) {
            @file_put_contents(__DIR__ . '/session_debug.log', date('c') . " " . $msg . PHP_EOL, FILE_APPEND);
        }
    }
}

// UA helpers
if (!function_exists('session_ua_prefix')) {
    function session_ua_prefix(): string {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $ua_norm = preg_replace('/\s+/', ' ', trim($ua));
        return substr($ua_norm, 0, 200);
    }
}
if (!function_exists('session_fingerprint')) {
    function session_fingerprint(): string {
        return hash('sha256', session_ua_prefix());
    }
}

// --- Session save path & permissions
$raw_session_save_path = getenv('SESSION_SAVE_PATH');
$session_save_path = $raw_session_save_path && trim($raw_session_save_path) !== ''
    ? $raw_session_save_path
    : __DIR__ . '/../var/sessions';
$session_save_path = rtrim($session_save_path, DIRECTORY_SEPARATOR);

if (!is_dir($session_save_path)) {
    if (!@mkdir($session_save_path, 0700, true)) {
        sess_dbg("WARNING: could not create session save path: {$session_save_path}");
    } else {
        sess_dbg("created session save path: {$session_save_path}");
    }
}
if (is_dir($session_save_path)) {
    @chmod($session_save_path, 0700);
    $save_user = getenv('SESSION_SAVE_USER') ?: null;
    if ($save_user) {
        @chown($session_save_path, $save_user);
        sess_dbg("attempted chown session dir to: {$save_user}");
    }
    session_save_path($session_save_path);
    ini_set('session.save_handler', 'files');
    sess_dbg("session.save_path set to: {$session_save_path} owner=" . @fileowner($session_save_path) . " perms=" . substr(sprintf('%o', @fileperms($session_save_path)), -4));
} else {
    sess_dbg("session.save_path not set, directory unavailable: {$session_save_path}");
}

// --- ensure_session_started
if (!function_exists('ensure_session_started')) {
    /**
     * Ensure session is active.
     * @param bool $createIfMissing If true, create server-side session even if browser has no cookie.
     * @return bool
     */
    function ensure_session_started(bool $createIfMissing = false): bool {
        if (session_status() === PHP_SESSION_ACTIVE) return true;

        $name = session_name();
        $sid = $_COOKIE[$name] ?? null;

        if (!empty($sid)) {
            session_id($sid);
            session_start();
            sess_dbg("ensure_session_started: resumed session id=" . session_id());
            return session_status() === PHP_SESSION_ACTIVE;
        }

        if ($createIfMissing) {
            session_start();
            sess_dbg("ensure_session_started: created server-side session id=" . session_id());
            return session_status() === PHP_SESSION_ACTIVE;
        }

        sess_dbg("ensure_session_started: no session started (cookie absent)");
        return false;
    }
}

// --- Request detection & auto-resume
$request_uri = $_SERVER['REQUEST_URI'] ?? '/';
$request_path = parse_url($request_uri, PHP_URL_PATH) ?: '/';
$static_ext_pattern = '/\.(css|js|png|jpg|jpeg|gif|svg|ico|webp|map|woff2?|ttf|eot|otf)$/i';
$is_static_asset = preg_match($static_ext_pattern, $request_path) || str_starts_with($request_path, '/static/');

$session_cookie_path = $cookie_options['path'] ?? '/';
if ($session_cookie_path === '') $session_cookie_path = '/';
if ($session_cookie_path[0] !== '/') $session_cookie_path = '/' . $session_cookie_path;

$path_allowed = (
    $session_cookie_path === '/' ||
    str_starts_with($request_path, rtrim($session_cookie_path, '/').'/') ||
    $request_path === rtrim($session_cookie_path, '/')
);

// Check whether session cookie (canonical name) was sent
$cookieSent = !empty($_COOKIE[session_name()]);

$sess_dbg_msg = sprintf(
    "auto-resume check: canonical=%s cookieSent=%d static=%d path_allowed=%d",
    session_name(),
    $cookieSent ? 1 : 0,
    $is_static_asset ? 1 : 0,
    $path_allowed ? 1 : 0
);
sess_dbg($sess_dbg_msg);

$session_started_here = false;
if (!$is_static_asset && $path_allowed && $cookieSent) {
    $res = ensure_session_started(false);
    $session_started_here = $res;
    sess_dbg("auto-resume: cookie_present=1 started=" . ($res ? '1' : '0'));
} else {
    sess_dbg("auto-resume skipped: cookie_present=" . ($cookieSent ? '1' : '0') . " static={$is_static_asset} path_allowed=" . ($path_allowed ? '1' : '0'));
}

// --- Periodic housekeeping (when resumed)
if ($session_started_here && session_status() === PHP_SESSION_ACTIVE) {
    if (!isset($_SESSION['_session_created'])) {
        $_SESSION['_session_created'] = time();
        $_SESSION['_fingerprint'] = session_fingerprint();
        $_SESSION['_ua_prefix'] = session_ua_prefix();
        sess_dbg("new session created id=" . session_id());
    } else {
        $regenerate_interval = 3600;
        if (time() - ($_SESSION['_session_created'] ?? 0) > $regenerate_interval) {
            @session_regenerate_id(true);
            $_SESSION['_session_created'] = time();
            sess_dbg("session_regenerate_id executed new_id=" . session_id());
            $_SESSION['_fingerprint'] = session_fingerprint();
            $_SESSION['_ua_prefix'] = session_ua_prefix();
        }
    }
}

// --- Refresh cookie only if session active and path allows
if (!headers_sent() && session_status() === PHP_SESSION_ACTIVE && $session_started_here) {
    if ($path_allowed) {
        $expires = ($cookie_options['lifetime'] > 0) ? time() + (int)$cookie_options['lifetime'] : 0;
        $setCookieOptions = [
            'path' => $cookie_options['path'],
            'domain' => $cookie_options['domain'],
            'secure' => $cookie_options['secure'],
            'httponly' => $cookie_options['httponly'],
            'samesite' => $cookie_options['samesite'],
        ];
        if ($expires > 0) $setCookieOptions['expires'] = $expires;
        setcookie(session_name(), session_id(), $setCookieOptions);
        $_COOKIE[session_name()] = session_id();
        sess_dbg("refreshed cookie: session_id=" . session_id() . " options=" . json_encode($setCookieOptions));
    } else {
        sess_dbg("skip refresh cookie for {$request_path} outside {$session_cookie_path}");
    }
} else {
    sess_dbg("no cookie refresh (headers_sent=" . (headers_sent() ? '1' : '0') . ", session_active=" . (session_status() === PHP_SESSION_ACTIVE ? '1' : '0') . ")");
}

// --- Logout helper
if (!function_exists('logout_user')) {
    function logout_user(): void {
        $_SESSION = [];
        global $cookie_options;
        $paths = [$cookie_options['path'] ?? '/', '/'];
        $domain = $cookie_options['domain'] ?? null;
        if ($domain === '') $domain = null;

        foreach ($paths as $p) {
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $p,
                'domain' => null,
                'secure' => $cookie_options['secure'] ?? false,
                'httponly' => $cookie_options['httponly'] ?? true,
                'samesite' => $cookie_options['samesite'] ?? 'Lax'
            ]);
            if ($domain) {
                $dot = (strpos($domain, '.') === 0) ? $domain : '.' . $domain;
                setcookie(session_name(), '', [
                    'expires' => time() - 42000,
                    'path' => $p,
                    'domain' => $dot,
                    'secure' => $cookie_options['secure'] ?? false,
                    'httponly' => $cookie_options['httponly'] ?? true,
                    'samesite' => $cookie_options['samesite'] ?? 'Lax'
                ]);
            }
        }

        @session_unset();
        @session_destroy();
        if (getenv('SESSION_DEBUG') === '1') {
            @file_put_contents(__DIR__ . '/session_debug.log', date('c') . " logout_user executed\n", FILE_APPEND);
        }
        sess_dbg("logout_user executed");
    }
}

// --- is_logged_in (requires active session)
if (!function_exists('is_logged_in')) {
    function is_logged_in(): bool {
        if (session_status() !== PHP_SESSION_ACTIVE) return false;
        if (empty($_SESSION['user_id'])) return false;
        if (!isset($_SESSION['_fingerprint'])) return false;

        $current_fp = session_fingerprint();
        $stored_fp = $_SESSION['_fingerprint'] ?? null;
        if ($stored_fp && hash_equals((string)$stored_fp, (string)$current_fp)) return true;

        $stored_prefix = $_SESSION['_ua_prefix'] ?? '';
        $current_prefix = session_ua_prefix();
        $match_len = 64;
        if ($stored_prefix !== '' && substr($stored_prefix, 0, $match_len) === substr($current_prefix, 0, $match_len)) {
            sess_dbg("fingerprint mismatch tolerated (UA prefix match)");
            $_SESSION['_fingerprint'] = $current_fp;
            $_SESSION['_ua_prefix'] = $current_prefix;
            return true;
        }

        sess_dbg("fingerprint mismatch in is_logged_in()");
        return false;
    }
}

// --- Helper: current_user
if (!function_exists('current_user')) {
    function current_user(PDO $pdo): ?array {
        if (!is_logged_in()) return null;
        $uid = (int)($_SESSION['user_id'] ?? 0);
        if ($uid <= 0) return null;
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $uid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

// Backwards-friendly wrapper (optional)
if (!function_exists('current_user_global')) {
    function current_user_global(): ?array {
        global $pdo;
        if (!isset($pdo) || !$pdo) return null;
        return current_user($pdo);
    }
}

// ------------------------------------------------------------------
// Stateless CSRF: HMAC signed tokens (no server state required)
// ------------------------------------------------------------------
if (!function_exists('stateless_csrf_token')) {
    function stateless_csrf_token(): string {
        $secret = getenv('SESSION_SECRET') ?: '';
        if ($secret === '') {
            sess_dbg("stateless_csrf_token: missing SESSION_SECRET");
            throw new RuntimeException('Missing SESSION_SECRET for stateless CSRF.');
        }
        $ts = time();
        $data = json_encode(['ts' => $ts, 'ua' => substr(session_ua_prefix(), 0, 200)]);
        $payload = rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
        $sig = hash_hmac('sha256', $payload, $secret);
        return $payload . '.' . $sig;
    }
}
if (!function_exists('stateless_csrf_check')) {
    function stateless_csrf_check(?string $token, int $ttl = 300): bool {
        if (empty($token)) { sess_dbg("stateless_csrf_check: empty token"); return false; }
        $secret = getenv('SESSION_SECRET') ?: '';
        if ($secret === '') { sess_dbg("stateless_csrf_check: missing secret"); return false; }
        $parts = explode('.', $token);
        if (count($parts) !== 2) { sess_dbg("stateless_csrf_check: malformed"); return false; }
        [$payload, $sig] = $parts;
        $expected = hash_hmac('sha256', $payload, $secret);
        if (!hash_equals($expected, $sig)) { sess_dbg("stateless_csrf_check: signature mismatch"); return false; }
        $json = base64_decode(strtr($payload, '-_', '+/'));
        $data = json_decode($json, true);
        if (!$data || empty($data['ts'])) { sess_dbg("stateless_csrf_check: missing ts"); return false; }
        if (time() - (int)$data['ts'] > $ttl) { sess_dbg("stateless_csrf_check: expired"); return false; }
        if (!empty($data['ua'])) {
            if (substr($data['ua'], 0, 64) !== substr(session_ua_prefix(), 0, 64)) {
                sess_dbg("stateless_csrf_check: UA mismatch"); return false;
            }
        }
        return true;
    }
}

// --- csrf kondisional
if (!function_exists('csrf_token')) {
    function csrf_token(): string {
        // jika ada konteks dashboard, gunakan session-backed token
        if (defined('DASHBOARD_CONTEXT') || (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['user_id'] ?? null))) {
            return session_csrf_token();
        }
        // fallback ke stateless (public)
        return stateless_csrf_token();
    }
}
if (!function_exists('csrf_check')) {
    function csrf_check(?string $token): bool {
        // if session active and user logged in, prefer session check
        if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['user_id'] ?? null)) {
            // consider a TTL (e.g. 0 = no expiry other than session)
            return session_csrf_check($token, 0);
        }
        // else fallback to stateless (public)
        return stateless_csrf_check($token);
    }
}


// --- Session-backed CSRF (for logged-in/dashboard use)
if (!function_exists('session_csrf_token')) {
    function session_csrf_token(int $len = 32): string {
        // ensure session active
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes($len));
            $_SESSION['csrf_token_time'] = time();
        }
        return $_SESSION['csrf_token'];
    }
}
if (!function_exists('session_csrf_check')) {
    function session_csrf_check(?string $token, int $ttl = 0): bool {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        if (empty($token)) return false;
        if (empty($_SESSION['csrf_token'])) return false;
        // constant-time compare
        $ok = hash_equals((string)$_SESSION['csrf_token'], (string)$token);
        if (!$ok) return false;
        // optional TTL (0 = no expiry except session destroy)
        if ($ttl > 0 && !empty($_SESSION['csrf_token_time'])) {
            if (time() - (int)$_SESSION['csrf_token_time'] > $ttl) return false;
        }
        return $ok;
    }
}


// --- login_user: create session & send cookie
if (!function_exists('login_user')) {
    function login_user(int $user_id, ?string $email = null): void {
        ensure_session_started(true);

        $_SESSION['user_id'] = $user_id;
        $_SESSION['user_email'] = $email;
        $_SESSION['auth_time'] = time();
        $_SESSION['last_activity'] = time();
        $_SESSION['_fingerprint'] = session_fingerprint();
        $_SESSION['_ua_prefix'] = session_ua_prefix();
        $_SESSION['_session_created'] = time();

        @session_regenerate_id(true);
        sess_dbg("login_user: session_regenerate_id new_id=" . session_id());

        global $cookie_options;
        $params = session_get_cookie_params();
        $opts = array_merge($params, $cookie_options);

        $domain = $opts['domain'] ?? null;
        if ($domain === '') $domain = null;
        $expires = ($opts['lifetime'] ?? 0) > 0 ? time() + (int)$opts['lifetime'] : 0;

        $cookieOptions = [
            'path' => $opts['path'] ?? '/',
            'domain' => $domain,
            'secure' => (bool)($opts['secure'] ?? false),
            'httponly' => (bool)($opts['httponly'] ?? true),
            'samesite' => $opts['samesite'] ?? 'Lax'
        ];
        if ($expires > 0) $cookieOptions['expires'] = $expires;

        if (!headers_sent()) {
            setcookie(session_name(), session_id(), $cookieOptions);
            $_COOKIE[session_name()] = session_id();
            sess_dbg("login_user: cookie sent session_id=" . session_id() . " options=" . json_encode($cookieOptions));
        } else {
            sess_dbg("login_user: headers already sent, cookie not set");
        }

        session_write_close();
    }
}
