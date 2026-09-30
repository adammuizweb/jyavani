<?php
declare(strict_types=1);

require_once __DIR__ . '/_home.php';
$home = default_theme_home_data($pdo);
?>
<!-- EMPTY STATE -->
<section class="hp-hero hp-hero--center">
    <div class="hp-hero__content">
        <span class="hp-hero__label"><?= $esc($home['site_title']) ?></span>
        <h1 class="hp-hero__title"><?= __('Selamat datang di Jyavani CMS') ?></h1>
        <?php if ($home['site_description'] !== ''): ?>
            <p class="hp-hero__desc"><?= $esc($home['site_description']) ?></p>
        <?php else: ?>
            <p class="hp-hero__desc"><?= __('Website ini sudah siap. Mulai buat artikel atau halaman pertama dari dashboard.') ?></p>
        <?php endif; ?>
        <div class="hp-hero__actions">
            <a class="hp-cta hp-cta--primary" href="<?= $esc($home['admin_url']) ?>"><?= __('Buka Dashboard') ?></a>
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
