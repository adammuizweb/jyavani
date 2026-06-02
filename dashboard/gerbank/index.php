<?php
// golden is silence
 error_reporting(E_ALL);
 ini_set('display_errors', 1);
 ini_set('display_startup_errors', 1);
 
 require_once __DIR__ . '/../bootstrap.php';
// Jika belum login, tampilkan 404 publik (menggunakan tema homepage)
if (!is_logged_in()) {
    http_response_code(404);
    require FRONTEND_404_PATH;
    exit;
}