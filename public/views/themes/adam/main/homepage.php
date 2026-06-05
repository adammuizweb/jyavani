<?php
// hero.php - optional hero; show on home when available
$title = $title ?? ($site['title'] ?? 'Welcome');
$subtitle = $subtitle ?? '';
?>
<!-- MAIN LANDING PAGE CONTENT -->
<main class="adamz-main">
  
  <!-- Hero Section -->
  <section class="adamz-hero">
     <h1 class="adamz-hero-title">Beyond The Code</h1>
     <p class="adamz-hero-subtitle">
       Menjelajahi dunia pemrograman, menggali kebijaksanaan filosofi, dan berbagi perjalanan dalam setiap baris kode.
     </p>
     <a href="#" class="adamz-btn">Mulai Belajar</a>
  </section>

  <!-- Categories Section -->
  <section class="adamz-section">
     <h2 class="adamz-section-title">Jelajahi <span>Topik</span></h2>
     <div class="adamz-grid">
        
        <!-- Card 1: Programming -->
        <article class="adamz-card">
           <div class="adamz-card-header code">
             <span>&lt;/&gt;</span>
           </div>
           <div class="adamz-card-body">
              <h3 class="adamz-card-title">Programming</h3>
              <p class="adamz-card-text">
                Kumpulan tutorial dan best practice mulai dari HTML, CSS, PHP, hingga Framework modern. Bangun karir digitalmu.
              </p>
              <a href="#" class="adamz-card-link">Lihat Tutorial &rarr;</a>
           </div>
        </article>

        <!-- Card 2: Blog -->
        <article class="adamz-card">
           <div class="adamz-card-header blog">
             <span>&#9998;</span>
           </div>
           <div class="adamz-card-body">
              <h3 class="adamz-card-title">Jurnal Harian</h3>
              <p class="adamz-card-text">
                Catatan personal, sharing session, dan pengalaman di dunia industri teknologi yang terus berkembang.
              </p>
              <a href="#" class="adamz-card-link">Baca Jurnal &rarr;</a>
           </div>
        </article>

        <!-- Card 3: Philosophy -->
        <article class="adamz-card">
           <div class="adamz-card-header philo">
             <span>&#129504;</span>
           </div>
           <div class="adamz-card-body">
              <h3 class="adamz-card-title">Philosophy</h3>
              <p class="adamz-card-text">
                Memahami logika, etika, dan stoikisme sebagai fondasi mental yang kuat bagi seorang developer.
              </p>
              <a href="#" class="adamz-card-link">Pelajari Filosofi &rarr;</a>
           </div>
        </article>

     </div>
  </section>

  <!-- About / Intro Section -->
  <section class="adamz-bg-light">
    <div class="adamz-about-wrap">
       <h2 class="adamz-section-title" style="margin-bottom:20px; font-size:1.8rem">Tentang <span>Adamz Code</span></h2>
       <p style="color:#64748b; line-height:1.8; font-size:1.1rem; max-width:800px; margin:0 auto;">
         Adamz Code bukan sekadar repositori kode. Ini adalah ruang di mana logika bertemu estetika. Kami percaya bahwa programmer yang hebat tidak hanya menulis kode yang efisien, tetapi juga memiliki pola pikir yang jernih dan terstruktur. Mari bergabung dalam komunitas pembelajaran tanpa batas.
       </p>
    </div>
  </section>

</main>