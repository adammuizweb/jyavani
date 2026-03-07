<?php
// /adiwira/index.php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

require_once __DIR__ . '/bootstrap.php';

// ✅ Start/resume session sedini mungkin (hindari “kadang belum kebaca role”)
if (!headers_sent()) {
    if (function_exists('ensure_session_started')) {
        // true = “pastikan sesi aktif” (sesuai pemakaianmu sebelumnya)
        ensure_session_started(true);
    } elseif (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

// Optional debug
if (function_exists('sess_dbg')) {
    sess_dbg("adiwira/index.php: request start");
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

// ✅ Ensure session still active (safe)
if (!headers_sent()) {
    if (function_exists('ensure_session_started')) {
        ensure_session_started(true);
    } elseif (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

// Load user
$user = null;
try {
    $user = current_user($pdo);
} catch (Throwable $e) {
    if (function_exists('sess_dbg')) {
        sess_dbg("adiwira/index.php: current_user exception: " . $e->getMessage());
    }
    // kalau user gagal dimuat, ini serius, karena dashboard butuh identity
    http_response_code(500);
    echo "Internal error (user load failed).";
    exit;
}

// ✅ FIX: sinkronkan role dari DB → session (agar aside.php stabil)
if (is_array($user)) {
    $role = $user['role'] ?? $user['user_role'] ?? null;
    if (is_string($role) && $role !== '') {
        $_SESSION['user_role'] = strtolower(trim($role));
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