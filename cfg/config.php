<?php
// /home/u528279701/jyavani-cfg/config.php

// 1. Load env loader
require_once __DIR__ . '/env.php';

// 2. Load isi .env
load_env(__DIR__ . '/.env');

require_once __DIR__ . '/helpers/debug_helpers.php';
app_configure_error_reporting();
app_register_shutdown_handler();

// set timezone dan locale global (Indonesia, GMT+7)
date_default_timezone_set('Asia/Jakarta'); // GMT+7
// coba beberapa variasi locale untuk kompatibilitas server
setlocale(LC_TIME, 'id_ID.UTF-8', 'id_ID', 'indonesian', 'Indonesia');

// 3. Inisialisasi koneksi database
require_once __DIR__ . '/db.php';

// 4. Definisikan konstanta global (opsional)
define('cfg_PATH', __DIR__);

// 5. Konstanta path untuk public (dibutuhkan oleh theme_helper & widget_helper)
if (!defined('PUBLIC_PATH')) {
    $publicGuess = realpath(__DIR__ . '/../public');
    define('PUBLIC_PATH', $publicGuess ?: (__DIR__ . '/../public'));
}
if (!defined('VIEWS_BASE')) {
    define('VIEWS_BASE', realpath(PUBLIC_PATH . '/views/themes') ?: (PUBLIC_PATH . '/views/themes'));
}
if (!defined('DEFAULT_THEME_FOLDER')) {
    define('DEFAULT_THEME_FOLDER', 'default');
}

// 6. Frontend helpers (widget sebelum theme, karena theme_helper bisa depend)
require_once __DIR__ . '/helpers/widget_helper.php';
require_once __DIR__ . '/helpers/theme_helper.php';

// 8. Gunakan helper ini jika ingin gunakan waktu indo
require_once __DIR__ . '/helpers/time_helpers.php';

// 9. helpers Redirect
require_once __DIR__ . '/helpers/success_redirect.php';

// 10. helpers batasi author
require_once __DIR__ . '/helpers/author_helpers.php';

// 11. helpers globL ROLE
require_once __DIR__ . '/helpers/role_helpers.php';

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