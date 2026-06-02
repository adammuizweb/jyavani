<?php
declare(strict_types=1);

// /adiwira/logout.php
require_once __DIR__ . '/bootstrap.php';

// Resume session yang sudah ada saja.
// Tidak perlu membuat session baru kalau guest membuka logout.php.
if (function_exists('ensure_session_started')) {
    ensure_session_started(false);
} elseif (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// perform logout
if (function_exists('logout_user')) {
    logout_user();
}

// redirect ke homepage
header('Location: /');
exit;