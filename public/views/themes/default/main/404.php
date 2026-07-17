<?php
// /views/themes/default/main/404.php
?>
<section class="error-page">
  <h1 class="error-title"><?= __('404 — Page Not Found') ?></h1>
  <p class="error-message"><?= __('Sorry, the page you are looking for is not available or has been moved.') ?></p>
  <a href="/" class="error-link"><?= __('Back to Home') ?></a>
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