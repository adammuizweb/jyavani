<?php
// /adiwira/admin/modal_img/list_modal.php
if (!defined('DASHBOARD_CONTEXT')) define('DASHBOARD_CONTEXT', true);
require_once __DIR__ . '/../../bootstrap.php';
if (session_status() === PHP_SESSION_NONE) session_start();

header('Content-Type: text/html; charset=utf-8');

// compute absolute base URL for absolute paths
$proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host  = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? '');
$baseUrl = rtrim($proto . '://' . $host, '/');

// input
$search = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$per_page = isset($_GET['per_page']) ? max(1, min(200, (int)$_GET['per_page'])) : 10; // reasonable default

$params = [];
$where = '';
if ($search !== '') {
  $where = " WHERE title LIKE :q OR filename LIKE :q OR caption LIKE :q";
  $params[':q'] = '%' . $search . '%';
}

try {
  // total count
  $countSql = "SELECT COUNT(*) FROM media" . $where;
  $countStmt = $pdo->prepare($countSql);
  foreach ($params as $k=>$v) $countStmt->bindValue($k, $v);
  $countStmt->execute();
  $total = (int)$countStmt->fetchColumn();

  $total_pages = (int)max(1, ceil($total / $per_page));
  if ($page > $total_pages) $page = $total_pages;

  $offset = ($page - 1) * $per_page;

  // select rows
  $sql = "SELECT * FROM media" . $where . " ORDER BY id DESC LIMIT :limit OFFSET :offset";
  $stmt = $pdo->prepare($sql);
  foreach ($params as $k=>$v) $stmt->bindValue($k, $v);
  $stmt->bindValue(':limit', (int)$per_page, PDO::PARAM_INT);
  $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
  $stmt->execute();
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
  echo '<div style="color:#c00">DB error: ' . htmlspecialchars($e->getMessage()) . '</div>';
  $rows = [];
  $total = 0;
  $total_pages = 1;
}
?>

<style>
.gallery-grid{display:flex;flex-wrap:wrap;gap:10px}
.gallery-thumb{width:140px;border:1px solid #eee;border-radius:8px;overflow:hidden;position:relative;background:#fff}
.gallery-thumb img{width:100%;height:95px;object-fit:cover;display:block}
.thumb-info{padding:6px;font-size:13px}
.thumb-actions{position:absolute;right:6px;bottom:6px;display:flex;gap:6px}
.thumb-actions button{padding:6px 8px;border-radius:6px;border:0;cursor:pointer;font-size:.85rem}
.btn-insert{background:#0b80ff;color:#fff}
.btn-detail{background:#fff;color:#0b80ff;border:1px solid #cfe4ff}
.small{font-size:.9rem;color:#666}
.search-row{display:flex;gap:8px;margin-bottom:10px}
.pagination{display:flex;gap:6px;align-items:center;margin-top:12px;flex-wrap:wrap}
.pagination a, .pagination span{display:inline-block;padding:6px 8px;border-radius:6px;border:1px solid #e6e6e6;background:#fff;cursor:pointer;text-decoration:none;color:#0b80ff}
.pagination a.disabled, .pagination span.disabled{opacity:.45;cursor:default;color:#999}
.pagination .current{background:#0b80ff;color:#fff;border-color:#0b80ff}
.pagination-info{font-size:13px;color:#666;margin-left:8px}
</style>

<div>
  <div class="search-row">
    <input id="modal-search" placeholder="Cari title/filename/caption..." value="<?= htmlspecialchars($search, ENT_QUOTES) ?>" style="flex:1;padding:8px;border:1px solid #ccc;border-radius:6px">
    <button id="modal-search-btn" style="padding:8px;border-radius:6px">Cari</button>
  </div>

  <div id="gallery-container">
    <?php if (count($rows) === 0): ?>
      <div class="small">Tidak ada media</div>
    <?php else: ?>
      <div class="gallery-grid" id="gallery-grid">
        <?php foreach ($rows as $r):
          $id = (int)$r['id'];
          $url = $r['url'];
          // normalize to absolute URL
          if ($url && !preg_match('#^https?://#i', $url)) {
            if (substr($url,0,1) === '/') $url = $baseUrl . $url;
            else $url = $baseUrl . '/' . ltrim($url, '/');
          }
          $title = $r['title'] ?? '';
          $caption = $r['caption'] ?? '';
          $alt = $r['alt'] ?? '';
          $credit = $r['credit'] ?? '';
        ?>
          <div class="gallery-thumb"
               data-id="<?= $id ?>"
               data-url="<?= htmlspecialchars($url, ENT_QUOTES) ?>"
               data-title="<?= htmlspecialchars($title, ENT_QUOTES) ?>"
               data-alt="<?= htmlspecialchars($alt, ENT_QUOTES) ?>"
               data-caption="<?= htmlspecialchars($caption, ENT_QUOTES) ?>"
               data-credit="<?= htmlspecialchars($credit, ENT_QUOTES) ?>">
            <img src="<?= htmlspecialchars($url, ENT_QUOTES) ?>" alt="<?= htmlspecialchars($alt ?: $title, ENT_QUOTES) ?>">
            <div class="thumb-info">
              <div style="font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars($title) ?></div>
              <div class="small"><?= htmlspecialchars($r['filename']) ?></div>
            </div>
            <div class="thumb-actions">
              <button class="btn-detail" type="button" data-id="<?= $id ?>">Detail</button>
              <button class="btn-insert" type="button" data-id="<?= $id ?>" data-url="<?= htmlspecialchars($url, ENT_QUOTES) ?>">Insert</button>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <!-- pagination -->
      <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:8px">
        <div class="pagination" id="pagination">
          <?php
          // build pages window (max 7 page links)
          $max_links = 7;
          $half = floor($max_links / 2);
          $start = max(1, $page - $half);
          $end = min($total_pages, $start + $max_links - 1);
          if ($end - $start + 1 < $max_links) {
            $start = max(1, $end - $max_links + 1);
          }

          // helper to build link url
          function pageUrl($p, $q, $per_page) {
            $parts = [];
            if ($q !== '') $parts[] = 'q=' . urlencode($q);
            $parts[] = 'page=' . (int)$p;
            $parts[] = 'per_page=' . (int)$per_page;
            return '/adiwira/admin/modal_img/list_modal.php?' . implode('&', $parts);
          }

          // Prev
          if ($page <= 1) {
            echo '<span class="disabled">Prev</span>';
          } else {
            echo '<a href="' . htmlspecialchars(pageUrl($page-1, $search, $per_page)) . '" data-page="' . ($page-1) . '">Prev</a>';
          }

          // first page + gap if needed
          if ($start > 1) {
            echo '<a href="' . htmlspecialchars(pageUrl(1, $search, $per_page)) . '" data-page="1">1</a>';
            if ($start > 2) echo '<span class="disabled">…</span>';
          }

          for ($i = $start; $i <= $end; $i++) {
            if ($i == $page) {
              echo '<span class="current" data-page="' . $i . '">' . $i . '</span>';
            } else {
              echo '<a href="' . htmlspecialchars(pageUrl($i, $search, $per_page)) . '" data-page="' . $i . '">' . $i . '</a>';
            }
          }

          // last page + gap
          if ($end < $total_pages) {
            if ($end < $total_pages - 1) echo '<span class="disabled">…</span>';
            echo '<a href="' . htmlspecialchars(pageUrl($total_pages, $search, $per_page)) . '" data-page="' . $total_pages . '">' . $total_pages . '</a>';
          }

          // Next
          if ($page >= $total_pages) {
            echo '<span class="disabled">Next</span>';
          } else {
            echo '<a href="' . htmlspecialchars(pageUrl($page+1, $search, $per_page)) . '" data-page="' . ($page+1) . '">Next</a>';
          }
          ?>
        </div>

        <div class="pagination-info">
          Menampilkan <?= $total === 0 ? 0 : ($offset + 1) ?> -
          <?= min($offset + $per_page, $total) ?> dari <?= $total ?> hasil
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>

<script>
(function(){
  // broadcast helper (document + parent messages)
  function broadcast(name, detail) {
    try { document.dispatchEvent(new CustomEvent(name, { detail })); } catch(e){}
    try { window.dispatchEvent(new CustomEvent(name, { detail })); } catch(e){}
    try { if (window.parent && window.parent !== window) window.parent.postMessage({ type: name, detail }, '*'); } catch(e){}
  }

  // Fetch and replace gallery-container, preserving q/per_page/page in query string
  function fetchAndReplace(url) {
    fetch(url, { credentials: 'include' })
      .then(function(res){ if (!res.ok) throw new Error('HTTP ' + res.status); return res.text(); })
      .then(function(html){
        var parser = new DOMParser();
        var doc = parser.parseFromString(html, 'text/html');
        var newContainer = doc.getElementById('gallery-container');
        var cur = document.getElementById('gallery-container');
        if (newContainer && cur) {
          cur.parentNode.replaceChild(newContainer, cur);
          // dispatch an event so outer scripts can re-run enhancements if needed
          broadcast('modal:gallery:updated', { });
        } else {
          console.warn('Failed to find gallery-container in fetched HTML');
        }
      }).catch(function(err){
        console.error('fetchAndReplace failed', err);
      });
  }

  // delegate clicks inside gallery-container
  document.addEventListener('click', function(ev){
    var a = ev.target;

    // pagination link
    var pag = a.closest && a.closest('#pagination a');
    if (pag) {
      ev.preventDefault();
      var href = pag.getAttribute('href');
      if (!href) return;
      fetchAndReplace(href);
      return;
    }

    // detail button
    var detailBtn = a.closest && a.closest('.btn-detail');
    if (detailBtn) {
      ev.preventDefault();
      var id = detailBtn.getAttribute('data-id');
      if (!id) return;
      var url = '/adiwira/admin/modal_img/single_modal.php?id=' + encodeURIComponent(id);
      try { if (window.adamModalOpen) { window.adamModalOpen(url, { maxWidth: '820px' }); return; } } catch(e){}
      try { if (window.parent && window.parent.adamModalOpen) { window.parent.adamModalOpen(url, { maxWidth: '820px' }); return; } } catch(e){}
      window.open(url, '_blank');
      return;
    }

    // insert button
    var ins = a.closest && a.closest('.btn-insert');
    if (ins) {
      ev.preventDefault();
      var thumb = ins.closest && ins.closest('.gallery-thumb');
      if (!thumb) return;
      var id = thumb.getAttribute('data-id');
      var url = thumb.getAttribute('data-url') || '';
      var title = (thumb.getAttribute('data-title') || '').trim();
      var alt = (thumb.getAttribute('data-alt') || '').trim();
      var caption = (thumb.getAttribute('data-caption') || '').trim();
      var credit = (thumb.getAttribute('data-credit') || '').trim();

      var detail = {
        id: id ? parseInt(id, 10) : null,
        url: url,
        title: title,
        alt: alt,
        caption: caption,
        credit: credit
      };

      broadcast('media:insert', detail);

      // attempt to close modal in parent
      try { if (window.parent && window.parent !== window && typeof window.parent.adamModalClose === 'function') window.parent.adamModalClose(); } catch(e){}
      try { if (typeof window.adamModalClose === 'function') window.adamModalClose(); } catch(e){}
      return;
    }

    // image click -> open detail
    var img = a.closest && a.closest('.gallery-thumb img');
    if (img) {
      var thumb = img.closest && img.closest('.gallery-thumb');
      if (!thumb) return;
      var id = thumb.getAttribute('data-id');
      var url = '/adiwira/admin/modal_img/single_modal.php?id=' + encodeURIComponent(id);
      try { if (window.adamModalOpen) { window.adamModalOpen(url, { maxWidth: '820px' }); return; } } catch(e){}
      try { if (window.parent && window.parent.adamModalOpen) { window.parent.adamModalOpen(url, { maxWidth: '820px' }); return; } } catch(e){}
      window.open(url, '_blank');
      return;
    }
  }, false);

  // search button: fetch with q and reset to page=1
  document.getElementById('modal-search-btn')?.addEventListener('click', function(){
    var q = document.getElementById('modal-search')?.value || '';
    var url = '/adiwira/admin/modal_img/list_modal.php?q=' + encodeURIComponent(q) + '&page=1&per_page=<?= (int)$per_page ?>';
    fetchAndReplace(url);
  });

  // allow Enter in search input
  document.getElementById('modal-search')?.addEventListener('keydown', function(ev){
    if (ev.key === 'Enter') {
      ev.preventDefault();
      document.getElementById('modal-search-btn')?.click();
    }
  });

  // when gallery updated, (optional) you could run any enhancement functions here.
  document.addEventListener('modal:gallery:updated', function(){ /* placeholder for enhancements */ });

})();
</script>
