<?php
// /adiwira/frontend_404.php
if (!defined('ADIWIRA_BOOTSTRAPPED')) {
require_once __DIR__ . '/bootstrap_core.php';
require_once __DIR__ . '/bootstrap_theme.php';
}

http_response_code(404);
$page_title = '404 — Tidak ditemukan';
$content_html = '
<section class="error-page">
  <div class="container">
    <h1 class="error-title">404 — Halaman Tidak Ditemukan</h1>
    <p class="error-message">Maaf, halaman yang kamu cari tidak tersedia atau telah dipindahkan.</p>
    <a href="/" class="error-link">Kembali ke Beranda</a>
  </div>
</section>
<style>
.error-page {
  text-align: center;
  padding: 80px 20px;
}
.error-title {
  font-size: 2.5em;
  color: #d9534f;
}
.error-message {
  font-size: 1.2em;
  margin: 20px 0;
}
.error-link {
  display: inline-block;
  margin-top: 30px;
  padding: 10px 20px;
  background-color: #0275d8;
  color: #fff;
  text-decoration: none;
  border-radius: 5px;
}
</style>
';

require __DIR__ . '/layout.php';
exit;
