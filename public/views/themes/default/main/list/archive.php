<?php
// /views/themes/default/main/list/archive.php
// Expected vars (optional):
// - $archive_label
// - $posts
// - $month
// - $year
// - $page
// - $totalPages
// - $basePath

$archive_label = isset($archive_label) ? (string)$archive_label : '';
$posts         = (isset($posts) && is_array($posts)) ? $posts : [];
$month         = isset($month) && $month !== '' ? (int)$month : null;
$year          = isset($year) && $year !== '' ? (int)$year : null;
$page          = isset($page) ? max(1, (int)$page) : 1;
$totalPages    = isset($totalPages) ? max(1, (int)$totalPages) : 1;
$basePath      = isset($basePath) && is_string($basePath) && $basePath !== '' ? $basePath : '/archive/';

// Build title safely
if ($archive_label !== '') {
    $archiveTitle = $archive_label;
} elseif ($month !== null && $month >= 1 && $month <= 12 && $year !== null && $year > 0) {
    $archiveTitle = date('F', mktime(0, 0, 0, $month, 1, $year)) . ' ' . $year;
} elseif ($year !== null && $year > 0) {
    $archiveTitle = __('Year') . ' ' . $year;
} else {
    $archiveTitle = __('Archive');
}
?>
<section id="adamz-arch-container">
    <header class="adamz-arch-header">
        <span class="adamz-arch-label"><?= __('Writing Collection') ?></span>
        <h1 class="adamz-arch-title">
            <?= htmlspecialchars($archiveTitle, ENT_QUOTES, 'UTF-8') ?>
        </h1>
    </header>

    <?php if (empty($posts)): ?>
        <div style="text-align:center; padding:5rem; color:#ced6e0; border:2px dashed #f1f2f6; border-radius:24px;">
            <p><?= __('No articles found in this period.') ?></p>
        </div>
    <?php else: ?>
        <div class="adamz-arch-grid">
            <?php foreach ($posts as $p): ?>
                <?php
                    if (!is_array($p)) continue;

                    $slug      = (string)($p['slug'] ?? '');
                    $titleRaw  = (string)($p['title'] ?? __('Untitled'));
                    $content   = (string)($p['content'] ?? '');
                    $createdAt = !empty($p['created_at']) ? strtotime((string)$p['created_at']) : false;

                    $postUrl = function_exists('get_post_permalink') ? get_post_permalink($p) : '/' . rawurlencode($slug) . '/';
                    $title   = htmlspecialchars($titleRaw, ENT_QUOTES, 'UTF-8');
                    $date    = $createdAt ? date('d M Y', $createdAt) : '-';

                    // display_image > thumbnail > placeholder
                    $img = $p['display_image'] ?? $p['thumbnail'] ?? 'https://via.placeholder.com/600x450?text=No+Image';
                ?>
                <article class="adamz-arch-card">
                    <a href="<?= htmlspecialchars($postUrl, ENT_QUOTES, 'UTF-8') ?>" class="adamz-arch-img-link">
                        <img
                            src="<?= htmlspecialchars((string)$img, ENT_QUOTES, 'UTF-8') ?>"
                            alt="<?= $title ?>"
                            class="adamz-arch-img"
                            loading="lazy"
                        >
                        <div class="adamz-arch-date-tag"><?= htmlspecialchars($date, ENT_QUOTES, 'UTF-8') ?></div>
                    </a>

                    <div class="adamz-arch-content">
                        <h2 class="adamz-arch-post-title">
                            <a href="<?= htmlspecialchars($postUrl, ENT_QUOTES, 'UTF-8') ?>"><?= $title ?></a>
                        </h2>
                        <p class="adamz-arch-excerpt">
                            <?= htmlspecialchars(mb_strimwidth(safe_strip_tags($content), 0, 150, '…'), ENT_QUOTES, 'UTF-8') ?>
                        </p>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>

        <?php if ($totalPages > 1): ?>
            <nav class="adamz-arch-pagination" aria-label="Pagination">
                <?php if ($page > 1): ?>
                    <?php
                        $prevPage = $page - 1;
                        $prevUrl = $basePath . ($prevPage === 1 ? '' : '?page=' . $prevPage);
                    ?>
                    <a href="<?= htmlspecialchars($prevUrl, ENT_QUOTES, 'UTF-8') ?>" class="adamz-arch-nav-btn">
                        <?= __('← Prev') ?>
                    </a>
                <?php endif; ?>

                <span class="adamz-arch-page-info">
                    <?= sprintf(__('Page %d / %d'), $page, $totalPages) ?>
                </span>

                <?php if ($page < $totalPages): ?>
                    <?php $nextUrl = $basePath . '?page=' . ($page + 1); ?>
                    <a href="<?= htmlspecialchars($nextUrl, ENT_QUOTES, 'UTF-8') ?>" class="adamz-arch-nav-btn">
                        <?= __('Next →') ?>
                    </a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</section>