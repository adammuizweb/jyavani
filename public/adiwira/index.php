<?php
// /adiwira/index.php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

require_once __DIR__ . '/bootstrap.php';

// Resume session yang sudah ada saja.
// Jangan create session baru untuk guest di halaman dashboard.
if (!headers_sent()) {
    if (function_exists('ensure_session_started')) {
        ensure_session_started(false);
    } elseif (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

if (function_exists('sess_dbg')) {
    sess_dbg("adiwira/index.php: request start");
}

// Gate 1: session valid?
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

// Gate 2: user masih aktif di DB?
try {
    $status = current_user_status($pdo);

    if (($status['ok'] ?? false) !== true) {
        if (function_exists('sess_dbg')) {
            sess_dbg("adiwira/index.php: blocked user reason=" . ($status['reason'] ?? 'unknown'));
        }

        logout_user();

        http_response_code(404);
        require __DIR__ . '/../frontend_404.php';
        exit;
    }

    $user = $status['user'];
} catch (Throwable $e) {
    if (function_exists('sess_dbg')) {
        sess_dbg("adiwira/index.php: current_user_status exception: " . $e->getMessage());
    }
    http_response_code(500);
    echo "Internal error (user load failed).";
    exit;
}

// dashboard context
define('DASHBOARD_CONTEXT', true);

// sinkronkan role dari DB -> session
if (is_array($user)) {
    $role = $user['role'] ?? $user['user_role'] ?? null;
    if (is_string($role) && $role !== '') {
        $_SESSION['user_role'] = strtolower(trim($role));
    }
}

// flash
$flash_success = null;
if (!empty($_SESSION['flash_success'])) {
    $flash_success = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}

// render
require_once DASH_PATH . '/theme/adam/layout.php';