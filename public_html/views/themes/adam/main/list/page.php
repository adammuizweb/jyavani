<?php
// /views/themes/adam/main/list/page.php

if (!isset($pages) || !is_array($pages)) return;

$page = max(1, (int)($page ?? 1));
$total = isset($total) ? (int)$total : count($pages);
$perPage = isset($perPage) ? (int)$perPage : 50;
$pagesCount = max(1, (int)ceil($total / max(1, $perPage)));
?>

<section id="adamz-pages-container">
    <header class="adamz-pages-header">
        <h1 class="adamz-pages-main-title">Daftar Halaman</h1>
        <?php if ($total): ?>
            <div class="adamz-pages-count">
                <?= htmlspecialchars(number_format($total), ENT_QUOTES, 'UTF-8') ?> Total Informasi
            </div>
        <?php endif; ?>
    </header>

    <?php if (empty($pages)): ?>
        <div style="text-align:center; padding:4rem; color:#cbd5e0;">
            <p>Belum ada halaman yang dapat ditampilkan.</p>
        </div>
    <?php else: ?>
        <ul class="adamz-pages-grid">
            <?php foreach ($pages as $index => $p):
                $title = htmlspecialchars($p['title'] ?? 'Untitled', ENT_QUOTES, 'UTF-8');
                $slug  = rawurlencode($p['slug'] ?? '');
                $dateRaw = isset($p['created_at']) ? $p['created_at'] : '';
                $dateLabel = $dateRaw ? date('d M Y', strtotime($dateRaw)) : 'Arsip';
                
                // Membuat nomor urut otomatis (01, 02, dst)
                $orderNumber = str_pad(($index + 1) + (($page - 1) * $perPage), 2, '0', STR_PAD_LEFT);
            ?>
                <li class="adamz-pages-item">
                    <div class="adamz-pages-item-main">
                        <span style="font-size: 0.75rem; font-weight: 800; color: #cbd5e0; margin-bottom: 0.5rem; display: block;">
                            ITEM #<?= $orderNumber ?>
                        </span>
                        
                        <a href="/<?= $slug ?>/" class="adamz-page-link">
                            <?= $title ?>
                        </a>

                        <div class="adamz-pages-meta">
                            <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                            </svg>
                            <span>Terbit: <?= $dateLabel ?></span>
                        </div>
                    </div>

                    <div class="adamz-pages-item-actions">
                        <a href="/<?= $slug ?>/" class="adamz-btn-open">
                            Lihat Detail →
                        </a>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>

        <?php if ($pagesCount > 1): ?>
            <nav class="adamz-pagination">
                <div class="adamz-pagination-inner">
                    <?php if ($page > 1): ?>
                        <a class="adamz-pagination-link" href="/halaman/?page=<?= $page-1 ?>">← Prev</a>
                    <?php endif; ?>

                    <span class="adamz-pagination-info"><?= $page ?> / <?= $pagesCount ?></span>

                    <?php if ($page < $pagesCount): ?>
                        <a class="adamz-pagination-link" href="/halaman/?page=<?= $page+1 ?>">Next →</a>
                    <?php endif; ?>
                </div>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</section>