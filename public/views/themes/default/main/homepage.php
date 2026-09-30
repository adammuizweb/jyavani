<?php
declare(strict_types=1);

$pdo = ($pdo ?? null) instanceof PDO ? $pdo : ($GLOBALS['pdo'] ?? null);
require_once dirname(__DIR__) . '/partials/shortcodes/section/_home.php';

$home = default_theme_home_data($pdo instanceof PDO ? $pdo : null);

if ($home['has_posts']):
    echo render_theme_section('home.hero', [], $pdo instanceof PDO ? $pdo : null);

    if ($pdo instanceof PDO && function_exists('theme_zone_has_position') && theme_zone_has_position($pdo, 'main.homepage', 'before')): ?>
        <div class="hp-zone hp-zone--before">
            <?= theme_zone_render_position($pdo, 'main.homepage', 'before') ?>
        </div>
    <?php endif;

    echo render_theme_section('home.guide-bento', [], $pdo instanceof PDO ? $pdo : null);
    echo render_theme_section('home.latest-carousel', [], $pdo instanceof PDO ? $pdo : null, ['defer_carousel_script' => true]);
    echo render_theme_section('home.preset-posts', [], $pdo instanceof PDO ? $pdo : null);
    echo render_theme_section('home.categories', [], $pdo instanceof PDO ? $pdo : null);
    echo render_theme_section('home.random-posts', [], $pdo instanceof PDO ? $pdo : null);
    echo render_theme_section('home.cta', [], $pdo instanceof PDO ? $pdo : null);
    echo render_theme_section('home.topic-columns', [], $pdo instanceof PDO ? $pdo : null);

    if ($pdo instanceof PDO && function_exists('theme_zone_has_position') && theme_zone_has_position($pdo, 'main.homepage', 'after')): ?>
        <div class="hp-zone hp-zone--after">
            <?= theme_zone_render_position($pdo, 'main.homepage', 'after') ?>
        </div>
    <?php endif;

    echo default_theme_home_carousel_script();
else:
    echo render_theme_section('home.empty-state', [], $pdo instanceof PDO ? $pdo : null);
endif;
