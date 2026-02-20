<?php
// lokasi file /views/category/posts_list.php
?>

<div id="adamz-cat-wrapper">

    <?php
      // breadcrumb
      $breadcrumbPath = __DIR__ . '/_breadcrumb.php';
      if (is_file($breadcrumbPath)) {
          include $breadcrumbPath;
      }
    ?>

    <header class="adamz-cat-header">
        <h1><?= htmlspecialchars($category['name'], ENT_QUOTES, 'UTF-8') ?></h1>

        <?php if (!empty($category['description'])): ?>
            <div class="adamz-cat-desc">
                <?= nl2br(htmlspecialchars($category['description'], ENT_QUOTES, 'UTF-8')) ?>
            </div>
        <?php endif; ?>
    </header>

    <?php if (empty($posts)): ?>
        <div style="padding: 4rem; text-align: center; background: #f9f9f9; border-radius: 20px; color: #b2bec3;">
            <p>Tidak ada artikel dalam kategori ini.</p>
        </div>
    <?php else: ?>
        <div class="adamz-cat-list">
            <?php foreach ($posts as $p): 
                $postUrl = "/" . rawurlencode($p['slug']) . "/";
                $postTitle = htmlspecialchars($p['title'], ENT_QUOTES, 'UTF-8');
                $postDate = date('d M Y', strtotime($p['created_at']));
            ?>
                <article class="adamz-cat-card">
                    <?php if (!empty($p['thumbnail'])): ?>
                        <a href="<?= $postUrl ?>" class="adamz-cat-thumb-box">
                            <img src="<?= htmlspecialchars($p['thumbnail'], ENT_QUOTES, 'UTF-8') ?>"
                                 alt="<?= $postTitle ?>"
                                 class="adamz-cat-img"
                                 loading="lazy">
                        </a>
                    <?php endif; ?>

                    <div class="adamz-cat-body">
                        <div class="adamz-cat-meta">
                            Diterbitkan • <?= $postDate ?>
                        </div>

                        <h2>
                            <a href="<?= $postUrl ?>"><?= $postTitle ?></a>
                        </h2>

                        <p class="adamz-cat-excerpt">
                            <?= htmlspecialchars(mb_strimwidth(strip_tags($p['content']), 0, 220, '…'), ENT_QUOTES, 'UTF-8') ?>
                        </p>

                        <a class="adamz-cat-btn" href="<?= $postUrl ?>">
                            Baca Selengkapnya →
                        </a>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>

        <?php
            $base = '/category/' . implode('/', array_map('rawurlencode', explode('/', $category_path))) . '/';
        ?>
        
        <?php if ($totalPages > 1): ?>
            <nav class="adamz-cat-pager" aria-label="Pagination">
                <?php if ($page > 1): ?>
                    <a class="adamz-cat-page-link" href="<?= htmlspecialchars($base . '?page=' . ($page - 1), ENT_QUOTES, 'UTF-8') ?>">
                        &larr; Prev
                    </a>
                <?php endif; ?>

                <span style="font-weight: 700; color: #b2bec3;">
                    <?= $page ?> / <?= $totalPages ?>
                </span>

                <?php if ($page < $totalPages): ?>
                    <a class="adamz-cat-page-link" href="<?= htmlspecialchars($base . '?page=' . ($page + 1), ENT_QUOTES, 'UTF-8') ?>">
                        Next &rarr;
                    </a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</div>