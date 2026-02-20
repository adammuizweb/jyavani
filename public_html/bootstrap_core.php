<?php
declare(strict_types=1);

/**
 * bootstrap_core.php
 *
 * Tanggung jawab:
 * - Load env & core config (DB)
 * - Load session helpers (session.php) — safe: session hanya dimulai jika cookie ada atau kode memanggil ensure_session_started(true)
 * - Menyediakan $pdo untuk file yang meng-include
 *
 * Jangan mendefinisikan PUBLIC_PATH di sini — itu tugas bootstrap_theme.php
 */

// minimal error visibility untuk development; produksi: matikan display_errors
if (!defined('CORE_DEBUG')) {
    define('CORE_DEBUG', (getenv('SESSION_DEBUG') === '1'));
}
if (CORE_DEBUG) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
}

// Resolve BACKEND_PATH: prefer constant, kemudian env, kemudian sensible default relative to public_html
if (!defined('BACKEND_PATH')) {
    $env_backend = getenv('BACKEND_PATH') ?: '';
    if ($env_backend !== '') {
        define('BACKEND_PATH', $env_backend);
    } else {
        // default guess: sibling folder next to public_html
        // e.g. public_html/  and backend in ../jyavani-cfg
        $guess = realpath(__DIR__ . '/../v1-cfg');
        if ($guess !== false) {
            define('BACKEND_PATH', $guess);
        } else {
            // last resort: absolute path the project used previously (safe fallback)
            define('BACKEND_PATH', '/home/u528279701/v1-cfg');
        }
    }
}

// Load core config (expects BACKEND_PATH/.env loader, db init, helpers)
// config.php should define/initialize $pdo
$core_config = rtrim(BACKEND_PATH, '/\\') . DIRECTORY_SEPARATOR . 'config.php';
if (is_file($core_config)) {
    require_once $core_config;
} else {
    // fatal: core config required
    http_response_code(500);
    if (CORE_DEBUG) {
        error_log("bootstrap_core: missing config.php at {$core_config}");
        die("❌ bootstrap_core: config.php not found. Please check BACKEND_PATH.");
    } else {
        die("❌ Backend bootstrap error.");
    }
}

// ensure $pdo exists
if (!isset($pdo) || !$pdo) {
    http_response_code(500);
    die("❌ Database connection not available (expected \$pdo).");
}

// expose pdo to global for safety (dipakai helper/helper lain)
$GLOBALS['pdo'] = $pdo;

// Load session helpers (session.php lives under BACKEND_PATH)
// session.php must be safe: it should not forcibly create a session unless cookie present or ensure_session_started(true) invoked.
$session_path = rtrim(BACKEND_PATH, '/\\') . DIRECTORY_SEPARATOR . 'session.php';
if (is_file($session_path)) {
    require_once $session_path;
} else {
    // Not fatal, but warn: many features depend on session helpers
    if (CORE_DEBUG) {
        error_log("bootstrap_core: session.php not found at {$session_path}. Session helpers unavailable.");
    }
}

// Provide a small global debug helper for bootstrap_core context
if (!function_exists('core_dbg')) {
    function core_dbg(string $msg): void {
        if (CORE_DEBUG) {
            error_log("[bootstrap_core] " . $msg);
        }
    }
}

core_dbg("loaded BACKEND_PATH=" . BACKEND_PATH . " ; PDO present=" . (isset($pdo) ? '1' : '0'));