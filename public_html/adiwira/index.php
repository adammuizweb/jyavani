<?php
// /adiwira/index.php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

require_once __DIR__ . '/bootstrap.php';

// Optional debug
if (function_exists('sess_dbg')) {
    sess_dbg("adiwira/index.php: request start");
}

// Resume session ONLY if canonical cookie exists
$sessionName = session_name(); // <- berasal dari SESSION_NAME
if (!empty($_COOKIE[$sessionName]) && function_exists('ensure_session_started') && !headers_sent()) {
    ensure_session_started(false);
}

// Auth gate
try {
    if (!is_logged_in()) {
        http_response_code(404);
        require __DIR__ . '/../frontend_404.php';
        exit;
    }
} catch (Throwable $e) {
    if (function_exists('sess_dbg')) {
        sess_dbg("adiwira/index.php: is_logged_in exception: " . $e->getMessage());
    }
    http_response_code(500);
    echo "Internal error (see logs).";
    exit;
}

// Logged in → dashboard context
define('DASHBOARD_CONTEXT', true);

// Ensure session fully active (safe)
if (!headers_sent()) {
    ensure_session_started(true);
}

// Load user
$user = null;
try {
    $user = current_user($pdo);
} catch (Throwable $e) {
    if (function_exists('sess_dbg')) {
        sess_dbg("adiwira/index.php: current_user exception: " . $e->getMessage());
    }
}

// Flash
$flash_success = null;
if (!empty($_SESSION['flash_success'])) {
    $flash_success = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}

// Render
require_once DASH_PATH . '/theme/adam/layout.php';
