<?php
// /adiwira/admin/media/list.php
if (!defined('DASHBOARD_CONTEXT')) define('DASHBOARD_CONTEXT', true);
require_once __DIR__ . '/../../bootstrap.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if (!headers_sent()) {
    header('Content-Type: text/html; charset=utf-8');
}

function human_filesize($bytes, $decimals = 1) {
    if ($bytes <= 0) return '0 B';
    $units = ['B','KB','MB','GB','TB'];
    $factor = floor((strlen((string)$bytes) - 1) / 3);
    $factor = min($factor, count($units)-1);
    $size = $bytes / pow(1024, $factor);
    return sprintf("%.{$decimals}f %s", $size, $units[$factor]);
}

// params
$search = isset($_GET['q']) ? trim($_GET['q']) : '';
$page   = max(1, (int)($_GET['p'] ?? 1));
$per_page = 20; // ubah sesuai kebutuhan
$offset = ($page - 1) * $per_page;

// build WHERE and params
$where = [];
$params = [];

if ($search !== '') {
    // use parentheses to be safe
    $where[] = "(title LIKE :q OR filename LIKE :q OR caption LIKE :q)";
    $params[':q'] = '%' . $search . '%';
}

$where_sql = '';
if (!empty($where)) {
    $where_sql = 'WHERE ' . implode(' AND ', $where);
}

// total count
try {
    $count_sql = "SELECT COUNT(*) FROM media $where_sql";
    $countStmt = $pdo->prepare($count_sql);
    foreach ($params as $k=>$v) $countStmt->bindValue($k, $v);
    $countStmt->execute();
    $total = (int)$countStmt->fetchColumn();
} catch (Exception $e) {
    echo "<div style='color:red'>DB Error: " . htmlspecialchars($e->getMessage()) . "</div>";
    $total = 0;
}

// fetch rows with limit/offset
$rows = [];
if ($total > 0) {
    $sql = "SELECT * FROM media $where_sql ORDER BY id DESC LIMIT :limit OFFSET :offset";
    try {
        $stmt = $pdo->prepare($sql);
        foreach ($params as $k=>$v) $stmt->bindValue($k, $v);
        $stmt->bindValue(':limit', (int)$per_page, PDO::PARAM_INT);
        $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        echo "<div style='color:red'>DB Error: " . htmlspecialchars($e->getMessage()) . "</div>";
        $rows = [];
    }
}

// pagination helper (compact, same idea seperti file lain)
function build_pagination_items(int $current, int $total_pages, int $max_visible = 9): array {
    if ($total_pages <= $max_visible) return range(1, $total_pages);

    $items = [];
    $reserved = 6;
    $middle_slots = max(1, $max_visible - $reserved);
    $half = (int)floor($middle_slots / 2);
    $start = max(3, $current - $half);
    $end = min($total_pages - 2, $current + $half);

    if ($start === 3) $end = min($total_pages - 2, $start + $middle_slots - 1);
    if ($end === $total_pages - 2) $start = max(3, $end - $middle_slots + 1);

    $items[] = 1;
    $items[] = 2;
    if ($start > 3) $items[] = '...';
    for ($i = $start; $i <= $end; $i++) $items[] = $i;
    if ($end < $total_pages - 2) $items[] = '...';
    $items[] = $total_pages - 1;
    $items[] = $total_pages;

    while (count($items) > $max_visible) {
        for ($i = 0; $i < count($items); $i++) {
            if (is_int($items[$i]) && $items[$i] !== 1 && $items[$i] !== 2 && $items[$i] !== $total_pages - 1 && $items[$i] !== $total_pages) {
                array_splice($items, $i, 1);
                break;
            }
        }
    }

    return $items;
}

$total_pages = max(1, (int)ceil($total / $per_page));
$paging_items = build_pagination_items($page, $total_pages, 9);

// helper e() biasanya ada di bootstrap; fallback:
if (!function_exists('e')) {
    function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
?>

<style>
.media-list { font-family:system-ui, -apple-system, "Segoe UI", Roboto; }
.media-list .table { width:100%; border-collapse:collapse; margin-top:8px; }
.media-list th, .media-list td { padding:8px; border-bottom:1px solid #f0f0f0; text-align:left; vertical-align:middle; }
.media-thumb { width:72px; height:48px; object-fit:cover; border-radius:6px; border:1px solid #eee; }
.controls { display:flex; gap:8px; align-items:center; margin-bottom:8px; }
.btn { padding:8px 10px; border-radius:6px; border:1px solid #ddd; background:#fff; cursor:pointer; }
.btn.danger { background:#e53935; color:#fff; border-color:#e53935; }
.select-all { margin-right:6px; }
.small { font-size:.9rem; color:#666; }
.empty { padding:20px; text-align:center; color:#666; }

/* pagination */
.media-pagination { margin-top:12px; display:flex; gap:.35rem; align-items:center; flex-wrap:wrap; }
.media-pagination a, .media-pagination strong, .media-pagination .dots { padding:.35rem .55rem; border-radius:6px; text-decoration:none; color:#0b66d0; border:1px solid transparent; font-size:.95rem; }
.media-pagination a { background:#fff; border:1px solid #e6eefc; }
.media-pagination a:hover { background:#eef6ff; }
.media-pagination strong { background:#0b80ff; color:#fff; border-color:#0b80ff; }
.media-pagination .dots { color:#888; padding:.35rem .5rem; }
</style>

<div class="media-list">
  <div class="controls">
    <input type="checkbox" id="select-all" class="select-all" />
    <label for="select-all" class="small">Pilih semua</label>

    <button id="delete-bulk-btn" class="btn danger">Delete Selected</button>

    <input type="text" id="media-search" class="search" placeholder="Cari title / filename / caption" value="<?= e($search) ?>" style="margin-left:12px;">
    <button id="media-search-btn" class="btn">Cari</button>

    <div style="margin-left:auto" class="small">Total: <?= $total ?></div>
  </div>

  <?php if (empty($rows)): ?>
    <div class="empty">Tidak ada media</div>
  <?php else: ?>
    <table class="table" id="media-table">
      <thead>
        <tr>
          <th></th>
          <th>Preview</th>
          <th>Title / Filename</th>
          <th>Meta</th>
          <th style="width:120px">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <tr data-id="<?= (int)$r['id'] ?>">
            <td><input type="checkbox" class="row-checkbox" value="<?= (int)$r['id'] ?>"></td>
            <td>
              <img src="<?= e($r['url']) ?>" alt="<?= e($r['alt']) ?>" class="media-thumb" />
            </td>
            <td>
              <div style="font-weight:600"><?= e($r['title']) ?></div>
              <div class="small"><?= e($r['filename']) ?></div>
            </td>
            <td>
              <div class="small">MIME: <?= e($r['mime']) ?> — Size: <?= e(human_filesize((int)($r['size'] ?? 0))) ?></div>
              <?php if (!empty($r['width']) && !empty($r['height'])): ?>
                <div class="small">Dim: <?= (int)$r['width'] ?>×<?= (int)$r['height'] ?></div>
              <?php endif; ?>
              <div class="small">Caption: <?= nl2br(e($r['caption'])) ?></div>
            </td>
            <td>
              <button class="btn btn-open" data-id="<?= (int)$r['id'] ?>">Open</button>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <!-- pagination -->
    <?php if ($total_pages > 1): ?>
      <div class="media-pagination" role="navigation" aria-label="Pagination">
        <?php foreach ($paging_items as $item): 
          if ($item === '...') { echo '<span class="dots">…</span>'; continue; }
          $i = (int)$item;
          $link = '#';
        ?>
          <?php if ($i === $page): ?>
            <strong><?= $i ?></strong>
          <?php else: ?>
            <a href="<?= htmlspecialchars($link, ENT_QUOTES, 'UTF-8') ?>" class="media-page-link" data-page="<?= $i ?>" data-q="<?= e($search) ?>"><?= $i ?></a>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

  <?php endif; ?>
</div>

<script>
(function(){
  // get container helper (parent of fragment)
  function getContainer() {
    // if included inside panel-list, the outer panel-list will be replaced,
    // so we get the current .media-list element's parent for delegation context
    return document.querySelector('.media-list');
  }

  // open single
  function openSingleById(id) {
    const url = '/adiwira/admin/media/single.php?id=' + encodeURIComponent(id);
    if (window.adamModalOpen) window.adamModalOpen(url, { maxWidth: '800px' });
    else window.open(url, '_blank');
  }

  // reload list fragment with optional q and p
  async function reloadListFragment(q = '', p = 1) {
    try {
      const url = '/adiwira/admin/media/list.php' + (q ? ('?q=' + encodeURIComponent(q) + '&p=' + encodeURIComponent(p)) : ('?p=' + encodeURIComponent(p)));
      const res = await fetch(url, { credentials: 'include' });
      if (!res.ok) throw new Error('HTTP ' + res.status);
      const html = await res.text();
      // parse fragment and replace the .media-list in the current document
      const parser = new DOMParser();
      const doc = parser.parseFromString(html, 'text/html');
      const newFrag = doc.querySelector('.media-list');
      const old = document.querySelector('.media-list');
      if (newFrag && old) {
        old.replaceWith(newFrag);
        // scroll to top of list for better UX
        newFrag.scrollIntoView({ behavior:'smooth', block:'start' });
      } else {
        // fallback: replace panel-list if present
        const panel = document.getElementById('panel-list');
        if (panel) panel.innerHTML = html;
      }
    } catch (err) {
      console.error('Gagal load list.php:', err);
      alert('Gagal memuat daftar media: ' + err.message);
    }
  }

  // delegated click handler
  document.addEventListener('click', async function(ev){
    const t = ev.target;

    // Open button
    if (t.matches('.btn-open')) {
      ev.preventDefault();
      const id = t.dataset.id;
      if (!id) return;
      openSingleById(id);
      return;
    }

    // Search button
    if (t.matches('#media-search-btn')) {
      ev.preventDefault();
      const input = document.getElementById('media-search');
      const q = input ? input.value.trim() : '';
      await reloadListFragment(q, 1);
      return;
    }

    // Pagination link -> intercept and load via AJAX
    if (t.matches('.media-page-link')) {
      ev.preventDefault();
      const p = t.dataset.page ? parseInt(t.dataset.page, 10) : 1;
      const q = t.dataset.q || (document.getElementById('media-search') ? document.getElementById('media-search').value.trim() : '');
      await reloadListFragment(q, p);
      return;
    }

    // Bulk delete
    if (t.matches('#delete-bulk-btn')) {
      ev.preventDefault();
      const container = getContainer();
      const checked = Array.from((container || document).querySelectorAll('.row-checkbox:checked')).map(cb=>cb.value);
      if (checked.length === 0) return alert('Pilih minimal satu media untuk dihapus.');
      if (!confirm('Hapus ' + checked.length + ' item?')) return;

      const fd = new FormData();
      checked.forEach(id => fd.append('ids[]', id));

      try {
        const res = await fetch('/adiwira/admin/media/delete_bulk.php', {
          method: 'POST',
          credentials: 'include',
          body: fd
        });
        const j = await res.json();
        if (j.ok) {
          alert('Deleted: ' + (j.deleted_count || checked.length));
          document.dispatchEvent(new CustomEvent('media:deleted', { detail: { ids: checked, result: j } }));
          // remove rows from current DOM
          checked.forEach(id => {
            const tr = (container || document).querySelector('tr[data-id="'+id+'"]');
            if (tr) tr.remove();
          });
          // optionally reload current page to refresh total/pagination
          const currentQ = document.getElementById('media-search') ? document.getElementById('media-search').value.trim() : '';
          const currentPageLink = document.querySelector('.media-pagination strong');
          const currentPage = currentPageLink ? parseInt(currentPageLink.textContent, 10) : 1;
          await reloadListFragment(currentQ, currentPage);
        } else {
          console.error('delete_bulk error', j);
          alert('Error: ' + (j.error || 'unknown'));
        }
      } catch (err) {
        console.error('Network error (delete_bulk):', err);
        alert('Network error: ' + err.message);
      }
      return;
    }

  }, false);

  // delegated change for select-all
  document.addEventListener('change', function(ev){
    const t = ev.target;
    if (t.matches('#select-all')) {
      const checked = t.checked;
      const container = getContainer();
      (container || document).querySelectorAll('.row-checkbox').forEach(cb => cb.checked = checked);
    }
  }, false);

  // listen for media events triggered elsewhere (add/update/delete)
  document.addEventListener('media:updated', function(e){
    // after update, reload current page with same search/page if possible
    const q = document.getElementById('media-search') ? document.getElementById('media-search').value.trim() : '';
    const currentPageLink = document.querySelector('.media-pagination strong');
    const currentPage = currentPageLink ? parseInt(currentPageLink.textContent, 10) : 1;
    reloadListFragment(q, currentPage);
  });

  document.addEventListener('media:deleted', function(e){
    const q = document.getElementById('media-search') ? document.getElementById('media-search').value.trim() : '';
    const currentPageLink = document.querySelector('.media-pagination strong');
    const currentPage = currentPageLink ? parseInt(currentPageLink.textContent, 10) : 1;
    reloadListFragment(q, currentPage);
  });

  // debug
  if (!window.__mediaListDelegationInstalled) {
    console.log('Media list: pagination & delegation installed.');
    window.__mediaListDelegationInstalled = true;
  }

})();
</script>
