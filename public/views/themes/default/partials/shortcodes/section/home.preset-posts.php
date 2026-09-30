<?php
declare(strict_types=1);

if (!$pdo instanceof PDO || !function_exists('render_shortcode_preset')) return;

$preset = is_string($attrs['preset'] ?? null) ? strtolower(trim($attrs['preset'])) : '';
$presetIsValid = function_exists('shortcode_preset_slug_is_valid')
    ? shortcode_preset_slug_is_valid($preset)
    : ($preset !== '' && preg_match('/\A[a-z0-9_-]+\z/', $preset) === 1);
if (!$presetIsValid) return;

$presetHtml = render_shortcode_preset($pdo, $preset, [], array_merge($context, [
    'surface' => 'theme_section.home.preset-posts',
    'section' => $section,
]));
if (trim($presetHtml) === '') return;

$title = trim((string)($attrs['title'] ?? ''));
$summary = trim((string)($attrs['summary'] ?? ''));
?>
<section class="hp-section hp-preset-posts" data-theme-section="<?= $esc($section) ?>" data-preset="<?= $esc($preset) ?>">
  <?php if ($title !== '' || $summary !== ''): ?>
    <header class="hp-section__head hp-preset-posts__head">
      <div>
        <?php if ($title !== ''): ?>
          <h2 class="hp-section__title"><?= $esc($title) ?></h2>
        <?php endif; ?>
        <?php if ($summary !== ''): ?>
          <p class="hp-preset-posts__summary"><?= $esc($summary) ?></p>
        <?php endif; ?>
      </div>
    </header>
  <?php endif; ?>
  <div class="hp-preset-posts__collection">
    <?= $presetHtml ?>
  </div>
</section>
