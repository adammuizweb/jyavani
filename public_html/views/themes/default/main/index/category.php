<?php
/**
 * vars:
 * - $categories : array of parent categories
 */
?>
<div class="container category-index">
    <h1 class="page-title">Kategori</h1>

    <?php if (empty($categories)): ?>
        <p class="empty-msg">Tidak ada kategori.</p>
    <?php else: ?>
        <ul class="category-list">
            <?php foreach ($categories as $cat): ?>
                <li class="category-item">
                    <a class="category-link" href="/category/<?= rawurlencode($cat['slug']) ?>/">
                        <div class="category-body">
                            <h3 class="category-name"><?= htmlspecialchars($cat['name'], ENT_QUOTES, 'UTF-8') ?></h3>

                            <?php if (!empty($cat['description'])): ?>
                                <p class="category-desc"><?= nl2br(htmlspecialchars($cat['description'], ENT_QUOTES, 'UTF-8')) ?></p>
                            <?php endif; ?>
                        </div>

                        <div class="category-meta">
                            <span class="arrow">›</span>
                        </div>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>

<style>
/* Layout container - keep consistent with site, but ensure readable width */
.container.category-index {
    max-width: 1120px;
    margin: 0 auto;
    padding: 2rem 1rem;
    box-sizing: border-box;
}

/* Page title */
.page-title {
    margin: 0 0 1.25rem 0;
    font-size: 2rem;
    line-height: 1.1;
    color: #1f2937; /* dark slate */
}

/* Empty state */
.empty-msg {
    color: #6b7280;
}

/* Grid list for categories */
.category-list {
    list-style: none;
    padding: 0;
    margin: 0;
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
    gap: 0.75rem;
}

/* Card-like items */
.category-item {
    background: #ffffff;
    border: 1px solid #eef2f6;
    border-radius: 8px;
    overflow: hidden;
    transition: transform .12s ease, box-shadow .12s ease;
    box-shadow: 0 0 0 rgba(0,0,0,0); /* for smoother hover transition */
}

/* Link covers whole card */
.category-link {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    text-decoration: none;
    color: inherit;
    padding: 1rem 1.1rem;
    height: 100%;
}

/* Hover / focus states */
.category-item:hover,
.category-item:focus-within {
    transform: translateY(-4px);
    box-shadow: 0 8px 20px rgba(31,41,55,0.06);
    border-color: #e6eefb;
}

/* Category content */
.category-body { flex: 1; min-width: 0; }

.category-name {
    margin: 0 0 0.35rem 0;
    font-size: 1.05rem;
    color: #0f172a;
    word-break: break-word;
}

/* description */
.category-desc {
    margin: 0;
    color: #6b7280;
    font-size: 0.95rem;
    line-height: 1.4;
    max-height: 3.6em; /* roughly 2 lines */
    overflow: hidden;
    text-overflow: ellipsis;
}

/* Right-side meta (chevron) */
.category-meta {
    flex: 0 0 28px;
    display:flex;
    align-items:center;
    justify-content:center;
    color: #60a5fa;
    font-size: 1.25rem;
}

/* clickable area visual affordance */
.category-link:focus,
.category-link:hover {
    outline: none;
}

/* small screens: tighter spacing */
@media (max-width: 520px) {
    .container.category-index { padding: 1.25rem .75rem; }
    .category-list { gap: 0.5rem; grid-template-columns: 1fr; }
    .category-link { padding: .9rem; }
}
</style>
