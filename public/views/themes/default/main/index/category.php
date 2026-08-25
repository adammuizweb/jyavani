<?php
/**
 * vars:
 * - $categories : array of parent categories
 */
?>
<div class="container category-index">
    <h1 class="page-title"><?= __('Categories') ?></h1>
    <p class="category-index-subtitle"><?= __('Explore topics written by our authors.') ?></p>

    <?php if (function_exists('theme_zone_has_position') && theme_zone_has_position($pdo, 'index.category', 'before_loop')): ?>
        <div class="tz-index-category-before"><?= theme_zone_render_position($pdo, 'index.category', 'before_loop') ?></div>
    <?php endif; ?>

    <?php if (empty($categories)): ?>
        <p class="empty-msg"><?= __('No categories.') ?></p>
    <?php else: ?>
        <ul class="category-list">
            <?php foreach ($categories as $cat): ?>
                <?php $categoryUrl = function_exists('get_category_permalink') ? get_category_permalink($pdo, $cat) : '/category/' . rawurlencode($cat['slug']) . '/'; if (!function_exists('get_category_permalink') && function_exists('localized_path_url')) $categoryUrl = localized_path_url($categoryUrl); ?>
                <li class="category-item">
                    <a class="category-link" href="<?= htmlspecialchars($categoryUrl, ENT_QUOTES, 'UTF-8') ?>">
                        <span class="category-name"><?= htmlspecialchars($cat['name'], ENT_QUOTES, 'UTF-8') ?></span>
                        <span class="category-count"><?= (int)($cat['post_count'] ?? 0) ?></span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <?php if (function_exists('theme_zone_has_position') && theme_zone_has_position($pdo, 'index.category', 'after_loop')): ?>
        <div class="tz-index-category-after"><?= theme_zone_render_position($pdo, 'index.category', 'after_loop') ?></div>
    <?php endif; ?>
</div>

<style>
.container.category-index {
    max-width: 800px;
    margin: 0 auto;
    padding: 2rem 1rem;
    box-sizing: border-box;
}

.page-title {
    margin: 0 0 .5rem;
    font-size: 1.75rem;
    line-height: 1.2;
}

.category-index-subtitle {
    margin: 0 0 2rem;
    opacity: .55;
    font-size: .95rem;
}

.category-list {
    list-style: none;
    padding: 0;
    margin: 0;
    display: grid;
    gap: 1px;
    background: var(--border);
    border-radius: 12px;
    overflow: hidden;
}

.category-item {
    background: var(--surface);
}

.category-link {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: .85rem 1.25rem;
    text-decoration: none;
    color: var(--text);
    transition: background .15s ease;
}

.category-link:hover,
.category-link:focus-visible {
    background: var(--surface-hover);
    text-decoration: none;
    outline: none;
}

.category-name {
    margin: 0;
    font-size: 1rem;
    font-weight: 500;
}

.category-count {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 28px;
    height: 24px;
    padding: 0 8px;
    border-radius: 999px;
    font-size: .8rem;
    font-weight: 600;
    background: color-mix(in srgb, var(--accent) 12%, transparent);
    color: var(--accent);
    flex-shrink: 0;
}

@media (max-width: 520px) {
    .container.category-index { padding: 1.25rem .75rem; }
    .category-link { padding: .75rem 1rem; }
}
</style>
