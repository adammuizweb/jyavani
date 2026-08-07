<?php
// /adiwira/theme/adam/partials/pages/list.php
// Expects:
//   $pages   => array of page rows (id, title, slug, created_at, ...)
//   $page    => current page (optional)
//   $total   => total items (optional)
//   $perPage => items per page (optional)

if (!isset($pages) || !is_array($pages)) return;

$page = max(1, (int)($page ?? 1));
$total = isset($total) ? (int)$total : count($pages);
$perPage = isset($perPage) ? (int)$perPage : 50;
$pagesCount = max(1, (int)ceil($total / max(1, $perPage)));
?>
<section class="adam-pages-list">
  <header class="adam-pages-list__header" aria-labelledby="adam-pages-title">
    <h1 id="adam-pages-title" class="adam-pages-list__title"><?= __('Pages') ?></h1>

    <?php if ($total): ?>
      <div class="adam-pages-list__count" aria-live="polite">
        <?= sprintf(__('%s pages registered'), htmlspecialchars(number_format($total), ENT_QUOTES, 'UTF-8')) ?>
      </div>
    <?php endif; ?>
  </header>

  <?php if (function_exists('theme_zone_has_position') && theme_zone_has_position($pdo, 'list.page', 'before_loop')): ?>
    <div class="tz-list-page-before"><?= theme_zone_render_position($pdo, 'list.page', 'before_loop') ?></div>
  <?php endif; ?>

  <?php if (empty($pages)): ?>
    <div class="adam-empty-card" role="status">
      <?= __('No pages.') ?>
    </div>
  <?php else: ?>
    <ul class="adam-pages-list__grid" aria-live="polite">
      <?php foreach ($pages as $p):
         $title = htmlspecialchars($p['title'] ?? __('Untitled'), ENT_QUOTES, 'UTF-8');
         $slug  = function_exists('get_page_permalink') ? get_page_permalink($p) : '/' . rawurlencode($p['slug'] ?? '') . '/';
         $date  = isset($p['created_at']) && $p['created_at']
             ? htmlspecialchars(date('Y-m-d', strtotime($p['created_at'])), ENT_QUOTES, 'UTF-8')
             : '';
         $dateLabel = $date ? htmlspecialchars(date('j F Y', strtotime($p['created_at'])), ENT_QUOTES, 'UTF-8') : '';
      ?>
        <li class="adam-pages-list__item">
          <div class="adam-pages-list__item-main">
            <a href="<?= htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') ?>" class="adam-page-link" title="<?= $title ?>">
              <?= $title ?>
            </a>

            <?php if ($dateLabel): ?>
              <div class="adam-pages-list__meta">
                <time datetime="<?= $date ?>" class="adam-pages-list__date"><?= __('Created') ?> <?= $dateLabel ?></time>
              </div>
            <?php endif; ?>
          </div>

          <div class="adam-pages-list__item-actions">
            <a href="<?= htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') ?>" class="adam-btn-open" aria-label="<?= sprintf(__('Open %s'), $title) ?>"><?= __('Open →') ?></a>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>

    <?php if ($pagesCount > 1): ?>
      <nav class="adam-pagination" role="navigation" aria-label="<?= __('Pagination') ?>">
        <div class="adam-pagination__inner">
          <?php if ($page > 1): ?>
            <a class="adam-pagination__link" href="<?= htmlspecialchars(collection_paginated_url($base, $page - 1, (string)($q ?? '')), ENT_QUOTES, 'UTF-8') ?>" rel="prev"><?= __('← Previous') ?></a>
          <?php endif; ?>

          <span class="adam-pagination__info"><?= sprintf(__('Page %d of %d'), $page, $pagesCount) ?></span>

          <?php if ($page < $pagesCount): ?>
            <a class="adam-pagination__link" href="<?= htmlspecialchars(collection_paginated_url($base, $page + 1, (string)($q ?? '')), ENT_QUOTES, 'UTF-8') ?>" rel="next"><?= __('Next →') ?></a>
          <?php endif; ?>
        </div>
      </nav>
    <?php endif; ?>

    <?php if (function_exists('theme_zone_has_position') && theme_zone_has_position($pdo, 'list.page', 'after_loop')): ?>
      <div class="tz-list-page-after"><?= theme_zone_render_position($pdo, 'list.page', 'after_loop') ?></div>
    <?php endif; ?>

  <?php endif; ?>
</section>

<style>
.adam-btn-open {
  display: inline-block;
  padding: .55rem 1.1rem;
  border-radius: 9999px;
  background: linear-gradient(90deg, #0066ff, #00c6ff);
  color: #fff;
  font-weight: 600;
  font-size: .95rem;
  text-decoration: none;
  box-shadow: 0 0 12px rgba(0, 102, 255, 0.3);
  transition: all .3s ease;
}
.adam-btn-open:hover {
  background: linear-gradient(90deg, #00c6ff, #0066ff);
  transform: translateY(-2px) scale(1.03);
  box-shadow: 0 0 18px rgba(0, 174, 255, 0.6);
}
.adam-btn-open:active {
  transform: scale(.98);
}
.adam-pages-list li:hover {
  box-shadow:0 10px 28px rgba(0,0,0,.08);
  transform:translateY(-2px);
}
@media (max-width:720px){
  .adam-pages-list li {
    flex-direction:column;
    align-items:flex-start;
  }
  .adam-pages-list li a:last-child {
    width:100%;
    text-align:center;
  }
}
</style>
