<?php
/**
 * lokasi file: /views/photo/photo-list.php
 * View: Gallery Leaf (Photos Grid) - MODAL ONLY
 * URL : /gallery/{category_path}/  (leaf category)
 * Vars:
 *  - $category, $items, $page, $totalPages, $breadcrumbs
 */

$prefix = '/gallery/';

if (!isset($category) || !is_array($category)) $category = ['name' => 'Gallery', 'slug' => ''];
$items = (isset($items) && is_array($items)) ? $items : [];
$breadcrumbs = (isset($breadcrumbs) && is_array($breadcrumbs)) ? $breadcrumbs : [];

$page = max(1, (int)($page ?? 1));
$totalPages = max(1, (int)($totalPages ?? 1));

function gallery_path(string $prefix, array $slugs): string {
    $slugs = array_values(array_filter($slugs, fn($s) => trim((string)$s) !== ''));
    $enc = array_map(fn($s) => rawurlencode((string)$s), $slugs);
    return rtrim($prefix, '/') . '/' . (empty($enc) ? '' : implode('/', $enc) . '/') ;
}

// current path = breadcrumbs slugs + current slug
$crumbSlugs = [];
foreach ($breadcrumbs as $b) {
    if (!empty($b['slug'])) $crumbSlugs[] = (string)$b['slug'];
}
$curSlug = (string)($category['slug'] ?? '');
$currentSlugs = array_merge($crumbSlugs, ($curSlug !== '' ? [$curSlug] : []));
$base = gallery_path($prefix, $currentSlugs);

$buildPageUrl = function(int $n) use ($base) {
    $n = max(1, $n);
    if ($n <= 1) return $base;
    return rtrim($base, '/') . '/page/' . $n . '/';
};
?>

<style>
.gallery-wrap{
  width:100%;
  max-width:1600px;
  margin:0 auto;
  padding:14px 14px 34px;
  font-family:system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif;
}

.gallery-bc{
  font-size:12px;
  color:#64748b;
  margin:2px 0 10px;
}
.gallery-bc a{ color:#64748b; text-decoration:none; }
.gallery-bc a:hover{ text-decoration:underline; }

/* Masonry ala Google Images */
.gallery-grid{
  column-gap:12px;
  column-count:6;
}
@media (max-width: 1600px){ .gallery-grid{ column-count:5; } }
@media (max-width: 1300px){ .gallery-grid{ column-count:4; } }
@media (max-width: 980px){  .gallery-grid{ column-count:3; } }
@media (max-width: 640px){  .gallery-grid{ column-count:2; } }
@media (max-width: 420px){  .gallery-grid{ column-count:1; } }

.gallery-item{
  break-inside:avoid;
  margin:0 0 12px;
  border-radius:14px;
  overflow:hidden;
  background:#f1f5f9;
  border:1px solid rgba(15,23,42,.06);
}

.gallery-btn{
  width:100%;
  border:0;
  padding:0;
  background:transparent;
  cursor:pointer;
  display:block;
}
.gallery-btn:focus-visible{
  outline:3px solid rgba(59,130,246,.35);
  outline-offset:3px;
  border-radius:14px;
}

.gallery-item img{
  width:100%;
  height:auto;
  max-height:400px;
  display:block;
  object-fit:cover;
  transform:translateZ(0);
  transition:transform .16s ease, filter .16s ease;
}
.gallery-item:hover img{
  transform:scale(1.01);
  filter:saturate(1.03);
}

.gallery-pager{
  margin-top:16px;
  display:flex;
  justify-content:center;
  gap:18px;
  font-size:13px;
  color:#64748b;
}
.gallery-pager a{
  color:#0f172a;
  text-decoration:none;
  font-weight:800;
}
.gallery-pager a:hover{ text-decoration:underline; }

.gallery-muted{ color:#64748b;font-size:14px;padding:14px 4px; }
</style>

<div class="gallery-wrap">
CTRL
  <div class="gallery-bc">
    <a href="<?= htmlspecialchars($prefix, ENT_QUOTES, 'UTF-8') ?>">Gallery</a>
    <?php
      $acc = [];
      foreach ($breadcrumbs as $b):
        $acc[] = (string)($b['slug'] ?? '');
        $href = gallery_path($prefix, $acc);
    ?>
      <span> / </span>
      <a href="<?= htmlspecialchars($href, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($b['name'] ?? '', ENT_QUOTES, 'UTF-8') ?></a>
    <?php endforeach; ?>
    <span> / </span>
    <strong><?= htmlspecialchars($category['name'] ?? 'Kategori', ENT_QUOTES, 'UTF-8') ?></strong>
  </div>

  <?php if (empty($items)): ?>
    <div class="gallery-muted">Belum ada foto pada album ini.</div>
  <?php else: ?>
    <div class="gallery-grid" aria-label="Gallery photo grid">
      <?php foreach ($items as $it): ?>
        <?php
          $img = (string)($it['url'] ?? '');
          if ($img === '') continue;

          $alt = (string)($it['alt'] ?? '');
          if ($alt === '') $alt = (string)($it['caption'] ?? '');
          if ($alt === '') $alt = 'Photo';

          $full = $img;
        ?>
        <div class="gallery-item">
          <button
            type="button"
            class="gallery-btn"
            data-modal-img="1"
            data-src="<?= htmlspecialchars($full, ENT_QUOTES, 'UTF-8') ?>"
            data-alt="<?= htmlspecialchars($alt, ENT_QUOTES, 'UTF-8') ?>"
            aria-label="<?= htmlspecialchars($alt, ENT_QUOTES, 'UTF-8') ?>"
          >
            <img
              src="<?= htmlspecialchars($img, ENT_QUOTES, 'UTF-8') ?>"
              alt="<?= htmlspecialchars($alt, ENT_QUOTES, 'UTF-8') ?>"
              loading="lazy"
            >
          </button>
        </div>
      <?php endforeach; ?>
    </div>

    <?php if ($totalPages > 1): ?>
      <nav class="gallery-pager" aria-label="Pagination">
        <?php if ($page > 1): ?>
          <a href="<?= htmlspecialchars($buildPageUrl($page-1), ENT_QUOTES, 'UTF-8') ?>">&larr; Sebelumnya</a>
        <?php endif; ?>
        <span>Album <?= (int)$page ?> / <?= (int)$totalPages ?></span>
        <?php if ($page < $totalPages): ?>
          <a href="<?= htmlspecialchars($buildPageUrl($page+1), ENT_QUOTES, 'UTF-8') ?>">Berikutnya &rarr;</a>
        <?php endif; ?>
      </nav>
    <?php endif; ?>
  <?php endif; ?>

</div>

<script src="/static/assets/modal-img.js" defer></script>
