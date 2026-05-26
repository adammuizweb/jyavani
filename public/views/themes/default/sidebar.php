<?php
// /views/themes/default/sidebar.php

if (isset($_GET['nosidebar']) && $_GET['nosidebar'] === '1') {
    return;
}

$helperPath = __DIR__ . '/../../../../cfg/helpers/widget_helper.php';
if (is_file($helperPath)) {
    require_once $helperPath;
}

if (!function_exists('widget')) {
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

// --- Auto-load published presets dari database ---
global $pdo;
if (isset($pdo) && $pdo instanceof PDO && function_exists('load_preset_widgets')) {
    load_preset_widgets($pdo);
}

// --- Cek managed sidebar widgets dari settings ---
if (function_exists('render_sidebar_widgets')) {
    $managed = render_sidebar_widgets($pdo);
    if ($managed !== '') {
        echo '<div class="sidebar-wrap">' . $managed . '</div>';
        return;
    }
}

// --- Fallback: hardcoded widgets (sebelum konfigurasi via admin) ---
if (class_exists('ShortcodeQuery') && !defined('SIDEBAR_PRESETS_REGISTERED')) {
    define('SIDEBAR_PRESETS_REGISTERED', true);

    ShortcodeQuery::posts()->type('article')->category('news')
        ->limit(5)->latest()->layout('list')
        ->registerWidget('last_news');

    ShortcodeQuery::posts()->type('page')
        ->limit(8)->random()
        ->registerWidget('random_pages');

    ShortcodeQuery::posts()->type('article')
        ->limit(3)->random()->layout('cards')
        ->registerWidget('random_articles');
}
?>
<div class="sidebar-wrap">
  <?php
  echo widget('last_news');
  ?>
</div>
<div class="sidebar-wrap">
  <?php
  echo widget('search_form', ['placeholder' => 'Cari artikel...']);
  echo widget('categories_list', ['title' => 'Kategori', 'limit' => 30, 'only_parents' => true]);
  echo widget('author_posts', ['title' => 'Artikel Saya', 'limit' => 8]);
  ?>
</div>
<div class="sidebar-wrap">
  <?php
  echo widget('random_articles');
  ?>
</div>
