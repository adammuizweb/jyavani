<?php
// header.php
$siteTitle = $site['title'] ?? 'Jyavani';
$baseUrl   = rtrim($site['url'] ?? '/', '/');
$homeUrl   = $baseUrl ?: '/';

// Ambil keyword search jika ada
$searchQuery = $_GET['s'] ?? '';
?>
<div id="overlay" class="overlay"></div>

<header class="site-header">
  <div class="header-inner">
    <!-- BRAND -->
    <a href="<?= htmlspecialchars($homeUrl) ?>" class="brand">
      <img src="<?= $homeUrl ?>/static/img/jyavani.svg" alt="<?= htmlspecialchars($siteTitle) ?>">
      <span>UniHeader</span>
    </a>

    <button id="hamburger" class="hamburger" aria-label="Menu">
      <svg viewBox="0 0 24 24"><path d="M4 6h16M4 12h16M4 18h16" stroke-linecap="round" stroke-linejoin="round"/></svg>
    </button>

    <nav id="navbar" class="navbar">
      
      <div class="mobile-head"> 
        <span class="mobile-title">Menu Utama</span>
        <button id="closeMenu" class="close-btn" aria-label="Close Menu">
          <svg viewBox="0 0 24 24"><path d="M18 6L6 18M6 6l12 12" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </button>
      </div>

      <ul class="menu">
        <li class="menu-item">
          <a href="<?= htmlspecialchars($homeUrl) ?>" class="menu-link" data-key="beranda">
            Beranda
          </a>
        </li>

        <li class="menu-item has-child">
          <div class="mobile-row">
            <a href="#" class="menu-link" data-key="tentang">
              Tentang Kami 
              <svg class="arrow-icon" viewBox="0 0 24 24"><path d="M6 9l6 6 6-6" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </a>
            <button class="mobile-toggle-btn">
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
                 <button class="mobile-toggle-btn">
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

        <li class="menu-item has-child">
          <div class="mobile-row">
            <a href="#" class="menu-link" data-key="layanan">
              Layanan 
              <svg class="arrow-icon" viewBox="0 0 24 24"><path d="M6 9l6 6 6-6" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </a>
            <button class="mobile-toggle-btn">
               <svg viewBox="0 0 24 24"><path d="M9 18l6-6-6-6" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>
          </div>
          <ul class="submenu">
            <li><a href="#" data-key="web">Web Dev</a></li>
            <li><a href="#" data-key="mobile">Mobile App</a></li>
          </ul>
        </li>
      </ul>

      <div class="controls">
        <select id="themeSelect" class="ctrl-item">
          <option value="system">Tema: Auto</option>
          <option value="light">Terang</option>
          <option value="dark">Gelap</option>
        </select>
        <select class="ctrl-item" id="lang-switch"><option value="id">Indonesia</option><option value="en">English</option></select>
        
                <!-- SEARCH FORM (WAJIB FORM + name="s") -->
        <form method="get" action="<?= htmlspecialchars($homeUrl) ?>">
          <input
            type="search"
            name="s"
            class="ctrl-item"
            placeholder="Cari..."
            value="<?= htmlspecialchars($searchQuery) ?>"
          >
        </form>
        
      </div>
    </nav>
  </div>
</header>
