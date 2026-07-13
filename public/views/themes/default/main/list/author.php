<?php
// /views/themes/default/main/list/author.php
/**
 * Expected vars:
 * - array $author
 * - array $posts
 * - int   $page
 * - int   $total
 */

// safe defaults
$author = (isset($author) && is_array($author)) ? $author : [];
$posts  = (isset($posts) && is_array($posts)) ? $posts : [];
$page   = isset($page) ? max(1, (int)$page) : 1;
$total  = isset($total) ? max(0, (int)$total) : count($posts);

$perPage = 10;
$pages   = max(1, (int)ceil($total / $perPage));

// safe author fields
$authorName = (string)($author['name'] ?? $author['username'] ?? __('Author'));
$authorImg  = (string)($author['img'] ?? '');
$authorBio  = trim((string)($author['bio'] ?? ''));

// build author link safely
$authorUsername = trim((string)($author['username'] ?? ''));
$authorId       = isset($author['id']) ? (string)$author['id'] : '';
$authorSlug     = $authorUsername !== '' ? $authorUsername : $authorId;
$authorLink     = $authorSlug !== '' ? '/author/' . rawurlencode($authorSlug) . '/' : null;
?>

<div class="author-container">

  <header class="author-header">
    <?php if ($authorImg !== ''): ?>
      <img
        class="author-photo"
        src="<?= htmlspecialchars($authorImg, ENT_QUOTES, 'UTF-8') ?>"
        alt="<?= htmlspecialchars($authorName, ENT_QUOTES, 'UTF-8') ?>"
      >
    <?php else: ?>
      <div class="author-photo fallback">
        <?= htmlspecialchars(strtoupper(mb_substr($authorName, 0, 1)), ENT_QUOTES, 'UTF-8') ?>
      </div>
    <?php endif; ?>

    <div class="author-head-meta">
      <h1 class="author-title"><?= htmlspecialchars($authorName, ENT_QUOTES, 'UTF-8') ?></h1>

      <?php if ($authorBio !== ''): ?>
        <?php $bioDisplay = mb_strimwidth($authorBio, 0, 300, '…'); ?>
        <div class="author-bio"><?= nl2br(htmlspecialchars($bioDisplay, ENT_QUOTES, 'UTF-8')) ?></div>
      <?php endif; ?>
    </div>
  </header>

  <?php if (empty($posts)): ?>
    <p><?= __('No articles.') ?></p>
  <?php else: ?>
    <div class="author-posts-list">
      <?php foreach ($posts as $p): ?>
        <?php
          if (!is_array($p)) continue;

          $slug      = (string)($p['slug'] ?? '');
          $titleRaw  = (string)($p['title'] ?? __('Untitled'));
          $content   = (string)($p['content'] ?? '');
          $createdAt = (string)($p['created_at'] ?? '');

          $imgUrl  = !empty($p['display_image'])
            ? (string)$p['display_image']
            : (!empty($p['thumbnail']) ? (string)$p['thumbnail'] : '');

          $postUrl = $slug !== ''
              ? (function_exists('get_post_permalink') ? get_post_permalink($p) : '/' . rawurlencode($slug) . '/')
              : '#';
          $title   = htmlspecialchars($titleRaw, ENT_QUOTES, 'UTF-8');
        ?>
        <article class="post-card">
          <?php if ($imgUrl !== ''): ?>
            <a class="thumb-wrap" href="<?= htmlspecialchars($postUrl, ENT_QUOTES, 'UTF-8') ?>">
              <img
                src="<?= htmlspecialchars($imgUrl, ENT_QUOTES, 'UTF-8') ?>"
                alt="<?= $title ?>"
              >
            </a>
          <?php endif; ?>

          <div class="post-info">
            <h2 class="post-title">
              <a href="<?= htmlspecialchars($postUrl, ENT_QUOTES, 'UTF-8') ?>"><?= $title ?></a>
            </h2>

            <?php if ($createdAt !== ''): ?>
              <div class="post-meta"><?= htmlspecialchars($createdAt, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>

            <p class="post-excerpt">
              <?= htmlspecialchars(mb_strimwidth(safe_strip_tags(html_entity_decode($content, ENT_QUOTES, "UTF-8")), 0, 220, '…'), ENT_QUOTES, 'UTF-8') ?>
            </p>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <nav class="pagination" aria-label="Pagination">
    <?php if ($page > 1): ?>
      <a class="page-prev" href="?page=<?= max(1, $page - 1) ?>"><?= __('← Previous') ?></a>
    <?php endif; ?>

    <span class="page-info"><?= sprintf(__('Page %d / %d'), $page, $pages) ?></span>

    <?php if ($page < $pages): ?>
      <a class="page-next" href="?page=<?= min($pages, $page + 1) ?>"><?= __('Next →') ?></a>
    <?php endif; ?>
  </nav>
</div>

<style>
.author-container { max-width:920px; margin:0 auto; padding:0 16px; }

.author-header { display:flex; gap:1rem; align-items:center; margin:1.5rem 0; }
.author-photo { width:84px; height:84px; object-fit:cover; border-radius:50%; }
.author-photo.fallback {
  width:84px;
  height:84px;
  border-radius:50%;
  background:#eee;
  display:flex;
  align-items:center;
  justify-content:center;
  font-size:28px;
  color:#666;
}

.author-head-meta .author-title { margin:0; font-size:1.35rem; }

.author-bio {
  color:var(--muted);
  font-size:.98rem;
  margin-top:.4rem;
  max-width:700px;
  line-height:1.45;
  white-space:pre-wrap;
}

.author-link-row { margin-top:.6rem; }

.author-posts-list { display:flex; flex-direction:column; gap:1.25rem; }
.author-post-item { display:flex; gap:1rem; align-items:flex-start; padding:12px 0; border-bottom:1px solid #eee; }
.thumb-wrap img { width:180px; height:110px; object-fit:cover; border-radius:6px; display:block; }
.post-info { flex:1; }
.post-title { margin:0 0 .35rem 0; font-size:1.15rem; }
.post-meta { color:#777; font-size:.9rem; margin-bottom:.5rem; }
.post-excerpt { margin:0; color:inherit; }

.pagination { display:flex; gap:1rem; align-items:center; margin-top:1.25rem; }
.page-info { color:#666; font-size:.95rem; }
.page-prev, .page-next { color:#0073e6; text-decoration:none; }

@media (max-width:720px) {
  .author-header { flex-direction:row; gap:.75rem; }
  .thumb-wrap img { width:140px; height:88px; }
}
</style>