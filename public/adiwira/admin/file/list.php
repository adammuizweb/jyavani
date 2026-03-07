<?php
declare(strict_types=1);

// /adiwira/admin/file/list.php

// ✅ allow AJAX fetch safely: if no dashboard/theme context, require guard + auth
if (!defined('DASHBOARD_CONTEXT') && !defined('ADAM_THEME')) {
    require_once __DIR__ . '/../_guard.php';     // /adiwira/admin/_guard.php
    adiwira_require_admin(false);                // hide as 404 if not logged in
    if (!defined('DASHBOARD_CONTEXT')) define('DASHBOARD_CONTEXT', true);
    if (!defined('ADAM_THEME')) define('ADAM_THEME', true);
}

if (!headers_sent()) {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}

function human_filesize($bytes, $decimals = 1) {
    $bytes = (int)$bytes;
    if ($bytes <= 0) return '0 B';
    $units = ['B','KB','MB','GB','TB'];
    $factor = (int)floor((strlen((string)$bytes) - 1) / 3);
    if ($factor < 0) $factor = 0;
    if ($factor > count($units)-1) $factor = count($units)-1;
    $size = $bytes / pow(1024, $factor);
    return sprintf("%.{$decimals}f %s", $size, $units[$factor]);
}

$search = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$page   = max(1, (int)($_GET['p'] ?? 1));
$per_page = 10;
$offset = ($page - 1) * $per_page;

$where = [];
$params = [];

if ($search !== '') {
    $where[] = "(title LIKE :q OR filename LIKE :q OR caption LIKE :q OR mime LIKE :q OR ext LIKE :q)";
    $params[':q'] = '%' . $search . '%';
}
$where_sql = '';
if (!empty($where)) $where_sql = 'WHERE ' . implode(' AND ', $where);

// count
try {
    $count_sql = "SELECT COUNT(*) FROM `file` $where_sql";
    $countStmt = $pdo->prepare($count_sql);
    foreach ($params as $k=>$v) $countStmt->bindValue($k, $v);
    $countStmt->execute();
    $total = (int)$countStmt->fetchColumn();
} catch (Throwable $e) {
    echo "<div style='color:red'>DB Error: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . "</div>";
    $total = 0;
}

// rows
$rows = [];
if ($total > 0) {
    $sql = "SELECT * FROM `file` $where_sql ORDER BY id DESC LIMIT :limit OFFSET :offset";
    try {
        $stmt = $pdo->prepare($sql);
        foreach ($params as $k=>$v) $stmt->bindValue($k, $v);
        $stmt->bindValue(':limit', (int)$per_page, PDO::PARAM_INT);
        $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        echo "<div style='color:red'>DB Error: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . "</div>";
        $rows = [];
    }
}

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

if (!function_exists('e')) {
    function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
?>
<div class="media-list">
  <div class="controls">
    <input type="checkbox" id="select-all" class="select-all" />
    <label for="select-all" class="small">Pilih semua</label>

    <button id="delete-bulk-btn" class="btn danger">Delete Selected</button>

    <input type="text" id="media-search" class="search" placeholder="Cari title / filename / caption / mime" value="<?= e($search) ?>" style="margin-left:12px;">
    <button id="media-search-btn" class="btn">Cari</button>

    <div style="margin-left:auto" class="small">Total: <?= (int)$total ?></div>
  </div>

  <?php if (empty($rows)): ?>
    <div class="empty">Tidak ada file</div>
  <?php else: ?>
    <table class="table" id="media-table">
      <thead>
        <tr>
          <th></th>
          <th>Preview</th>
          <th>Title / Filename</th>
          <th>Meta</th>
          <th style="width:180px">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <?php
            $ext = strtoupper((string)($r['ext'] ?? ''));
            if ($ext === '') $ext = strtoupper((string)pathinfo((string)($r['filename'] ?? ''), PATHINFO_EXTENSION));
            if ($ext === '') $ext = 'FILE';
          ?>
          <tr data-id="<?= (int)$r['id'] ?>">
            <td><input type="checkbox" class="row-checkbox" value="<?= (int)$r['id'] ?>"></td>
            <td><div class="file-thumb"><?= e($ext) ?></div></td>
            <td>
              <div style="font-weight:700"><?= e((string)($r['title'] ?? '')) ?></div>
              <div class="small"><?= e((string)($r['filename'] ?? '')) ?></div>
              <?php if (!empty($r['media_type'])): ?>
                <div class="badge"><?= e((string)$r['media_type']) ?></div>
              <?php endif; ?>
            </td>
            <td>
              <div class="small">
                MIME: <?= e((string)($r['mime'] ?? '')) ?> —
                Size: <?= e(human_filesize((int)($r['size'] ?? 0))) ?>
              </div>
              <?php if (!empty($r['duration'])): ?>
                <div class="small">Durasi: <?= (int)$r['duration'] ?>s</div>
              <?php endif; ?>
              <div class="small">Caption: <?= nl2br(e((string)($r['caption'] ?? ''))) ?></div>
            </td>
            <td>
              <button class="btn btn-open" data-id="<?= (int)$r['id'] ?>">Open</button>
              <a class="btn" href="<?= e((string)$r['url']) ?>" target="_blank" rel="noopener">Download</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <?php if ($total_pages > 1): ?>
      <div class="media-pagination" role="navigation" aria-label="Pagination">
        <?php foreach ($paging_items as $item): ?>
          <?php
            if ($item === '...') { echo '<span class="dots">…</span>'; continue; }
            $i = (int)$item;
          ?>
          <?php if ($i === $page): ?>
            <strong><?= $i ?></strong>
          <?php else: ?>
            <a href="#" class="file-page-link" data-page="<?= $i ?>" data-q="<?= e($search) ?>"><?= $i ?></a>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div>

<script>
(function(){
  // install once
  if (window.__fileListDelegationInstalled) return;
  window.__fileListDelegationInstalled = true;

  function getCsrfToken(){
    const el = document.getElementById('csrf_token');
    return el && el.value ? el.value : '';
  }
  async function readJsonSafe(res){
    const txt = await res.text();
    let j = null;
    try { j = txt ? JSON.parse(txt) : null; } catch(e) {}
    return { txt, j };
  }

  function getContainer() { return document.querySelector('.media-list'); }

  function openSingleById(id) {
    const url = '/adiwira/admin/file/single.php?id=' + encodeURIComponent(id);
    if (window.adamModalOpen) window.adamModalOpen(url, { maxWidth: '800px' });
    else window.open(url, '_blank');
  }

  async function reloadListFragment(q = '', p = 1, silent = false) {
    try {
      const url = '/adiwira/admin/file/list.php'
        + '?q=' + encodeURIComponent(q || '')
        + '&p=' + encodeURIComponent(p || 1)
        + '&_ts=' + Date.now();
      const res = await fetch(url, { credentials: 'include', cache:'no-store' });
      if (!res.ok) throw new Error('HTTP ' + res.status);
      const html = await res.text();

      const parser = new DOMParser();
      const doc = parser.parseFromString(html, 'text/html');
      const newFrag = doc.querySelector('.media-list');
      const old = document.querySelector('.media-list');
      if (newFrag && old) {
        old.replaceWith(newFrag);
      } else {
        const panel = document.getElementById('panel-list');
        if (panel) panel.innerHTML = html;
      }
    } catch (err) {
      console.error('Gagal load list.php:', err);
      if (!silent) alert('Gagal memuat daftar file: ' + err.message);
    }
  }

  function currentQ(){
    const el = document.getElementById('media-search');
    return el ? (el.value || '').trim() : '';
  }
  function currentPage(){
    const el = document.querySelector('.media-pagination strong');
    const p = el ? parseInt(el.textContent, 10) : 1;
    return (isFinite(p) && p > 0) ? p : 1;
  }

  document.addEventListener('click', async function(ev){
    const t = ev.target;

    if (t && t.matches('.btn-open')) {
      ev.preventDefault();
      const id = t.dataset.id;
      if (id) openSingleById(id);
      return;
    }

    if (t && t.matches('#media-search-btn')) {
      ev.preventDefault();
      await reloadListFragment(currentQ(), 1, false);
      return;
    }

    if (t && t.matches('.file-page-link')) {
      ev.preventDefault();
      const p = t.dataset.page ? parseInt(t.dataset.page, 10) : 1;
      const q = t.dataset.q || currentQ();
      await reloadListFragment(q, p, false);
      return;
    }

    if (t && t.matches('#delete-bulk-btn')) {
      ev.preventDefault();
      const container = getContainer();
      const checked = Array.from((container || document).querySelectorAll('.row-checkbox:checked')).map(cb=>cb.value);

      if (!checked.length) return alert('Pilih minimal satu file untuk dihapus.');
      if (!confirm('Hapus ' + checked.length + ' item?')) return;

      const fd = new FormData();
      checked.forEach(id => fd.append('ids[]', id));

      const csrf = getCsrfToken();
      if (csrf) fd.append('csrf_token', csrf);

      try {
        const res = await fetch('/adiwira/admin/file/delete_bulk.php', {
          method: 'POST',
          credentials: 'include',
          cache: 'no-store',
          body: fd
        });

        const { txt, j } = await readJsonSafe(res);

        if (!res.ok) {
          alert('Error: ' + ((j && j.error) ? j.error : (txt || ('HTTP ' + res.status))));
          return;
        }

        if (j && j.ok) {
          alert('Deleted: ' + (j.deleted_count || checked.length));
          try { document.dispatchEvent(new CustomEvent('file:deleted', { detail: j })); } catch(e){}
          try { document.dispatchEvent(new CustomEvent('media:deleted', { detail: j })); } catch(e){}
          await reloadListFragment(currentQ(), currentPage(), true);
        } else {
          alert('Error: ' + ((j && j.error) ? j.error : (txt || 'unknown')));
        }
      } catch (err) {
        alert('Network error: ' + err.message);
      }
      return;
    }
  }, false);

  document.addEventListener('change', function(ev){
    const t = ev.target;
    if (t && t.matches('#select-all')) {
      const checked = !!t.checked;
      const container = getContainer();
      (container || document).querySelectorAll('.row-checkbox').forEach(cb => cb.checked = checked);
    }
  }, false);

  document.addEventListener('file:updated', function(){ reloadListFragment(currentQ(), currentPage(), true); });
  document.addEventListener('file:deleted', function(){ reloadListFragment(currentQ(), currentPage(), true); });
  document.addEventListener('file:added',   function(){ reloadListFragment(currentQ(), 1, true); });

})();
</script>