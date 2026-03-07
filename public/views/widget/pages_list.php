<?php
$pdo = $__pdo ?? null;
if (!$pdo instanceof PDO) return;

$title = $title ?? 'Halaman';
$limit = isset($limit) ? (int)$limit : 20;

$items = widget_fetch_pages($pdo, $limit);
?>
<div class="w-box w-pages">
  <div class="w-title"><?= widget_h($title) ?></div>

  <?php if (empty($items)): ?>
    <div class="w-empty">Belum ada halaman.</div>
  <?php else: ?>
    <ul class="w-list">
      <?php foreach ($items as $p): ?>
        <li><a href="/<?= widget_h($p['slug']) ?>/"><?= widget_h($p['title']) ?></a></li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</div>
