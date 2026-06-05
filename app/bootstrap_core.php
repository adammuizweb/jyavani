<?php
declare(strict_types=1);

// ---- Fresh-install welcome guard ----
$envFile = realpath(__DIR__ . '/../cfg') . '/.env';
if (!is_file($envFile)) {
    http_response_code(200);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="id"><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>Jyavani CMS</title>'
        . '<style>'
        . 'body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;'
        . 'max-width:520px;margin:4rem auto;padding:0 1rem;line-height:1.7;text-align:center}'
        . 'h1{font-size:2rem;color:#111;margin-bottom:.25rem}'
        . 'p{color:#555}'
        . 'a{display:inline-block;margin-top:1.5rem;padding:.75rem 2rem;'
        . 'background:#2563eb;color:#fff;text-decoration:none;border-radius:6px;font-weight:600}'
        . 'a:hover{background:#1d4ed8}'
        . '</style>'
        . '<h1>Jyavani CMS</h1>'
        . '<p>Selamat datang! Website ini menggunakan Jyavani CMS.</p>'
        . '<p>Silakan lanjutkan proses instalasi melalui tautan di bawah ini.</p>'
        . '<a href="/pondasi/">Mulai Instalasi →</a></html>';
    exit;
}

/**
 * bootstrap_core.php
 */

if (!defined('BACKEND_PATH')) {
    $env_backend = getenv('BACKEND_PATH') ?: '';
    if ($env_backend !== '') {
        define('BACKEND_PATH', $env_backend);
    } else {
        $guess = realpath(__DIR__ . '/../cfg');
        if ($guess !== false) {
            define('BACKEND_PATH', $guess);
        } else {
            http_response_code(500);
            die('Backend path not configured.');
        }
    }
}

$core_config = rtrim(BACKEND_PATH, '/\\') . DIRECTORY_SEPARATOR . 'config.php';
if (is_file($core_config)) {
    require_once $core_config;
} else {
    http_response_code(500);
    error_log("bootstrap_core: missing config.php at {$core_config}");
    die(app_debug_enabled() ? "bootstrap_core: config.php not found." : "Backend bootstrap error.");
}

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    error_log("bootstrap_core: PDO missing / DB init failed.");
    die(app_debug_enabled() ? "Database connection not available (\$pdo missing)." : "Sadly it not work");
}

$GLOBALS['pdo'] = $pdo;

// Initialize locale from site_language setting
if (function_exists('settings_get')) {
    $siteLang = settings_get($pdo, 'site_language', 'en');
    if (!in_array($siteLang, get_supported_locales(), true)) {
        $siteLang = 'en';
    }
    set_locale($siteLang);
    if ($siteLang !== 'en') {
        setlocale(LC_TIME, 'id_ID.UTF-8', 'id_ID', 'indonesian', 'Indonesia');
    }
}

$session_path = rtrim(BACKEND_PATH, '/\\') . DIRECTORY_SEPARATOR . 'session.php';
if (is_file($session_path)) {
    require_once $session_path;
} else {
    error_log("bootstrap_core: session.php not found at {$session_path}");
}

if (!function_exists('core_dbg')) {
    function core_dbg(string $msg): void {
        if (function_exists('app_debug_enabled') && app_debug_enabled()) {
            error_log("[bootstrap_core] " . $msg);
        }
    }
}

core_dbg("loaded BACKEND_PATH=" . BACKEND_PATH . " ; PDO present=" . (isset($pdo) ? '1' : '0'));