<?php
// /adiwira/theme/adam/layout.php
// Layout hanya boleh dimuat dari index.php (DASHBOARD_CONTEXT)
if (!defined('DASHBOARD_CONTEXT')) {
    http_response_code(404);
    require __DIR__ . '/../../../frontend_404.php';
    exit;
}

define('ADAM_THEME', true);

require_once __DIR__ . '/../../admin/_notify.php';

$base_url = rtrim(str_replace('\\','/', dirname($_SERVER['SCRIPT_NAME'])), '/'); // ex: /adiwira
$themePath = DASH_PATH . '/theme/adam';
$dashboard_toasts = function_exists('adiwira_flash_pull') ? adiwira_flash_pull() : [];
?>
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">

  <!-- apply state early -->
  <script src="<?= htmlspecialchars($base_url . '/theme/adam/js/aside-state.js', ENT_QUOTES) ?>"></script>

  <title>Dashboard — CMS Adiwira</title>
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
  <link rel="stylesheet" href="<?= htmlspecialchars($base_url . '/theme/adam/css/style.css', ENT_QUOTES, 'UTF-8') ?>">
  <link rel="stylesheet" href="<?= htmlspecialchars($base_url . '/static/components/confirm/confirm.css', ENT_QUOTES, 'UTF-8') ?>">
  <link rel="stylesheet" href="<?= htmlspecialchars($base_url . '/static/components/toast/toast.css', ENT_QUOTES, 'UTF-8') ?>">

  <!-- quill -->
  <link href="<?= htmlspecialchars($base_url . '/static/vendor/quill/quill.snow.css', ENT_QUOTES) ?>" rel="stylesheet">
  <script src="<?= htmlspecialchars($base_url . '/static/vendor/quill/quill.min.js', ENT_QUOTES) ?>"></script>

  <!-- codemirror -->
  <link rel="stylesheet" href="<?= htmlspecialchars($base_url . '/static/vendor/codemirror/codemirror.min.css', ENT_QUOTES) ?>">
  <script src="<?= htmlspecialchars($base_url . '/static/vendor/codemirror/codemirror.min.js', ENT_QUOTES) ?>"></script>

  <script src="<?= htmlspecialchars($base_url . '/static/vendor/codemirror/mode/htmlmixed/htmlmixed.min.js', ENT_QUOTES) ?>"></script>
  <script src="<?= htmlspecialchars($base_url . '/static/vendor/codemirror/mode/xml/xml.min.js', ENT_QUOTES) ?>"></script>
  <script src="<?= htmlspecialchars($base_url . '/static/vendor/codemirror/mode/javascript/javascript.min.js', ENT_QUOTES) ?>"></script>
  <script src="<?= htmlspecialchars($base_url . '/static/vendor/codemirror/mode/css/css.min.js', ENT_QUOTES) ?>"></script>
  <script src="<?= htmlspecialchars($base_url . '/static/vendor/codemirror/mode/php/php.min.js', ENT_QUOTES) ?>"></script>
  <script src="<?= htmlspecialchars($base_url . '/static/vendor/codemirror/addon/edit/closetag.min.js', ENT_QUOTES) ?>"></script>
  <script src="<?= htmlspecialchars($base_url . '/static/vendor/codemirror/addon/edit/closebrackets.min.js', ENT_QUOTES) ?>"></script>
  <link rel="stylesheet" href="<?= htmlspecialchars($base_url . '/static/vendor/codemirror/theme/dracula.min.css', ENT_QUOTES) ?>">

  <!-- CM fold addon -->
  <link rel="stylesheet" href="<?= htmlspecialchars($base_url . '/static/vendor/codemirror/addon/fold/foldgutter.css', ENT_QUOTES) ?>">
  <script src="<?= htmlspecialchars($base_url . '/static/vendor/codemirror/addon/fold/foldcode.js', ENT_QUOTES) ?>"></script>
  <script src="<?= htmlspecialchars($base_url . '/static/vendor/codemirror/addon/fold/foldgutter.js', ENT_QUOTES) ?>"></script>
  <script src="<?= htmlspecialchars($base_url . '/static/vendor/codemirror/addon/fold/brace-fold.js', ENT_QUOTES) ?>"></script>
  <script src="<?= htmlspecialchars($base_url . '/static/vendor/codemirror/addon/fold/xml-fold.js', ENT_QUOTES) ?>"></script>
  <script src="<?= htmlspecialchars($base_url . '/static/vendor/codemirror/addon/fold/comment-fold.js', ENT_QUOTES) ?>"></script>
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

  <script src="<?= htmlspecialchars($base_url . '/static/components/toast/toast.js', ENT_QUOTES) ?>" defer></script>
  <script src="<?= htmlspecialchars($base_url . '/static/components/confirm/confirm.js', ENT_QUOTES) ?>" defer></script>

  <script src="<?= htmlspecialchars($base_url . '/theme/adam/js/index-list.js', ENT_QUOTES) ?>" defer></script>
  <script src="<?= htmlspecialchars($base_url . '/theme/adam/js/aside.js', ENT_QUOTES) ?>" defer></script>
  <script src="<?= htmlspecialchars($base_url . '/theme/adam/js/panel.js', ENT_QUOTES) ?>" defer></script>
  <script src="<?= htmlspecialchars($base_url . '/theme/adam/js/accordion.js', ENT_QUOTES) ?>" defer></script>
  <script src="<?= htmlspecialchars($base_url . '/theme/adam/js/theme-toggle.js', ENT_QUOTES) ?>" defer></script>

</body>
</html>