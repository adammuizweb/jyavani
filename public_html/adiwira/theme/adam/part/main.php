<?php
// /adiwira/theme/adam/part/main.php
if (!defined('ADAM_THEME')) {
    http_response_code(403);
    exit('Forbidden');
}

echo '<main id="adam-main" class="adam-main">';

// tampilkan flash success jika ada (hanya sekali)
if (!empty($flash_success)) {
    echo '<div id="adam-flash" class="adam-flash adam-flash-success" role="status" aria-live="polite">';
    echo htmlspecialchars($flash_success, ENT_QUOTES, 'UTF-8');
    echo '</div>';
}

// baca parameter page, default 'home'
$page = trim((string)($_GET['page'] ?? 'home'), " \t\n\r\0\x0B/");

// validasi format: hanya huruf kecil, angka, dash, underscore, dan slash untuk subfolder
if ($page === '') $page = 'home';
if (!preg_match('#^[a-z0-9_\-\/]+$#', $page)) {
    if (!headers_sent()) http_response_code(400);
    echo '<h2>Halaman tidak valid</h2>';
    echo '</main>';
    return;
}

// Jika page adalah 'home', coba muat view tema lokal terlebih dahulu
if ($page === 'home') {
    $themeHome = __DIR__ . '/views/home.php';
    if (is_file($themeHome) && is_readable($themeHome)) {
        include $themeHome;
        echo '</main>';
        return;
    }
    // jika tidak ada theme view, kita akan coba fallback ke file di DASH_PATH bawah
}

// bentuk path file target di dalam DASH_PATH
$targetRelative = $page . '.php';
$targetFull = realpath(DASH_PATH . '/' . $targetRelative);

// pastikan file ada dan berada di bawah DASH_PATH (mencegah traversal)
$safe = false;
if ($targetFull !== false) {
    $dashReal = realpath(DASH_PATH);
    if ($dashReal !== false && strpos($targetFull, $dashReal) === 0 && is_file($targetFull) && is_readable($targetFull)) {
        $safe = true;
    }
}

if ($safe) {
    include $targetFull;
} else {
    if ($page === 'home') {
        // fallback markup jika baik theme/home.php maupun DASH_PATH/home.php tidak tersedia
        echo '<section class="adam-welcome">';
        echo '<h2>Halo, selamat datang!</h2>';
        echo '<p>Ini adalah dashboard Adiwira dengan tema <strong>adam</strong>.</p>';
        echo '</section>';
    } else {
        if (!headers_sent()) http_response_code(404);
        echo '<h2>Halaman tidak ditemukan</h2>';
        echo '<p>Permintaan: ' . htmlspecialchars($page, ENT_QUOTES, 'UTF-8') . '</p>';
    }
}

echo '</main>';
