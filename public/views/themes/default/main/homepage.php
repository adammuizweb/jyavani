<?php
// main/homepage.php — Rich dynamic landing page for Jyavani CMS.
// Works with or without demo content installed.

declare(strict_types=1);

$pdo = ($pdo ?? null) instanceof PDO ? $pdo : ($GLOBALS['pdo'] ?? null);

$siteTitle = function_exists('settings_get') ? (string)settings_get($pdo, 'site_title', 'Jyavani CMS') : 'Jyavani CMS';
$siteDesc  = function_exists('settings_get') ? (string)settings_get($pdo, 'site_description', '') : '';
$adminUrl  = function_exists('get_admin_path') && $pdo instanceof PDO ? '/' . trim(get_admin_path($pdo), '/') . '/' : '/dashboard/';

$categories = [];
$panduanPosts = [];
$latestPosts  = [];
$keamananPosts = [];
$pengembanganPosts = [];
$sistemPosts = [];

if ($pdo instanceof PDO) {
    if (function_exists('widget_fetch_categories')) {
        $categories = widget_fetch_categories($pdo, 50, true);
    }
    if (function_exists('cms_posts_by_category')) {
        $panduanPosts = cms_posts_by_category($pdo, 'panduan', [
            'type' => 'article', 'status' => 'published',
            'limit' => 5, 'order_by' => 'created_at', 'order_dir' => 'DESC',
        ]);
        $latestPosts = cms_posts_by_category($pdo, '', [
            'type' => 'article', 'status' => 'published',
            'limit' => 8, 'order_by' => 'created_at', 'order_dir' => 'DESC',
        ]);
        $keamananPosts = cms_posts_by_category($pdo, 'keamanan', [
            'type' => 'article', 'status' => 'published',
            'limit' => 3, 'order_by' => 'created_at', 'order_dir' => 'DESC',
        ]);
        $pengembanganPosts = cms_posts_by_category($pdo, 'pengembangan', [
            'type' => 'article', 'status' => 'published',
            'limit' => 3, 'order_by' => 'created_at', 'order_dir' => 'DESC',
        ]);
        $sistemPosts = cms_posts_by_category($pdo, 'sistem', [
            'type' => 'article', 'status' => 'published',
            'limit' => 3, 'order_by' => 'created_at', 'order_dir' => 'DESC',
        ]);
    }
}

function hp_post_url(array $post): string {
    return function_exists('get_post_permalink')
        ? get_post_permalink($post)
        : '/' . rawurlencode($post['slug'] ?? '') . '/';
}

function hp_post_thumb(array $post): string {
    $thumb = trim((string)($post['thumbnail'] ?? ''));
    if ($thumb !== '') return $thumb;
    if (function_exists('widget_first_image_from_content')) {
        return widget_first_image_from_content((string)($post['content'] ?? ''));
    }
    return '';
}

function hp_post_date(array $post): string {
    return function_exists('widget_format_date_id')
        ? widget_format_date_id($post['created_at'] ?? null)
        : '';
}

function hp_excerpt(string $html, int $len = 140): string {
    $txt = trim((string)preg_replace('/\s+/', ' ', strip_tags($html)));
    if (function_exists('mb_substr')) {
        return mb_strlen($txt, 'UTF-8') > $len ? mb_substr($txt, 0, $len, 'UTF-8') . '…' : $txt;
    }
    return strlen($txt) > $len ? substr($txt, 0, $len) . '…' : $txt;
}

$hasPosts = !empty($latestPosts);
$mainPost = $panduanPosts[0] ?? null;
$sidePosts = array_slice($panduanPosts, 1, 4);
$carouselPosts = array_slice($latestPosts, 0, 8);
?>

<?php if ($hasPosts): ?>

<section class="hp-hero hp-hero--filled">
    <div class="hp-hero__content">
        <span class="hp-hero__label"><?= h($siteTitle) ?></span>
        <h1 class="hp-hero__title"><?= __('Panduan, tips, dan insight untuk website-mu') ?></h1>
        <?php if ($siteDesc !== ''): ?>
            <p class="hp-hero__desc"><?= h($siteDesc) ?></p>
        <?php endif; ?>
        <div class="hp-hero__actions">
            <a class="hp-cta hp-cta--primary" href="/artikel/"><?= __('Jelajahi Semua Artikel') ?></a>
            <a class="hp-cta hp-cta--secondary" href="/category/panduan/"><?= __('Mulai dari Panduan') ?></a>
        </div>
    </div>
</section>

<?php if (function_exists('theme_zone_has_position') && theme_zone_has_position($pdo, 'main.homepage', 'before')): ?>
    <div class="hp-zone hp-zone--before">
        <?= theme_zone_render_position($pdo, 'main.homepage', 'before') ?>
    </div>
<?php endif; ?>

<!-- SECTION 1: Bento Panduan -->
<section class="hp-section hp-bento">
    <div class="hp-section__head">
        <h2 class="hp-section__title"><?= __('Dari Kategori Panduan') ?></h2>
        <a class="hp-section__more" href="/category/panduan/"><?= __('Lihat semua →') ?></a>
    </div>
    <?php if (!empty($panduanPosts)): ?>
        <div class="hp-bento__grid">
            <?php if ($mainPost): ?>
                <article class="hp-bento__main">
                    <a class="hp-bento__main-link" href="<?= h(hp_post_url($mainPost)) ?>" aria-label="<?= h($mainPost['title'] ?? '') ?>">
                        <div class="hp-bento__media">
                            <?php $thumb = hp_post_thumb($mainPost); if ($thumb !== ''): ?>
                                <img src="<?= h($thumb) ?>" alt="" loading="lazy" decoding="async">
                            <?php else: ?>
                                <div class="hp-bento__placeholder"></div>
                            <?php endif; ?>
                        </div>
                        <div class="hp-bento__main-body">
                            <span class="hp-bento__eyebrow"><?= __('Featured') ?></span>
                            <h3 class="hp-bento__main-title"><?= h($mainPost['title'] ?? '') ?></h3>
                            <p class="hp-bento__main-excerpt"><?= h(hp_excerpt((string)($mainPost['content'] ?? ''), 160)) ?></p>
                            <time class="hp-bento__date" datetime="<?= h($mainPost['created_at'] ?? '') ?>"><?= h(hp_post_date($mainPost)) ?></time>
                        </div>
                    </a>
                </article>
            <?php endif; ?>

            <div class="hp-bento__side">
                <?php foreach ($sidePosts as $p): ?>
                    <article class="hp-bento__side-item">
                        <a class="hp-bento__side-link" href="<?= h(hp_post_url($p)) ?>" aria-label="<?= h($p['title'] ?? '') ?>">
                            <div class="hp-bento__side-media">
                                <?php $thumb = hp_post_thumb($p); if ($thumb !== ''): ?>
                                    <img src="<?= h($thumb) ?>" alt="" loading="lazy" decoding="async">
                                <?php else: ?>
                                    <div class="hp-bento__placeholder"></div>
                                <?php endif; ?>
                            </div>
                            <div class="hp-bento__side-body">
                                <h4 class="hp-bento__side-title"><?= h($p['title'] ?? '') ?></h4>
                                <time class="hp-bento__date" datetime="<?= h($p['created_at'] ?? '') ?>"><?= h(hp_post_date($p)) ?></time>
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

<!-- SECTION 2: Infinite Carousel -->
<section class="hp-section hp-carousel">
    <div class="hp-section__head">
        <h2 class="hp-section__title"><?= __('Terbaru dari Kami') ?></h2>
        <a class="hp-section__more" href="/artikel/"><?= __('Lihat semua →') ?></a>
    </div>
    <?php if (!empty($carouselPosts)): ?>
        <div class="hp-carousel__swiper swiper">
            <div class="swiper-wrapper">
                <?php foreach ($carouselPosts as $p): ?>
                    <article class="swiper-slide hp-carousel__slide">
                        <a class="hp-carousel__card" href="<?= h(hp_post_url($p)) ?>" aria-label="<?= h($p['title'] ?? '') ?>">
                            <div class="hp-carousel__media">
                                <?php $thumb = hp_post_thumb($p); if ($thumb !== ''): ?>
                                    <img src="<?= h($thumb) ?>" alt="" loading="lazy" decoding="async">
                                <?php else: ?>
                                    <div class="hp-carousel__placeholder"></div>
                                <?php endif; ?>
                            </div>
                            <div class="hp-carousel__body">
                                <h3 class="hp-carousel__title"><?= h($p['title'] ?? '') ?></h3>
                                <time class="hp-carousel__date" datetime="<?= h($p['created_at'] ?? '') ?>"><?= h(hp_post_date($p)) ?></time>
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

<!-- SECTION 3: Explore Categories -->
<section class="hp-section hp-categories">
    <div class="hp-section__head">
        <h2 class="hp-section__title"><?= __('Jelajahi Kategori') ?></h2>
    </div>
    <?php if (!empty($categories)): ?>
        <div class="hp-categories__grid">
            <?php foreach ($categories as $cat):
                $catUrl = function_exists('get_category_permalink') ? get_category_permalink($pdo, $cat) : '/category/' . rawurlencode($cat['slug'] ?? '') . '/';
                $catIcon = match($cat['slug'] ?? '') {
                    'panduan' => '<svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>',
                    'keamanan' => '<svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>',
                    'pengembangan' => '<svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M16 18l6-6-6-6M8 6l-6 6 6 6"/></svg>',
                    'sistem' => '<svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>',
                    default => '<svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>',
                };
            ?>
                <a class="hp-categories__card" href="<?= h($catUrl) ?>">
                    <span class="hp-categories__icon"><?= $catIcon ?></span>
                    <span class="hp-categories__name"><?= h($cat['name'] ?? '') ?></span>
                    <span class="hp-categories__slug"><?= h($cat['slug'] ?? '') ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="hp-empty-card"><?= __('Belum ada kategori.') ?></div>
    <?php endif; ?>
</section>

<!-- SECTION 4: Newsletter / CTA -->
<section class="hp-section hp-cta-block">
    <div class="hp-cta-block__inner">
        <div class="hp-cta-block__content">
            <h2 class="hp-cta-block__title"><?= __('Siap membangun website profesional?') ?></h2>
            <p class="hp-cta-block__desc"><?= __('Jyavani CMS menyediakan semua tools yang kamu butuhkan: posts, pages, media, menu, tema, plugin, dan masih banyak lagi.') ?></p>
        </div>
        <div class="hp-cta-block__actions">
            <a class="hp-cta hp-cta--primary" href="/dashboard/"><?= __('Dashboard Admin') ?></a>
            <a class="hp-cta hp-cta--secondary" href="https://jyavani.com" target="_blank" rel="noopener"><?= __('Pelajari Lebih Lanjut') ?></a>
        </div>
    </div>
</section>

<!-- SECTION 5: Multi-category columns -->
<section class="hp-section hp-multicol">
    <div class="hp-section__head">
        <h2 class="hp-section__title"><?= __('Temukan Topik Favoritmu') ?></h2>
    </div>
    <div class="hp-multicol__grid">
        <?php
        $cols = [
            ['title' => __('Keamanan'), 'slug' => 'keamanan', 'posts' => $keamananPosts],
            ['title' => __('Pengembangan'), 'slug' => 'pengembangan', 'posts' => $pengembanganPosts],
            ['title' => __('Sistem'), 'slug' => 'sistem', 'posts' => $sistemPosts],
        ];
        foreach ($cols as $col):
            $colUrl = '/category/' . rawurlencode($col['slug']) . '/';
        ?>
            <div class="hp-multicol__col">
                <div class="hp-multicol__header">
                    <h3 class="hp-multicol__title"><?= h($col['title']) ?></h3>
                    <a class="hp-multicol__more" href="<?= h($colUrl) ?>"><?= __('Lihat semua') ?></a>
                </div>
                <?php if (!empty($col['posts'])): ?>
                    <ul class="hp-multicol__list">
                        <?php foreach ($col['posts'] as $p): ?>
                            <li class="hp-multicol__item">
                                <a href="<?= h(hp_post_url($p)) ?>" class="hp-multicol__link">
                                    <?php $thumb = hp_post_thumb($p); if ($thumb !== ''): ?>
                                        <span class="hp-multicol__thumb"><img src="<?= h($thumb) ?>" alt="" loading="lazy" decoding="async"></span>
                                    <?php endif; ?>
                                    <span class="hp-multicol__body">
                                        <span class="hp-multicol__item-title"><?= h($p['title'] ?? '') ?></span>
                                        <time class="hp-multicol__date"><?= h(hp_post_date($p)) ?></time>
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

<?php if (function_exists('theme_zone_has_position') && theme_zone_has_position($pdo, 'main.homepage', 'after')): ?>
    <div class="hp-zone hp-zone--after">
        <?= theme_zone_render_position($pdo, 'main.homepage', 'after') ?>
    </div>
<?php endif; ?>

<script>
(function(){
    if (typeof Swiper === 'undefined') return;
    new Swiper('.hp-carousel__swiper', {
        slidesPerView: 1.25,
        spaceBetween: 16,
        loop: true,
        autoplay: {
            delay: 3000,
            disableOnInteraction: false,
            pauseOnMouseEnter: true,
        },
        speed: 650,
        breakpoints: {
            520: { slidesPerView: 2.25 },
            780: { slidesPerView: 3.25 },
            1100: { slidesPerView: 4.25 },
        },
    });
})();
</script>

<?php else: ?>

<!-- EMPTY STATE -->
<section class="hp-hero hp-hero--center">
    <div class="hp-hero__content">
        <span class="hp-hero__label"><?= h($siteTitle) ?></span>
        <h1 class="hp-hero__title"><?= __('Selamat datang di Jyavani CMS') ?></h1>
        <?php if ($siteDesc !== ''): ?>
            <p class="hp-hero__desc"><?= h($siteDesc) ?></p>
        <?php else: ?>
            <p class="hp-hero__desc"><?= __('Website ini sudah siap. Mulai buat artikel atau halaman pertama dari dashboard.') ?></p>
        <?php endif; ?>
        <div class="hp-hero__actions">
            <a class="hp-cta hp-cta--primary" href="<?= h($adminUrl) ?>"><?= __('Buka Dashboard') ?></a>
            <a class="hp-cta hp-cta--secondary" href="https://jyavani.com" target="_blank" rel="noopener"><?= __('Dokumentasi') ?></a>
        </div>
    </div>
</section>

<section class="hp-section hp-empty">
    <div class="hp-empty__box">
        <h2><?= __('Belum ada konten') ?></h2>
        <p><?= __('Install konten demo saat setup atau buat artikel pertama sekarang. Homepage ini akan otomatis menampilkan section bento, carousel, kategori, dan multi-kolom beg ada konten.') ?></p>
    </div>
</section>

<?php endif; ?>
