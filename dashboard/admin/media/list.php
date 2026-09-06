<?php
declare(strict_types=1);

// /adiwira/admin/media/list.php
require_once __DIR__ . '/../_guard.php';

if (!defined('DASHBOARD_CONTEXT') && !defined('ADAM_THEME')) {
    if (adiwira_is_navigate_request()) {
        http_response_code(404);
        require FRONTEND_404_PATH;
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

if (!function_exists('mdlib_has_column')) {
    function mdlib_has_column(string $col): bool {
        try {
            $st = $GLOBALS['pdo']->prepare("SELECT {$col} FROM media LIMIT 0");
            $st->execute();
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }
}
$hasVisibility = mdlib_has_column('visibility');

if (!function_exists('modalfilez_client_url')) {
    function modalfilez_client_url(array $row): string
    {
        $id = (int)($row['id'] ?? 0);
        $visibility = strtolower((string)($row['visibility'] ?? 'public'));
        $disk = strtolower((string)($row['storage_disk'] ?? 'public'));
        if ($id > 0 && ($visibility === 'private' || $disk === 'private')) {
            return '/private/media/view/?id=' . $id;
        }
        return (string)($row['url'] ?? '');
    }
}

$search   = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$visFilter = $hasVisibility ? trim((string)($_GET['v'] ?? '')) : '';
$page     = max(1, (int)($_GET['p'] ?? 1));
$perPageOptions = [20, 50, 100, 200];
$requestedPerPage = (int)($_GET['per_page'] ?? 20);
$per_page = in_array($requestedPerPage, $perPageOptions, true) ? $requestedPerPage : 20;

$where = ['is_deleted = 0'];
$params = [];

if (!$isAdmin) {
    $where[] = 'user_id = :uid';
    $params[':uid'] = $uid;
}

if ($search !== '') {
    $where[] = '(title LIKE :q OR filename LIKE :q OR caption LIKE :q)';
    $params[':q'] = '%' . $search . '%';
}

if ($hasVisibility && $visFilter !== '' && in_array($visFilter, ['public','private','auto'], true)) {
    $where[] = 'visibility = :vis';
    $params[':vis'] = $visFilter;
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

$total_pages = max(1, (int)ceil($total / $per_page));
$page = min($page, $total_pages);
$offset = ($page - 1) * $per_page;

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
        if ($total_pages <= 7) return range(1, $total_pages);

        $pages = [1, 2, $current - 1, $current, $current + 1, $total_pages - 1, $total_pages];
        $pages = array_values(array_unique(array_filter(
            $pages,
            static fn(int $number): bool => $number >= 1 && $number <= $total_pages
        )));
        sort($pages);

        $items = [];
        $previous = 0;
        foreach ($pages as $number) {
            if ($previous > 0 && $number > $previous + 1) $items[] = '...';
            $items[] = $number;
            $previous = $number;
        }
        return $items;
    }
}

$paging_items = build_pagination_items($page, $total_pages, 9);
?>
<div class="media-list">
  <div class="controls">
    <input type="checkbox" id="select-all" class="select-all">
    <label for="select-all" class="small"><?= _e('Select all') ?></label>

    <button id="delete-bulk-btn" class="btn danger"><?=_e('Delete Selected')?></button>

    <?php if ($hasVisibility): ?>
    <select id="visibility-filter" style="margin-left:12px;padding:3px 6px;font-size:12px">
      <option value=""><?= _e('All') ?></option>
      <option value="public" <?= $visFilter === 'public' ? 'selected' : '' ?>><?=_e('Public')?></option>
      <option value="private" <?= $visFilter === 'private' ? 'selected' : '' ?>><?=_e('Private')?></option>
      <option value="auto" <?= $visFilter === 'auto' ? 'selected' : '' ?>><?=_e('Auto')?></option>
    </select>
    <?php endif; ?>

    <label class="media-page-size" for="media-per-page">
      <span><?= _e('Items per page') ?></span>
      <select id="media-per-page" class="media-page-select" aria-label="<?= _e('Items per page') ?>">
        <?php foreach ($perPageOptions as $option): ?>
          <option value="<?= $option ?>" <?= $per_page === $option ? 'selected' : '' ?>><?= $option ?></option>
        <?php endforeach; ?>
      </select>
    </label>

    <input type="text" id="media-search" class="search" placeholder="<?= _e('Search title / filename / caption') ?>" value="<?= e($search) ?>" style="margin-left:12px;">
    <button id="media-search-btn" class="btn"><?= _e('Search') ?></button>

    <div class="media-list-total small"><?= _e('Total') ?>: <?= $total ?></div>
  </div>

  <?php if (empty($rows)): ?>
    <div class="empty"><?= _e('No media') ?></div>
  <?php else: ?>
    <table class="table" id="media-table">
      <thead>
        <tr>
          <th></th>
          <th><?=_e('Preview')?></th>
          <th><?=_e('Title / Filename')?></th>
          <th><?=_e('Meta')?></th>
          <th style="width:120px"><?=_e('Actions')?></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <?php
            $visibility = strtolower((string)($r['visibility'] ?? 'public')) ?: 'public';
            $accessScope = strtolower((string)($r['access_scope'] ?? 'public')) ?: 'public';
            $isDownloadable = (int)($r['is_downloadable'] ?? 1);
            $isPrivate = ($visibility === 'private');
            $clientUrl = $hasVisibility ? modalfilez_client_url($r) : $r['url'];
          ?>
          <tr data-id="<?= (int)$r['id'] ?>" data-visibility="<?= e($visibility) ?>" data-access-scope="<?= e($accessScope) ?>">
            <td><input type="checkbox" class="row-checkbox" value="<?= (int)$r['id'] ?>"></td>
            <td>
              <img src="<?= e($clientUrl) ?>" alt="<?= e($r['alt']) ?>" class="media-thumb">
            </td>
            <td>
              <div style="font-weight:600"><?= e($r['title']) ?></div>
              <div class="small"><?= e($r['filename']) ?></div>
              <?php if ($hasVisibility): ?>
              <div style="margin-top:6px;display:flex;gap:5px;flex-wrap:wrap">
                <span class="badge" style="background:<?= $isPrivate ? '#fef3c7' : '#dcfce7' ?>;color:<?= $isPrivate ? '#92400e' : '#166534' ?>;padding:2px 7px;border-radius:999px;font-size:10px;font-weight:800"><?= e(strtoupper($visibility)) ?></span>
                <span class="badge" style="padding:2px 7px;border-radius:999px;font-size:10px;font-weight:800"><?= e(content_access_scope_label($accessScope)) ?></span>
                <?php if (!$isDownloadable): ?>
                  <span class="badge" style="background:#fee2e2;color:#991b1b;padding:2px 7px;border-radius:999px;font-size:10px;font-weight:800"><?=__('NO DOWNLOAD')?></span>
                <?php endif; ?>
              </div>
              <?php endif; ?>
            </td>
            <td>
              <div class="small"><?=_e('MIME:')?> <?= e($r['mime']) ?> — <?=_e('Size:')?> <?= e(human_filesize((int)($r['size'] ?? 0))) ?></div>
              <?php if (!empty($r['width']) && !empty($r['height'])): ?>
                <div class="small"><?=_e('Dim:')?> <?= (int)$r['width'] ?>×<?= (int)$r['height'] ?></div>
              <?php endif; ?>
              <div class="small"><?=_e('Caption:')?> <?= nl2br(e($r['caption'])) ?></div>
            </td>
            <td>
              <button class="btn btn-open" data-id="<?= (int)$r['id'] ?>"><?=_e('Open')?></button>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <div class="media-list-footer">
      <?php if ($total_pages > 1): ?>
      <nav class="media-pagination" aria-label="<?= _e('Pagination') ?>">
        <?php if ($page > 1): ?>
          <a href="#" class="media-page-link media-page-nav" rel="prev" data-page="<?= $page - 1 ?>" data-q="<?= e($search) ?>" data-v="<?= e($visFilter) ?>" data-per-page="<?= $per_page ?>"><?= _e('Previous') ?></a>
        <?php endif; ?>
        <?php foreach ($paging_items as $item): ?>
          <?php if ($item === '...'): ?>
            <span class="dots">…</span>
          <?php else: ?>
            <?php $i = (int)$item; ?>
            <?php if ($i === $page): ?>
              <strong data-page="<?= $i ?>" aria-current="page"><?= $i ?></strong>
            <?php else: ?>
              <a href="#"
                 class="media-page-link"
                 data-page="<?= $i ?>"
                 data-q="<?= e($search) ?>"
                 data-v="<?= e($visFilter) ?>"
                 data-per-page="<?= $per_page ?>"><?= $i ?></a>
            <?php endif; ?>
          <?php endif; ?>
        <?php endforeach; ?>
        <?php if ($page < $total_pages): ?>
          <a href="#" class="media-page-link media-page-nav" rel="next" data-page="<?= $page + 1 ?>" data-q="<?= e($search) ?>" data-v="<?= e($visFilter) ?>" data-per-page="<?= $per_page ?>"><?= _e('Next') ?></a>
        <?php endif; ?>
      </nav>
      <?php endif; ?>
      <div class="media-list-range small">
        <?= $total > 0 ? (int)($offset + 1) . '&ndash;' . (int)min($offset + $per_page, $total) : '0' ?> / <?= (int)$total ?>
      </div>
    </div>
  <?php endif; ?>
</div>
<script>
(function(){
  if (window.__ADIWIRA_MEDIA_LIST_DELEGATES__) return;
  window.__ADIWIRA_MEDIA_LIST_DELEGATES__ = true;
  let listRequestSequence = 0;
  let listController = null;

  function uiToast(type, title, message, duration, action) {
    if (window.mediaUi && typeof window.mediaUi.toast === 'function') {
      window.mediaUi.toast(type, title, message, duration, action);
      return;
    }
    if (window.NewNotifToast && typeof window.NewNotifToast.show === 'function') {
      window.NewNotifToast.show({ type: type, title: title, message: message, duration: duration, action: action || null });
      return;
    }
    alert(message || title || <?= json_encode(__('Something happened.')) ?>);
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
    return Promise.resolve(window.confirm((opts && opts.message) ? opts.message: <?= json_encode(__('Proceed with this action?')) ?>));
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
    const url = '<?= ADMIN_BASE_PATH ?>/admin/media/single.php?id=' + encodeURIComponent(id);
    if (window.adamModalOpen) window.adamModalOpen(url, { maxWidth: '800px' });
    else window.open(url, '_blank');
  }

  async function reloadListFragment(q = '', p = 1, silent = false, requestedPerPage = null) {
    try {
      const vEl = document.getElementById('visibility-filter');
      const v = vEl ? vEl.value : '';
      const perPageEl = document.getElementById('media-per-page');
      const perPage = requestedPerPage || (perPageEl ? perPageEl.value : '<?= (int)$per_page ?>');
      if (window.mediaUi && typeof window.mediaUi.refreshListPanel === 'function') {
        return window.mediaUi.refreshListPanel({ silent: silent, q: q, page: p, perPage: perPage });
      }
      const url = '<?= ADMIN_BASE_PATH ?>/admin/media/list.php?q=' + encodeURIComponent(q) + '&p=' + encodeURIComponent(p) + '&per_page=' + encodeURIComponent(perPage) + (v ? '&v=' + encodeURIComponent(v) : '') + '&_ts=' + Date.now();
      const requestId = ++listRequestSequence;
      if (listController) listController.abort();
      listController = typeof AbortController === 'function' ? new AbortController() : null;
      const res = await fetch(url, { credentials: 'include', cache:'no-store', signal: listController ? listController.signal : undefined });
      if (!res.ok) throw new Error('HTTP ' + res.status);

      const html = await res.text();
      const parser = new DOMParser();
      const doc = parser.parseFromString(html, 'text/html');
      const newFrag = doc.querySelector('.media-list');
      const old = document.querySelector('.media-list');
      if (requestId !== listRequestSequence) return;

      if (newFrag && old) {
        old.replaceWith(newFrag);
      } else {
        const panel = document.getElementById('panel-list');
        if (panel) panel.innerHTML = html;
      }
    } catch (err) {
      if (err && err.name === 'AbortError') return;
      console.error('Gagal load list.php:', err);
      if (!silent) uiToast('error', '<?=__('Media')?>', '<?=__('Failed to load media:')?> ' + (err.message || err));
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
      await reloadListFragment(q, p, false, t.dataset.perPage || null);
      return;
    }

    if (t.matches('#delete-bulk-btn')) {
      ev.preventDefault();

      const checked = Array.from(document.querySelectorAll('.row-checkbox:checked')).map(cb => cb.value);
      if (checked.length === 0) {
        uiToast('warning', '<?=__('Media')?>', '<?=__('Select at least one media to delete.')?>');
        return;
      }

      const ok = await uiAsk('danger', {
        title: <?= json_encode(__('Move selected media to trash')) ?>,
        message: <?= json_encode(__('')) ?> + checked.length + <?= json_encode(__(' media will be moved to trash.')) ?>,
        confirmText: <?= json_encode(__('Yes, move to trash')) ?>,
        cancelText: <?= json_encode(__('Cancel')) ?>
      });
      if (!ok) return;

      const fd = new FormData();
      checked.forEach(id => fd.append('ids[]', id));

      const csrf = getCsrfToken();
      if (csrf) fd.append('csrf_token', csrf);

      try {
        const res = await fetch('<?= ADMIN_BASE_PATH ?>/admin/media/delete_bulk.php', {
          method: 'POST',
          credentials: 'include',
          body: fd
        });

        const { txt, j } = await readJsonSafe(res);

        if (!res.ok) {
          uiToast('error', '<?=__('Media')?>', j?.error || txt || ('HTTP ' + res.status));
          return;
        }

        if (j && j.ok) {
          uiToast('success', '<?=__('Media')?>', <?= json_encode(__('%d media moved to trash.')) ?>.replace('%d', j.deleted_count || checked.length), undefined, j.action);
          if (Array.isArray(j.warnings) && j.warnings.length) {
            uiToast('warning', '<?=__('Media')?>', j.warnings.map(item => item && item.message ? item.message : String(item)).join('\n'));
          }

          document.dispatchEvent(new CustomEvent('media:deleted', { detail: { ids: checked, result: j } }));
        } else {
          uiToast('error', '<?=__('Media')?>', j?.error || txt || '<?=__('Something happened.')?>');
        }
      } catch (err) {
        uiToast('error', '<?=__('Media')?>', '<?=__('Network error:')?> ' + (err.message || err));
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
    if (t.matches('#visibility-filter, #media-per-page')) {
      reloadListFragment(currentQ(), 1, false);
    }
  }, false);

  function currentQ() {
    return document.getElementById('media-search') ? document.getElementById('media-search').value.trim() : '';
  }
  function currentPage() {
    const el = document.querySelector('.media-pagination strong');
    return el ? parseInt(el.textContent, 10) : 1;
  }

})();
</script>
