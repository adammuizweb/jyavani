<?php
$pdo = $__pdo ?? null;
if (!$pdo instanceof PDO) return;

$title = $title ?? 'Artikel Terbaru';
$limit = isset($limit) ? (int)$limit : 5;

$items = widget_fetch_recent_posts($pdo, $limit, 'article');

function _recent_posts_thumb(?string $content, ?string $thumbnail): string {
    if (!empty($thumbnail)) return $thumbnail;
    if (empty($content) || !class_exists('DOMDocument')) return '';
    $prev = libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    $loaded = @$doc->loadHTML('<?xml encoding="utf-8" ?>' . $content);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    if (!$loaded) return '';
    $imgs = $doc->getElementsByTagName('img');
    foreach ($imgs as $img) {
        $src = trim((string)$img->getAttribute('src'));
        if ($src !== '') return $src;
    }
    return '';
}

function _recent_posts_date(?string $date): string {
    if (empty($date)) return '';
    $ts = strtotime($date);
    if (!$ts) return '';
    return function_exists('format_date_id') ? format_date_id($ts) : date('d M Y', $ts);
}
?>
<div class="w-box w-recent-posts">
  <div class="w-title"><?= widget_h($title) ?></div>

  <?php if (empty($items)): ?>
    <div class="w-empty">Belum ada artikel.</div>
  <?php else: ?>
    <ul class="w-recent-list">
      <?php foreach ($items as $p):
        $postUrl = function_exists('get_post_permalink')
          ? get_post_permalink($p)
          : '/' . rawurlencode($p['slug'] ?? '') . '/';
        $thumb = _recent_posts_thumb($p['content'] ?? null, $p['thumbnail'] ?? null);
        $dateLabel = _recent_posts_date($p['created_at'] ?? null);
      ?>
        <li class="w-recent-item">
          <?php if ($thumb !== ''): ?>
            <a href="<?= widget_h($postUrl) ?>" class="w-recent-thumb" aria-hidden="true">
              <img src="<?= widget_h($thumb) ?>" alt="" loading="lazy" decoding="async">
            </a>
          <?php endif; ?>
          <div class="w-recent-body">
            <a href="<?= widget_h($postUrl) ?>" class="w-recent-title"><?= widget_h($p['title'] ?? '') ?></a>
            <?php if ($dateLabel !== ''): ?>
              <span class="w-recent-date"><?= widget_h($dateLabel) ?></span>
            <?php endif; ?>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</div>
