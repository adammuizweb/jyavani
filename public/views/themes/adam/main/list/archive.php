<?php
// main/list/archive.php - posts grouped by date (controller should supply $archive_label and $posts)
$archive_label = $archive_label ?? '';
$posts = $posts ?? [];
?>
<section id="adamz-arch-container">
    <header class="adamz-arch-header">
        <span class="adamz-arch-label">Koleksi Tulisan</span>
        <h1 class="adamz-arch-title">
            <?php 
                if ($month) {
                    echo date('F', mktime(0, 0, 0, $month, 1, $year)) . ' ' . $year;
                } else {
                    echo 'Tahun ' . $year;
                }
            ?>
        </h1>
    </header>

    <?php if (empty($posts)): ?>
        <div style="text-align:center; padding:5rem; color:#ced6e0; border: 2px dashed #f1f2f6; border-radius:24px;">
            <p>Tidak ditemukan artikel pada periode ini.</p>
        </div>
    <?php else: ?>
        <div class="adamz-arch-grid">
            <?php foreach ($posts as $p): 
                $postUrl = "/" . rawurlencode($p['slug']) . "/";
                $title = htmlspecialchars($p['title'], ENT_QUOTES, 'UTF-8');
                $date = date('d M Y', strtotime($p['created_at']));
                
                // Mengambil gambar dari attach_display_images controller
                $img = $p['display_image'] ?? $p['thumbnail'] ?? 'https://via.placeholder.com/600x450?text=No+Image';
            ?>
                <article class="adamz-arch-card">
                    <a href="<?= $postUrl ?>" class="adamz-arch-img-link">
                        <img src="<?= htmlspecialchars($img, ENT_QUOTES, 'UTF-8') ?>" 
                             alt="<?= $title ?>" 
                             class="adamz-arch-img"
                             loading="lazy">
                        <div class="adamz-arch-date-tag"><?= $date ?></div>
                    </a>

                    <div class="adamz-arch-content">
                        <h2 class="adamz-arch-post-title">
                            <a href="<?= $postUrl ?>"><?= $title ?></a>
                        </h2>
                        <p class="adamz-arch-excerpt">
                            <?= htmlspecialchars(mb_strimwidth(safe_strip_tags($p['content']), 0, 150, '…'), ENT_QUOTES, 'UTF-8') ?>
                        </p>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>

        <?php if ($totalPages > 1): ?>
            <nav class="adamz-arch-pagination" aria-label="Pagination">
                <?php if ($page > 1): ?>
                    <a href="<?= htmlspecialchars($basePath . ($page-1 === 1 ? '' : '?page=' . ($page-1)), ENT_QUOTES, 'UTF-8') ?>" 
                       class="adamz-arch-nav-btn">
                       &larr; Prev
                    </a>
                <?php endif; ?>

                <span class="adamz-arch-page-info">
                    Halaman <?= $page ?> / <?= $totalPages ?>
                </span>

                <?php if ($page < $totalPages): ?>
                    <a href="<?= htmlspecialchars($basePath . '?page=' . ($page+1), ENT_QUOTES, 'UTF-8') ?>" 
                       class="adamz-arch-nav-btn">
                       Next &rarr;
                    </a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</section>