<?php
$siteTitle = $site['title'] ?? 'ABC';
$baseUrl   = rtrim($site['url'] ?? '/', '/');
$homeUrl   = $baseUrl ?: '/';
$searchQuery = $_GET['s'] ?? '';
$colorMode = function_exists('get_theme_color_mode') ? get_theme_color_mode() : 'both';

$tcLogo = function_exists('theme_mod') ? (string)theme_mod('logo', '') : '';
$tcMenu = function_exists('theme_mod') ? (string)theme_mod('nav_menu', '') : '';
$tcShowTheme = !function_exists('theme_mod') || theme_mod('show_theme', true);
$tcShowLang  = !function_exists('theme_mod') || theme_mod('show_lang', true);
$tcShowSearch = !function_exists('theme_mod') || theme_mod('show_search', true);
$navMenuSlug = $tcMenu !== '' ? $tcMenu : 'primary';
?>
<div id="overlay" class="overlay"></div>

<header class="site-header moving-line ml-header onload"
  data-anim-trigger="load"
  data-ml-duration="980"
  data-ml-delay="240"
>
  <div class="header-inner"><!-- jangan kasih animasi di wrapper -->

    <!-- BRAND -->
<a href="<?= htmlspecialchars($homeUrl) ?>" class="brand onload wave-span"
   data-anim-trigger="load"
   data-wave-target=".jyavani-logo"
   data-wave-step="28">

  <img
    src="<?= $tcLogo !== '' ? htmlspecialchars($tcLogo, ENT_QUOTES, 'UTF-8') : htmlspecialchars($homeUrl) . '/static/img/jyavani.svg' ?>"
    alt="<?= htmlspecialchars($siteTitle) ?>"
    class="flip-logo onload"
    data-anim-trigger="load"
    data-fl-duration="900"
    data-fl-delay="120"
  >

  <span class="jyavani-logo" aria-label="Jyavani">
    <span class="letter accent" data-word="Just">J</span>
    <span class="letter base" data-word="Your">y</span>
    <span class="letter accent" data-word="Visiting">v</span>
    <span class="letter base" data-word="Always">a</span>
    <span class="letter base" data-word="Nice">n</span>
    <span class="letter base" data-word="Inspire">i</span>
  </span>
</a>


    <!-- HAMBURGER -->
    <button id="hamburger" class="hamburger pop onload"
      data-anime-trigger="load"
      data-duration="700"
      data-delay="260"
      aria-label="<?= __('Menu') ?>"
    >
      <svg viewBox="0 0 24 24"><path d="M4 6h16M4 12h16M4 18h16" stroke-linecap="round" stroke-linejoin="round"/></svg>
    </button>

    <nav id="navbar" class="navbar">
      <div class="mobile-head">
        <span class="mobile-title"><?= __('Main Menu') ?></span>
        <button id="closeMenu" class="close-btn pop onload"
          data-anime-trigger="load"
          data-duration="700"
          data-delay="320"
          aria-label="<?= __('Close Menu') ?>"
        >
          <svg viewBox="0 0 24 24"><path d="M18 6L6 18M6 6l12 12" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </button>
      </div>

      <!-- Dynamic Menu dari Menu Manager -->
      <?php
      if (function_exists('menu_render')) {
          echo menu_render($pdo, $navMenuSlug, [
              'menu_class' => 'menu expand-center-safe moving-line onload',
              'submenu_class' => 'submenu',
              'ul_attr' => 'data-anime-trigger="load" data-duration="2500" data-delay="360" data-ml-duration="1000" data-ml-delay="520"',
              'depth' => 0,
          ]);
      }
      ?>

      <!-- CONTROLS (animasi per-item, jangan wrapper) -->
      <div class="controls">
        <?php if ($colorMode === 'both' && $tcShowTheme): ?>
        <select id="themeSelect" class="ctrl-item blur-in onload"
          data-anime-trigger="load"
          data-duration="1700"
          data-delay="760"
        >
          <option value="light"><?= __('Light') ?></option>
          <option value="dark"><?= __('Dark') ?></option>
        </select>
        <?php endif; ?>

        <?php if ($tcShowLang): ?>
        <select id="lang-switch" class="ctrl-item blur-in onload"
          data-anime-trigger="load"
          data-duration="700"
          data-delay="860"
        >
          <option value="id"><?= __('Indonesian') ?></option>
          <option value="en"><?= __('English') ?></option>
        </select>
        <?php endif; ?>

        <?php if ($tcShowSearch): ?>
        <form method="get" action="<?= htmlspecialchars($homeUrl) ?>">
          <input
            type="search"
            name="s"
            class="ctrl-item pop"
            data-anime-trigger="load"
            data-duration="1000"
            data-delay="1000"
            placeholder="<?= __('Search...') ?>"
            value="<?= htmlspecialchars($searchQuery) ?>"
          >
        </form>
        <?php endif; ?>
      </div>
    </nav>

  </div>
</header>
