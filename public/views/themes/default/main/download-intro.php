<?php
// main/download-intro.php
// Expect: $pdo, $version (string)
?>
<style>
.dl-hero {
  text-align: center;
  padding: 4rem 1rem 2rem;
}
.dl-hero h1 {
  font-size: 2.8rem;
  font-weight: 700;
  margin-bottom: .5rem;
}
.dl-hero .tagline {
  font-size: 1.15rem;
  color: var(--muted, #666);
  max-width: 560px;
  margin: 0 auto 2rem;
}
.dl-btn {
  display: inline-block;
  padding: .85rem 2.5rem;
  background: var(--accent, #2563eb);
  color: #fff;
  font-weight: 600;
  border-radius: 8px;
  text-decoration: none;
  font-size: 1.1rem;
  transition: background .2s;
}
.dl-btn:hover {
  background: var(--link-hover, #1d4ed8);
}
.dl-version {
  display: block;
  margin-top: .6rem;
  font-size: .85rem;
  color: var(--muted, #999);
}
.dl-features {
  max-width: 720px;
  margin: 3rem auto;
  padding: 0 1rem;
}
.dl-features h2 {
  text-align: center;
  margin-bottom: 1.5rem;
}
.dl-features ul {
  list-style: none;
  padding: 0;
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: .75rem;
}
.dl-features li {
  padding: .75rem 1rem;
  background: var(--card-bg, #f5f5f5);
  border-radius: 8px;
  font-size: .95rem;
}
.dl-features li::before {
  content: "✓ ";
  color: var(--accent, #2563eb);
  font-weight: 700;
}
.dl-tech {
  text-align: center;
  padding: 2rem 1rem 3rem;
  font-size: .9rem;
  color: var(--muted, #999);
}
@media (max-width: 600px) {
  .dl-hero h1 { font-size: 2rem; }
  .dl-features ul { grid-template-columns: 1fr; }
}
</style>

<section class="dl-hero">
  <h1>Jyavani CMS</h1>
  <p class="tagline">CMS native PHP tanpa framework. Cepat, ringan, mudah dikustomisasi. <br>Tidak perlu Composer, Node.js, atau build tools.</p>
  <a class="dl-btn" href="/download/latest/">Download v<?= e($version) ?></a>
  <span class="dl-version">Rilis terbaru — <?= e($version) ?></span>
</section>

<section class="dl-features">
  <h2>Fitur</h2>
  <ul>
    <li>Tanpa framework — pure PHP 8</li>
    <li>Admin panel di luar public root</li>
    <li>Login & register path kustom</li>
    <li>Theme system dengan slot rendering</li>
    <li>Plugin system dengan uploader</li>
    <li>Private file & media streaming</li>
    <li>Shortcode engine (widget, video, PDF)</li>
    <li>Custom permalink structures</li>
    <li>Multi-zone sidebar</li>
    <li>MariaDB / MySQL</li>
  </ul>
</section>

<section class="dl-tech">
  <p>PHP 8.1+ &bull; MariaDB 10.6+ &bull; Nginx / Apache</p>
  <p>Dikembangkan oleh Adam Muiz &middot; Jyavani CMS v<?= e($version) ?></p>
</section>
