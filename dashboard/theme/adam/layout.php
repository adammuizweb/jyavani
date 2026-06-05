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
?>
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">

  <!-- apply state early -->
  <script src="/static/dashboard/js/aside-state.js"></script>

  <title><?= _e('Dashboard — CMS Adiwira') ?></title>
  <script>window.ADMIN_PATH = '<?= ADMIN_BASE_PATH ?>';</script>
  <link rel="icon" type="image/png" sizes="32x32" href="/static/img/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="/static/img/favicon-16x16.png">
  
  <script>
(function(){
  try{
    var key = 'adam_theme';
    var saved = localStorage.getItem(key);
    var root = document.documentElement;

    if (saved === 'dark') root.classList.add('theme-dark');
    else if (saved === 'light') root.classList.add('theme-light');
  }catch(e){}
})();
</script>

  <!-- stylesheet tema -->
  <link rel="stylesheet" href="/static/dashboard/css/style.css">
  <link rel="stylesheet" href="/static/components/confirm/confirm.css">
  <link rel="stylesheet" href="/static/components/toast/toast.css">

  <!-- quill -->
  <link href="/static/vendor/quill/quill.snow.css" rel="stylesheet">
  <script src="/static/vendor/quill/quill.min.js"></script>

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

<?php do_action('admin_footer'); ?>
</body>
</html>