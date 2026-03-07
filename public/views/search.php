<?php
// lokasi file /views/search_results.php
// variabel yang tersedia: $results, $total, $page, $totalPages, $qEsc
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

<h1>(View RLV) Hasil pencarian untuk: “<?= $qEsc ?>”</h1>
<p class="summary"><?= $total ?> hasil ditemukan.</p>

<?php if (empty($results)): ?>
  <p>Tidak ada hasil. Coba kata kunci lain.</p>

<?php else: ?>
  <?php foreach ($results as $p): ?>
    <article class="card">
      <h2>
        <a href="/<?= htmlspecialchars($p['slug'], ENT_QUOTES) ?>/">
          <?= htmlspecialchars($p['title'], ENT_QUOTES) ?>
        </a>
      </h2>
      <time datetime="<?= htmlspecialchars($p['created_at'], ENT_QUOTES) ?>">
        <?= htmlspecialchars(date('d M Y', strtotime($p['created_at'])), ENT_QUOTES) ?>
      </time>
    </article>
  <?php endforeach; ?>

  <?php if ($totalPages > 1): ?>
  <nav class="pagination" aria-label="Pagination">
    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
      <a href="?s=<?= urlencode($qEsc) ?>&page=<?= $i ?>" <?= $i == $page ? 'aria-current="page"' : '' ?>>
        <?= $i ?>
      </a>
    <?php endfor; ?>
  </nav>
  <?php endif; ?>

<?php endif; ?>
</section>
