<?php
// /views/themes/default/main/single/post.php
// Fixed: PHP 8+ deprecated optional parameter before required parameter
// Also cleaned breadcrumb attrs to avoid duplicate class attributes.

if (!isset($post) || !is_array($post)) {
    return;
}

/**
 * Helper: build full category path (e.g. parent/child/grandchild) from a category slug.
 * Returns string like "parent/child" (without leading/trailing slash) or the original slug on failure.
 *
 * Requires $pdo to be available in scope. If $pdo is not available, it returns the slug unchanged.
 */
if (!function_exists('build_category_full_path')) {
    function build_category_full_path(string $slug, ?PDO $pdo = null): string
    {
        $slug = trim((string)$slug);
        if ($slug === '') {
            return '';
        }

        // if no PDO provided, fallback to slug (best-effort)
        if (!($pdo instanceof PDO)) {
            return $slug;
        }

        try {
            // find the category by slug
            $stm = $pdo->prepare("
                SELECT id, slug, parent_id
                FROM categories
                WHERE slug = :slug
                  AND is_deleted = 0
                LIMIT 1
            ");
            $stm->execute([':slug' => $slug]);
            $row = $stm->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                return $slug;
            }

            $parts = [];
            $cur   = $row;

            // traverse up to root
            while ($cur) {
                $parts[] = $cur['slug'];

                if (empty($cur['parent_id'])) {
                    break;
                }

                $pstm = $pdo->prepare("
                    SELECT id, slug, parent_id
                    FROM categories
                    WHERE id = :id
                      AND is_deleted = 0
                    LIMIT 1
                ");
                $pstm->execute([':id' => (int)$cur['parent_id']]);
                $cur = $pstm->fetch(PDO::FETCH_ASSOC);

                if (!$cur) {
                    break;
                }
            }

            // parts currently [child, parent, root] -> reverse to [root, ..., child]
            return implode('/', array_reverse($parts));
        } catch (Throwable $e) {
            error_log('[build_category_full_path] ' . $e->getMessage());
            return $slug;
        }
    }
}

// Use local PDO if available, fallback to global
$pdoForCategoryPath = null;
if (isset($pdo) && $pdo instanceof PDO) {
    $pdoForCategoryPath = $pdo;
} elseif (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
    $pdoForCategoryPath = $GLOBALS['pdo'];
}

// 1. Helper Data Processing
$authorName = !empty($post['author_name'])
    ? $post['author_name']
    : (!empty($post['author_username'])
        ? $post['author_username']
        : (!empty($post['author_email']) ? $post['author_email'] : __('Author')));

$authorImg  = !empty($post['author_img']) ? $post['author_img'] : null;
$authorSlug = !empty($post['author_username']) ? $post['author_username'] : (!empty($post['author_id']) ? (string)$post['author_id'] : '');
$authorUrl  = $authorSlug !== '' ? "/author/" . rawurlencode($authorSlug) . "/" : null;

$categories = [];
if (!empty($post['category_names']) && !empty($post['category_slugs'])) {
    $catNames = explode(', ', (string)$post['category_names']);
    $catSlugs = explode(', ', (string)$post['category_slugs']);

    foreach ($catNames as $i => $name) {
        if (isset($catSlugs[$i])) {
            $categories[] = [
                'name' => $name,
                'slug' => $catSlugs[$i],
            ];
        }
    }
}

// Estimasi waktu baca
$wordCount = str_word_count(safe_strip_tags((string)($post['content'] ?? '')));
$readTime  = max(1, (int)ceil($wordCount / 200)); // minimal 1 menit

// Tanggal ISO 8601
$datePublished = !empty($post['created_at']) ? date('c', strtotime((string)$post['created_at'])) : null;
$dateModified  = !empty($post['updated_at']) ? date('c', strtotime((string)$post['updated_at'])) : null;

// URL gambar: gunakan display_image (controller sudah menyiapkannya).
// fallback ke thumbnail hanya jika display_image tidak ada (safety).
$thumbUrl = !empty($post['display_image'])
    ? $post['display_image']
    : (!empty($post['thumbnail']) ? $post['thumbnail'] : '');
?>

<?php if ($datePublished): ?>
<script type="application/ld+json">
<?= json_encode([
  '@context' => 'https://schema.org',
  '@type' => 'BlogPosting',
  'mainEntityOfPage' => [
    '@type' => 'WebPage',
    '@id' => (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . ($_SERVER['REQUEST_URI'] ?? '/')
  ],
  'headline' => $post['title'] ?? '',
  'image' => $thumbUrl ? $thumbUrl : null,
  'author' => [
    '@type' => 'Person',
    'name' => $authorName,
    'url' => $authorUrl ?: null,
  ],
  'publisher' => [
    '@type' => 'Organization',
    'name' => 'Jyavani',
    'logo' => [
      '@type' => 'ImageObject',
      'url' => 'https://jyavani.com/asset/img/jyavani.svg'
    ]
  ],
  'datePublished' => $datePublished,
  'dateModified' => $dateModified ?: $datePublished,
  'description' => mb_strimwidth(safe_strip_tags((string)($post['content'] ?? '')), 0, 160, '...')
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>
</script>
<?php endif; ?>

<article class="adam-post-single">

<?php
// ===== Breadcrumb typewrite timings =====
$bcStart   = 120;  // delay awal (ms)
$bcGap     = 320;  // jarak antar item (ms)
$bcDurLink = 420;  // durasi type link (ms)
$bcDurSep  = 220;  // durasi type separator "/" (ms)

$bcDelay = $bcStart;

// helper render data attrs
$bcDataAttrs = function (int $dur, int $delay): string {
    return ' data-anim-trigger="load" data-duration="' . $dur . '" data-delay="' . $delay . '"';
};
?>

<nav class="adam-breadcrumb" aria-label="Breadcrumb">
  <a href="/" class="typewrite onload"<?= $bcDataAttrs($bcDurLink, $bcDelay) ?>><?= __('Home') ?></a>
  <?php $bcDelay += $bcGap; ?>

  <span class="sep typewrite onload"<?= $bcDataAttrs($bcDurSep, $bcDelay) ?>>/</span>
  <?php $bcDelay += $bcGap; ?>

  <?php $catBase = (function_exists('get_category_path') && isset($GLOBALS['pdo'])) ? (($_cp = get_category_path($GLOBALS['pdo'])) !== '' ? '/' . $_cp . '/' : '/') : '/category/'; ?>
  <?php if (!empty($categories)): ?>
    <?php
      $firstCat = $categories[0];
      $fullPath = function_exists('build_category_full_path')
        ? build_category_full_path((string)$firstCat['slug'], $pdoForCategoryPath)
        : (string)$firstCat['slug'];

      // encode per-segmen, keep slash
      $segments = array_map('rawurlencode', explode('/', $fullPath));
      $catHref  = $catBase . implode('/', $segments) . '/';
    ?>
    <a href="<?= htmlspecialchars($catHref, ENT_QUOTES, 'UTF-8') ?>"
       class="typewrite onload"<?= $bcDataAttrs($bcDurLink, $bcDelay) ?>>
      <?= htmlspecialchars((string)$firstCat['name'], ENT_QUOTES, 'UTF-8') ?>
    </a>
    <?php $bcDelay += $bcGap; ?>

    <span class="sep typewrite onload"<?= $bcDataAttrs($bcDurSep, $bcDelay) ?>>/</span>
    <?php $bcDelay += $bcGap; ?>
  <?php endif; ?>

  <span class="current blur-in onload typewrite"
        aria-current="page"<?= $bcDataAttrs($bcDurLink, $bcDelay) ?>><?= __('Article') ?></span>
</nav>

<header class="adam-post-header">
  <?php if (!empty($categories)): ?>
    <div class="adam-cat-badges slide-up onload" data-anim-trigger="load">
      <?php foreach ($categories as $cat): ?>
        <?php
          $fullPath = function_exists('build_category_full_path')
            ? build_category_full_path((string)$cat['slug'], $pdoForCategoryPath)
            : (string)$cat['slug'];

          // ensure encoding of each segment but keep slashes
          $segments = array_map('rawurlencode', explode('/', $fullPath));
          $href     = $catBase . implode('/', $segments) . '/';
        ?>
        <a href="<?= htmlspecialchars($href, ENT_QUOTES, 'UTF-8') ?>" class="cat-badge">
          <?= htmlspecialchars((string)$cat['name'], ENT_QUOTES, 'UTF-8') ?>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

<?php $postTitle = trim((string)($post['title'] ?? '')); ?>

<h1 class="adam-post-title fade-up blur-in onload moving-line ml-text"
    data-anim-trigger="load"
    data-duration="700"
    data-delay="120">
  <?= htmlspecialchars($postTitle, ENT_QUOTES, 'UTF-8') ?>
</h1>

<?php
$showPostMeta = !function_exists('theme_mod') || theme_mod('show_post_meta', true);
$showAuthor   = !function_exists('theme_mod') || theme_mod('show_author', true);
$showDate     = !function_exists('theme_mod') || theme_mod('show_date', true);
$showReadTime = !function_exists('theme_mod') || theme_mod('show_read_time', true);
?>

<?php if ($showPostMeta): ?>
<div class="adam-post-meta-row fade-up onload" data-anim-trigger="load">
  <?php if ($showAuthor): ?>
  <div class="meta-author">
    <?php if (!empty($authorImg)): ?>
      <a href="<?= $authorUrl ?: '#' ?>"<?= $authorUrl ? '' : ' aria-disabled="true" tabindex="-1"' ?>>
        <img src="<?= htmlspecialchars((string)$authorImg, ENT_QUOTES, 'UTF-8') ?>"
             alt="<?= htmlspecialchars($authorName, ENT_QUOTES, 'UTF-8') ?>"
             class="author-avatar flip-logo onload" data-fl-duration="2400">
      </a>
    <?php else: ?>
      <div class="author-avatar-placeholder"><?= htmlspecialchars(strtoupper(substr($authorName, 0, 1)), ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <div class="meta-text">
      <span class="by"><?= __('Written by') ?></span>
      <?php if ($authorUrl): ?>
        <a class="name typewrite" data-duration="400" href="<?= htmlspecialchars($authorUrl, ENT_QUOTES, 'UTF-8') ?>">
          <?= htmlspecialchars($authorName, ENT_QUOTES, 'UTF-8') ?>
        </a>
      <?php else: ?>
        <span class="name"><?= htmlspecialchars($authorName, ENT_QUOTES, 'UTF-8') ?></span>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

<?php
$gap       = 400;
$d0        = 0;
$d1        = $gap;
$d2        = $gap * 2;
$readDelay = $d1; // default kalau updated tidak muncul
?>

<?php if ($showDate || $showReadTime): ?>
<div class="meta-details">

  <?php if ($showDate): ?>
  <div class="meta-item slide-up onload"
       data-anim-trigger="load"
       data-duration="300"
       data-delay="200">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
    <time datetime="<?= htmlspecialchars((string)($datePublished ?? ''), ENT_QUOTES, 'UTF-8') ?>">
      <?= htmlspecialchars(!empty($post['created_at']) ? date('d M Y', strtotime((string)$post['created_at'])) : '', ENT_QUOTES, 'UTF-8') ?>
    </time>
  </div>
  <?php endif; ?>

  <?php if ($showDate && !empty($post['updated_at']) && !empty($post['created_at']) && $post['updated_at'] > $post['created_at']): ?>
    <div class="meta-item updated slide-up onload"
         title="<?= __('Last updated') ?>"
         data-anim-trigger="load"
         data-duration="300"
         data-delay="<?= $d1 ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12a9 9 0 0 1-9 9m9-9a9 9 0 0 0-9-9m9 9H3m9 9a9 9 0 0 1-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 0 1 9-9"/></svg>
      <?= __('Updated:') ?> <?= htmlspecialchars(date('d M Y', strtotime((string)$post['updated_at'])), ENT_QUOTES, 'UTF-8') ?>
    </div>
    <?php $readDelay = $d2; ?>
  <?php endif; ?>

  <?php if ($showReadTime): ?>
  <div class="meta-item slide-up onload"
       data-anim-trigger="load"
       data-duration="300"
       data-delay="<?= $readDelay ?>">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
    <?= sprintf(__('%d min read'), (int)$readTime) ?>
  </div>
  <?php endif; ?>

</div>
<?php endif; ?>

</div>
<?php endif; ?>
</header>

<?php
// ===== Featured: YouTube > display_image > thumbnail =====
$ytid = null;
if (!empty($post['youtube'])) {
    if (preg_match('#(?:youtu\.be/|[?&]v=|/embed/)([A-Za-z0-9_\-]+)#i', (string)$post['youtube'], $m)) {
        $ytid = $m[1];
    }
}

$featDur   = 1100;  // ms
$featDelay = 780;   // ms
$featEase  = 'cubic-bezier(.16,1,.3,1)';

// Animasi utk YouTube (iframe)
$ytAnimClass = 'flip-y onload';

// Animasi utk image (frag)
$imgAnimClass = 'frag-reveal onload';

// fallback image: display_image dulu, baru thumbnail
$imgSrcRaw = !empty($post['display_image'])
    ? $post['display_image']
    : (!empty($post['thumbnail']) ? $post['thumbnail'] : null);
?>

<?php if ($ytid): ?>

  <figure class="adam-post-thumb <?= htmlspecialchars($ytAnimClass, ENT_QUOTES, 'UTF-8') ?>"
          data-anim-trigger="load"
          data-duration="<?= (int)$featDur ?>"
          data-delay="<?= (int)$featDelay ?>"
          data-ease="<?= htmlspecialchars($featEase, ENT_QUOTES, 'UTF-8') ?>">
    <div style="position:relative;padding-top:56.25%">
      <iframe
        src="https://www.youtube.com/embed/<?= rawurlencode($ytid) ?>"
        style="position:absolute;inset:0;width:100%;height:100%;border:0"
        allowfullscreen
        loading="lazy"></iframe>
    </div>
  </figure>

<?php elseif ($imgSrcRaw): ?>

  <?php
    $imgAltRaw = $post['title'] ?? '';
    $turlRaw   = $post['display_image_target_url'] ?? null;
    $tattrRaw  = $post['display_image_target_attribute'] ?? null;

    $imgSrc = htmlspecialchars((string)$imgSrcRaw, ENT_QUOTES, 'UTF-8');
    $imgAlt = htmlspecialchars((string)$imgAltRaw, ENT_QUOTES, 'UTF-8');
    $turl   = $turlRaw ? htmlspecialchars((string)$turlRaw, ENT_QUOTES, 'UTF-8') : null;
    $tattr  = $tattrRaw ? htmlspecialchars((string)$tattrRaw, ENT_QUOTES, 'UTF-8') : null;

    $targetAttr = $tattr ? ' target="' . $tattr . '"' : '';
    $relAttr    = ((string)$tattrRaw === '_blank') ? ' rel="noopener noreferrer"' : '';
  ?>

  <figure class="adam-post-thumb <?= htmlspecialchars($imgAnimClass, ENT_QUOTES, 'UTF-8') ?>"
          data-anim-trigger="load"
          data-duration="<?= (int)$featDur ?>"
          data-delay="<?= (int)$featDelay ?>"
          data-ease="<?= htmlspecialchars($featEase, ENT_QUOTES, 'UTF-8') ?>"
          data-frag-cols="14"
          data-frag-rows="8"
          data-frag-step="18"
          data-frag-spread="160"
          data-frag-rotate="16"
          data-frag-cleanup="1">

    <?php if ($turl): ?>
      <a href="<?= $turl ?>"<?= $targetAttr ?><?= $relAttr ?>
         class="adam-thumb--linked"
         aria-label="<?= sprintf(__('Open material: %s'), $imgAlt) ?>">
        <img src="<?= $imgSrc ?>" alt="<?= $imgAlt ?>" loading="lazy">
        <div class="adam-thumb-badge"><?= svg_ico('file-text', '', ['style' => 'width:14px;height:14px;vertical-align:middle;margin-right:4px']) ?> <?= htmlspecialchars((string)($typeLabel ?? __('Material')), ENT_QUOTES, 'UTF-8') ?></div>
        <div class="adam-thumb-overlay" aria-hidden="true"><div class="adam-thumb-cta"><?= __('View Material') ?></div></div>
        <span class="adam-thumb-bottom" aria-hidden="false"><?= __('Open Material') ?></span>
      </a>
    <?php else: ?>
      <img src="<?= $imgSrc ?>" alt="<?= $imgAlt ?>" loading="lazy">
    <?php endif; ?>

  </figure>

<?php endif; ?>

<div class="adam-post-body">
  <?= apply_filters('post_content', (string)($post['content'] ?? ''), $post ?? []) ?>
</div>

<section class="adam-post-footer">
  <div class="footer-actions">
    <a href="/" class="btn-back">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
      <?= __('Home') ?>
    </a>

    <?php if ($authorUrl): ?>
      <a href="<?= htmlspecialchars($authorUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn-back" style="margin-left:.75rem">
        <span class="profa">
          <svg xmlns="http://www.w3.org/2000/svg"
               viewBox="0 0 64 64"
               role="img"
               aria-labelledby="avatarTitle avatarDesc">
            <title id="avatarTitle"><?= __('Avatar') ?></title>
            <desc id="avatarDesc"><?= __('Simple user silhouette icon (head and shoulders)') ?></desc>
            <g fill="var(--let-base, #1f2937)">
              <circle cx="32" cy="18" r="10" />
              <path d="M6 54c0-14 13-26 26-26s26 12 26 26H6z" />
            </g>
          </svg>
        </span>
      </a>
    <?php endif; ?>
  </div>
</section>

</article>

<div id="adam-img-modal" style="display:none;position:fixed;z-index:9999;left:0;top:0;width:100%;height:100%;background:rgba(0,0,0,0.8);align-items:center;justify-content:center;cursor:zoom-out;">
  <img id="adam-img-modal-img" src="" alt="" style="max-width:90%;max-height:90%;border-radius:8px;box-shadow:0 4px 30px rgba(0,0,0,0.4);">
</div>
<script src="/static/assets/js/modal-img.js" defer></script>