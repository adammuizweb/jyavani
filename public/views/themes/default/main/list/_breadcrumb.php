<?php
/**
 * vars:
 * - $category      : current category row
 * - $category_path : string "parent/child/grandchild"
 *
 * Architecture:
 * - Category prefix is configurable via "Category path" setting (default: "category").
 * - If prefix is empty, categories live at root level — $catBase becomes '/'.
 * - Breadcrumb always links to the configured prefix (or root if empty).
 */

$category_path = isset($category_path) && is_string($category_path) ? trim($category_path, '/') : '';

$_cp = (function_exists('get_category_path') && isset($GLOBALS['pdo'])) ? get_category_path($GLOBALS['pdo']) : 'category';
$catBase = $_cp !== '' ? '/' . $_cp . '/' : '/';
unset($_cp);

?>

<nav class="breadcrumb" aria-label="Breadcrumb">
    <a href="<?= $catBase ?>"><?= __('Category') ?></a>

    <?php if ($category_path !== ''):
        $accum = [];
        foreach (explode('/', $category_path) as $seg):
            if (trim($seg) === '') continue;
            $accum[] = rawurlencode($seg);
            $url = $catBase . implode('/', $accum) . '/';
    ?>&gt; <a href="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($seg, ENT_QUOTES, 'UTF-8') ?></a>
    <?php endforeach; endif; ?>
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
</style>