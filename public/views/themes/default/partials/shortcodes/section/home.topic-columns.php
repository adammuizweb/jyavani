<?php
declare(strict_types=1);

require_once __DIR__ . '/_home.php';
$home = default_theme_home_data($pdo);
$columns = [
    ['title' => __('Keamanan'), 'slug' => 'keamanan', 'posts' => $home['keamanan_posts']],
    ['title' => __('Pengembangan'), 'slug' => 'pengembangan', 'posts' => $home['pengembangan_posts']],
    ['title' => __('Sistem'), 'slug' => 'sistem', 'posts' => $home['sistem_posts']],
];
?>
<!-- SECTION 5: Multi-category columns -->
<section class="hp-section hp-multicol">
    <div class="hp-section__head">
        <h2 class="hp-section__title"><?= __('Temukan Topik Favoritmu') ?></h2>
    </div>
    <div class="hp-multicol__grid">
        <?php foreach ($columns as $column):
            $columnUrl = '/category/' . rawurlencode($column['slug']) . '/';
            if (function_exists('localized_path_url')) $columnUrl = localized_path_url($columnUrl);
        ?>
            <div class="hp-multicol__col">
                <div class="hp-multicol__header">
                    <h3 class="hp-multicol__title"><?= $esc($column['title']) ?></h3>
                    <a class="hp-multicol__more" href="<?= $esc($columnUrl) ?>"><?= __('Lihat semua') ?></a>
                </div>
                <?php if (!empty($column['posts'])): ?>
                    <ul class="hp-multicol__list">
                        <?php foreach ($column['posts'] as $post): ?>
                            <li class="hp-multicol__item">
                                <a href="<?= $esc(default_theme_home_post_url($post)) ?>" class="hp-multicol__link">
                                    <?php $thumb = default_theme_home_post_thumb($post); if ($thumb !== ''): ?>
                                        <span class="hp-multicol__thumb"><img src="<?= $esc($thumb) ?>" alt="" loading="lazy" decoding="async"></span>
                                    <?php endif; ?>
                                    <span class="hp-multicol__body">
                                        <span class="hp-multicol__item-title"><?= $esc($post['title'] ?? '') ?></span>
                                        <time class="hp-multicol__date"><?= $esc(default_theme_home_post_date($post)) ?></time>
                                    </span>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <div class="hp-empty-card hp-empty-card--sm"><?= __('Belum ada artikel.') ?></div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</section>
