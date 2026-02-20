<?php
/**
 * lokasi file: /views/photo/photo-categories.php
 * View: Gallery Categories (Branch)
 * URL : /gallery/{category_path}/  (jika punya children)
 * Vars:
 *  - $category, $children, $breadcrumbs
 */

$prefix = '/gallery/';

if (!isset($category) || !is_array($category)) $category = ['name' => 'Kategori', 'slug' => ''];
$children = (isset($children) && is_array($children)) ? $children : [];
$breadcrumbs = (isset($breadcrumbs) && is_array($breadcrumbs)) ? $breadcrumbs : [];

function gallery_path(string $prefix, array $slugs): string {
    $slugs = array_values(array_filter($slugs, fn($s) => trim((string)$s) !== ''));
    $enc = array_map(fn($s) => rawurlencode((string)$s), $slugs);
    return rtrim($prefix, '/') . '/' . (empty($enc) ? '' : implode('/', $enc) . '/') ;
}

// build current slugs from breadcrumb chain + current
$crumbSlugs = [];
foreach ($breadcrumbs as $b) {
    if (!empty($b['slug'])) $crumbSlugs[] = (string)$b['slug'];
}
$curSlug = (string)($category['slug'] ?? '');
$currentSlugs = array_merge($crumbSlugs, ($curSlug !== '' ? [$curSlug] : []));
$currentPath = gallery_path($prefix, $currentSlugs);
?>

<style>
.gallery-cats-wrap{
  width:100%;
  max-width:1400px;
  margin:0 auto;
  padding:14px 14px 34px;
  font-family:system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif;
}

.gallery-bc{
  font-size:12px;
  color:#64748b;
  margin:2px 0 12px;
}
.gallery-bc a{ color:#64748b; text-decoration:none; }
.gallery-bc a:hover{ text-decoration:underline; }

.cat-grid{
  display:grid;
  grid-template-columns:repeat(auto-fill,minmax(220px,1fr));
  gap:12px;
  margin-top:10px;
}
.cat-card{
  border:1px solid rgba(15,23,42,.08);
  border-radius:14px;
  padding:14px 14px;
  background:#fff;
  transition:transform .14s ease, box-shadow .14s ease;
}
.cat-card:hover{
  transform:translateY(-2px);
  box-shadow:0 10px 24px rgba(15,23,42,.06);
}
.cat-title{ font-weight:800;font-size:14px;color:#0f172a; }
.cat-count{ font-size:12px;color:#64748b;margin-top:6px; }
</style>

<div class="gallery-cats-wrap">

  <div class="gallery-bc">
    <a href="<?= htmlspecialchars($prefix, ENT_QUOTES, 'UTF-8') ?>">Gallery</a>
    <?php
      // breadcrumb links must be cumulative: /gallery/news/ , /gallery/news/photo/
      $acc = [];
      foreach ($breadcrumbs as $b):
        $acc[] = (string)($b['slug'] ?? '');
        $href = gallery_path($prefix, $acc);
    ?>
      <span> / </span>
      <a href="<?= htmlspecialchars($href, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($b['name'] ?? '', ENT_QUOTES, 'UTF-8') ?></a>
    <?php endforeach; ?>
    <span> / </span>
    <strong><?= htmlspecialchars($category['name'] ?? '', ENT_QUOTES, 'UTF-8') ?></strong>
  </div>

  <?php if (empty($children)): ?>
    <div style="color:#64748b;font-size:14px;padding:14px 4px">Tidak ada subkategori.</div>
  <?php else: ?>
    <div class="cat-grid" role="list" aria-label="Subcategories">
      <?php foreach ($children as $ch): ?>
        <?php
          $chSlug = (string)($ch['slug'] ?? '');
          $href = rtrim($currentPath, '/') . '/' . rawurlencode($chSlug) . '/';
        ?>
        <div class="cat-card" role="listitem">
          <a href="<?= htmlspecialchars($href, ENT_QUOTES, 'UTF-8') ?>" style="text-decoration:none;color:inherit;display:block">
            <div class="cat-title"><?= htmlspecialchars($ch['name'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
            <div class="cat-count"><?= (int)($ch['cnt'] ?? 0) ?> Foto</div>
          </a>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

</div>
