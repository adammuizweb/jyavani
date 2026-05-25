<?php
$siteTitle = $site['title'] ?? 'ABC';
$baseUrl   = rtrim($site['url'] ?? '/', '/');
$homeUrl   = $baseUrl ?: '/';
$searchQuery = $_GET['s'] ?? '';
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
    src="<?= $homeUrl ?>/static/img/jyavani.svg"
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
      aria-label="Menu"
    >
      <svg viewBox="0 0 24 24"><path d="M4 6h16M4 12h16M4 18h16" stroke-linecap="round" stroke-linejoin="round"/></svg>
    </button>

    <nav id="navbar" class="navbar">
      <div class="mobile-head">
        <span class="mobile-title">Menu Utama</span>
        <button id="closeMenu" class="close-btn pop onload"
          data-anime-trigger="load"
          data-duration="700"
          data-delay="320"
          aria-label="Close Menu"
        >
          <svg viewBox="0 0 24 24"><path d="M18 6L6 18M6 6l12 12" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </button>
      </div>

      <!-- Dynamic Menu dari Menu Manager -->
      <?php
      if (function_exists('menu_render')) {
          echo menu_render($pdo, 'primary', [
              'menu_class' => 'menu expand-center-safe moving-line onload',
              'submenu_class' => 'submenu',
              'ul_attr' => 'data-anime-trigger="load" data-duration="2500" data-delay="360" data-ml-duration="1000" data-ml-delay="520"',
              'depth' => 0,
          ]);
      }
      ?>

      <!-- CONTROLS (animasi per-item, jangan wrapper) -->
      <div class="controls">
        <select id="themeSelect" class="ctrl-item blur-in onload"
          data-anime-trigger="load"
          data-duration="1700"
          data-delay="760"
        >
          <option value="light">Terang</option>
          <option value="dark">Gelap</option>
        </select>

        <select id="lang-switch" class="ctrl-item blur-in onload"
          data-anime-trigger="load"
          data-duration="700"
          data-delay="860"
        >
          <option value="id">Indonesia</option>
          <option value="en">English</option>
        </select>

        <form method="get" action="<?= htmlspecialchars($homeUrl) ?>">
          <input
            type="search"
            name="s"
            class="ctrl-item pop"
            data-anime-trigger="load"
            data-duration="1000"
            data-delay="1000"
            placeholder="Cari..."
            value="<?= htmlspecialchars($searchQuery) ?>"
          >
        </form>
      </div>
    </nav>

  </div>
</header>
