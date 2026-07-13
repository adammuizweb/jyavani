<?php
// lokasi file /views/themes/default/main/list/post.php
// Expects:
//   $posts   => array of post rows (id, title, slug, content, thumbnail, youtube, created_at, ...)
//   $page    => current page number (int, optional)
//   $total   => total number of items (int, optional)
//   $perPage => items per page (int, optional)
//   $base    => base url for pagination links (optional, e.g. '/artikel/')
//   $site_context => optional context string (e.g. 'posts_list' or 'category:slug')

// safety: bail out if posts not provided
if (!isset($posts) || !is_array($posts)) {
    return;
}

// normalize pagination vars (controller should ideally provide these)
$page = max(1, (int)($page ?? 1));
$total = isset($total) ? (int)$total : count($posts);
$perPage = isset($perPage) ? (int)$perPage : 10;
$pages = max(1, (int)ceil($total / max(1, $perPage)));
$base = isset($base) ? rtrim($base, '/') . '/' : '/artikel/';

if (!function_exists('_theme_posts_excerpt')) {
    function _theme_posts_excerpt($html, $len = 200) {
        $text = trim(safe_strip_tags(html_entity_decode($html, ENT_QUOTES, "UTF-8")));
        if (mb_strlen($text) <= $len) return $text;
        return mb_substr($text, 0, $len) . '…';
    }
}

if (!function_exists('_theme_posts_youtube_id')) {
    function _theme_posts_youtube_id(?string $url): ?string {
        if (!$url) return null;
        $url = trim($url);
        if ($url === '') return null;
        if (preg_match('#youtu\.be/([A-Za-z0-9_\-]+)#i', $url, $m)) return $m[1];
        if (preg_match('#[?&]v=([A-Za-z0-9_\-]+)#i', $url, $m)) return $m[1];
        if (preg_match('#/(?:embed|v)/([A-Za-z0-9_\-]+)#i', $url, $m)) return $m[1];
        return null;
    }
}

if (!function_exists('_theme_posts_youtube_thumb')) {
    function _theme_posts_youtube_thumb(?string $url): ?string {
        $id = _theme_posts_youtube_id($url);
        if (!$id) return null;
        return "https://img.youtube.com/vi/{$id}/hqdefault.jpg";
    }
}

if (!function_exists('_theme_posts_extract_first_img')) {
    function _theme_posts_extract_first_img(?string $html): ?string {
        if (!$html) return null;
        // only attempt if DOMDocument exists
        if (!class_exists('DOMDocument')) return null;
        libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        $loaded = @$doc->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();
        if (!$loaded) return null;
        $imgs = $doc->getElementsByTagName('img');
        foreach ($imgs as $img) {
            $src = trim((string)$img->getAttribute('src'));
            if ($src !== '') return $src;
        }
        return null;
    }
}

if (!function_exists('_theme_posts_resolve_image')) {
    function _theme_posts_resolve_image(array $p): ?string {
        // 1) youtube
        if (!empty($p['youtube'])) {
            $yt = _theme_posts_youtube_thumb($p['youtube']);
            if ($yt) return $yt;
        }
        // 2) explicit display_image (controller may set)
        if (!empty($p['display_image'])) return $p['display_image'];
        // 3) thumbnail column
        if (!empty($p['thumbnail'])) return $p['thumbnail'];
        // 4) first image in content
        if (!empty($p['content'])) {
            $img = _theme_posts_extract_first_img($p['content']);
            if ($img) return $img;
        }
        return null;
    }
}
?>

<section class="adam-posts-list" style="max-width:980px;margin:0 auto;padding:1rem;">
  <div style="margin-bottom:1rem;">
    <h1 style="margin:0;font-size:1.6rem"><?= __('Articles') ?></h1>
    <?php if ($total): ?>
      <div style="color:#666;font-size:.9rem;margin-top:.35rem">
        <?= sprintf(__('%s items'), htmlspecialchars(number_format($total), ENT_QUOTES, 'UTF-8')) ?>
      </div>
    <?php endif; ?>
  </div>

  <?php if (empty($posts)): ?>
    <div style="padding:1rem;border-radius:8px;box-shadow:0 6px 18px rgba(0,0,0,.04);"><?= __('No articles.') ?></div>
  <?php else: ?>
    <div style="display:grid;grid-template-columns: 1fr;gap:1rem;">
      <?php foreach ($posts as $p):
          $title = htmlspecialchars($p['title'] ?? __('Untitled'), ENT_QUOTES, 'UTF-8');
          $slug  = function_exists('get_post_permalink') ? get_post_permalink($p) : '/' . rawurlencode($p['slug'] ?? '') . '/';
          $date  = isset($p['created_at'])
              ? htmlspecialchars(function_exists('format_datetime_id') ? format_datetime_id($p['created_at']) : $p['created_at'], ENT_QUOTES, 'UTF-8')
              : '';
          $displayImage = _theme_posts_resolve_image($p);
      ?>
        <article class="post-card">
          <?php if (!empty($displayImage)): ?>
            <div style="flex:0 0 160px;">
              <a href="<?= htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') ?>">
                <img src="<?= htmlspecialchars($displayImage, ENT_QUOTES, 'UTF-8') ?>" alt="<?= $title ?>" style="width:100%;height:auto;border-radius:6px;display:block;object-fit:cover;">
              </a>
            </div>
          <?php endif; ?>

          <div style="flex:1;">
            <h2 style="margin:0 0 .35rem;font-size:1.15rem;color: var(--accent);">
              <a href="<?= htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') ?>" style="text-decoration:none;"><?= $title ?></a>
            </h2>
            <div style="font-size:.85rem;margin-bottom:.5rem;"><?= $date ?></div>
            <p style="margin:0;line-height:1.6;font-size:.98rem;"><?= htmlspecialchars(_theme_posts_excerpt($p['content'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p>
            <p style="margin-top:.6rem">
              <a href="<?= htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') ?>" style="text-decoration:none;font-weight:600"><?= __('Read more →') ?></a>
            </p>
          </div>
        </article>
      <?php endforeach; ?>
    </div>

    <!-- pagination -->
    <?php if ($pages > 1): ?>
      <div aria-label="Pagination" style="margin-top:1rem;text-align:center">
        <div style="display:inline-flex;gap:.5rem;align-items:center;">
<?php if ($page > 1): ?>
  <a href="<?= htmlspecialchars($base . '?page=' . ($page-1), ENT_QUOTES, 'UTF-8') ?>" style="padding:.45rem .75rem;border-radius:6px;border:1px solid #eee;text-decoration:none"><?= __('← Prev') ?></a>
<?php endif; ?>

<span style="padding:.45rem .75rem;border-radius:6px;border:1px solid #eee"><?= sprintf(__('Page %d of %d'), $page, $pages) ?></span>

<?php if ($page < $pages): ?>
  <a href="<?= htmlspecialchars($base . '?page=' . ($page+1), ENT_QUOTES, 'UTF-8') ?>" style="padding:.45rem .75rem;border-radius:6px;border:1px;text-decoration:none"><?= __('Next →') ?></a>
<?php endif; ?>
        </div>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</section>
