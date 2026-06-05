<?php
/**
 * lokasi file: /views/photo/photo-index.php
 * View: Gallery Index
 * URL : /gallery/
 * Vars:
 *  - $categories (kategori utama / top-level)
 *  - $recent (photo posts terbaru; tiap item idealnya punya display_image)
 *  - optional: $q
 */

$prefix = '/gallery/';

$categories = (isset($categories) && is_array($categories)) ? $categories : [];
$items = (isset($recent) && is_array($recent)) ? $recent : [];

if (count($items) > 30) $items = array_slice($items, 0, 30);

function gallery_path(string $prefix, array $slugs): string {
    $slugs = array_values(array_filter($slugs, fn($s) => trim((string)$s) !== ''));
    $enc = array_map(fn($s) => rawurlencode((string)$s), $slugs);
    return rtrim($prefix, '/') . '/' . (empty($enc) ? '' : implode('/', $enc) . '/') ;
}
?>

<style>
.gallery-wrap{
  width:100%;
  max-width:1600px;
  margin:0 auto;
  padding:14px 14px 34px;
  font-family:system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif;
}

/* ===== Categories (top) ===== */
.gallery-cats{
  display:grid;
  grid-template-columns:repeat(auto-fill,minmax(220px,1fr));
  gap:12px;
  margin-bottom:16px;
}
.gallery-cat{
  border:1px solid rgba(15,23,42,.08);
  border-radius:14px;
  padding:14px 14px;
  background:#fff;
  transition:transform .14s ease, box-shadow .14s ease;
}
.gallery-cat:hover{
  transform:translateY(-2px);
  box-shadow:0 10px 24px rgba(15,23,42,.06);
}
.gallery-cat a{ text-decoration:none;color:inherit;display:block; }
.gallery-cat-title{ font-weight:800;font-size:14px;color:#0f172a; }
.gallery-cat-count{ font-size:12px;color:#64748b;margin-top:6px; }

/* ===== Masonry grid ala Google Images ===== */
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
.gallery-item a{ display:block; text-decoration:none; color:inherit; }
.gallery-item img{
  width:100%;
  height:auto;
  max-height:400px;     /* sesuai request */
  display:block;
  object-fit:cover;
  transform:translateZ(0);
  transition:transform .16s ease, filter .16s ease;
}
.gallery-item:hover img{
  transform:scale(1.01);
  filter:saturate(1.03);
}

.gallery-muted{
  color:#64748b;
  font-size:14px;
  padding:14px 4px;
}
</style>

<div class="gallery-wrap">

  <?php if (!empty($categories)): ?>
    <div class="gallery-cats" role="list" aria-label="Gallery categories">
      <?php foreach ($categories as $c): ?>
        <?php
          $href = gallery_path($prefix, [$c['slug'] ?? '']);
        ?>
        <div class="gallery-cat" role="listitem">
          <a href="<?= htmlspecialchars($href, ENT_QUOTES, 'UTF-8') ?>">
            <div class="gallery-cat-title"><?= htmlspecialchars($c['name'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
            <div class="gallery-cat-count"><?= sprintf(__('%d Photos'), (int)($c['cnt'] ?? 0)) ?></div>
          </a>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if (empty($items)): ?>
    <div class="gallery-muted"><?= __('No photos yet.') ?></div>
  <?php else: ?>
    <div class="gallery-grid" aria-label="Gallery photo grid">
      <?php foreach ($items as $p): ?>
        <?php
          $img = $p['display_image'] ?? ($p['thumbnail'] ?? '');
          if (!$img) continue; // hanya featured
          $slug = $p['slug'] ?? '';
          $href = $slug !== '' ? ('/' . rawurlencode($slug) . '/') : '#';
          $alt  = $p['title'] ?? __('Photo');
        ?>
        <div class="gallery-item">
          <a href="<?= htmlspecialchars($href, ENT_QUOTES, 'UTF-8') ?>" aria-label="<?= htmlspecialchars($alt, ENT_QUOTES, 'UTF-8') ?>">
            <img src="<?= htmlspecialchars($img, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($alt, ENT_QUOTES, 'UTF-8') ?>" loading="lazy">
          </a>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

</div>
