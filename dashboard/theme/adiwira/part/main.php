<?php
// /adiwira/theme/adiwira/part/main.php
if (!defined('ADAM_THEME')) {
    http_response_code(403);
    exit('Forbidden');
}

do_action('admin_main');
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
    echo '<h2>' . __('Invalid page') . '</h2>';
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

// ===== PLUGIN PAGE LOADER =====
// Cek plugin dulu sebelum fallback ke DASH_PATH
if (function_exists('plugin_resolve_route')) {
    $pluginPage = plugin_resolve_route($page);
    if ($pluginPage && isset($pluginPage['file']) && is_file($pluginPage['file'])) {
        if (function_exists('plugin_guard_route')) {
            $pdo = $GLOBALS['pdo'] ?? null;
            if ($pdo instanceof PDO) {
                plugin_guard_route($pdo, $pluginPage, false);
            }
        }
        require $pluginPage['file'];
        echo '</main>';
        return;
    }
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
    try {
        include $targetFull;
    } catch (Throwable $error) {
        if (!headers_sent()) http_response_code(500);
        error_log(sprintf(
            '[DASHBOARD PAGE] %s in %s:%d',
            $error->getMessage(),
            $error->getFile(),
            $error->getLine()
        ));
        echo '<section class="adam-main-error" role="alert">';
        echo '<h2>' . __('Error') . '</h2>';
        if (function_exists('app_debug_enabled') && app_debug_enabled()) {
            echo '<pre>FATAL: ' . htmlspecialchars($error->getMessage(), ENT_QUOTES, 'UTF-8') . "\n";
            echo 'File: ' . htmlspecialchars($error->getFile(), ENT_QUOTES, 'UTF-8') . ' : line ' . $error->getLine() . "\n\n";
            echo htmlspecialchars($error->getTraceAsString(), ENT_QUOTES, 'UTF-8') . '</pre>';
        } else {
            echo '<p>' . __('Internal error (see logs).') . '</p>';
        }
        echo '</section>';
    }

} else {
    if ($page === 'home') {
        // fallback markup jika baik theme/home.php maupun DASH_PATH/home.php tidak tersedia
        echo '<section class="adam-welcome">';
        echo '<h2>' . __('Hello, welcome!') . '</h2>';
        echo '<p>' . __('Welcome to the Jyavani CMS dashboard.') . '</p>';
        echo '</section>';
    } else {
        if (!headers_sent()) http_response_code(404);
        echo '<h2>' . __('Page not found') . '</h2>';
        echo '<p>' . __('Request:') . ' ' . htmlspecialchars($page, ENT_QUOTES, 'UTF-8') . '</p>';
    }
}

echo '</main>';
