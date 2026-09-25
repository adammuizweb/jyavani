<?php
declare(strict_types=1);

require_once __DIR__ . '/dev_lock.php';

require_once __DIR__ . '/../app/bootstrap_core.php';

// Load plugin system (hooks + registry + active plugin auto-loader)
require_once __DIR__ . '/../plugins/index.php';
plugin_load_active();
plugin_run_frontend_init();

// Direct index.php requests must honor the same exact root-route contract as
// router.php so deployment routing cannot bypass a plugin-owned homepage.
$requestMethod = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (function_exists('resolve_frontend_route')) {
    $rootPluginRoute = resolve_frontend_route('', $requestMethod);
    if ($rootPluginRoute !== null) {
        if (($rootPluginRoute['method_allowed'] ?? false) !== true) {
            http_response_code(405);
            $allowed = $rootPluginRoute['allowed_methods'] ?? [];
            if (is_array($allowed) && $allowed !== []) header('Allow: ' . implode(', ', $allowed));
            exit;
        }
        $handler = $rootPluginRoute['handler'] ?? null;
        if (is_callable($handler)) {
            $handler($pdo);
            exit;
        }
        if (is_string($handler) && is_file($handler)) {
            require $handler;
            exit;
        }
        error_log('[plugin-route] A matched root route has no readable handler.');
        http_response_code(500);
        exit;
    }
}

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
