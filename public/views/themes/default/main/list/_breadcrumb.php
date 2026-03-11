<?php
/**
 * vars:
 * - $category      : current category row
 * - $category_path : string "parent/child/grandchild"
 */

// safe defaults
$category = (isset($category) && is_array($category)) ? $category : [];
$category_path = isset($category_path) && is_string($category_path) ? trim($category_path, '/') : '';

$parts = ($category_path !== '') ? explode('/', $category_path) : [];
$accum = [];
?>

<nav class="breadcrumb" aria-label="Breadcrumb">
    <a href="/category/">Kategori</a>

    <?php foreach ($parts as $slug): ?>
        <?php
            $slug = trim((string)$slug);
            if ($slug === '') continue;

            $accum[] = $slug;
            $url = '/category/' . implode('/', array_map('rawurlencode', $accum)) . '/';
        ?>
        <span class="sep">›</span>
        <a href="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>">
            <?= htmlspecialchars(ucwords(str_replace('-', ' ', $slug)), ENT_QUOTES, 'UTF-8') ?>
        </a>
    <?php endforeach; ?>
</nav>

<style>
.breadcrumb {
    font-size: .9rem;
    margin-bottom: 1rem;
    color: #666;
}
.breadcrumb a {
    color: inherit;
    text-decoration: none;
}
.breadcrumb a:hover {
    text-decoration: underline;
}
.breadcrumb .sep {
    margin: 0 .35rem;
}
</style>