<?php
declare(strict_types=1);

// /adiwira/admin/media/list.php
if (!defined('DASHBOARD_CONTEXT') && !defined('ADAM_THEME')) {
    require_once __DIR__ . '/../_guard.php';

    if (adiwira_is_navigate_request()) {
        http_response_code(404);
        require __DIR__ . '/../../../frontend_404.php';
        exit;
    }
}

[$uid, $role] = adiwira_require_editorial($pdo, false);
$isAdmin = ($role === 'admin');

if (!headers_sent()) {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}

if (!function_exists('human_filesize')) {
    function human_filesize(int $bytes, int $decimals = 1): string {
        if ($bytes <= 0) return '0 B';
        $units = ['B','KB','MB','GB','TB'];
        $factor = (int)floor((strlen((string)$bytes) - 1) / 3);
        $factor = min($factor, count($units) - 1);
        $size = $bytes / pow(1024, $factor);
        return sprintf("%.{$decimals}f %s", $size, $units[$factor]);
    }
}

if (!function_exists('e')) {
    function e($s): string {
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    }
}

$search   = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$page     = max(1, (int)($_GET['p'] ?? 1));
$per_page = 20;
$offset   = ($page - 1) * $per_page;

$where = [];
$params = [];

if (!$isAdmin) {
    $where[] = 'user_id = :uid';
    $params[':uid'] = $uid;
}

if ($search !== '') {
    $where[] = '(title LIKE :q OR filename LIKE :q OR caption LIKE :q)';
    $params[':q'] = '%' . $search . '%';
}

$where_sql = !empty($where) ? ('WHERE ' . implode(' AND ', $where)) : '';

try {
    $count_sql = "SELECT COUNT(*) FROM media $where_sql";
    $countStmt = $pdo->prepare($count_sql);
    foreach ($params as $k => $v) {
        $countStmt->bindValue($k, $v);
    }
    $countStmt->execute();
    $total = (int)$countStmt->fetchColumn();
} catch (Throwable $e) {
    error_log('media/list count error: ' . $e->getMessage());
    $total = 0;
}

$rows = [];
if ($total > 0) {
    $sql = "SELECT * FROM media $where_sql ORDER BY id DESC LIMIT :limit OFFSET :offset";
    try {
        $stmt = $pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('media/list rows error: ' . $e->getMessage());
        $rows = [];
    }
}

if (!function_exists('build_pagination_items')) {
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
}

$total_pages = max(1, (int)ceil($total / $per_page));
$paging_items = build_pagination_items($page, $total_pages, 9);
?>
<div class="media-list">
  <div class="controls">
    <input type="checkbox" id="select-all" class="select-all">
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
              <img src="<?= e($r['url']) ?>" alt="<?= e($r['alt']) ?>" class="media-thumb">
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

    <?php if ($total_pages > 1): ?>
      <div class="media-pagination" role="navigation" aria-label="Pagination">
        <?php foreach ($paging_items as $item): ?>
          <?php if ($item === '...'): ?>
            <span class="dots">…</span>
          <?php else: ?>
            <?php $i = (int)$item; ?>
            <?php if ($i === $page): ?>
              <strong><?= $i ?></strong>
            <?php else: ?>
              <a href="#"
                 class="media-page-link"
                 data-page="<?= $i ?>"
                 data-q="<?= e($search) ?>"><?= $i ?></a>
            <?php endif; ?>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div>
<script>
(function(){
  function uiToast(type, title, message) {
    if (window.mediaUi && typeof window.mediaUi.toast === 'function') {
      window.mediaUi.toast(type, title, message);
      return;
    }
    if (window.NewNotifToast && typeof window.NewNotifToast.show === 'function') {
      window.NewNotifToast.show({ type: type, title: title, message: message });
      return;
    }
    alert(message || title || 'Terjadi sesuatu.');
  }

  function uiAsk(variant, opts) {
    if (window.mediaUi && typeof window.mediaUi.ask === 'function') {
      return window.mediaUi.ask(variant, opts || {});
    }
    if (window.NewNotifConfirm) {
      if (variant === 'danger' && typeof window.NewNotifConfirm.danger === 'function') {
        return window.NewNotifConfirm.danger(opts || {});
      }
      if (typeof window.NewNotifConfirm.warning === 'function') {
        return window.NewNotifConfirm.warning(opts || {});
      }
    }
    return Promise.resolve(window.confirm((opts && opts.message) ? opts.message : 'Lanjutkan aksi ini?'));
  }

  function getCsrfToken(){
    if (window.mediaUi && typeof window.mediaUi.getCsrfToken === 'function') {
      return window.mediaUi.getCsrfToken();
    }
    const el = document.getElementById('csrf_token');
    return el && el.value ? el.value : '';
  }

  async function readJsonSafe(res){
    if (window.mediaUi && typeof window.mediaUi.readJsonSafe === 'function') {
      return window.mediaUi.readJsonSafe(res);
    }
    const txt = await res.text();
    let j = null;
    try { j = txt ? JSON.parse(txt) : null; } catch(e) {}
    return { txt, j };
  }

  function openSingleById(id) {
    const url = '/adiwira/admin/media/single.php?id=' + encodeURIComponent(id);
    if (window.adamModalOpen) window.adamModalOpen(url, { maxWidth: '800px' });
    else window.open(url, '_blank');
  }

  async function reloadListFragment(q = '', p = 1, silent = false) {
    try {
      const url = '/adiwira/admin/media/list.php?q=' + encodeURIComponent(q) + '&p=' + encodeURIComponent(p) + '&_ts=' + Date.now();
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
      if (!silent) uiToast('error', 'Media', 'Gagal memuat daftar media: ' + (err.message || err));
    }
  }

  document.addEventListener('click', async function(ev){
    const t = ev.target;

    if (t.matches('.btn-open')) {
      ev.preventDefault();
      const id = t.dataset.id;
      if (!id) return;
      openSingleById(id);
      return;
    }

    if (t.matches('#media-search-btn')) {
      ev.preventDefault();
      const input = document.getElementById('media-search');
      const q = input ? input.value.trim() : '';
      await reloadListFragment(q, 1, false);
      return;
    }

    if (t.matches('.media-page-link')) {
      ev.preventDefault();
      const p = t.dataset.page ? parseInt(t.dataset.page, 10) : 1;
      const q = t.dataset.q || (document.getElementById('media-search') ? document.getElementById('media-search').value.trim() : '');
      await reloadListFragment(q, p, false);
      return;
    }

    if (t.matches('#delete-bulk-btn')) {
      ev.preventDefault();

      const checked = Array.from(document.querySelectorAll('.row-checkbox:checked')).map(cb => cb.value);
      if (checked.length === 0) {
        uiToast('warning', 'Media', 'Pilih minimal satu media untuk dihapus.');
        return;
      }

      const ok = await uiAsk('danger', {
        title: 'Hapus media terpilih',
        message: 'Sebanyak ' + checked.length + ' media akan dihapus permanen. Lanjutkan?',
        confirmText: 'Ya, hapus',
        cancelText: 'Batal'
      });
      if (!ok) return;

      const fd = new FormData();
      checked.forEach(id => fd.append('ids[]', id));

      const csrf = getCsrfToken();
      if (csrf) fd.append('csrf_token', csrf);

      try {
        const res = await fetch('/adiwira/admin/media/delete_bulk.php', {
          method: 'POST',
          credentials: 'include',
          body: fd
        });

        const { txt, j } = await readJsonSafe(res);

        if (!res.ok) {
          uiToast('error', 'Media', j?.error || txt || ('HTTP ' + res.status));
          return;
        }

        if (j && j.ok) {
          uiToast('success', 'Media', 'Berhasil menghapus ' + (j.deleted_count || checked.length) + ' media.');
          if (Array.isArray(j.warnings) && j.warnings.length) {
            uiToast('warning', 'Media', j.warnings.join('\n'));
          }

          document.dispatchEvent(new CustomEvent('media:deleted', { detail: { ids: checked, result: j } }));

          const currentQ = document.getElementById('media-search') ? document.getElementById('media-search').value.trim() : '';
          const currentPageEl = document.querySelector('.media-pagination strong');
          const currentPage = currentPageEl ? parseInt(currentPageEl.textContent, 10) : 1;
          await reloadListFragment(currentQ, currentPage, true);
        } else {
          uiToast('error', 'Media', j?.error || txt || 'Terjadi kesalahan.');
        }
      } catch (err) {
        uiToast('error', 'Media', 'Network error: ' + (err.message || err));
      }
      return;
    }
  }, false);

  document.addEventListener('change', function(ev){
    const t = ev.target;
    if (t.matches('#select-all')) {
      const checked = t.checked;
      document.querySelectorAll('.row-checkbox').forEach(cb => cb.checked = checked);
    }
  }, false);

  function currentQ() {
    return document.getElementById('media-search') ? document.getElementById('media-search').value.trim() : '';
  }
  function currentPage() {
    const el = document.querySelector('.media-pagination strong');
    return el ? parseInt(el.textContent, 10) : 1;
  }

  document.addEventListener('media:updated', function(){
    reloadListFragment(currentQ(), currentPage(), true);
  });
  document.addEventListener('media:deleted', function(){
    reloadListFragment(currentQ(), currentPage(), true);
  });
  document.addEventListener('media:added', function(){
    reloadListFragment(currentQ(), 1, true);
  });
})();
</script>