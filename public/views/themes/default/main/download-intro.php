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
  display: none;
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
  <p class="tagline"><?= __('Native PHP CMS without framework. Fast, lightweight, easy to customize.') ?><br><?= __('No need for Composer, Node.js, or build tools.') ?></p>
  <a class="dl-btn" href="/download/latest/">Download v<?= e($version) ?></a>
  <span class="dl-version"><?= __('Latest release — ') ?><?= e($version) ?></span>
</section>

<section class="dl-features">
  <h2><?= __('Features') ?></h2>
  <ul>
    <li><?= svg_ico('circle-check', '', ['style' => 'width:14px;height:14px;vertical-align:middle;margin-right:4px;color:var(--accent)']) ?> <?= __('No framework — pure PHP 8') ?></li>
    <li><?= svg_ico('circle-check', '', ['style' => 'width:14px;height:14px;vertical-align:middle;margin-right:4px;color:var(--accent)']) ?> <?= __('Admin panel outside public root') ?></li>
    <li><?= svg_ico('circle-check', '', ['style' => 'width:14px;height:14px;vertical-align:middle;margin-right:4px;color:var(--accent)']) ?> <?= __('Custom login & register path') ?></li>
    <li><?= svg_ico('circle-check', '', ['style' => 'width:14px;height:14px;vertical-align:middle;margin-right:4px;color:var(--accent)']) ?> <?= __('Theme system with slot rendering') ?></li>
    <li><?= svg_ico('circle-check', '', ['style' => 'width:14px;height:14px;vertical-align:middle;margin-right:4px;color:var(--accent)']) ?> <?= __('Plugin system with uploader') ?></li>
    <li><?= svg_ico('circle-check', '', ['style' => 'width:14px;height:14px;vertical-align:middle;margin-right:4px;color:var(--accent)']) ?> <?= __('Private file & media streaming') ?></li>
    <li><?= svg_ico('circle-check', '', ['style' => 'width:14px;height:14px;vertical-align:middle;margin-right:4px;color:var(--accent)']) ?> <?= __('Shortcode engine (widget, video, PDF)') ?></li>
    <li><?= svg_ico('circle-check', '', ['style' => 'width:14px;height:14px;vertical-align:middle;margin-right:4px;color:var(--accent)']) ?> <?= __('Custom permalink structures') ?></li>
    <li><?= svg_ico('circle-check', '', ['style' => 'width:14px;height:14px;vertical-align:middle;margin-right:4px;color:var(--accent)']) ?> <?= __('Multi-zone sidebar') ?></li>
    <li><?= svg_ico('circle-check', '', ['style' => 'width:14px;height:14px;vertical-align:middle;margin-right:4px;color:var(--accent)']) ?> <?= __('MariaDB / MySQL') ?></li>
  </ul>
</section>

<section class="dl-tech">
  <p><?= __('PHP 8.1+ • MariaDB 10.6+ • Nginx / Apache') ?></p>
  <p><?= __('Developed by Adam Muiz') ?> &middot; Jyavani CMS v<?= e($version) ?></p>
</section>
