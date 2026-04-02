<?php
// lokasi /views/themes/default/main/single/page.php
// Layout: Baris1 title | Baris2 author username + updated_at | Baris3 content | Baris4 published-by (author img+username) + created_at

if (!isset($post)) {
    if (isset($page) && (is_array($page) || is_object($page))) {
        $post = (array)$page;
    }
}

if (!isset($post) || !is_array($post)) {
    return;
}

// author fields (assume PageController.augmentAuthor filled author_* keys)
$authorName = !empty($post['author_name'])
    ? $post['author_name']
    : (!empty($post['author_username'])
        ? $post['author_username']
        : (!empty($post['author_email']) ? $post['author_email'] : 'Penulis'));

$authorImg = !empty($post['author_img']) ? $post['author_img'] : null;
$authorIdOrSlug = $post['author_username'] ?? (isset($post['author_id']) ? (string)$post['author_id'] : '');
$authorUrl = $authorIdOrSlug !== '' ? "/author/" . rawurlencode($authorIdOrSlug) . "/" : null;

// dates (safe)
$createdTs = !empty($post['created_at']) ? @strtotime($post['created_at']) : null;
$updatedTs = !empty($post['updated_at']) ? @strtotime($post['updated_at']) : null;
$displayCreated = $createdTs ? date('d M Y', $createdTs) : '';
$displayUpdated = $updatedTs ? date('d M Y', $updatedTs) : '';

// ensure title safe
$titleSafe = htmlspecialchars($post['title'] ?? '', ENT_QUOTES, 'UTF-8');
?>

<article class="adam-page-single" itemscope itemtype="https://schema.org/Article">

  <!-- Baris 1: Title -->
  <header class="post-header">
    <h1 class="post-title" itemprop="headline"><?= $titleSafe ?></h1>

    <!-- Baris 2: author username | terakhir diperbarui -->
    <div class="post-top-meta">
      <div class="author-inline">
          <span class="author-label">Author:</span>
          <?php if ($authorUrl): ?>
          <a href="<?= htmlspecialchars($authorUrl, ENT_QUOTES, 'UTF-8') ?>" class="author-link">
            <span class="author-username"><?= htmlspecialchars($authorName, ENT_QUOTES, 'UTF-8') ?></span>
          </a>
        <?php else: ?>
          <span class="author-username"><?= htmlspecialchars($authorName, ENT_QUOTES, 'UTF-8') ?></span>
        <?php endif; ?>
      </div>

      <?php if ($updatedTs): ?>
        <div class="updated-at" title="Terakhir diperbarui">
         Updated: <?= htmlspecialchars($displayUpdated, ENT_QUOTES, 'UTF-8') ?>
        </div>
      <?php endif; ?>
    </div>
  </header>

  <!-- Baris 3: content -->
  <main class="post-content" itemprop="articleBody">
    <?= $post['content'] ?? '' ?>
  </main>

  <!-- Baris 4: published by (author img & username) | published on created_at -->
  <footer class="post-published-by">
    <div class="published-by-inner">
      <div class="publisher-avatar">
        <?php if ($authorImg): ?>
          <a href="<?= $authorUrl ?: '#' ?>" <?= $authorUrl ? '' : 'aria-disabled="true" tabindex="-1"' ?>>
            <img class="avatar-img" src="<?= htmlspecialchars($authorImg, ENT_QUOTES, 'UTF-8') ?>"
                 alt="<?= htmlspecialchars($authorName, ENT_QUOTES, 'UTF-8') ?>">
          </a>
        <?php else: ?>
          <div class="avatar-fallback" aria-hidden="true"><?= strtoupper(substr($authorName, 0, 1)) ?></div>
        <?php endif; ?>
      </div>

      <div class="publisher-meta">
        <div class="publisher-line">
          <strong>Dipublikasikan:</strong>
          <?php if ($authorUrl): ?>
            <a class="publisher-name" href="<?= htmlspecialchars($authorUrl, ENT_QUOTES, 'UTF-8') ?>">
              <?= htmlspecialchars($authorName, ENT_QUOTES, 'UTF-8') ?>
            </a>
          <?php else: ?>
            <span class="publisher-name"><?= htmlspecialchars($authorName, ENT_QUOTES, 'UTF-8') ?></span>
          <?php endif; ?>
        </div>

        <?php if ($displayCreated): ?>
        <div class="published-at">
          pada <?= htmlspecialchars($displayCreated, ENT_QUOTES, 'UTF-8') ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </footer>

</article>