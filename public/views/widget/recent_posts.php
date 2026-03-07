<?php
$pdo = $__pdo ?? null;
if (!$pdo instanceof PDO) return;

$title = $title ?? 'Artikel Terbaru';
$limit = isset($limit) ? (int)$limit : 5;

$items = widget_fetch_recent_posts($pdo, $limit, 'article');
?>
<div class="w-box w-recent-posts">
  <div class="w-title"><?= widget_h($title) ?></div>

  <?php if (empty($items)): ?>
    <div class="w-empty">Belum ada artikel.</div>
  <?php else: ?>
    <ul class="w-list">
      <?php foreach ($items as $p): ?>
        <li>
          <a href="/<?= widget_h($p['slug']) ?>/"><?= widget_h($p['title']) ?></a>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</div>
