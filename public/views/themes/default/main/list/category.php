<?php
// /views/themes/default/main/list/category.php
/**
 * vars expected:
 * - $category
 * - $posts
 * - $page
 * - $totalPages
 * - $category_path
 */

// safe defaults
$category = (isset($category) && is_array($category)) ? $category : [];
$posts = (isset($posts) && is_array($posts)) ? $posts : [];
$page = isset($page) ? max(1, (int)$page) : 1;
$totalPages = isset($totalPages) ? max(1, (int)$totalPages) : 1;
$category_path = isset($category_path) && is_string($category_path) ? trim($category_path, '/') : '';

// derived safe values
$categoryName = (string)($category['name'] ?? 'Kategori');
$categoryDescription = (string)($category['description'] ?? '');
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
        <h1><?= htmlspecialchars($categoryName, ENT_QUOTES, 'UTF-8') ?></h1>

        <?php if ($categoryDescription !== ''): ?>
            <p class="category-description">
                <?= nl2br(htmlspecialchars($categoryDescription, ENT_QUOTES, 'UTF-8')) ?>
            </p>
        <?php endif; ?>
    </header>

    <?php if (empty($posts)): ?>
        <p class="no-posts">Tidak ada artikel dalam kategori ini.</p>
    <?php else: ?>
        <div class="posts-list">
            <?php foreach ($posts as $p): ?>
                <?php
                    if (!is_array($p)) continue;

                    $postId = (int)($p['id'] ?? 0);
                    $postSlug = (string)($p['slug'] ?? '');
                    $postTitle = (string)($p['title'] ?? 'Tanpa Judul');
                    $postContent = (string)($p['content'] ?? '');
                    $postCreatedAt = !empty($p['created_at']) ? strtotime((string)$p['created_at']) : false;

                    $imgToShow = $p['display_image'] ?? ($p['thumbnail'] ?? '');
                ?>
                <article class="post-card" aria-labelledby="post-title-<?= $postId ?>">
                    <?php if (!empty($imgToShow)): ?>
                        <img
                            src="<?= htmlspecialchars((string)$imgToShow, ENT_QUOTES, 'UTF-8') ?>"
                            alt="<?= htmlspecialchars($postTitle, ENT_QUOTES, 'UTF-8') ?>"
                            style="max-width:220px;display:block;margin-bottom:.6rem"
                        >
                    <?php endif; ?>

                    <div class="post-body">
                        <h2 id="post-title-<?= $postId ?>">
                            <a href="/<?= rawurlencode($postSlug) ?>/">
                                <?= htmlspecialchars($postTitle, ENT_QUOTES, 'UTF-8') ?>
                            </a>
                        </h2>

                        <div class="meta">
                            <?php if ($postCreatedAt): ?>
                                <time datetime="<?= htmlspecialchars(date('c', $postCreatedAt), ENT_QUOTES, 'UTF-8') ?>">
                                    <?= htmlspecialchars(date('Y-m-d H:i', $postCreatedAt), ENT_QUOTES, 'UTF-8') ?>
                                </time>
                            <?php endif; ?>
                        </div>

                        <p class="excerpt">
                            <?= htmlspecialchars(mb_strimwidth(safe_strip_tags($postContent), 0, 240, '…'), ENT_QUOTES, 'UTF-8') ?>
                        </p>

                        <div class="post-actions">
                            <a class="read-more" href="/<?= rawurlencode($postSlug) ?>/">Baca selengkapnya →</a>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>

        <?php
            $base = $category_path !== ''
                ? '/category/' . implode('/', array_map('rawurlencode', explode('/', $category_path))) . '/'
                : '/category/';
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