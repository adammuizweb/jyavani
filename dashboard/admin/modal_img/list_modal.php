<?php
declare(strict_types=1);

// /adiwira/admin/modal_img/list_modal.php
require_once __DIR__ . '/../_guard.php';

if (!defined('DASHBOARD_CONTEXT') && !defined('ADAM_THEME')) {
    if (function_exists('adiwira_is_navigate_request') && adiwira_is_navigate_request()) {
        http_response_code(404);
        require FRONTEND_404_PATH;
        exit;
    }
}

if (!isset($pdo)) {
    require_once __DIR__ . '/../_guard.php';
}

if (!isset($uid) || !isset($role)) {
    [$uid, $role] = adiwira_require_editorial($pdo, false);
}

$isAdmin = ((string)$role === 'admin');

$proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host  = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? '');
$baseUrl = rtrim($proto . '://' . $host, '/');

$search   = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$page     = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPageOptions = [20, 50, 100, 200];
$requestedPerPage = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 20;
$per_page = in_array($requestedPerPage, $perPageOptions, true) ? $requestedPerPage : 20;
$filterVisibility = isset($_GET['visibility']) ? strtolower(trim((string)$_GET['visibility'])) : '';
if (!in_array($filterVisibility, ['public','private'], true)) $filterVisibility = '';

if (!function_exists('mdlib_has_column')) {
    function mdlib_has_column(PDO $pdo, string $column): bool
    {
        try {
            $st = $pdo->prepare("SELECT {$column} FROM media LIMIT 0");
            $st->execute();
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }
}
$hasVisibility = mdlib_has_column($pdo, 'visibility');

if (!function_exists('mdlib_media_client_url')) {
    function mdlib_media_client_url(array $row): string
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

if ($filterVisibility !== '') {
    $where[] = 'visibility = :visibility';
    $params[':visibility'] = $filterVisibility;
}

$whereSql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';

try {
    $countSql = "SELECT COUNT(*) FROM media" . $whereSql;
    $countStmt = $pdo->prepare($countSql);
    foreach ($params as $k => $v) {
        $countStmt->bindValue($k, $v);
    }
    $countStmt->execute();
    $total = (int)$countStmt->fetchColumn();

    $total_pages = (int)max(1, ceil($total / $per_page));
    if ($page > $total_pages) $page = $total_pages;

    $offset = ($page - 1) * $per_page;

    $sql = "SELECT * FROM media" . $whereSql . " ORDER BY id DESC LIMIT :limit OFFSET :offset";
    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':limit', (int)$per_page, PDO::PARAM_INT);
    $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    error_log('modal_img/list_modal.php error: ' . $e->getMessage());
    $rows = [];
    $total = 0;
    $total_pages = 1;
    $offset = 0;
}
?>
<div id="mdlib-list-root">
  <div class="mdlib-bar">
    <input id="mdlib-search" class="mdlib-input" placeholder="<?=_e('Search title/filename/caption...')?>" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
    <select id="mdlib-visibility-filter" class="mdlib-select">
      <option value="" <?= $filterVisibility === '' ? 'selected' : '' ?>><?=_e('All')?></option>
      <option value="public" <?= $filterVisibility === 'public' ? 'selected' : '' ?>><?=_e('Public')?></option>
      <option value="private" <?= $filterVisibility === 'private' ? 'selected' : '' ?>><?=_e('Private')?></option>
    </select>
    <label class="mdlib-page-size">
      <span><?= _e('Items per page') ?></span>
      <select id="mdlib-per-page" class="mdlib-select" aria-label="<?= _e('Items per page') ?>">
        <?php foreach ($perPageOptions as $option): ?>
          <option value="<?= $option ?>" <?= $per_page === $option ? 'selected' : '' ?>><?= $option ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <button id="mdlib-search-btn" class="mdlib-btn" type="button"><?= _e('Search') ?></button>
  </div>

  <div id="mdlib-gallery-container">
    <?php if (count($rows) === 0): ?>
      <div class="mdlib-note"><?= _e('No media') ?></div>
    <?php else: ?>
      <div class="mdlib-pic-grid" id="mdlib-pic-grid">
        <?php foreach ($rows as $r): ?>
          <?php
            $id = (int)$r['id'];
            $rawUrl = (string)($r['url'] ?? '');

            $visibility = $hasVisibility ? (strtolower((string)($r['visibility'] ?? 'public')) ?: 'public') : 'public';
            $storageDisk = $hasVisibility ? (strtolower((string)($r['storage_disk'] ?? 'public')) ?: 'public') : 'public';
            $accessScope = $hasVisibility ? (strtolower((string)($r['access_scope'] ?? 'public')) ?: 'public') : 'public';
            $isDownloadable = $hasVisibility ? (int)($r['is_downloadable'] ?? 1) : 1;

            $clientUrl = mdlib_media_client_url($r);
            $url = ($visibility === 'private' || $storageDisk === 'private') ? $clientUrl : $rawUrl;

            if ($url !== '' && !preg_match('#^https?://#i', $url)) {
                if (substr($url, 0, 1) === '/') $url = $baseUrl . $url;
                else $url = $baseUrl . '/' . ltrim($url, '/');
            }

            $title = (string)($r['title'] ?? '');
            $caption = (string)($r['caption'] ?? '');
            $alt = (string)($r['alt'] ?? '');
            $credit = (string)($r['credit'] ?? '');
            $filename = (string)($r['filename'] ?? '');
            $mime = (string)($r['mime'] ?? '');
            $size = (string)($r['size'] ?? '');
          ?>
          <div class="mdlib-pic"
               data-id="<?= $id ?>"
               data-url="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>"
               data-protected-url="<?= htmlspecialchars($clientUrl, ENT_QUOTES, 'UTF-8') ?>"
               data-title="<?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?>"
               data-alt="<?= htmlspecialchars($alt, ENT_QUOTES, 'UTF-8') ?>"
               data-caption="<?= htmlspecialchars($caption, ENT_QUOTES, 'UTF-8') ?>"
               data-credit="<?= htmlspecialchars($credit, ENT_QUOTES, 'UTF-8') ?>"
               data-filename="<?= htmlspecialchars($filename, ENT_QUOTES, 'UTF-8') ?>"
               data-mime="<?= htmlspecialchars($mime, ENT_QUOTES, 'UTF-8') ?>"
               data-size="<?= htmlspecialchars($size, ENT_QUOTES, 'UTF-8') ?>"
               data-visibility="<?= htmlspecialchars($visibility, ENT_QUOTES, 'UTF-8') ?>"
               data-storage-disk="<?= htmlspecialchars($storageDisk, ENT_QUOTES, 'UTF-8') ?>"
               data-access-scope="<?= htmlspecialchars($accessScope, ENT_QUOTES, 'UTF-8') ?>"
               data-is-downloadable="<?= (int)$isDownloadable ?>">
            <img src="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($alt ?: $title, ENT_QUOTES, 'UTF-8') ?>" loading="lazy" decoding="async">
            <div class="mdlib-pic-info">
              <div class="mdlib-pic-title"><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></div>
              <div class="mdlib-pic-sub"><?= htmlspecialchars($filename, ENT_QUOTES, 'UTF-8') ?></div>
              <div class="mdlib-badges" style="margin-top:4px">
                <span class="mdlib-pill mdlib-pill-<?= htmlspecialchars($visibility, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(strtoupper($visibility), ENT_QUOTES, 'UTF-8') ?></span>
                <span class="mdlib-pill"><?= htmlspecialchars(content_access_scope_label($accessScope), ENT_QUOTES, 'UTF-8') ?></span>
              </div>
            </div>
            <div class="mdlib-pic-actions">
              <button class="mdlib-btn-detail" type="button" data-id="<?= $id ?>">Detail</button>
              <button class="mdlib-btn-insert" type="button" data-id="<?= $id ?>" data-url="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>">Insert</button>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <div class="mdlib-flex-between">
        <div class="mdlib-pager" id="mdlib-pager">
          <?php
          $pageNumbers = $total_pages <= 5 ? range(1, $total_pages) : [1, 2, $page, $total_pages - 1, $total_pages];
          $pageNumbers = array_values(array_unique(array_filter($pageNumbers, static fn(int $number): bool => $number >= 1 && $number <= $total_pages)));
          sort($pageNumbers);

          $pageUrl = function(int $p, string $q, int $perPage, string $vf): string {
              $parts = [];
              if ($q !== '') $parts[] = 'q=' . urlencode($q);
              $parts[] = 'page=' . $p;
              $parts[] = 'per_page=' . $perPage;
              if ($vf !== '') $parts[] = 'visibility=' . urlencode($vf);
              return ADMIN_BASE_PATH . '/admin/modal_img/list_modal.php?' . implode('&', $parts);
          };

          if ($page <= 1) {
              echo '<span class="disabled">' . __('Previous') . '</span>';
          } else {
              echo '<a href="' . htmlspecialchars($pageUrl($page - 1, $search, $per_page, $filterVisibility), ENT_QUOTES, 'UTF-8') . '" data-page="' . ($page - 1) . '">' . __('Previous') . '</a>';
          }

          $previousNumber = 0;
          foreach ($pageNumbers as $number) {
              if ($previousNumber > 0 && $number > $previousNumber + 1) echo '<span class="disabled">…</span>';
              if ($number === $page) {
                  echo '<span class="current" data-page="' . $number . '">' . $number . '</span>';
              } else {
                  echo '<a href="' . htmlspecialchars($pageUrl($number, $search, $per_page, $filterVisibility), ENT_QUOTES, 'UTF-8') . '" data-page="' . $number . '">' . $number . '</a>';
              }
              $previousNumber = $number;
          }

          if ($page >= $total_pages) {
              echo '<span class="disabled">' . __('Next') . '</span>';
          } else {
              echo '<a href="' . htmlspecialchars($pageUrl($page + 1, $search, $per_page, $filterVisibility), ENT_QUOTES, 'UTF-8') . '" data-page="' . ($page + 1) . '">' . __('Next') . '</a>';
          }
          ?>
        </div>

        <span class="disabled" style="border:0;background:transparent;cursor:default">
          <?= $total === 0 ? 0 : ($offset + 1) ?>–<?= min($offset + $per_page, $total) ?> / <?= $total ?>
        </span>
      </div>
    <?php endif; ?>
  </div>
</div>

<script>
(function(){
  var root = document.getElementById('mdlib-list-root');
  if (!root) return;

  if (root.dataset.modalimgBound === '1') {
    return;
  }
  root.dataset.modalimgBound = '1';
  var requestSequence = 0;
  var activeController = null;
  var galleryBackup = '';

  function uiToast(type, title, message, duration){
    if (typeof window.modalImgToast === 'function') {
      window.modalImgToast(type, title, message, duration);
      return;
    }
    alert(message || title || <?= json_encode(__('Something happened.')) ?>);
  }

  function broadcast(name, detail) {
    try { document.dispatchEvent(new CustomEvent(name, { detail: detail })); } catch(e){}
    try { window.dispatchEvent(new CustomEvent(name, { detail: detail })); } catch(e){}
    try {
      if (window.parent && window.parent !== window) {
        window.parent.postMessage({ type: name, detail: detail }, '*');
      }
    } catch(e){}
  }

  function withTs(url) {
    return url + (url.indexOf('?') >= 0 ? '&' : '?') + '_ts=' + Date.now();
  }

  function replaceRootOnly(html) {
    var parser = new DOMParser();
    var doc = parser.parseFromString(String(html || ''), 'text/html');
    var nextRoot = doc.getElementById('mdlib-list-root');
    if (!nextRoot) throw new Error('Invalid gallery response');

    root.innerHTML = nextRoot.innerHTML;
  }

  function imageSkeleton() {
    var cards = '';
    for (var i = 0; i < 8; i++) {
      cards += '<div class="mdlib-skeleton-card"><span class="mdlib-skeleton-thumb adam-shimmer"></span><span class="mdlib-skeleton-line adam-shimmer"></span><span class="mdlib-skeleton-line mdlib-skeleton-line--short adam-shimmer"></span></div>';
    }
    return '<div class="mdlib-list-skeleton mdlib-list-skeleton--image" aria-hidden="true">' + cards + '</div>';
  }

  function buildListUrl(page) {
    var input = root.querySelector('#mdlib-search');
    var visibility = root.querySelector('#mdlib-visibility-filter');
    var perPage = root.querySelector('#mdlib-per-page');
    var url = '<?= ADMIN_BASE_PATH ?>/admin/modal_img/list_modal.php?q=' + encodeURIComponent(input ? input.value || '' : '') + '&page=' + encodeURIComponent(page || 1) + '&per_page=' + encodeURIComponent(perPage ? perPage.value : '<?= (int)$per_page ?>');
    if (visibility && visibility.value) url += '&visibility=' + encodeURIComponent(visibility.value);
    return url;
  }

  function requestList(url) {
    var requestId = ++requestSequence;
    if (activeController) activeController.abort();
    activeController = typeof AbortController === 'function' ? new AbortController() : null;
    var gallery = root.querySelector('#mdlib-gallery-container');
    if (gallery) {
      if (gallery.getAttribute('aria-busy') !== 'true') galleryBackup = gallery.innerHTML;
      gallery.setAttribute('aria-busy', 'true');
      gallery.innerHTML = imageSkeleton();
    }
    return fetch(withTs(url), {
      credentials: 'include',
      cache: 'no-store',
      signal: activeController ? activeController.signal : undefined
    })
      .then(function(res){
        if (!res.ok) throw new Error('HTTP ' + res.status);
        return res.text();
      })
      .then(function(html){
        if (requestId !== requestSequence) return;
        replaceRootOnly(html);
        broadcast('modal:gallery:updated', {});
      })
      .catch(function(err){
        if (requestId !== requestSequence || err.name === 'AbortError') return;
        console.error('modal_img list fetch error', err);
        var currentGallery = root.querySelector('#mdlib-gallery-container');
        if (currentGallery) currentGallery.innerHTML = galleryBackup;
        uiToast('error', <?= json_encode(__('Gallery')) ?>, <?= json_encode(__('Failed to load gallery:') . ' ') ?> + (err.message || err), 6000);
      })
      .finally(function(){
        if (requestId !== requestSequence) return;
        activeController = null;
        var currentGallery = root.querySelector('#mdlib-gallery-container');
        if (currentGallery) currentGallery.removeAttribute('aria-busy');
      });
  }

  root.addEventListener('click', function(ev){
    var target = ev.target;

    var pag = target.closest && target.closest('#mdlib-pager a');
    if (pag) {
      ev.preventDefault();
      var href = pag.getAttribute('href');
      if (!href) return;
      requestList(href);
      return;
    }

    var detailBtn = target.closest && target.closest('.mdlib-btn-detail');
    if (detailBtn) {
      ev.preventDefault();

      var id = detailBtn.getAttribute('data-id');
      if (!id) {
        uiToast('warning', '<?=__('Gallery')?>', '<?=__('Media ID not found.')?>', 4000);
        return;
      }

      var url = '<?= ADMIN_BASE_PATH ?>/admin/modal_img/single_modal.php?id=' + encodeURIComponent(id) + '&embedded=1';

      try {
        if (window.parent && window.parent !== window && typeof window.parent.adamModalOpen === 'function') {
          window.parent.adamModalOpen(url, { maxWidth: '820px' });
          return;
        }
      } catch(e){}

      try {
        if (typeof window.adamModalOpen === 'function') {
          window.adamModalOpen(url, { maxWidth: '820px' });
          return;
        }
      } catch(e){}

      window.open(url, '_blank');
      return;
    }

    var insertBtn = target.closest && target.closest('.mdlib-btn-insert');
    if (insertBtn) {
      ev.preventDefault();

      var thumb = insertBtn.closest && insertBtn.closest('.mdlib-pic');
      if (!thumb) return;

      var id = thumb.getAttribute('data-id');
      var url = thumb.getAttribute('data-url') || '';
      var protectedUrl = thumb.getAttribute('data-protected-url') || '';
      var title = (thumb.getAttribute('data-title') || '').trim();
      var alt = (thumb.getAttribute('data-alt') || '').trim();
      var caption = (thumb.getAttribute('data-caption') || '').trim();
      var credit = (thumb.getAttribute('data-credit') || '').trim();
      var filename = thumb.getAttribute('data-filename') || '';
      var mime = thumb.getAttribute('data-mime') || '';
      var size = thumb.getAttribute('data-size') || '';
      var visibility = thumb.getAttribute('data-visibility') || 'public';
      var storageDisk = thumb.getAttribute('data-storage-disk') || 'public';
      var accessScope = thumb.getAttribute('data-access-scope') || 'public';
      var isDownloadable = thumb.getAttribute('data-is-downloadable') || '1';

      var detail = {
        id: id ? parseInt(id, 10) : null,
        url: url,
        protected_url: protectedUrl,
        title: title,
        alt: alt,
        caption: caption,
        credit: credit,
        filename: filename,
        mime: mime,
        size: size,
        visibility: visibility,
        storage_disk: storageDisk,
        access_scope: accessScope,
        is_downloadable: isDownloadable
      };

      broadcast('media:insert', detail);
      broadcast('file:insert', detail);

      try {
        if (window.parent && window.parent !== window && typeof window.parent.adamModalClose === 'function') {
          window.parent.adamModalClose();
          return;
        }
      } catch(e){}

      try {
        if (typeof window.adamModalClose === 'function') {
          window.adamModalClose();
        }
      } catch(e){}
      return;
    }

    var searchBtn = target.closest && target.closest('#mdlib-search-btn');
    if (searchBtn) {
      ev.preventDefault();
      requestList(buildListUrl(1));
      return;
    }
  }, false);

  root.addEventListener('change', function(ev){
    var target = ev.target;
    if (target && (target.id === 'mdlib-visibility-filter' || target.id === 'mdlib-per-page')) {
      requestList(buildListUrl(1));
    }
  });

  root.addEventListener('keydown', function(ev){
    var target = ev.target;
    if (!target || target.id !== 'mdlib-search') return;

    if (ev.key === 'Enter') {
      ev.preventDefault();
      requestList(buildListUrl(1));
    }
  }, false);
})();
</script>
