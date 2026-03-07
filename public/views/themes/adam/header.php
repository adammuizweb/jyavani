<?php
// header.php - simple header with site title and nav
$siteTitle = $site['title'] ?? 'Jyavani';
$baseUrl = rtrim($site['url'] ?? '/', '/');
?>
<!-- HEADER UTAMA -->
<header class="adamz-topbar">
  <!-- Brand -->
  <div class="adamz-brand">
      <div class="adamz-logo">ADZ</div>
      <div class="adamz-title">Adamz Code</div>
  </div>

  <!-- Desktop Navigation -->
  <nav class="adamz-nav">
    <ul class="adamz-menu">
      
      <!-- A. HOME -->
      <li class="adamz-item"><a href="#">Home</a></li>

      <!-- B. BLOG -->
      <li class="adamz-item has-submenu">
        <a href="#">Blog</a>
        <ul class="adamz-submenu">
          <li><a href="#">Jurnal Harian</a></li>
          <li><a href="#">Sharing Session</a></li>
          <li><a href="#">Tutorial Singkat</a></li>
        </ul>
      </li>

      <!-- C. PROGRAMMING (Level 1) -->
      <li class="adamz-item has-submenu">
        <a href="#">Programming</a>
        <ul class="adamz-submenu">
          
          <!-- 1). CODING (Level 2) -->
          <li class="has-submenu">
            <a href="#">Coding</a>
            <ul class="adamz-submenu">
              <!-- a). HTML/CSS (Level 3) -->
              <li><a href="#">HTML 5</a></li>
              <li><a href="#">CSS 3</a></li>
              <li><a href="#">JavaScript</a></li>
            </ul>
          </li>

          <!-- 2). PHP -->
          <li><a href="#">PHP Native</a></li>
          
          <!-- Tambahan: Frameworks -->
          <li class="has-submenu">
            <a href="#">Frameworks</a>
            <ul class="adamz-submenu">
                <li><a href="#">Laravel</a></li>
                <li><a href="#">React JS</a></li>
                <li><a href="#">Vue JS</a></li>
            </ul>
          </li>

        </ul>
      </li>

      <!-- D. PHILOSOPHY -->
      <li class="adamz-item has-submenu">
        <a href="#">Philosophy</a>
        <ul class="adamz-submenu">
            <li><a href="#">Stoicism</a></li>
            <li><a href="#">Logic & Critical Thinking</a></li>
            <li><a href="#">Ethics in Tech</a></li>
        </ul>
      </li>

      <!-- E. LAIN-LAIN (Karang Sendiri) -->
      <li class="adamz-item has-submenu">
        <a href="#">Portofolio</a>
        <ul class="adamz-submenu">
            <li><a href="#">Web Projects</a></li>
            <li><a href="#">Mobile Apps</a></li>
            <li><a href="#">UI/UX Design</a></li>
        </ul>
      </li>

      <li class="adamz-item"><a href="#">Contact</a></li>

    </ul>
  </nav>

  <!-- Mobile Burger Button -->
  <div class="adamz-actions">
      <button class="adamz-burger" id="adamz-burger">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="12" x2="21" y2="12"></line><line x1="3" y1="6" x2="21" y2="6"></line><line x1="3" y1="18" x2="21" y2="18"></line></svg>
      </button>
  </div>
</header>

<!-- MOBILE DRAWER (SIDEBAR) -->
<div class="adamz-mobile-drawer" id="adamz-mobile-drawer">
  <div class="adamz-backdrop" id="adamz-backdrop"></div>
  
  <aside class="adamz-panel">
    <header>
      <h3>Adamz Menu</h3>
      <button id="adamz-close" style="background:transparent;border:0;color:#fff;font-size:24px;cursor:pointer">✕</button>
    </header>

    <ul id="mobile-menu-root">

      <!-- A. HOME -->
      <li>
        <a href="#" class="nav-link">Home</a>
      </li>

      <!-- B. BLOG -->
      <li>
        <button class="toggle">Blog</button>
        <ul class="sub">
          <li><a href="#" class="nav-link">Jurnal Harian</a></li>
          <li><a href="#" class="nav-link">Sharing Session</a></li>
          <li><a href="#" class="nav-link">Tutorial Singkat</a></li>
        </ul>
      </li>

      <!-- C. PROGRAMMING -->
      <li>
        <button class="toggle">Programming</button>
        <ul class="sub">
          
          <!-- 1). Coding -->
          <li>
            <button class="toggle">Coding</button>
            <ul class="sub">
              <li><a href="#" class="nav-link">HTML 5</a></li>
              <li><a href="#" class="nav-link">CSS 3</a></li>
              <li><a href="#" class="nav-link">JavaScript</a></li>
            </ul>
          </li>

          <!-- 2). PHP -->
          <li><a href="#" class="nav-link">PHP Native</a></li>

           <!-- Tambahan Frameworks -->
           <li>
            <button class="toggle">Frameworks</button>
            <ul class="sub">
              <li><a href="#" class="nav-link">Laravel</a></li>
              <li><a href="#" class="nav-link">React JS</a></li>
            </ul>
          </li>
        </ul>
      </li>

      <!-- D. PHILOSOPHY -->
      <li>
        <button class="toggle">Philosophy</button>
        <ul class="sub">
            <li><a href="#" class="nav-link">Stoicism</a></li>
            <li><a href="#" class="nav-link">Logic</a></li>
        </ul>
      </li>

      <!-- E. PORTOFOLIO -->
      <li>
        <button class="toggle">Portofolio</button>
        <ul class="sub">
            <li><a href="#" class="nav-link">Web Projects</a></li>
            <li><a href="#" class="nav-link">Mobile Apps</a></li>
        </ul>
      </li>

      <li><a href="#" class="nav-link">Contact</a></li>

    </ul>
  </aside>
</div>