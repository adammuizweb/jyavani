<?php
// /adiwira/theme/adam/partials/pages/single.php

if (!isset($post)) {
    if (isset($page) && (is_array($page) || is_object($page))) {
        $post = (array)$page;
    }
}

if (!isset($post) || !is_array($post)) return;

// Author Logic
$authorName = !empty($post['author_name']) ? $post['author_name'] : 
              (!empty($post['author_username']) ? $post['author_username'] : 
              (!empty($post['author_email']) ? $post['author_email'] : 'Penulis'));

$authorImg = $post['author_img'] ?? null;
$authorUrl = !empty($post['author_username']) ? "/author/" . rawurlencode($post['author_username']) . "/" : null;

// Date Logic
$createdTs = !empty($post['created_at']) ? strtotime($post['created_at']) : null;
$updatedTs = !empty($post['updated_at']) ? strtotime($post['updated_at']) : null;
$displayCreated = $createdTs ? date('d M Y', $createdTs) : '';
$displayUpdated = $updatedTs ? date('d M Y', $updatedTs) : '';

$titleSafe = htmlspecialchars($post['title'] ?? '', ENT_QUOTES, 'UTF-8');
?>

<article class="adamz-page-article" itemscope itemtype="https://schema.org/Article">

  <header class="adamz-page-header">
    <h1 class="adamz-page-title" itemprop="headline"><?= $titleSafe ?></h1>

    <div class="adamz-page-top-meta">
      <div class="adamz-page-author-inline">
          <span class="label">Penulis:</span>
          <?php if ($authorUrl): ?>
            <a href="<?= htmlspecialchars($authorUrl, ENT_QUOTES, 'UTF-8') ?>" class="adamz-page-author-link">
              <?= htmlspecialchars($authorName, ENT_QUOTES, 'UTF-8') ?>
            </a>
          <?php else: ?>
            <span style="font-weight:700"><?= htmlspecialchars($authorName, ENT_QUOTES, 'UTF-8') ?></span>
          <?php endif; ?>
      </div>

      <?php if ($updatedTs): ?>
        <div class="adamz-page-updated" title="Terakhir diperbarui">
          <span style="font-style: italic">Terakhir diperbarui: <?= htmlspecialchars($displayUpdated, ENT_QUOTES, 'UTF-8') ?></span>
        </div>
      <?php endif; ?>
    </div>
  </header>

  <main class="adamz-page-content" itemprop="articleBody">
    <?= $post['content'] ?? '' ?>
  </main>

  <footer class="adamz-page-footer">
    <div class="adamz-page-publisher-box">
      <div class="adamz-page-avatar-wrap">
        <?php if ($authorImg): ?>
          <a href="<?= $authorUrl ?: '#' ?>">
            <img class="adamz-page-avatar-img" 
                 src="<?= htmlspecialchars($authorImg, ENT_QUOTES, 'UTF-8') ?>"
                 alt="<?= htmlspecialchars($authorName, ENT_QUOTES, 'UTF-8') ?>">
          </a>
        <?php else: ?>
          <div class="adamz-page-avatar-fallback"><?= strtoupper(substr($authorName, 0, 1)) ?></div>
        <?php endif; ?>
      </div>

      <div class="adamz-page-publisher-info">
        <strong>Diterbitkan Oleh</strong>
        <?php if ($authorUrl): ?>
          <a class="adamz-page-publisher-name" href="<?= htmlspecialchars($authorUrl, ENT_QUOTES, 'UTF-8') ?>">
            <?= htmlspecialchars($authorName, ENT_QUOTES, 'UTF-8') ?>
          </a>
        <?php else: ?>
          <span class="adamz-page-publisher-name"><?= htmlspecialchars($authorName, ENT_QUOTES, 'UTF-8') ?></span>
        <?php endif; ?>

        <?php if ($displayCreated): ?>
          <div class="adamz-page-published-at">
            Tayang pada <?= htmlspecialchars($displayCreated, ENT_QUOTES, 'UTF-8') ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </footer>

</article>