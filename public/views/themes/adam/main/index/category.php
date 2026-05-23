<?php
// main/index/category.php - list top-level categories (controller supplies $categories)
$categories = $categories ?? [];
?>
<div id="adamz-catidx-wrapper">
    <header class="adamz-catidx-header">
        <h1 class="adamz-catidx-title">Eksplorasi Topik</h1>
    </header>

    <?php if (empty($categories)): ?>
        <div style="padding: 4rem; text-align: center; background: #f8fafc; border-radius: 20px; color: #94a3b8;">
            <p>Belum ada kategori yang tersedia saat ini.</p>
        </div>
    <?php else: ?>
        <ul class="adamz-catidx-grid">
            <?php foreach ($categories as $cat): 
                $catUrl = "/category/" . rawurlencode($cat['slug']) . "/";
                $catName = htmlspecialchars($cat['name'], ENT_QUOTES, 'UTF-8');
            ?>
                <li class="adamz-catidx-item">
                    <a class="adamz-catidx-link" href="<?= $catUrl ?>">
                        <h3 class="adamz-catidx-name"><?= $catName ?></h3>

                        <?php if (!empty($cat['description'])): ?>
                            <p class="adamz-catidx-desc">
                                <?= htmlspecialchars(safe_strip_tags($cat['description']), ENT_QUOTES, 'UTF-8') ?>
                            </p>
                        <?php else: ?>
                            <p class="adamz-catidx-desc">Jelajahi kumpulan artikel menarik dalam topik <?= $catName ?>.</p>
                        <?php endif; ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>