<?php
// /adiwira/theme/adam/layout.php
// Layout hanya boleh dimuat dari index.php (DASHBOARD_CONTEXT)
if (!defined('DASHBOARD_CONTEXT')) {
    http_response_code(404);
    require FRONTEND_404_PATH;
    exit;
}

define('ADAM_THEME', true);

require_once __DIR__ . '/../../admin/_notify.php';

$themePath = DASH_PATH . '/theme/adam';
$dashboard_toasts = function_exists('adiwira_flash_pull') ? adiwira_flash_pull() : [];

// server-side theme: read cookie so browser never flashes dark during nav
$adamTheme = '';
if (!empty($_COOKIE['adam_theme'])) {
    $at = (string)$_COOKIE['adam_theme'];
    if ($at === 'dark' || $at === 'light') $adamTheme = $at;
}
$htmlClass = ' class="theme-' . htmlspecialchars($adamTheme ?: 'light', ENT_QUOTES, 'UTF-8') . '"';
$colorScheme = $adamTheme ?: 'light';
$themeColor = $adamTheme === 'dark' ? '#071022' : '#f9fafb';
?>
<!doctype html>
<html lang="<?= h(get_locale()) ?>"<?= $htmlClass ?>>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="color-scheme" content="<?= htmlspecialchars($colorScheme, ENT_QUOTES, 'UTF-8') ?>">
  <meta name="theme-color" content="<?= htmlspecialchars($themeColor, ENT_QUOTES, 'UTF-8') ?>">

  <!-- apply state early -->
  <script src="/static/dashboard/js/aside-state.js"></script>

  <title><?= _e('Dashboard — CMS Adiwira') ?></title>
  <script>window.ADMIN_PATH = '<?= ADMIN_BASE_PATH ?>';</script>
<?php
$faviconUrl = (isset($pdo) && $pdo instanceof PDO && function_exists('settings_get'))
    ? (settings_get($pdo, 'favicon_url', '') ?? '')
    : '';
if ($faviconUrl !== ''): ?>
  <link rel="icon" href="<?= htmlspecialchars($faviconUrl, ENT_QUOTES, 'UTF-8') ?>">
<?php else: ?>
  <link rel="icon" type="image/png" sizes="32x32" href="/static/img/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="/static/img/favicon-16x16.png">
<?php endif; ?>
  
  <script>
(function(){
  try{
    var key = 'adam_theme';
    var saved = localStorage.getItem(key);
    var root = document.documentElement;

    if (saved === 'dark') {
      root.classList.add('theme-dark');
      root.style.colorScheme = 'dark';
    } else if (saved === 'light') {
      root.classList.add('theme-light');
      root.style.colorScheme = 'light';
    } else {
      // belum pernah memilih tema: paksa terang agar OS/browser dark mode tidak bocor ke kontrol
      root.classList.add('theme-light');
      root.style.colorScheme = 'light';
    }

    // sync to cookie so server prevents flash on next navigation
    if (saved === 'dark' || saved === 'light') {
      try { document.cookie = 'adam_theme=' + saved + '; path=/; max-age=' + (60*60*24*365); } catch(e){}
      var tc = document.querySelector('meta[name="theme-color"]');
      if (tc) tc.setAttribute('content', saved === 'dark' ? '#071022' : '#f9fafb');
    }
  }catch(e){}
})();
</script>

  <!-- stylesheet tema -->
<?php
  $cssFile = defined('PUBLIC_PATH') ? (PUBLIC_PATH . '/static/dashboard/css/style.css') : '';
  if (!$cssFile || !is_file($cssFile)) { $cssFile = __DIR__ . '/../../../public_html/static/dashboard/css/style.css'; }
  if (!is_file($cssFile)) { $cssFile = __DIR__ . '/../../../public/static/dashboard/css/style.css'; }
  $cssVer = is_file($cssFile) ? filemtime($cssFile) : '';
?><link rel="stylesheet" href="/static/dashboard/css/style.css?v=<?= $cssVer ?>">
  <link rel="stylesheet" href="/static/components/confirm/confirm.css">
  <link rel="stylesheet" href="/static/components/toast/toast.css">

  <!-- quill -->
  <link href="/static/vendor/quill/quill.snow.css" rel="stylesheet">
  <script src="/static/vendor/quill/quill.min.js"></script>
  <style>.ql-editor p{margin:.35em 0 .75em}.ql-editor p:first-child{margin-top:0}.ql-editor p:last-child{margin-bottom:0}</style>

  <!-- codemirror -->
  <link rel="stylesheet" href="/static/vendor/codemirror/codemirror.min.css">
  <script src="/static/vendor/codemirror/codemirror.min.js"></script>

  <script src="/static/vendor/codemirror/mode/htmlmixed/htmlmixed.min.js"></script>
  <script src="/static/vendor/codemirror/mode/xml/xml.min.js"></script>
  <script src="/static/vendor/codemirror/mode/javascript/javascript.min.js"></script>
  <script src="/static/vendor/codemirror/mode/css/css.min.js"></script>
  <script src="/static/vendor/codemirror/mode/php/php.min.js"></script>
  <script src="/static/vendor/codemirror/addon/edit/closetag.min.js"></script>
  <script src="/static/vendor/codemirror/addon/edit/closebrackets.min.js"></script>
  <link rel="stylesheet" href="/static/vendor/codemirror/theme/dracula.min.css">

  <!-- CM fold addon -->
  <link rel="stylesheet" href="/static/vendor/codemirror/addon/fold/foldgutter.css">
  <script src="/static/vendor/codemirror/addon/fold/foldcode.js"></script>
  <script src="/static/vendor/codemirror/addon/fold/foldgutter.js"></script>
  <script src="/static/vendor/codemirror/addon/fold/brace-fold.js"></script>
  <script src="/static/vendor/codemirror/addon/fold/xml-fold.js"></script>
  <script src="/static/vendor/codemirror/addon/fold/comment-fold.js"></script>
<?php do_action('admin_head'); ?>
<?php
$pa = function_exists('plugin_assets') ? plugin_assets() : [];
foreach ($pa['css'] ?? [] as $css_url) {
    echo '<link rel="stylesheet" href="' . htmlspecialchars($css_url, ENT_QUOTES, 'UTF-8') . '">' . PHP_EOL;
}
?>
</head>

<body id="adam-body" class="adam-body ad-body">
  <div id="adam-app" class="adam-app">

    <?php require_once $themePath . '/part/header.php'; ?>

    <div id="adam-layout" class="adam-layout" role="application">
      <?php require_once $themePath . '/part/aside.php'; ?>
      <?php require_once $themePath . '/part/main.php'; ?>
      <?php require_once $themePath . '/part/details.php'; ?>
    </div>

    <?php require_once $themePath . '/part/footer.php'; ?>

    <div id="newnotif-confirm-root"></div>
    <div id="newnotif-toast-root" aria-live="polite" aria-atomic="true"></div>

    <?php
      if (!empty($dashboard_toasts) && function_exists('adiwira_bootstrap_toasts_script')) {
          echo adiwira_bootstrap_toasts_script($dashboard_toasts);
      }
    ?>

    <!-- root lama dibiarkan sementara agar halaman lain tidak langsung rusak -->
    <div id="adam-toast-root" aria-live="polite" aria-atomic="true" style="position:fixed;right:16px;bottom:20px;z-index:120000;pointer-events:none;"></div>
  </div>

  <script src="/static/components/toast/toast.js" defer></script>
  <script src="/static/components/confirm/confirm.js" defer></script>

  <script src="/static/dashboard/js/index-list.js" defer></script>
  <script src="/static/dashboard/js/aside.js" defer></script>
  <script src="/static/dashboard/js/panel.js" defer></script>
  <script src="/static/dashboard/js/accordion.js" defer></script>
  <script src="/static/dashboard/js/theme-toggle.js" defer></script>
  <script>
window.i18n_upd = <?= json_encode([
    'failed_to_check'   => __('Failed to check updates.'),
    'all_up_to_date'    => __('All up to date.'),
    'checking'          => __('Checking...'),
    'updates_available' => __('Updates available:'),
    'item'              => __('item'),
    'notification'      => __('Update Notification'),
    'plugin'            => __('Plugin'),
    'theme'             => __('Theme'),
]) ?>;
</script>
  <script src="/static/dashboard/js/update-notif.js" defer></script>

<?php
$pa_js = function_exists('plugin_assets') ? plugin_assets() : [];
foreach ($pa_js['js'] ?? [] as $js_url) {
    echo '<script src="' . htmlspecialchars($js_url, ENT_QUOTES, 'UTF-8') . '"></script>' . PHP_EOL;
}
?>
<?php do_action('admin_footer'); ?>
</body>
</html>