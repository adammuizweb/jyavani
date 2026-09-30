<?php
declare(strict_types=1);

require_once __DIR__ . '/_home.php';
$home = default_theme_home_data($pdo);
$panduanPosts = $home['panduan_posts'];
$mainPost = $home['main_post'];
$sidePosts = $home['side_posts'];
?>
<!-- SECTION 1: Bento Panduan -->
<section class="hp-section hp-bento">
    <div class="hp-section__head">
        <h2 class="hp-section__title"><?= __('Dari Kategori Panduan') ?></h2>
        <a class="hp-section__more" href="<?= $esc(function_exists('localized_path_url') ? localized_path_url('/category/panduan/') : '/category/panduan/') ?>"><?= __('Lihat semua →') ?></a>
    </div>
    <?php if (!empty($panduanPosts)): ?>
        <div class="hp-bento__grid">
            <?php if ($mainPost): ?>
                <article class="hp-bento__main">
                    <a class="hp-bento__main-link" href="<?= $esc(default_theme_home_post_url($mainPost)) ?>" aria-label="<?= $esc($mainPost['title'] ?? '') ?>">
                        <div class="hp-bento__media">
                            <?php $thumb = default_theme_home_post_thumb($mainPost); if ($thumb !== ''): ?>
                                <img src="<?= $esc($thumb) ?>" alt="" loading="lazy" decoding="async">
                            <?php else: ?>
                                <div class="hp-bento__placeholder"></div>
                            <?php endif; ?>
                        </div>
                        <div class="hp-bento__main-body">
                            <span class="hp-bento__eyebrow"><?= __('Featured') ?></span>
                            <h3 class="hp-bento__main-title"><?= $esc($mainPost['title'] ?? '') ?></h3>
                            <p class="hp-bento__main-excerpt"><?= $esc(default_theme_home_excerpt((string)($mainPost['content'] ?? ''), 160)) ?></p>
                            <time class="hp-bento__date" datetime="<?= $esc($mainPost['created_at'] ?? '') ?>"><?= $esc(default_theme_home_post_date($mainPost)) ?></time>
                        </div>
                    </a>
                </article>
            <?php endif; ?>

            <div class="hp-bento__side">
                <?php foreach ($sidePosts as $post): ?>
                    <article class="hp-bento__side-item">
                        <a class="hp-bento__side-link" href="<?= $esc(default_theme_home_post_url($post)) ?>" aria-label="<?= $esc($post['title'] ?? '') ?>">
                            <div class="hp-bento__side-media">
                                <?php $thumb = default_theme_home_post_thumb($post); if ($thumb !== ''): ?>
                                    <img src="<?= $esc($thumb) ?>" alt="" loading="lazy" decoding="async">
                                <?php else: ?>
                                    <div class="hp-bento__placeholder"></div>
                                <?php endif; ?>
                            </div>
                            <div class="hp-bento__side-body">
                                <h4 class="hp-bento__side-title"><?= $esc($post['title'] ?? '') ?></h4>
                                <time class="hp-bento__date" datetime="<?= $esc($post['created_at'] ?? '') ?>"><?= $esc(default_theme_home_post_date($post)) ?></time>
                            </div>
                        </a>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>
    <?php else: ?>
        <div class="hp-empty-card"><?= __('Belum ada artikel di kategori Panduan.') ?></div>
    <?php endif; ?>
</section>
