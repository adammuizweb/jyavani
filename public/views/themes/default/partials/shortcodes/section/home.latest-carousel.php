<?php
declare(strict_types=1);

require_once __DIR__ . '/_home.php';
$carouselPosts = default_theme_home_data($pdo)['carousel_posts'];
?>
<!-- SECTION 2: Infinite Carousel -->
<section class="hp-section hp-carousel">
    <div class="hp-section__head">
        <h2 class="hp-section__title"><?= __('Terbaru dari Kami') ?></h2>
        <a class="hp-section__more" href="<?= $esc(function_exists('localized_path_url') ? localized_path_url('/artikel/') : '/artikel/') ?>"><?= __('Lihat semua →') ?></a>
    </div>
    <?php if (!empty($carouselPosts)): ?>
        <div class="hp-carousel__swiper swiper">
            <div class="swiper-wrapper">
                <?php foreach ($carouselPosts as $post): ?>
                    <article class="swiper-slide hp-carousel__slide">
                        <a class="hp-carousel__card" href="<?= $esc(default_theme_home_post_url($post)) ?>" aria-label="<?= $esc($post['title'] ?? '') ?>">
                            <div class="hp-carousel__media">
                                <?php $thumb = default_theme_home_post_thumb($post); if ($thumb !== ''): ?>
                                    <img src="<?= $esc($thumb) ?>" alt="" loading="lazy" decoding="async">
                                <?php else: ?>
                                    <div class="hp-carousel__placeholder"></div>
                                <?php endif; ?>
                            </div>
                            <div class="hp-carousel__body">
                                <h3 class="hp-carousel__title"><?= $esc($post['title'] ?? '') ?></h3>
                                <time class="hp-carousel__date" datetime="<?= $esc($post['created_at'] ?? '') ?>"><?= $esc(default_theme_home_post_date($post)) ?></time>
                            </div>
                        </a>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>
    <?php else: ?>
        <div class="hp-empty-card"><?= __('Belum ada artikel terbaru.') ?></div>
    <?php endif; ?>
</section>

<?php if (($context['defer_carousel_script'] ?? false) !== true): ?>
<?= default_theme_home_carousel_script() ?>
<?php endif; ?>
