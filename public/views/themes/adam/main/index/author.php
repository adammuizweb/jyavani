<?php
// main/index/author.php - list authors (controller supplies $authors)
$authors = $authors ?? [];
?>
<div id="adamz-authidx-wrapper">
    <header class="adamz-authidx-header">
        <span class="adamz-authidx-subtitle">Creative Minds</span>
        <h1 class="adamz-authidx-page-title">Daftar Penulis</h1>
    </header>

    <?php if (empty($authors)): ?>
        <div style="text-align:center; padding:5rem; background:#f7fafc; border-radius:30px; color:#a0aec0;">
            <p>Belum ada penulis yang terdaftar.</p>
        </div>
    <?php else: ?>
        <div class="adamz-authidx-grid">
            <?php foreach ($authors as $a): 
                $link = !empty($a['username']) ? '/author/' . rawurlencode($a['username']) . '/' : '/author/' . rawurlencode($a['id']) . '/';
                $displayName = $a['name'] ?: $a['email'] ?: ($a['username'] ?? 'Penulis');
                $initial = strtoupper(mb_substr($displayName, 0, 1));
                $bio = trim($a['bio'] ?? '');
            ?>
                <a href="<?= $link ?>" class="adamz-authidx-card">
                    <div class="adamz-authidx-avatar-wrap">
                        <?php if (!empty($a['img'])): ?>
                            <img class="adamz-authidx-avatar" 
                                 src="<?= htmlspecialchars($a['img'], ENT_QUOTES, 'UTF-8') ?>" 
                                 alt="<?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?>">
                        <?php else: ?>
                            <div class="adamz-authidx-fallback"><?= $initial ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="adamz-authidx-info">
                        <h3 class="adamz-authidx-name"><?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?></h3>
                        
                        <?php if ($bio !== ''): ?>
                            <p class="adamz-authidx-bio">
                                <?= htmlspecialchars(mb_strimwidth($bio, 0, 80, '…'), ENT_QUOTES, 'UTF-8') ?>
                            </p>
                        <?php else: ?>
                            <p class="adamz-authidx-bio">Kontributor konten aktif yang berbagi wawasan bermanfaat.</p>
                        <?php endif; ?>
                        
                        <div class="adamz-authidx-view-btn">Lihat Profil &rarr;</div>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>