<?php
// /views/themes/default/sidebar.php

if (isset($_GET['nosidebar']) && $_GET['nosidebar'] === '1') {
    return;
}

// Pastikan helper widget tersedia dari root public.
// bootstrap_theme biasanya sudah meload ini,
// tapi blok ini aman sebagai backup.
if (!function_exists('widget') && !function_exists('render_widget')) {
    if (defined('PUBLIC_PATH')) {
        $helper = rtrim(PUBLIC_PATH, '/\\') . DIRECTORY_SEPARATOR . 'widget_helper.php';
        if (is_file($helper)) {
            require_once $helper;
        }
    }
}

// Adapter agar kompatibel dengan helper lama/baru
$widgetFn = null;
if (function_exists('widget')) {
    $widgetFn = 'widget';
} elseif (function_exists('render_widget')) {
    $widgetFn = 'render_widget';
}

if (!$widgetFn) {
    ?>
    <div class="sidebar-wrap">
      <div class="w-box">
        <div class="w-title">Sidebar</div>
        <p class="w-empty">Fungsi widget tidak tersedia.</p>
      </div>
    </div>
    <?php
    return;
}
?>
<div class="sidebar-wrap">
  <?php
  // Kalau mau “matikan widget” satu blok, tinggal comment blok ini pakai /* ... */
  echo widget('search_form', ['placeholder' => 'Cari artikel...']);
  echo widget('recent_posts', ['title' => 'Artikel Terbaru', 'limit' => 6]);
  echo widget('categories_list', ['title' => 'Kategori', 'limit' => 30, 'only_parents' => true]);
/*  echo widget('pages_list', ['title' => 'Halaman', 'limit' => 20]); */
  echo widget('author_posts', ['title' => 'Artikel Saya', 'limit' => 8]);
  ?>
</div>
