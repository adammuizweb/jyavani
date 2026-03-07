<?php
/**
 * lokasi file: /views/photo/photo-single.php
 * View: Single Photo Post (Album)
 * URL : /{slug}/  (post.type = 'photo')
 * Vars:
 *  - $post (array)
 *  - $items (array) => hasil fetchPhotoItems()
 */

$post  = (isset($post) && is_array($post)) ? $post : [];
$items = (isset($items) && is_array($items)) ? $items : [];

$title = (string)($post['title'] ?? 'Photo');
$items = array_values(array_filter($items, fn($it) => !empty($it['url'])));
?>
<style>
.photo-single-wrap{
  width:100%;
  max-width:1600px;
  margin:0 auto;
  padding:14px 14px 34px;
  font-family:system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif;
}
.photo-single-title{
  font-weight:900;
  font-size:18px;
  color:#0f172a;
  margin:0 0 10px;
}
.photo-single-muted{ color:#64748b;font-size:14px;padding:10px 0; }

.gallery-grid{ column-gap:12px; column-count:6; }
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
  max-height:520px;
  display:block;
  object-fit:cover;
  transition:transform .16s ease, filter .16s ease;
}
.gallery-item:hover img{
  transform:scale(1.01);
  filter:saturate(1.03);
}
</style>

<div class="photo-single-wrap">
  <h1 class="photo-single-title"><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h1>

  <?php if (empty($items)): ?>
    <div class="photo-single-muted">Belum ada foto di album ini.</div>
  <?php else: ?>
    <div class="gallery-grid" aria-label="Album photo grid">
      <?php foreach ($items as $it): ?>
        <?php
          $img = (string)($it['url'] ?? '');
          if ($img === '') continue;

          $alt = (string)($it['alt'] ?? '');
          if ($alt === '') $alt = (string)($it['caption'] ?? '');
          if ($alt === '') $alt = 'Photo';
        ?>
        <div class="gallery-item">
          <button
            type="button"
            class="gallery-btn"
            data-modal-img="1"
            data-src="<?= htmlspecialchars($img, ENT_QUOTES, 'UTF-8') ?>"
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
  <?php endif; ?>
</div>

<script src="/static/assets/modal-img.js" defer></script>