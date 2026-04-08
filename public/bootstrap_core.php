<?php
declare(strict_types=1);

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
            define('BACKEND_PATH', 'C:/Users/adamm/Downloads/windows/jyavani/cfg');
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