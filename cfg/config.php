<?php
// /home/u528279701/jyavani-cfg/config.php

// 1. Load env loader
require_once __DIR__ . '/env.php';

// 2. Load isi .env
load_env(__DIR__ . '/.env');

// set timezone dan locale global (Indonesia, GMT+7)
date_default_timezone_set('Asia/Jakarta'); // GMT+7
// coba beberapa variasi locale untuk kompatibilitas server
setlocale(LC_TIME, 'id_ID.UTF-8', 'id_ID', 'indonesian', 'Indonesia');

// 3. Inisialisasi koneksi database
require_once __DIR__ . '/db.php';

// 4. Definisikan konstanta global (opsional)
define('cfg_PATH', __DIR__);

// 5. Gunakan helper ini jika ingin gunakan waktu indo
require_once __DIR__ . '/helpers/time_helpers.php';

// 6. Gunakan helper ini jika ingin gunakan sukses post
require_once __DIR__ . '/helpers/success_redirect.php';

// 7. Gunakan helper ini batasi author
require_once __DIR__ . '/helpers/author_helpers.php';

// 8. Gunakan helper ini globL ROLE
require_once __DIR__ . '/helpers/role_helpers.php';

// 9. Gunakan helper ini Editor
require_once __DIR__ . '/helpers/editor_helpers.php';

// 10. Backend helpers (admin only)
require_once __DIR__ . '/helpers/null_helpers.php';

// 11. Backend helpers (admin only)
require_once __DIR__ . '/helpers/lang_helpers.php';

// 12. Backend helpers (admin only)
require_once __DIR__ . '/helpers/url_helpers.php';

// 13. helpers untuk konfigurasi setting
require_once __DIR__ . '/helpers/settings_helpers.php';

// 14. helpers untuk Redirect
require_once __DIR__ . '/helpers/redirct_helpers.php';

// 16. helpers untuk Content 
require_once __DIR__ . '/helpers/cms_content.php';

// 16. helpers untuk Widget
require_once __DIR__ . '/helpers/widget_shortcodes_p.php';