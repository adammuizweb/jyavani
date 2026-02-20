<?php
$pdo = $__pdo ?? null;
if (!$pdo instanceof PDO) return;

$title = $title ?? 'Artikel Saya';
$limit = isset($limit) ? (int)$limit : 8;

// sumber user_id:
// 1) param widget: user_id=...
// 2) session user_id kalau ada
$user_id = null;
if (isset($user_id)) $user_id = (int)$user_id;
elseif (!empty($_SESSION['user_id'])) $user_id = (int)$_SESSION['user_id'];

if (!$user_id) {
    // kalau tidak ada user login, jangan tampilkan widget ini
    return;
}

$items = widget_fetch_recent_posts($pdo, $limit, 'article', $user_id);
?>
<div class="w-box w-author-posts">
  <div class="w-title"><?= widget_h($title) ?></div>

  <?php if (empty($items)): ?>
    <div class="w-empty">Belum ada artikel dari user ini.</div>
  <?php else: ?>
    <ul class="w-list">
      <?php foreach ($items as $p): ?>
        <li><a href="/<?= widget_h($p['slug']) ?>/"><?= widget_h($p['title']) ?></a></li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</div>
