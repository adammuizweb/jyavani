<?php
// /home/u528279701/jyavani-cfg/config.php

// 1. Load env loader
require_once __DIR__ . '/env.php';

// 2. Load isi .env
load_env(__DIR__ . '/.env');

function app_is_absolute_path(string $path): bool {
    return str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
}

function app_resolve_public_path(string $projectRoot): string {
    $configured = trim((string)env('PUBLIC_PATH', ''));
    if ($configured !== '') {
        if (!app_is_absolute_path($configured)) {
            throw new RuntimeException('PUBLIC_PATH must be an absolute directory path.');
        }
        $resolved = realpath($configured);
        if ($resolved === false || !is_dir($resolved)) {
            throw new RuntimeException('PUBLIC_PATH does not exist or is not a directory: ' . $configured);
        }
        return rtrim($resolved, '/\\');
    }

    $candidates = [];
    if (!empty($_SERVER['SCRIPT_FILENAME'])) $candidates[] = dirname((string)$_SERVER['SCRIPT_FILENAME']);
    foreach (['public_html', 'public', 'www', 'htdocs'] as $directory) {
        $candidates[] = $projectRoot . '/' . $directory;
    }
    foreach (array_unique($candidates) as $candidate) {
        $resolved = realpath($candidate);
        if ($resolved !== false && is_dir($resolved)
            && (is_file($resolved . '/router.php') || is_file($resolved . '/index.php'))) {
            return rtrim($resolved, '/\\');
        }
    }
    throw new RuntimeException('PUBLIC_PATH could not be detected. Set it to an existing absolute directory.');
}

if (!defined('PUBLIC_PATH')) {
    define('PUBLIC_PATH', app_resolve_public_path(dirname(__DIR__)));
}
if (!defined('UPDATE_STATUS_FILE')) {
    $configuredUpdateStatusFile = trim((string)env('UPDATE_STATUS_FILE', ''));
    if ($configuredUpdateStatusFile !== '') {
        if (!app_is_absolute_path($configuredUpdateStatusFile)) {
            throw new RuntimeException('UPDATE_STATUS_FILE must be an absolute file path.');
        }
        define('UPDATE_STATUS_FILE', $configuredUpdateStatusFile);
    }
}

require_once __DIR__ . '/helpers/hooks.php';
require_once __DIR__ . '/helpers/package_archive.php';
require_once __DIR__ . '/helpers/update_operation.php';
require_once __DIR__ . '/helpers/debug_helpers.php';
app_configure_error_reporting();
app_register_shutdown_handler();
app_configure_security_headers();

// set timezone dan locale global (Indonesia, GMT+7)
date_default_timezone_set('Asia/Jakarta'); // GMT+7
// coba beberapa variasi locale untuk kompatibilitas server
setlocale(LC_TIME, 'id_ID.UTF-8', 'id_ID', 'indonesian', 'Indonesia');

// 3. Inisialisasi koneksi database
require_once __DIR__ . '/db.php';

// 4. Definisikan konstanta global (opsional)
define('cfg_PATH', __DIR__);

// Progress and cancellation must not discover executable themes/plugins or run
// unrelated application helpers while Core publication holds the writer lock.
if (defined('UPDATE_PROCESS_CONTROL_REQUEST')) {
    require_once __DIR__ . '/helpers/null_helpers.php';
    require_once __DIR__ . '/helpers/settings_helpers.php';
    require_once __DIR__ . '/helpers/authorization.php';
    require_once __DIR__ . '/helpers/lang_helpers.php';
    require_once __DIR__ . '/helpers/auth_helpers.php';
    return;
}

// 5. Konstanta path untuk public (dibutuhkan oleh theme_helper & widget_helper)
if (!defined('VIEWS_BASE')) {
    $appViews = realpath(PUBLIC_PATH . '/views/themes');
    define('VIEWS_BASE', $appViews ?: (PUBLIC_PATH . '/views/themes'));
}
if (!defined('DEFAULT_THEME_FOLDER')) {
    define('DEFAULT_THEME_FOLDER', 'default');
}

// 6. Frontend helpers (widget sebelum theme, karena theme_helper bisa depend)
require_once __DIR__ . '/helpers/widget_helper.php';
require_once __DIR__ . '/helpers/theme_helper.php';
if (PHP_SAPI !== 'cli' && !defined('UPDATE_PROCESS_CONTROL_REQUEST')) theme_lifecycle_reader_start();
require_once __DIR__ . '/helpers/theme_sections.php';

// 8. Gunakan helper ini jika ingin gunakan waktu indo
require_once __DIR__ . '/helpers/time_helpers.php';

// 9. helpers Redirect
require_once __DIR__ . '/helpers/success_redirect.php';

// 10. helpers batasi author
require_once __DIR__ . '/helpers/author_helpers.php';

// 11. helpers globL ROLE
require_once __DIR__ . '/helpers/role_helpers.php';

// 11b. Dynamic role and permission authorization
require_once __DIR__ . '/helpers/authorization.php';

// 12. helpers Editor
require_once __DIR__ . '/helpers/editor_helpers.php';

// 13. Backend helpers (admin only)
require_once __DIR__ . '/helpers/null_helpers.php';

// 14. Backend helpers (admin only)
require_once __DIR__ . '/helpers/lang_helpers.php';

// 15. Backend helpers (admin only)
require_once __DIR__ . '/helpers/url_helpers.php';

// 16. helpers untuk konfigurasi setting
require_once __DIR__ . '/helpers/settings_helpers.php';
require_once __DIR__ . '/helpers/mail.php';

// 17. helpers untuk Redirect
require_once __DIR__ . '/helpers/redirct_helpers.php';

// 18. helpers untuk Content
require_once __DIR__ . '/helpers/cms_content.php';

// 19. helpers untuk Widget
require_once __DIR__ . '/helpers/widget_shortcodes_p.php';

// 20. helpers untuk private file shortcodes
require_once __DIR__ . '/helpers/private_file_shortcodes.php';

// 21. helpers untuk video shortcodes
require_once __DIR__ . '/helpers/video_shortcodes.php';

// 22. helpers untuk menu system
require_once __DIR__ . '/helpers/menu_helper.php';

// 23. helpers untuk auth (login/register path, admin path, brute-force)
require_once __DIR__ . '/helpers/auth_helpers.php';

// 24. helpers untuk custom permalink structure
require_once __DIR__ . '/helpers/content_route_helpers.php';
require_once __DIR__ . '/helpers/permalink_helpers.php';

// 25. Generic collection routing, data, and URL extension helpers
require_once __DIR__ . '/helpers/collection_helpers.php';

// 26. helpers untuk permission auto-fix
require_once __DIR__ . '/helpers/permission_helper.php';

// 27. Theme Customizer (lite) — theme mods from theme.json declaration
require_once __DIR__ . '/helpers/theme_customizer.php';

// 28. Theme Zones — widget-area style header/footer slots
require_once __DIR__ . '/helpers/theme_zones.php';
