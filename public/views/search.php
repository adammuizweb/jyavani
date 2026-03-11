<?php
declare(strict_types=1);

// /public/views/search_results.php
// Variabel yang diharapkan:
// $results, $posts, $total, $page, $totalPages, $pages, $q, $qEsc, $base

if (
    PHP_SAPI !== 'cli' &&
    realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__
) {
    http_response_code(404);
    exit('Not found');
}

$results = $results ?? [];
$posts = $posts ?? $results;
$total = isset($total) ? (int)$total : count($posts);
$page = isset($page) ? max(1, (int)$page) : 1;
$totalPages = isset($totalPages) ? max(1, (int)$totalPages) : (isset($pages) ? max(1, (int)$pages) : 1);
$q = isset($q) ? (string)$q : '';
$qEsc = isset($qEsc) ? (string)$qEsc : htmlspecialchars($q, ENT_QUOTES, 'UTF-8');
$base = isset($base) && $base !== '' ? (string)$base : ('/?s=' . urlencode($q));
?>

<section class="search-results">
<style>
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
  border: 1px solid #e5e7eb;
}
.search-results .pagination a:hover {
  background: #e5e7eb;
}
.search-results .pagination a[aria-current="page"] {
  font-weight: 700;
}
</style>

<h1>Hasil pencarian untuk: “<?= $qEsc ?>”</h1>
<p class="summary"><?= $total ?> hasil ditemukan.</p>

<?php if (empty($posts)): ?>
  <p>Tidak ada hasil. Coba kata kunci lain.</p>
<?php else: ?>
  <?php foreach ($posts as $p): ?>
    <article class="card">
      <h2>
        <a href="/<?= htmlspecialchars((string)($p['slug'] ?? ''), ENT_QUOTES, 'UTF-8') ?>/">
          <?= htmlspecialchars((string)($p['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
        </a>
      </h2>

      <?php if (!empty($p['created_at'])): ?>
        <time datetime="<?= htmlspecialchars((string)$p['created_at'], ENT_QUOTES, 'UTF-8') ?>">
          <?= htmlspecialchars(date('d M Y', strtotime((string)$p['created_at'])), ENT_QUOTES, 'UTF-8') ?>
        </time>
      <?php endif; ?>

      <?php if (!empty($p['content'])): ?>
        <p><?= htmlspecialchars(mb_strimwidth(strip_tags((string)$p['content']), 0, 220, '…'), ENT_QUOTES, 'UTF-8') ?></p>
      <?php endif; ?>
    </article>
  <?php endforeach; ?>

  <?php if ($totalPages > 1): ?>
    <nav class="pagination" aria-label="Pagination">
      <?php for ($i = 1; $i <= $totalPages; $i++): ?>
        <?php
          $link = $base . (strpos($base, '?') !== false ? '&' : '?') . 'page=' . $i;
        ?>
        <a href="<?= htmlspecialchars($link, ENT_QUOTES, 'UTF-8') ?>" <?= $i === $page ? 'aria-current="page"' : '' ?>>
          <?= $i ?>
        </a>
      <?php endfor; ?>
    </nav>
  <?php endif; ?>
<?php endif; ?>
</section>