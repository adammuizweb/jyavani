<?php
// /adiwira/theme/adam/partials/posts/single.php
// Updated: build full hierarchical category path for links
// NOTE: only the featured/display_image area was modified — rest kept intact.

if (!isset($post) || !is_array($post)) { return; }

/**
 * Helper: build full category path (e.g. parent/child/grandchild) from a category slug.
 * Returns string like "parent/child" (without leading/trailing slash) or the original slug on failure.
 *
 * Requires $pdo to be available in scope. If $pdo is not available, it returns the slug unchanged.
 */
if (!function_exists('build_category_full_path')) {
    function build_category_full_path(PDO $pdo = null, string $slug): string
    {
        $slug = trim((string)$slug);
        if ($slug === '') return '';

        // if no PDO provided, fallback to slug (best-effort)
        if (!($pdo instanceof PDO)) {
            return $slug;
        }

        try {
            // find the category by slug (could be non-unique in pathological DBs but our schema sets slug unique)
            $stm = $pdo->prepare("SELECT id, slug, parent_id FROM categories WHERE slug = :slug AND is_deleted = 0 LIMIT 1");
            $stm->execute([':slug' => $slug]);
            $row = $stm->fetch(PDO::FETCH_ASSOC);
            if (!$row) return $slug; // fallback

            $parts = [];
            // traverse up to root
            $cur = $row;
            while ($cur) {
                $parts[] = $cur['slug'];
                if (empty($cur['parent_id'])) break;

                $pstm = $pdo->prepare("SELECT id, slug, parent_id FROM categories WHERE id = :id AND is_deleted = 0 LIMIT 1");
                $pstm->execute([':id' => (int)$cur['parent_id']]);
                $cur = $pstm->fetch(PDO::FETCH_ASSOC);
                if (!$cur) break;
            }

            // parts currently [child, parent, root] -> reverse to [root, ..., child]
            $parts = array_reverse($parts);
            return implode('/', $parts);
        } catch (Throwable $e) {
            error_log("[build_category_full_path] " . $e->getMessage());
            return $slug;
        }
    }
}

// 1. Helper Data Processing
$authorName = !empty($post['author_name'])
    ? $post['author_name']
    : (!empty($post['author_username'])
        ? $post['author_username']
        : (!empty($post['author_email']) ? $post['author_email'] : 'Penulis'));
$authorImg  = !empty($post['author_img']) ? $post['author_img'] : null;
$authorSlug = !empty($post['author_username']) ? $post['author_username'] : (!empty($post['author_id']) ? (string)$post['author_id'] : '');
$authorUrl  = $authorSlug !== '' ? "/author/" . rawurlencode($authorSlug) . "/" : null;

$categories = [];
if (!empty($post['category_names']) && !empty($post['category_slugs'])) {
    $catNames = explode(', ', $post['category_names']);
    $catSlugs = explode(', ', $post['category_slugs']);
    foreach ($catNames as $i => $name) {
        if (isset($catSlugs[$i])) $categories[] = ['name' => $name, 'slug' => $catSlugs[$i]];
    }
}

// Estimasi waktu baca
$wordCount = str_word_count(strip_tags($post['content'] ?? ''));
$readTime  = max(1, ceil($wordCount / 200)); // minimal 1 menit

// Tanggal ISO 8601
$datePublished = !empty($post['created_at']) ? date('c', strtotime($post['created_at'])) : null;
$dateModified  = !empty($post['updated_at']) ? date('c', strtotime($post['updated_at'])) : null;

// URL gambar: gunakan display_image (controller sudah menyiapkannya).
// fallback ke thumbnail hanya jika display_image tidak ada (safety).
$thumbUrl = !empty($post['display_image']) ? $post['display_image'] : (!empty($post['thumbnail']) ? $post['thumbnail'] : '');
?>

<?php if ($datePublished): ?>
<script type="application/ld+json">
<?= json_encode([
  '@context' => 'https://schema.org',
  '@type' => 'BlogPosting',
  'mainEntityOfPage' => [
    '@type' => 'WebPage',
    '@id' => (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']
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
  'description' => mb_strimwidth(strip_tags($post['content'] ?? ''), 0, 160, '...')
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>
</script>
<?php endif; ?>

<article class="adam-post-single fade-up onload" data-duration="1500">

<?php
// ===== Breadcrumb typewrite timings =====
$bcStart   = 120;  // delay awal (ms)
$bcGap     = 320;  // jarak antar item (ms) -> naikkan kalau mau lebih “beda”
$bcDurLink = 420;  // durasi type link (ms)
$bcDurSep  = 220;  // durasi type separator "/" (ms)

$bcDelay = $bcStart;

// helper render (biar rapi)
$bcAttrs = function(int $dur, int $delay){
  return ' class="typewrite onload" data-anim-trigger="load" data-duration="'.$dur.'" data-delay="'.$delay.'"';
};
?>

<nav class="adam-breadcrumb" aria-label="Breadcrumb">
  <a href="/"<?= $bcAttrs($bcDurLink, $bcDelay) ?>>Beranda</a>
  <?php $bcDelay += $bcGap; ?>

  <span class="sep"<?= $bcAttrs($bcDurSep, $bcDelay) ?>>/</span>
  <?php $bcDelay += $bcGap; ?>

  <?php if (!empty($categories)): ?>
    <?php
      $firstCat = $categories[0];
      $fullPath = function_exists('build_category_full_path')
        ? build_category_full_path($GLOBALS['pdo'] ?? null, $firstCat['slug'])
        : $firstCat['slug'];

      // encode per-segmen, keep slash
      $segments = array_map('rawurlencode', explode('/', $fullPath));
      $catHref = '/category/' . implode('/', $segments) . '/';
    ?>
    <a href="<?= htmlspecialchars($catHref, ENT_QUOTES, 'UTF-8') ?>"<?= $bcAttrs($bcDurLink, $bcDelay) ?>>
      <?= htmlspecialchars($firstCat['name'], ENT_QUOTES, 'UTF-8') ?>
    </a>
    <?php $bcDelay += $bcGap; ?>

    <span class="sep"<?= $bcAttrs($bcDurSep, $bcDelay) ?>>/</span>
    <?php $bcDelay += $bcGap; ?>
  <?php endif; ?>

  <span class="current blur-in onload" data-duration="2400" aria-current="page"<?= $bcAttrs($bcDurLink, $bcDelay) ?>>Artikel</span>
</nav>


  <header class="adam-post-header">
    <?php if (!empty($categories)): ?>
      <div class="adam-cat-badges slide-up onload" data-anim-trigger="load">
        <?php foreach ($categories as $cat): ?>
          <?php
            $fullPath = function_exists('build_category_full_path') ? build_category_full_path($GLOBALS['pdo'] ?? null, $cat['slug']) : $cat['slug'];
            // ensure encoding of each segment but keep slashes
            $segments = array_map('rawurlencode', explode('/', $fullPath));
            $href = '/category/' . implode('/', $segments) . '/';
          ?>
          <a href="<?= htmlspecialchars($href, ENT_QUOTES, 'UTF-8') ?>" class="cat-badge">
            <?= htmlspecialchars($cat['name']) ?>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

<?php $postTitle = trim((string)($post['title'] ?? '')); ?>

<h1 class="adam-post-title wave-span onload"
    data-anim-trigger="load"
    data-wave-step="22"><?= htmlspecialchars($postTitle, ENT_QUOTES, 'UTF-8') ?></h1>

    <div class="adam-post-meta-row fade-up onload" data-anim-trigger="load">
      <div class="meta-author">
        <?php if (!empty($authorImg)): ?>
          <a href="<?= $authorUrl ?: '#' ?>"<?= $authorUrl ? '' : ' aria-disabled="true" tabindex="-1"' ?>>
            <img src="<?= htmlspecialchars($authorImg, ENT_QUOTES, 'UTF-8') ?>"
                 alt="<?= htmlspecialchars($authorName, ENT_QUOTES, 'UTF-8') ?>"
                 class="author-avatar flip-logo onload" data-fl-duration="2400">
          </a>
        <?php else: ?>
          <div class="author-avatar-placeholder"><?= strtoupper(substr($authorName, 0, 1)) ?></div>
        <?php endif; ?>
        <div class="meta-text">
          <span class="by">Ditulis oleh</span>
          <?php if ($authorUrl): ?>
            <a class="name typewrite" data-duration="400" href="<?= htmlspecialchars($authorUrl, ENT_QUOTES, 'UTF-8') ?>">
              <?= htmlspecialchars($authorName, ENT_QUOTES, 'UTF-8') ?>
            </a>
          <?php else: ?>
            <span class="name"><?= htmlspecialchars($authorName, ENT_QUOTES, 'UTF-8') ?></span>
          <?php endif; ?>
        </div>
      </div>

<?php
$gap = 400;
$d0 = 0;
$d1 = $gap;
$d2 = $gap * 2;
$readDelay = $d1; // default kalau updated tidak muncul
?>

<div class="meta-details">

    <div class="meta-item slide-up onload"
       data-anim-trigger="load"
       data-duration="300"
       data-delay="200">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
    <time datetime="<?= htmlspecialchars($datePublished ?? '', ENT_QUOTES, 'UTF-8') ?>">
      <?= htmlspecialchars($post['created_at'] ? date('d M Y', strtotime($post['created_at'])) : '', ENT_QUOTES, 'UTF-8') ?>
    </time>
  </div>

  <?php if (!empty($post['updated_at']) && $post['updated_at'] > $post['created_at']): ?>
    <div class="meta-item updated slide-up onload"
         title="Terakhir diperbarui"
         data-anim-trigger="load"
         data-duration="300"
         data-delay="<?= $d1 ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12a9 9 0 0 1-9 9m9-9a9 9 0 0 0-9-9m9 9H3m9 9a9 9 0 0 1-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 0 1 9-9"/></svg>
      Updated: <?= htmlspecialchars(date('d M Y', strtotime($post['updated_at'])), ENT_QUOTES, 'UTF-8') ?>
    </div>
    <?php $readDelay = $d2; ?>
  <?php endif; ?>

    <div class="meta-item slide-up onload"
       data-anim-trigger="load"
       data-duration="300"
       data-delay="<?= $readDelay ?>">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
    <?= (int)$readTime ?> menit baca
  </div>

</div>

    </div>
  </header>

<?php
// ===== Featured: YouTube > display_image > thumbnail (aman) =====
$ytid = null;
if (!empty($post['youtube'])) {
  if (preg_match('#(?:youtu\.be/|[?&]v=|/embed/)([A-Za-z0-9_\-]+)#i', $post['youtube'], $m)) {
    $ytid = $m[1];
  }
}

// animasi featured (pilih salah satu: flip-y / flip-x)
$featAnimClass = 'flip-y onload';      // <- ganti ke 'flip-x onload' kalau mau
$featDur  = 900;                       // ms
$featDelay = 780;                      // ms (sesuaikan biar nyambung dengan meta/breadcrumb)
$featEase = 'cubic-bezier(.16,1,.3,1)';
?>

<?php if ($ytid): ?>

  <figure class="adam-post-thumb <?= $featAnimClass ?>"
          data-anim-trigger="load"
          data-duration="<?= $featDur ?>"
          data-delay="<?= $featDelay ?>"
          data-ease="<?= $featEase ?>">
    <div style="position:relative;padding-top:56.25%">
      <iframe
        src="https://www.youtube.com/embed/<?= rawurlencode($ytid) ?>"
        style="position:absolute;inset:0;width:100%;height:100%;border:0"
        allowfullscreen
        loading="lazy"></iframe>
    </div>
  </figure>

<?php else: ?>

  <?php
    // fallback image: display_image dulu, baru thumbnail
    $imgSrcRaw = !empty($post['display_image']) ? $post['display_image'] : (!empty($post['thumbnail']) ? $post['thumbnail'] : null);

    // kalau benar-benar tidak ada gambar, jangan render apa-apa
    if ($imgSrcRaw):
      $imgAltRaw = $post['title'] ?? '';
      $turlRaw  = $post['display_image_target_url'] ?? null;
      $tattrRaw = $post['display_image_target_attribute'] ?? null;

      $imgSrc = htmlspecialchars($imgSrcRaw, ENT_QUOTES, 'UTF-8');
      $imgAlt = htmlspecialchars($imgAltRaw, ENT_QUOTES, 'UTF-8');
      $turl = $turlRaw ? htmlspecialchars($turlRaw, ENT_QUOTES, 'UTF-8') : null;
      $tattr = $tattrRaw ? htmlspecialchars($tattrRaw, ENT_QUOTES, 'UTF-8') : null;

      $targetAttr = $tattr ? ' target="' . $tattr . '"' : '';
      $relAttr = ($tattrRaw === '_blank') ? ' rel="noopener noreferrer"' : '';
  ?>

    <figure class="adam-post-thumb <?= $featAnimClass ?>"
            data-anim-trigger="load"
            data-duration="<?= $featDur ?>"
            data-delay="<?= $featDelay ?>"
            data-ease="<?= $featEase ?>">

      <?php if ($turl): ?>
        <a href="<?= $turl ?>"<?= $targetAttr ?><?= $relAttr ?>
           class="adam-thumb--linked"
           aria-label="Buka materi: <?= $imgAlt ?>">
          <img src="<?= $imgSrc ?>" alt="<?= $imgAlt ?>" loading="lazy">
        <div class="adam-thumb-badge">📄 <?= htmlspecialchars($typeLabel, ENT_QUOTES, 'UTF-8') ?></div>

        <div class="adam-thumb-overlay" aria-hidden="true">
          <div class="adam-thumb-cta">Lihat Materi</div>
        </div>

        <span class="adam-thumb-bottom" aria-hidden="false">Buka Materi</span>
        </a>
      <?php else: ?>
        <img src="<?= $imgSrc ?>" alt="<?= $imgAlt ?>" loading="lazy">
      <?php endif; ?>

    </figure>

  <?php endif; ?>

<?php endif; ?>



  <div class="adam-post-body">
    <?= $post['content'] ?? '' ?>
  </div>

  <section class="adam-post-footer">
    <div class="footer-actions">
      <a href="/" class="btn-back">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
        Kembali ke Beranda
      </a>
      <?php if ($authorUrl): ?>
        <a href="<?= htmlspecialchars($authorUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn-back" style="margin-left:.75rem">
          Lihat profil penulis
        </a>
      <?php endif; ?>
    </div>
  </section>
</article>

<div id="adam-img-modal" style="display:none;position:fixed;z-index:9999;left:0;top:0;width:100%;height:100%;background:rgba(0,0,0,0.8);align-items:center;justify-content:center;cursor:zoom-out;">
  <img id="adam-img-modal-img" src="" alt="" style="max-width:90%;max-height:90%;border-radius:8px;box-shadow:0 4px 30px rgba(0,0,0,0.4);">
</div>
<script src="/static/assets/js/modal-img.js" defer></script>
