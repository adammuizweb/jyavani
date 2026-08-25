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
$category_breadcrumbs = isset($category_breadcrumbs) && is_array($category_breadcrumbs) ? $category_breadcrumbs : [];

$_cp = (function_exists('get_category_path') && isset($GLOBALS['pdo'])) ? get_category_path($GLOBALS['pdo']) : 'category';
$catBase = $_cp !== '' ? '/' . $_cp . '/' : '/';
$catBase = function_exists('localized_path_url') ? localized_path_url($catBase) : $catBase;
unset($_cp);

?>

<nav class="breadcrumb" aria-label="Breadcrumb">
    <a href="<?= htmlspecialchars($category_index_url ?? $catBase, ENT_QUOTES, 'UTF-8') ?>"><?= __('Category') ?></a>

    <?php if (!empty($category_breadcrumbs)): ?>
        <?php foreach ($category_breadcrumbs as $item): ?>
            &gt; <a href="<?= htmlspecialchars((string)($item['url'] ?? '#'), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string)($item['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></a>
        <?php endforeach; ?>
    <?php elseif ($category_path !== ''):
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
