<?php
declare(strict_types=1);

require_once __DIR__ . '/_home.php';
$home = default_theme_home_data($pdo);
?>
<section class="hp-hero hp-hero--filled">
    <div class="hp-hero__content">
        <span class="hp-hero__label"><?= $esc($home['site_title']) ?></span>
        <h1 class="hp-hero__title"><?= __('Panduan, tips, dan insight untuk website-mu') ?></h1>
        <?php if ($home['site_description'] !== ''): ?>
            <p class="hp-hero__desc"><?= $esc($home['site_description']) ?></p>
        <?php endif; ?>
        <div class="hp-hero__actions">
            <a class="hp-cta hp-cta--primary" href="<?= $esc(function_exists('localized_path_url') ? localized_path_url('/artikel/') : '/artikel/') ?>"><?= __('Jelajahi Semua Artikel') ?></a>
            <a class="hp-cta hp-cta--secondary" href="<?= $esc(function_exists('localized_path_url') ? localized_path_url('/category/panduan/') : '/category/panduan/') ?>"><?= __('Mulai dari Panduan') ?></a>
        </div>
    </div>
</section>
