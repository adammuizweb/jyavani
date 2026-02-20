<?php
/** views/author/posts.php
 * Variables:
 *   - array $author
 *   - array $posts
 *   - int   $page (1-based)
 *   - int   $total
 */
$perPage = 10;
$pages = max(1, (int)ceil(($total ?? 0) / $perPage));

// NOTE: remove email from author name fallback to avoid leaking email publicly
$authorName = $author['name'] ?? $author['username'] ?? 'Penulis';
$authorLink = !empty($author['username']) ? '/author/' . rawurlencode($author['username']) . '/' : '/author/' . rawurlencode($author['id']) . '/';
?>
<div class="author-container">

  <header class="author-header">
    <?php if (!empty($author['img'])): ?>
      <img class="author-photo" src="<?= htmlspecialchars($author['img'], ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($authorName, ENT_QUOTES, 'UTF-8') ?>">
    <?php else: ?>
      <div class="author-photo fallback"><?= htmlspecialchars(strtoupper(mb_substr($authorName, 0, 1)), ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <div class="author-head-meta">
      <h1 class="author-title"><?= htmlspecialchars($authorName, ENT_QUOTES, 'UTF-8') ?></h1>

      <?php
      // Show bio only if present (no email shown)
      $bio_raw = trim((string)($author['bio'] ?? ''));
      if ($bio_raw !== ''):
          // limit displayed length to 300 chars for header; keep line breaks
          $bio_display = mb_strimwidth($bio_raw, 0, 300, '…');
      ?>
        <div class="author-bio"><?= nl2br(htmlspecialchars($bio_display, ENT_QUOTES, 'UTF-8')) ?></div>
      <?php endif; ?>
    </div>
  </header>

  <?php if (empty($posts)): ?>
    <p>Tidak ada artikel.</p>
  <?php else: ?>
    <div class="author-posts-list">
      <?php foreach ($posts as $p):
$imgUrl = !empty($p['display_image']) ? $p['display_image'] : (!empty($p['thumbnail']) ? $p['thumbnail'] : null);
$postUrl = '/' . rawurlencode($p['slug']) . '/';
      ?>
        <article class="author-post-item">
            
<?php if ($imgUrl): ?>
  <a class="thumb-wrap" href="<?= $postUrl ?>">
    <img src="<?= htmlspecialchars($imgUrl, ENT_QUOTES, 'UTF-8') ?>"
         alt="<?= htmlspecialchars($p['title'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
  </a>
<?php endif; ?>

          <div class="post-info">
            <h2 class="post-title">
              <a href="<?= $postUrl ?>"><?= htmlspecialchars($p['title'], ENT_QUOTES, 'UTF-8') ?></a>
            </h2>

            <?php if (!empty($p['created_at'])): ?>
              <div class="post-meta"><?= htmlspecialchars($p['created_at'], ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>

            <p class="post-excerpt"><?= htmlspecialchars(mb_strimwidth(strip_tags($p['content'] ?? ''), 0, 220, '…'), ENT_QUOTES, 'UTF-8') ?></p>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <nav class="pagination" aria-label="Pagination">
    <?php if ($page > 1): ?>
      <a class="page-prev" href="?page=<?= max(1, $page - 1) ?>">&larr; Sebelumnya</a>
    <?php endif; ?>

    <span class="page-info">Halaman <?= $page ?> / <?= $pages ?></span>

    <?php if ($page < $pages): ?>
      <a class="page-next" href="?page=<?= min($pages, $page + 1) ?>">Berikutnya &rarr;</a>
    <?php endif; ?>
  </nav>
</div>

<style>
/* keep same container margin as index */
.author-container { max-width:920px; margin:0 auto; padding:0 16px; }

/* header */
.author-header { display:flex; gap:1rem; align-items:center; margin:1.5rem 0; }
.author-photo { width:84px; height:84px; object-fit:cover; border-radius:50%; }
.author-photo.fallback { width:84px; height:84px; border-radius:50%; background:#eee; display:flex; align-items:center; justify-content:center; font-size:28px; color:#666; }

.author-head-meta .author-title { margin:0; font-size:1.35rem; }

/* Bio (public) */
.author-bio {
  color:var(--muted);
  font-size:.98rem;
  margin-top:.4rem;
  max-width:700px;
  line-height:1.45;
  white-space:pre-wrap;
}

/* link row */
.author-link-row { margin-top:.6rem; }

/* posts list */
.author-posts-list { display:flex; flex-direction:column; gap:1.25rem; }
.author-post-item { display:flex; gap:1rem; align-items:flex-start; padding:12px 0; border-bottom:1px solid #eee; }
.thumb-wrap img { width:180px; height:110px; object-fit:cover; border-radius:6px; display:block; }
.post-info { flex:1; }
.post-title { margin:0 0 .35rem 0; font-size:1.15rem; }
.post-meta { color:#777; font-size:.9rem; margin-bottom:.5rem; }
.post-excerpt { margin:0; color: inherit; }

/* pagination */
.pagination { display:flex; gap:1rem; align-items:center; margin-top:1.25rem; }
.page-info { color:#666; font-size:.95rem; }
.page-prev, .page-next { color:#0073e6; text-decoration:none; }
@media (max-width:720px) {
  .author-header { flex-direction:row; gap:.75rem; }
  .thumb-wrap img { width:140px; height:88px; }
}
</style>
