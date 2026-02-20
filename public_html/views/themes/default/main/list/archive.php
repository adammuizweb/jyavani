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
                            <?= htmlspecialchars(mb_strimwidth(strip_tags($p['content']), 0, 150, '…'), ENT_QUOTES, 'UTF-8') ?>
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

<style>
    /* Container Arsip */
#adamz-arch-container {
    max-width: 1000px;
    margin: 2rem auto;
    padding: 0 1.5rem;
    font-family: 'Inter', -apple-system, sans-serif;
}

/* Header Arsip */
.adamz-arch-header {
    text-align: center;
    margin-bottom: 3rem;
    position: relative;
}

.adamz-arch-label {
    display: inline-block;
    padding: 0.4rem 1.2rem;
    border-radius: 99px;
    font-size: 0.8rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 2px;
    margin-bottom: 1rem;
}

.adamz-arch-title {
    font-size: 2.5rem;
    font-weight: 850;
    margin: 0;
    letter-spacing: -1.5px;
}

/* Grid 2 Kolom */
.adamz-arch-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 2.5rem 2rem;
}

/* Card Arsip */
.adamz-arch-card {
    display: flex;
    flex-direction: column;
    group: hover;
}

.adamz-arch-img-link {
    display: block;
    position: relative;
    border-radius: 20px;
    overflow: hidden;
    aspect-ratio: 4 / 3;
    margin-bottom: 1.25rem;
}

.adamz-arch-img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    transition: transform 0.6s cubic-bezier(0.165, 0.84, 0.44, 1);
}

/* Date Chip (Floating on Image) */
.adamz-arch-date-tag {
    position: absolute;
    bottom: 15px;
    left: 15px;
    padding: 0.5rem 0.8rem;
    border-radius: 12px;
    font-size: 0.75rem;
    font-weight: 800;
    box-shadow: 0 4px 10px rgba(0,0,0,0.1);
}

.adamz-arch-card:hover .adamz-arch-img {
    transform: scale(1.1);
}

/* Typography Arsip */
.adamz-arch-post-title {
    font-size: 1.35rem;
    line-height: 1.4;
    font-weight: 700;
    margin: 0 0 0.75rem 0;
}

.adamz-arch-post-title a {
    text-decoration: none;
    transition: color 0.2s;
}

.adamz-arch-post-title a:hover {
text-decoration: none;
}

.adamz-arch-excerpt {
    font-size: 0.95rem;
    line-height: 1.6;
    margin: 0;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

/* Pagination Style */
.adamz-arch-pagination {
    margin-top: 4rem;
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 1rem;
}

.adamz-arch-nav-btn {
    text-decoration: none;
    padding: 0.75rem 1.5rem;
    border-radius: 12px;
    font-weight: 600;
    font-size: 0.9rem;
    transition: opacity 0.2s;
}

.adamz-arch-nav-btn:hover {
    opacity: 0.85;
}

.adamz-arch-page-info {
    font-size: 0.9rem;
    font-weight: 700;
}

/* Mobile */
@media (max-width: 768px) {
    .adamz-arch-grid {
        grid-template-columns: 1fr;
    }
    .adamz-arch-title {
        font-size: 1.8rem;
    }
}
</style>