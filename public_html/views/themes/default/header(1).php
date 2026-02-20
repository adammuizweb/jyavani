<?php
// header.php
$siteTitle = $site['title'] ?? 'Jyavani';
$baseUrl   = rtrim($site['url'] ?? '/', '/');
$homeUrl   = $baseUrl ?: '/';

// Ambil keyword search jika ada
$searchQuery = $_GET['s'] ?? '';
?>
<div id="overlay" class="overlay"></div>

<header
  class="site-header moving-line ml-header fade-down onload"
  data-anim-trigger="load"
  data-anim-duration="900"
  data-anim-delay="40"
  data-ml-duration="980"
  data-ml-delay="180"
>
  <div class="header-inner">

    <!-- BRAND -->
    <a
      href="<?= htmlspecialchars($homeUrl) ?>"
      class="brand fade-right onload"
      data-anim-trigger="load"
      data-anim-duration="900"
      data-anim-delay="120"
    >
      <img
        src="<?= $homeUrl ?>/static/img/jyavani.svg"
        alt="<?= htmlspecialchars($siteTitle) ?>"
        class="zoom-in onload"
        data-anim-trigger="load"
        data-anim-duration="700"
        data-anim-delay="180"
      >

      <!-- Logo letters: wave-like via staggered delays (tanpa wave-span) -->
      <span
        class="jyavani-logo"
        aria-label="Jyavani"
        style="
          --anim-ease: cubic-bezier(.12,.85,.22,1);
          --anim-duration: 900ms;
        "
      >
        <span class="letter accent pop onload" data-anim-trigger="load" data-anim-delay="240" data-word="Just">J</span>
        <span class="letter base   pop onload" data-anim-trigger="load" data-anim-delay="285" data-word="Your">y</span>
        <span class="letter accent pop onload" data-anim-trigger="load" data-anim-delay="330" data-word="Visiting">v</span>
        <span class="letter base   pop onload" data-anim-trigger="load" data-anim-delay="375" data-word="Always">a</span>
        <span class="letter base   pop onload" data-anim-trigger="load" data-anim-delay="420" data-word="Nice">n</span>
        <span class="letter base   pop onload" data-anim-trigger="load" data-anim-delay="465" data-word="Inspire">i</span>
      </span>
    </a>

    <!-- HAMBURGER -->
    <button
      id="hamburger"
      class="hamburger fade-left onload"
      aria-label="Menu"
      data-anim-trigger="load"
      data-anim-duration="800"
      data-anim-delay="260"
    >
      <svg viewBox="0 0 24 24">
        <path d="M4 6h16M4 12h16M4 18h16" stroke-linecap="round" stroke-linejoin="round"/>
      </svg>
    </button>

    <nav id="navbar" class="navbar">

      <div class="mobile-head">
        <span class="mobile-title">Menu Utama</span>
        <button id="closeMenu" class="close-btn" aria-label="Close Menu">
          <svg viewBox="0 0 24 24">
            <path d="M18 6L6 18M6 6l12 12" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
        </button>
      </div>

      <!-- MENU PILL -->
      <ul
        class="menu expand-center-safe onload"
        data-anim-trigger="load"
        data-anim-duration="1600"
        data-anim-delay="360"
      >
        <li class="menu-item fade-down onload" data-anim-trigger="load" data-anim-duration="700" data-anim-delay="520">
          <a href="<?= htmlspecialchars($homeUrl) ?>" class="menu-link" data-key="beranda">Beranda</a>
        </li>

        <li class="menu-item has-child fade-down onload" data-anim-trigger="load" data-anim-duration="700" data-anim-delay="600">
          <div class="mobile-row">
            <a href="#" class="menu-link" data-key="tentang">
              Tentang Kami
              <svg class="arrow-icon" viewBox="0 0 24 24"><path d="M6 9l6 6 6-6" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </a>
            <button class="mobile-toggle-btn" type="button">
              <svg viewBox="0 0 24 24"><path d="M9 18l6-6-6-6" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>
          </div>

          <ul class="submenu">
            <li><a href="#" data-key="sekilas">Sekilas</a></li>
            <li class="has-child">
              <div class="mobile-row">
                <a href="#" class="menu-link" data-key="tim">
                  Tim Kami
                  <svg class="arrow-icon" viewBox="0 0 24 24"><path d="M9 18l6-6-6-6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </a>
                <button class="mobile-toggle-btn" type="button">
                  <svg viewBox="0 0 24 24"><path d="M9 18l6-6-6-6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </button>
              </div>
              <ul class="submenu">
                <li><a href="#" data-key="manajemen">Manajemen</a></li>
                <li><a href="#" data-key="staff">Staff Ahli</a></li>
              </ul>
            </li>
          </ul>
        </li>

        <li class="menu-item has-child fade-down onload" data-anim-trigger="load" data-anim-duration="700" data-anim-delay="680">
          <div class="mobile-row">
            <a href="#" class="menu-link" data-key="layanan">
              Layanan
              <svg class="arrow-icon" viewBox="0 0 24 24"><path d="M6 9l6 6 6-6" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </a>
            <button class="mobile-toggle-btn" type="button">
              <svg viewBox="0 0 24 24"><path d="M9 18l6-6-6-6" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>
          </div>
          <ul class="submenu">
            <li><a href="#" data-key="web">Web Dev</a></li>
            <li><a href="#" data-key="mobile">Mobile App</a></li>
          </ul>
        </li>
      </ul>

      <!-- CONTROLS -->
      <div class="controls">
        <select
          id="themeSelect"
          class="ctrl-item fade-up onload"
          data-anim-trigger="load"
          data-anim-duration="900"
          data-anim-delay="740"
        >
          <option value="light">Terang</option>
          <option value="dark">Gelap</option>
        </select>

        <select
          class="ctrl-item fade-up onload"
          id="lang-switch"
          data-anim-trigger="load"
          data-anim-duration="900"
          data-anim-delay="820"
        >
          <option value="id">Indonesia</option>
          <option value="en">English</option>
        </select>

        <!-- SEARCH FORM (WAJIB FORM + name="s") -->
        <form method="get" action="<?= htmlspecialchars($homeUrl) ?>">
          <input
            type="search"
            name="s"
            class="ctrl-item pop onload"
            data-anim-trigger="load"
            data-anim-duration="900"
            data-anim-delay="900"
            placeholder="Cari..."
            value="<?= htmlspecialchars($searchQuery) ?>"
          >
        </form>
      </div>

    </nav>
  </div>
</header>
