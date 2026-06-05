<?php
/**
 * Slot: list.gallery -> main/list/gallery.php
 * URL : /gallery/{path}/  (jika punya children)
 * Vars (dari PhotoController::renderCategory - branch):
 * - $gallery_home, $category, $children, $breadcrumbs, $category_path, $q
 */

$prefix = '/gallery/';
$category    = (isset($category) && is_array($category)) ? $category : ['name'=>__('Category'),'slug'=>''];
$children    = (isset($children) && is_array($children)) ? $children : [];
$breadcrumbs = (isset($breadcrumbs) && is_array($breadcrumbs)) ? $breadcrumbs : [];
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
  padding:14px;
  background:#fff;
  transition:transform .14s ease, box-shadow .14s ease;
}
.cat-card:hover{ transform:translateY(-2px); box-shadow:0 10px 24px rgba(15,23,42,.06); }
.cat-card a{ text-decoration:none;color:inherit;display:block; }
.cat-title{ font-weight:800;font-size:14px;color:#0f172a; }
.cat-count{ font-size:12px;color:#64748b;margin-top:6px; }
</style>

<div class="gallery-cats-wrap">
  <div class="gallery-bc">
    <a href="<?= htmlspecialchars($prefix, ENT_QUOTES, 'UTF-8') ?>"><?= __('Gallery') ?></a>
    <?php foreach ($breadcrumbs as $b): ?>
      <span> / </span>
      <a href="<?= htmlspecialchars((string)($b['url'] ?? '#'), ENT_QUOTES, 'UTF-8') ?>">
        <?= htmlspecialchars((string)($b['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
      </a>
    <?php endforeach; ?>
    <span> / </span>
    <strong><?= htmlspecialchars((string)($category['name'] ?? __('Category')), ENT_QUOTES, 'UTF-8') ?></strong>
  </div>

  <?php if (empty($children)): ?>
    <div style="color:#64748b;font-size:14px;padding:14px 4px"><?= __('No subcategories.') ?></div>
  <?php else: ?>
    <div class="cat-grid" role="list" aria-label="Subcategories">
      <?php foreach ($children as $ch): ?>
        <?php $href = (string)($ch['url'] ?? '#'); ?>
        <div class="cat-card" role="listitem">
          <a href="<?= htmlspecialchars($href, ENT_QUOTES, 'UTF-8') ?>">
            <div class="cat-title"><?= htmlspecialchars((string)($ch['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
            <div class="cat-count"><?= sprintf(__('%d Photos'), (int)($ch['cnt'] ?? 0)) ?></div>
          </a>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>