<?php
/** views/author/index.php
 * Variables:
 *   - array $authors
 */
?>
<div class="author-container">
  <h1 class="author-page-title"><?= __('Authors') ?></h1>

  <?php if (function_exists('theme_zone_has_position') && theme_zone_has_position($pdo, 'index.author', 'before_loop')): ?>
    <div class="tz-index-author-before"><?= theme_zone_render_position($pdo, 'index.author', 'before_loop') ?></div>
  <?php endif; ?>

  <?php if (empty($authors)): ?>
    <p><?= __('No authors.') ?></p>
  <?php else: ?>
    <div class="authors-grid">
      <?php foreach ($authors as $a): 
        $link = function_exists('get_author_permalink')
          ? get_author_permalink($a)
          : (!empty($a['username']) ? '/author/' . rawurlencode($a['username']) . '/' : '/author/' . rawurlencode($a['id']) . '/');
        $displayName = $a['name'] ?: $a['email'] ?: ($a['username'] ?? __('Author'));
        $initial = strtoupper(mb_substr($displayName, 0, 1));
      ?>
        <article class="author-card">
          <a href="<?= $link ?>" class="author-card-link">
            <?php if (!empty($a['img'])): ?>
              <img class="author-avatar" src="<?= htmlspecialchars($a['img'], ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?>">
            <?php else: ?>
              <div class="author-avatar fallback"><?= $initial ?></div>
            <?php endif; ?>

            <div class="author-meta">
              <h3 class="author-name"><?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?></h3>
              <?php if (!empty(trim($a['bio'] ?? ''))): ?>
  <div class="author-role">
    <?= htmlspecialchars(mb_strimwidth($a['bio'], 0, 80, '…'), ENT_QUOTES, 'UTF-8') ?>
  </div>
<?php endif; ?>

            </div>
          </a>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if (function_exists('theme_zone_has_position') && theme_zone_has_position($pdo, 'index.author', 'after_loop')): ?>
    <div class="tz-index-author-after"><?= theme_zone_render_position($pdo, 'index.author', 'after_loop') ?></div>
  <?php endif; ?>
</div>

<style>
/* Container + spacing (left-right margin) */
.author-container {
  max-width: 920px;
  margin: 0 auto;
  padding: 0 16px; /* <-- margin kiri/kanan yang kamu minta */
}

/* Grid */
.authors-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit,minmax(220px,1fr));
  gap: 1rem;
}

/* Card */
.author-card {
  background: inherit;
  border: 1px solid #eee;
  border-radius: 8px;
  overflow: hidden;
  text-decoration: none;
}
.author-card-link {
  display:flex;
  gap: .85rem;
  align-items:center;
  padding: 12px;
  color: inherit;
  text-decoration: none;
}

.author-card:hover, .author-card-link:hover { text-decoration: none; }

/* Avatar */
.author-avatar {
  width:64px; height:64px; object-fit:cover; border-radius:50%;
}
.author-avatar.fallback, .author-avatar.fallback * {
  display:inline-flex;
}
.author-avatar.fallback {
  width:64px; height:64px; border-radius:50%;
  background:#f0f0f0; color:#666; align-items:center; justify-content:center; font-weight:600;
}

/* Meta */
.author-name { margin:0; font-size:1.05rem; }
.author-role { color:var(--muted); font-size:.9rem; margin-top:.25rem; }

a:hover {
    text-decoration: none;
}
</style>
