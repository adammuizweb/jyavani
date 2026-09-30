<?php
declare(strict_types=1);

require_once __DIR__ . '/_home.php';
$categories = default_theme_home_data($pdo)['categories'];
?>
<!-- SECTION 3: Explore Categories -->
<section class="hp-section hp-categories">
    <div class="hp-section__head">
        <h2 class="hp-section__title"><?= __('Jelajahi Kategori') ?></h2>
    </div>
    <?php if (!empty($categories)): ?>
        <div class="hp-categories__grid">
            <?php foreach ($categories as $category):
                $categoryUrl = function_exists('get_category_permalink') && $pdo instanceof PDO
                    ? get_category_permalink($pdo, $category)
                    : '/category/' . rawurlencode((string)($category['slug'] ?? '')) . '/';
                if ((!function_exists('get_category_permalink') || !($pdo instanceof PDO)) && function_exists('localized_path_url')) {
                    $categoryUrl = localized_path_url($categoryUrl);
                }
                $categoryIcon = match ($category['slug'] ?? '') {
                    'panduan' => '<svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>',
                    'keamanan' => '<svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>',
                    'pengembangan' => '<svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M16 18l6-6-6-6M8 6l-6 6 6 6"/></svg>',
                    'sistem' => '<svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>',
                    default => '<svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>',
                };
            ?>
                <a class="hp-categories__card" href="<?= $esc($categoryUrl) ?>">
                    <span class="hp-categories__icon"><?= $categoryIcon ?></span>
                    <span class="hp-categories__name"><?= $esc($category['name'] ?? '') ?></span>
                    <span class="hp-categories__slug"><?= $esc($category['slug'] ?? '') ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="hp-empty-card"><?= __('Belum ada kategori.') ?></div>
    <?php endif; ?>
</section>
