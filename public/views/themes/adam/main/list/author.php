<?php
/** views/author/posts.php */
$perPage = 10;
$pages = max(1, (int)ceil(($total ?? 0) / $perPage));
$authorName = $author['name'] ?? $author['username'] ?? 'Penulis';
$authorLink = !empty($author['username']) ? '/author/' . rawurlencode($author['username']) . '/' : '/author/' . rawurlencode($author['id']) . '/';
?>

<div id="adamz-author-wrapper">
    
    <header class="adamz-author-profile">
        <div class="adamz-author-avatar-group">
            <?php if (!empty($author['img'])): ?>
                <img class="adamz-author-photo" 
                     src="<?= htmlspecialchars($author['img'], ENT_QUOTES, 'UTF-8') ?>" 
                     alt="<?= htmlspecialchars($authorName, ENT_QUOTES, 'UTF-8') ?>">
            <?php else: ?>
                <div class="adamz-author-fallback">
                    <?= htmlspecialchars(strtoupper(mb_substr($authorName, 0, 1)), ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="adamz-author-info">
            <h1 class="adamz-author-name"><?= htmlspecialchars($authorName, ENT_QUOTES, 'UTF-8') ?></h1>
            
            <?php 
            $bio_raw = trim((string)($author['bio'] ?? ''));
            if ($bio_raw !== ''): 
                $bio_display = mb_strimwidth($bio_raw, 0, 300, '…');
            ?>
                <p class="adamz-author-bio"><?= nl2br(htmlspecialchars($bio_display, ENT_QUOTES, 'UTF-8')) ?></p>
            <?php endif; ?>

            <div class="adamz-author-stats">
                <?= number_format($total ?? 0) ?> Artikel Diterbitkan
            </div>
        </div>
    </header>

    <?php if (empty($posts)): ?>
        <div style="text-align:center; padding:3rem; color:#a0aec0; background:#f8f9fa; border-radius:16px;">
            Penulis belum mempublikasikan artikel apapun.
        </div>
    <?php else: ?>
        <div class="adamz-author-posts-stack">
            <?php foreach ($posts as $p):
                $imgUrl = !empty($p['display_image']) ? $p['display_image'] : (!empty($p['thumbnail']) ? $p['thumbnail'] : null);
                $postUrl = function_exists('get_post_permalink') ? get_post_permalink($p) : '/' . rawurlencode($p['slug']) . '/';
                $date = !empty($p['created_at']) ? date('M d, Y', strtotime($p['created_at'])) : 'Recent';
            ?>
                <article class="adamz-author-post-card">
                    <?php if ($imgUrl): ?>
                        <a class="adamz-post-thumb-link" href="<?= $postUrl ?>">
                            <img class="adamz-post-img" 
                                 src="<?= htmlspecialchars($imgUrl, ENT_QUOTES, 'UTF-8') ?>"
                                 alt="<?= htmlspecialchars($p['title'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                        </a>
                    <?php endif; ?>

                    <div class="adamz-post-details">
                        <span class="adamz-post-date"><?= $date ?></span>
                        <h2 class="adamz-post-title">
                            <a href="<?= $postUrl ?>"><?= htmlspecialchars($p['title'], ENT_QUOTES, 'UTF-8') ?></a>
                        </h2>
                        <p class="adamz-post-excerpt">
                            <?= htmlspecialchars(mb_strimwidth(safe_strip_tags($p['content'] ?? ''), 0, 180, '…'), ENT_QUOTES, 'UTF-8') ?>
                        </p>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($pages > 1): ?>
    <nav class="adamz-pagination" aria-label="Pagination">
        <?php if ($page > 1): ?>
            <a class="adamz-page-btn" href="?page=<?= max(1, $page - 1) ?>">← Prev</a>
        <?php else: ?>
            <span></span> <?php endif; ?>

        <span style="font-size: 0.9rem; color: #718096; font-weight: 600;">
            Halaman <?= $page ?> dari <?= $pages ?>
        </span>

        <?php if ($page < $pages): ?>
            <a class="adamz-page-btn" href="?page=<?= min($pages, $page + 1) ?>">Next →</a>
        <?php endif; ?>
    </nav>
    <?php endif; ?>
</div>