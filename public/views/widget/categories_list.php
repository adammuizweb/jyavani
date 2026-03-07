<?php
$pdo = $__pdo ?? null;
if (!$pdo instanceof PDO) return;

$title = $title ?? 'Kategori';
$limit = isset($limit) ? (int)$limit : 50;
$only_parents = isset($only_parents) ? (bool)$only_parents : true;

$items = widget_fetch_categories($pdo, $limit, $only_parents);
?>
<div class="w-box w-categories">
  <div class="w-title"><?= widget_h($title) ?></div>

  <?php if (empty($items)): ?>
    <div class="w-empty">Belum ada kategori.</div>
  <?php else: ?>
    <ul class="w-list">
      <?php foreach ($items as $c): ?>
        <li><a href="/category/<?= widget_h($c['slug']) ?>/"><?= widget_h($c['name']) ?></a></li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</div>
