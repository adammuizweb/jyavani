<?php
// /views/themes/<active>/main/search.php
// variabel tersedia: $results, $total, $page, $totalPages, $qEsc
// controller SearchController juga menyediakan aliases: posts, pages, base, perPage (jika ada)

// make local-safe defaults
$results = $results ?? [];
$posts   = $posts   ?? $results;
$page    = isset($page) ? (int)$page : 1;
$perPage = isset($perPage) ? (int)$perPage : 10;
$total   = isset($total) ? (int)$total : count($posts);
$pages   = isset($pages) ? (int)$pages : (isset($totalPages) ? (int)$totalPages : max(1, (int)ceil($total / max(1, $perPage))));
$base    = $base ?? ('/?s=' . urlencode($q ?? ''));

// prepare vars for list.post (component)
$listVars = [
  'posts' => $posts,
  'page' => $page,
  'perPage' => $perPage,
  'total' => $total,
  'pages' => $pages,
  'base' => $base,
  'site_context' => 'posts_list',
];

// pdo used by render_slot (if needed)
$pdoForRender = $GLOBALS['pdo'] ?? null;

// track whether list was rendered by theme engine
$didRenderList = false;
?>
<section class="search-results">
<style>
/* your existing styles (kept as-is) */
.search-results {
  max-width: 860px;
  margin: 2.5rem auto;
  padding: 0 1rem;
}
.search-results h1 {
  font-size: 1.8rem;
  margin-bottom: .5rem;
  font-weight: 700;
}
.search-results .summary {
  font-size: .95rem;
  color: inherit;
  margin-bottom: 1.5rem;
}
.search-results .card {
  background: inherit;
  border-radius: 12px;
  padding: 1.2rem 1.4rem;
  margin-bottom: 1rem;
  border: 1px solid #e5e7eb;
  transition: box-shadow .25s;
}
.search-results .card:hover {
  box-shadow: 0 5px 24px rgba(0,0,0,.07);
}
.search-results .card h2 {
  margin: 0 0 .4rem;
  font-size: 1.15rem;
  line-height: 1.35;
}
.search-results .card a {
  text-decoration: none;
  color: inherit;
}
.search-results .card time {
  display: inline-block;
  margin-top: .25rem;
  font-size: .8rem;
  color: inherit;
}
.search-results .card p {
  margin: .6rem 0 0;
  color: inherit;
  font-size: .9rem;
}

.search-results .pagination {
  margin: 2rem 0;
  display: flex;
  justify-content: center;
  gap: .4rem;
  flex-wrap: wrap;
}
.search-results .pagination a {
  display: inline-block;
  padding: .45rem .75rem;
  border-radius: 6px;
  background: inherit;
  color: inherit;
  font-size: .85rem;
  text-decoration: none;
}
.search-results .pagination a:hover {
  background: #e5e7eb;
}
.search-results .pagination a[aria-current="page"] {
  background: inherit;
  color: inherit;
  font-weight: 600;
}
</style>

<h1>Hasil pencarian untuk (DEFAULT): “<?= $qEsc ?>”</h1>
<p class="summary"><?= (int)$total ?> hasil ditemukan.</p>

<?php if (empty($posts)): ?>
  <p>Tidak ada hasil. Coba kata kunci lain.</p>

<?php else: ?>

  <?php
  // 1) Prefer theme engine component 'list.post' if available
  if (function_exists('render_slot')) {
      try {
          $slotHtml = render_slot($pdoForRender, 'list.post', $listVars);
          if (trim((string)$slotHtml) !== '') {
              echo $slotHtml;
              $didRenderList = true;
          }
      } catch (Throwable $e) {
          // log and continue to fallback
          error_log('[theme/main.search] render_slot(list.post) error: ' . $e->getMessage());
      }
  }
  ?>

  <?php if (!$didRenderList): // fallback to inline list presentation ?>
    <?php foreach ($posts as $p): ?>
      <article class="card">
        <h2>
          <a href="/<?= htmlspecialchars($p['slug'] ?? '', ENT_QUOTES) ?>/">
            <?= htmlspecialchars($p['title'] ?? '(untitled)', ENT_QUOTES) ?>
          </a>
        </h2>
        <?php if (!empty($p['created_at'])): ?>
        <time datetime="<?= htmlspecialchars($p['created_at'], ENT_QUOTES) ?>">
          <?= htmlspecialchars(date('d M Y', strtotime($p['created_at'])), ENT_QUOTES) ?>
        </time>
        <?php endif; ?>

        <?php if (!empty($p['display_image'])): ?>
          <div style="margin-top:.6rem;margin-bottom:.4rem">
            <img src="<?= htmlspecialchars($p['display_image'], ENT_QUOTES) ?>" alt="" style="max-width:220px;display:block">
          </div>
        <?php endif; ?>

        <p><?= htmlspecialchars(mb_strimwidth(strip_tags($p['content'] ?? ''), 0, 240, '…'), ENT_QUOTES) ?></p>
      </article>
    <?php endforeach; ?>

    <?php if ($pages > 1): ?>
      <nav class="pagination" aria-label="Pagination">
        <?php for ($i = 1; $i <= $pages; $i++): 
            $link = $base . (strpos($base, '?') === false ? '&' : '&') . 'page=' . $i;
            // normalize (base probably contains ?s=...)
            if (strpos($base, '?') === false) {
                $link = $base . '?page=' . $i;
            } else {
                // ensure only one ? present; base already contains ?s=...
                $link = $base . '&page=' . $i;
            }
        ?>
          <a href="<?= htmlspecialchars($link, ENT_QUOTES) ?>" <?= $i == $page ? 'aria-current="page"' : '' ?>>
            <?= $i ?>
          </a>
        <?php endfor; ?>
      </nav>
    <?php endif; ?>

  <?php endif; // end fallback ?>

<?php endif; // end if empty posts ?>

</section>
