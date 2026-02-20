<?php
// /views/themes/adam/main/list/post.php

if (!isset($posts) || !is_array($posts)) return;

$page = max(1, (int)($page ?? 1));
$total = isset($total) ? (int)$total : count($posts);
$perPage = isset($perPage) ? (int)$perPage : 10;
$pages = max(1, (int)ceil($total / max(1, $perPage)));
$base = isset($base) ? rtrim($base, '/') . '/' : '/artikel/';

// Helper Functions
if (!function_exists('_theme_posts_excerpt')) {
    function _theme_posts_excerpt($html, $len = 120) {
        $text = trim(strip_tags($html));
        if (mb_strlen($text) <= $len) return $text;
        return mb_substr($text, 0, $len) . '...';
    }
}

// ... (fungsi _theme_posts_resolve_image tetap sama seperti sebelumnya) ...
if (!function_exists('_theme_posts_youtube_id')) {
    function _theme_posts_youtube_id(?string $url): ?string {
        if (!$url) return null;
        if (preg_match('#youtu\.be/([A-Za-z0-9_\-]+)#i', $url, $m)) return $m[1];
        if (preg_match('#[?&]v=([A-Za-z0-9_\-]+)#i', $url, $m)) return $m[1];
        return null;
    }
}
if (!function_exists('_theme_posts_youtube_thumb')) {
    function _theme_posts_youtube_thumb(?string $url): ?string {
        $id = _theme_posts_youtube_id($url);
        return $id ? "https://img.youtube.com/vi/{$id}/hqdefault.jpg" : null;
    }
}
if (!function_exists('_theme_posts_resolve_image')) {
    function _theme_posts_resolve_image(array $p): ?string {
        if (!empty($p['youtube'])) return _theme_posts_youtube_thumb($p['youtube']);
        if (!empty($p['display_image'])) return $p['display_image'];
        if (!empty($p['thumbnail'])) return $p['thumbnail'];
        return null; // Bisa ditambah default placeholder image di sini
    }
}
?>

<section id="adamz-posts-container">
    <header class="adamz-header">
        <h1 class="adamz-main-title">Artikel Terbaru</h1>
        <?php if ($total): ?>
            <small style="color:#888"><?= number_format($total) ?> Total Artikel</small>
        <?php endif; ?>
    </header>

    <?php if (empty($posts)): ?>
        <div style="text-align:center; padding: 3rem; color: #999;">Belum ada artikel yang diterbitkan.</div>
    <?php else: ?>
        <div class="adamz-posts-grid">
            <?php foreach ($posts as $p):
                $title = htmlspecialchars($p['title'] ?? 'Untitled', ENT_QUOTES, 'UTF-8');
                $slug  = rawurlencode($p['slug'] ?? '');
                $date  = isset($p['created_at']) ? date('d M Y', strtotime($p['created_at'])) : '';
                $img   = _theme_posts_resolve_image($p) ?? 'https://via.placeholder.com/600x400?text=No+Image';
            ?>
                <article class="adamz-post-card">
                    <div class="adamz-img-wrapper">
                        <a href="/<?= $slug ?>/">
                            <img src="<?= htmlspecialchars($img, ENT_QUOTES, 'UTF-8') ?>" 
                                 alt="<?= $title ?>" 
                                 class="adamz-thumbnail">
                        </a>
                    </div>

                    <div class="adamz-post-content">
                        <div class="adamz-entry-meta"><?= $date ?></div>
                        <h2 class="adamz-entry-title">
                            <a href="/<?= $slug ?>/"><?= $title ?></a>
                        </h2>
                        <p class="adamz-entry-excerpt">
                            <?= htmlspecialchars(_theme_posts_excerpt($p['content'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                        </p>
                        <div class="adamz-read-more-wrapper">
                            <a href="/<?= $slug ?>/" class="adamz-btn-link">Baca Selengkapnya →</a>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>

        <?php if ($pages > 1): ?>
            <div class="adamz-pagination-box">
                <nav class="adamz-pagination-nav">
                    <?php if ($page > 1): ?>
                        <a href="<?= $base ?>?page=<?= $page-1 ?>" class="adamz-page-item">Prev</a>
                    <?php endif; ?>
                    
                    <span class="adamz-page-item" style="background:#eee">Hal <?= $page ?></span>

                    <?php if ($page < $pages): ?>
                        <a href="<?= $base ?>?page=<?= $page+1 ?>" class="adamz-page-item">Next</a>
                    <?php endif; ?>
                </nav>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</section>