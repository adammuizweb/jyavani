<?php
// /views/themes/default/main/404.php
?>
<?php if (function_exists('theme_zone_has_position') && theme_zone_has_position($pdo, 'main.404', 'before')): ?>
  <div class="tz-404-before"><?= theme_zone_render_position($pdo, 'main.404', 'before') ?></div>
<?php endif; ?>

<section class="error-page">
  <h1 class="error-title"><?= __('404 — Page Not Found') ?></h1>
  <p class="error-message"><?= __('Sorry, the page you are looking for is not available or has been moved.') ?></p>
  <a href="<?= htmlspecialchars(function_exists('localized_home_url') ? localized_home_url() : '/', ENT_QUOTES, 'UTF-8') ?>" class="error-link"><?= __('Back to Home') ?></a>
</section>

<?php if (function_exists('theme_zone_has_position') && theme_zone_has_position($pdo, 'main.404', 'after')): ?>
  <div class="tz-404-after"><?= theme_zone_render_position($pdo, 'main.404', 'after') ?></div>
<?php endif; ?>

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
