<?php
declare(strict_types=1);

if (!$pdo instanceof PDO || !function_exists('render_shortcode_preset')) return;

$preset = is_string($attrs['preset'] ?? null) ? strtolower(trim($attrs['preset'])) : '';
$presetIsValid = function_exists('shortcode_preset_slug_is_valid')
    ? shortcode_preset_slug_is_valid($preset)
    : ($preset !== '' && preg_match('/\A[a-z0-9_-]+\z/', $preset) === 1);
if (!$presetIsValid) return;

$presetHtml = render_shortcode_preset($pdo, $preset, [
    'layout' => 'grid',
    'limit' => 4,
    'max_items' => 20,
    'pagination' => '1',
], array_merge($context, [
    'surface' => 'theme_section.home.random-posts',
    'section' => $section,
]));
if (trim($presetHtml) === '') return;

$title = trim((string)($attrs['title'] ?? ''));
$summary = trim((string)($attrs['summary'] ?? ''));
$refreshLabel = trim((string)($attrs['refresh_label'] ?? ''));
$requestPath = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
if (!is_string($requestPath)
    || !str_starts_with($requestPath, '/')
    || str_starts_with($requestPath, '//')
    || str_contains($requestPath, '\\')
    || preg_match('/[\x00-\x1F\x7F]/', $requestPath) === 1) {
    $requestPath = '/';
}
$paginationMatch = [];
$paginationKey = preg_match('/data-pcat-pagination-root="(pcat_page_[a-z0-9_-]+)"/', $presetHtml, $paginationMatch) === 1
    ? (string)$paginationMatch[1]
    : '';
$seedKey = function_exists('post_cat__pagination_seed_key')
    ? post_cat__pagination_seed_key($paginationKey)
    : 'pcat_seed_' . $preset;
$preservedQuery = [];
foreach ($_GET as $name => $value) {
    if (!is_string($name) || (!is_string($value) && !is_int($value))
        || $name === $paginationKey || $name === $seedKey || $name === 'random_posts'
        || preg_match('/\A[a-zA-Z0-9_-]{1,100}\z/', $name) !== 1) continue;
    $value = (string)$value;
    if (strlen($value) <= 500 && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1) {
        $preservedQuery[$name] = $value;
    }
}
?>
<section id="random-posts" class="hp-section hp-preset-posts hp-random-posts" data-theme-section="<?= $esc($section) ?>" data-preset="<?= $esc($preset) ?>">
  <?php if ($title !== '' || $summary !== '' || $refreshLabel !== ''): ?>
    <header class="hp-section__head hp-preset-posts__head hp-random-posts__head">
      <div>
        <?php if ($title !== ''): ?>
          <h2 class="hp-section__title"><?= $esc($title) ?></h2>
        <?php endif; ?>
        <?php if ($summary !== ''): ?>
          <p class="hp-preset-posts__summary"><?= $esc($summary) ?></p>
        <?php endif; ?>
      </div>
      <?php if ($refreshLabel !== ''): ?>
        <form class="hp-random-posts__refresh" method="get" action="<?= $esc($requestPath) ?>#random-posts">
          <?php foreach ($preservedQuery as $name => $value): ?>
            <input type="hidden" name="<?= $esc($name) ?>" value="<?= $esc($value) ?>">
          <?php endforeach; ?>
          <input type="hidden" name="random_posts" value="1">
          <button type="submit"><?= $esc($refreshLabel) ?></button>
        </form>
      <?php endif; ?>
    </header>
  <?php endif; ?>
  <div class="hp-preset-posts__collection hp-random-posts__collection">
    <?= $presetHtml ?>
  </div>
</section>
