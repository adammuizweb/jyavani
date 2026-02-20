<?php
// lokasi file /views/category/posts_list.php
/**
 * vars:
 * - $category
 * - $posts
 * - $page
 * - $totalPages
 * - $category_path
 */
?>
<div class="container category-posts">

    <?php
      // breadcrumb
      $breadcrumbPath = __DIR__ . '/_breadcrumb.php';
      if (is_file($breadcrumbPath)) {
          include $breadcrumbPath;
      }
    ?>

    <header class="category-header">
        <h1><?= htmlspecialchars($category['name'], ENT_QUOTES, 'UTF-8') ?></h1>

        <?php if (!empty($category['description'])): ?>
            <p class="category-description">
                <?= nl2br(htmlspecialchars($category['description'], ENT_QUOTES, 'UTF-8')) ?>
            </p>
        <?php endif; ?>
    </header>

    <?php if (empty($posts)): ?>
        <p class="no-posts">Tidak ada artikel dalam kategori ini.</p>
    <?php else: ?>
        <div class="posts-list">
            <?php foreach ($posts as $p): ?>
                <article class="post-card" aria-labelledby="post-title-<?= (int)$p['id'] ?>">
<?php
  // prefer display_image (set oleh PostController::attach_display_images), fallback to thumbnail or embed thumbnail
  $imgToShow = $p['display_image'] ?? ($p['thumbnail'] ?? '');
?>
<?php if (!empty($imgToShow)): ?>
  <img src="<?= htmlspecialchars($imgToShow, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($p['title'] ?? '', ENT_QUOTES, 'UTF-8') ?>" style="max-width:220px;display:block;margin-bottom:.6rem">
<?php endif; ?>


                    <div class="post-body">
                        <h2 id="post-title-<?= (int)$p['id'] ?>">
                            <a href="/<?= rawurlencode($p['slug']) ?>/">
                                <?= htmlspecialchars($p['title'], ENT_QUOTES, 'UTF-8') ?>
                            </a>
                        </h2>

                        <div class="meta">
                            <time datetime="<?= htmlspecialchars(date('c', strtotime($p['created_at'])), ENT_QUOTES, 'UTF-8') ?>">
                                <?= htmlspecialchars(date('Y-m-d H:i', strtotime($p['created_at'])), ENT_QUOTES, 'UTF-8') ?>
                            </time>
                        </div>

                        <p class="excerpt">
                            <?= htmlspecialchars(mb_strimwidth(strip_tags($p['content']), 0, 240, '…'), ENT_QUOTES, 'UTF-8') ?>
                        </p>

                        <div class="post-actions">
                            <a class="read-more" href="/<?= rawurlencode($p['slug']) ?>/">Baca selengkapnya →</a>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>

        <?php
            $base = '/category/' . implode('/', array_map('rawurlencode', explode('/', $category_path))) . '/';
        ?>
        <nav class="pagination" aria-label="Pagination">
            <?php if ($page > 1): ?>
                <a class="page-link" href="<?= htmlspecialchars($base . '?page=' . ($page - 1), ENT_QUOTES, 'UTF-8') ?>">&larr; Sebelumnya</a>
            <?php endif; ?>

            <span class="page-info">Halaman <?= $page ?> dari <?= $totalPages ?></span>

            <?php if ($page < $totalPages): ?>
                <a class="page-link" href="<?= htmlspecialchars($base . '?page=' . ($page + 1), ENT_QUOTES, 'UTF-8') ?>">Berikutnya &rarr;</a>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
</div>

<style>
/* Container / layout */
.container {
    max-width: 1100px;
    margin: 0 auto;
    padding: 1.25rem;
    box-sizing: border-box;
    font-family: system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial;
    line-height: 1.55;
}

/* Breadcrumb small */
.category-header + .posts-list,
.category-header + .no-posts {
    margin-top: .75rem;
}

.category-header {
    margin: .5rem 0 1rem;
    padding-bottom: .75rem;
    border-bottom: 1px;
}

.category-header h1 {
    margin: 0;
    font-size: 2rem;
    line-height: 1.05;
    font-weight: 700;
    letter-spacing: -0.5px;
}

.category-description {
    margin: .6rem 0 0;
    font-size: .98rem;
}

/* Posts list grid */
.posts-list {
    display: grid;
    gap: 1rem 1.25rem;
}

/* Post card */
.post-card {
    display: flex;
    gap: 1rem;
    padding: 1rem;
    border-radius: 10px;
    box-shadow: 0 0 0 1px rgba(14, 25, 30, 0.02);
    align-items: flex-start;
    transition: transform .12s ease, box-shadow .12s ease;
    border-left: 4px solid transparent;
}
.post-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 8px 18px rgba(20, 30, 40, 0.06);
    border-left-color: rgba(11,122,107,0.12);
}

/* Thumbnail */
.post-card .thumb {
    flex: 0 0 200px;
    display: block;
    overflow: hidden;
    border-radius: 8px;
}
.post-card .thumb img {
    width: 100%;
    height: 120px;
    object-fit: cover;
    display: block;
    border-radius: 8px;
}

/* When thumbnail is missing, shrink gap */
.post-card:not(:has(.thumb)) {
    padding-left: 1rem;
}

/* Body */
.post-body {
    flex: 1 1 auto;
    min-width: 0;
}
.post-body h2 {
    margin: 0 0 .35rem 0;
    font-size: 1.15rem;
    line-height: 1.2;
}
.post-body h2 a {
    text-decoration: none;
    font-weight: 700;
}
.post-body h2 a:hover {
    text-decoration: underline;
}

/* Meta */
.meta {
    font-size: .86rem;
    margin-bottom: .6rem;
}

/* Excerpt */
.excerpt {
    margin: 0 0 .6rem 0;
    font-size: .98rem;
}

/* Read more button */
.post-actions {
    margin-top: 0.25rem;
}
.read-more {
    display: inline-block;
    font-weight: 600;
    text-decoration: none;
    padding: .45rem .7rem;
    border-radius: 6px;
    border: 1px;
    transition: background .12s ease, transform .08s ease;
}
.read-more:hover {
    background: rgba(11,122,107,0.12);
    transform: translateY(-1px);
}

/* Pagination */
.pagination {
    margin-top: 1.2rem;
    display: flex;
    gap: .8rem;
    align-items: center;
    justify-content: center;
    padding-top: .5rem;
}
.page-link {
    display: inline-block;
    padding: .45rem .65rem;
    border-radius: 6px;
    border: 1px;
    text-decoration: none;
    font-weight: 600;
}
.page-info {
    font-size: .95rem;
}

/* No posts */
.no-posts {
    padding: 1rem;
    border-radius: 8px;
}

/* Responsive: small screens */
@media (max-width: 720px) {
    .container { padding: .9rem; }
    .post-card {
        flex-direction: column;
        gap: .6rem;
        padding: .9rem;
    }
    .post-card .thumb { width: 100%; flex: 0 0 auto; }
    .post-card .thumb img { height: 180px; }
    .post-body h2 { font-size: 1.05rem; }
    .excerpt { font-size: .98rem; }
}

/* Responsive: larger screens adjust thumbnail size */
@media (min-width: 1100px) {
    .post-card .thumb { flex: 0 0 220px; }
    .post-card .thumb img { height: 130px; }
}
</style>