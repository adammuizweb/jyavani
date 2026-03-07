<?php
// lokasi file /views/themes/default/main/list/category.php
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