<?php
declare(strict_types=1);

// index.php public
// define('DEV_LOCK_ENABLED', true);
// require_once __DIR__ . '/dev_lock.php';

require_once __DIR__ . '/../app/bootstrap_core.php';

// Load plugin system (hooks + registry + active plugin auto-loader)
require_once __DIR__ . '/../plugins/index.php';
plugin_load_active();
plugin_run_frontend_init();

// --- HANDLE root search via ?s= (minimal, non-invasive) ---
if (!empty($_GET['s'])) {
    // pastikan SearchController ada dan $pdo sudah tersedia dari bootstrap_public
    require_once __DIR__ . '/../app/controllers/SearchController.php';

    $q = trim((string)($_GET['s'] ?? ''));
    $page = max(1, (int)($_GET['p'] ?? $_GET['page'] ?? 1));

    // debug (opsional): tulis ke error_log jika perlu
    // error_log("INDEX.PHP: handling ?s= query q={$q} page={$page}");

    // panggil controller langsung — ini akan render hasil (SearchController sudah memanggil layout)
    SearchController::search($pdo, $q, $page);
    exit;
}

$context_for_layout = 'home';

// ambil konten landing (opsional) — misalnya posts bertipe 'page' dengan slug 'home' atau konten statis
// $content_html = '<h2>Selamat datang di Jyavani</h2><p>Konten homepage...</p>';

require __DIR__ . '/../app/layout.php';
