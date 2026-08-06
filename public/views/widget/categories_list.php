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
      <?php $catBase = (function_exists('get_category_path') && isset($GLOBALS['pdo'])) ? (($_cp = get_category_path($GLOBALS['pdo'])) !== '' ? '/' . $_cp . '/' : '/') : '/category/'; ?>
      <?php foreach ($items as $c): ?>
        <?php $url = apply_filters('widget_category_url', $catBase . rawurlencode($c['slug']) . '/', $c, $pdo); ?>
        <li><a href="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>"><?= widget_h($c['name']) ?></a></li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</div>
