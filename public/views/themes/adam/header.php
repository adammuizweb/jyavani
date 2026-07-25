<?php
$siteTitle = $site['title'] ?? 'Adamz Code';
$baseUrl   = rtrim($site['url'] ?? '/', '/');
$homeUrl   = $baseUrl ?: '/';
$searchQuery = $_GET['s'] ?? '';
$colorMode = function_exists('get_theme_color_mode') ? get_theme_color_mode() : 'both';

$tcLogo = function_exists('theme_mod') ? (string)theme_mod('logo', '') : '';
$tcMenu = function_exists('theme_mod') ? (string)theme_mod('nav_menu', '') : '';
$tcShowSearch = !function_exists('theme_mod') || theme_mod('show_search', true);
$tcShowLang   = !function_exists('theme_mod') || theme_mod('show_lang', true);
$tcShowTheme  = !function_exists('theme_mod') || theme_mod('show_theme', true);
$navMenuSlug = $tcMenu !== '' ? $tcMenu : 'primary';

$hasLogoZone = function_exists('theme_zone_has_position') && theme_zone_has_position($pdo, 'header', 'logo');
$hasNavZone  = function_exists('theme_zone_has_position') && theme_zone_has_position($pdo, 'header', 'nav');
$hasControlsZone = function_exists('theme_zone_has_position') && theme_zone_has_position($pdo, 'header', 'controls');
?>
<div id="overlay" class="overlay"></div>

<header class="site-header moving-line ml-header onload"
  data-anim-trigger="load"
  data-ml-duration="980"
  data-ml-delay="240"
>
  <div class="header-inner">

    <!-- BRAND -->
    <?php if ($hasLogoZone): ?>
      <div class="brand onload">
        <?= theme_zone_render_position($pdo, 'header', 'logo') ?>
      </div>
    <?php else: ?>
      <a href="<?= htmlspecialchars($homeUrl) ?>" class="brand onload wave-span"
         data-anim-trigger="load"
         data-wave-target=".adamz-logo"
         data-wave-step="28">

        <?php if ($tcLogo !== ''): ?>
          <img src="<?= htmlspecialchars($tcLogo, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($siteTitle, ENT_QUOTES, 'UTF-8') ?>" class="adamz-logo-custom" style="max-height:44px; width:auto;">
        <?php else: ?>
          <span class="adamz-logo-mark" aria-hidden="true">ADZ</span>
          <span class="adamz-logo" aria-label="<?= htmlspecialchars($siteTitle, ENT_QUOTES, 'UTF-8') ?>">
            <span class="letter accent" data-word="Code">A</span>
            <span class="letter base" data-word="Think">d</span>
            <span class="letter accent" data-word="Explore">a</span>
            <span class="letter base" data-word="Build">m</span>
            <span class="letter base" data-word="Share">z</span>
          </span>
        <?php endif; ?>
      </a>
    <?php endif; ?>

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
      <?php if ($hasNavZone): ?>
        <div class="nav-zone">
          <?= theme_zone_render_position($pdo, 'header', 'nav') ?>
        </div>
      <?php else: ?>
        <?php if (function_exists('menu_render')): ?>
          <?= menu_render($pdo, $navMenuSlug, [
              'menu_class' => 'menu expand-center-safe moving-line onload',
              'submenu_class' => 'submenu',
              'ul_attr' => 'data-anime-trigger="load" data-duration="2500" data-delay="360" data-ml-duration="1000" data-ml-delay="520"',
              'depth' => 0,
          ]) ?>
        <?php endif; ?>
      <?php endif; ?>

      <!-- CONTROLS -->
      <?php if ($hasControlsZone): ?>
        <div class="controls">
          <?= theme_zone_render_position($pdo, 'header', 'controls') ?>
        </div>
      <?php else: ?>
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
      <?php endif; ?>
    </nav>

  </div>
</header>
