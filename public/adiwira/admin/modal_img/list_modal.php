<?php
declare(strict_types=1);

// /adiwira/admin/modal_img/list_modal.php
if (!defined('DASHBOARD_CONTEXT') && !defined('ADAM_THEME')) {
    require_once __DIR__ . '/../_guard.php';

    if (function_exists('adiwira_is_navigate_request') && adiwira_is_navigate_request()) {
        http_response_code(404);
        require __DIR__ . '/../../../frontend_404.php';
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
$per_page = isset($_GET['per_page']) ? max(1, min(200, (int)$_GET['per_page'])) : 10;

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

<div id="modalimg-list-root">
  <div class="search-row">
    <input id="modal-search" placeholder="Cari title/filename/caption..." value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>" style="flex:1;padding:8px;border:1px solid #ccc;border-radius:6px">
    <button id="modal-search-btn" style="padding:8px;border-radius:6px">Cari</button>
  </div>

  <div id="gallery-container">
    <?php if (count($rows) === 0): ?>
      <div class="small">Tidak ada media</div>
    <?php else: ?>
      <div class="gallery-grid" id="gallery-grid">
        <?php foreach ($rows as $r): ?>
          <?php
            $id = (int)$r['id'];
            $url = (string)($r['url'] ?? '');

            if ($url !== '' && !preg_match('#^https?://#i', $url)) {
                if (substr($url, 0, 1) === '/') $url = $baseUrl . $url;
                else $url = $baseUrl . '/' . ltrim($url, '/');
            }

            $title = (string)($r['title'] ?? '');
            $caption = (string)($r['caption'] ?? '');
            $alt = (string)($r['alt'] ?? '');
            $credit = (string)($r['credit'] ?? '');
            $filename = (string)($r['filename'] ?? '');
          ?>
          <div class="gallery-thumb"
               data-id="<?= $id ?>"
               data-url="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>"
               data-title="<?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?>"
               data-alt="<?= htmlspecialchars($alt, ENT_QUOTES, 'UTF-8') ?>"
               data-caption="<?= htmlspecialchars($caption, ENT_QUOTES, 'UTF-8') ?>"
               data-credit="<?= htmlspecialchars($credit, ENT_QUOTES, 'UTF-8') ?>">
            <img src="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($alt ?: $title, ENT_QUOTES, 'UTF-8') ?>">
            <div class="thumb-info">
              <div style="font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
                <?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?>
              </div>
              <div class="small"><?= htmlspecialchars($filename, ENT_QUOTES, 'UTF-8') ?></div>
            </div>
            <div class="thumb-actions">
              <button class="btn-detail" type="button" data-id="<?= $id ?>">Detail</button>
              <button class="btn-insert" type="button" data-id="<?= $id ?>" data-url="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>">Insert</button>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:8px">
        <div class="pagination" id="pagination">
          <?php
          $max_links = 7;
          $half = (int) floor($max_links / 2);
          $start = max(1, $page - $half);
          $end = min($total_pages, $start + $max_links - 1);
          if (($end - $start + 1) < $max_links) {
              $start = max(1, $end - $max_links + 1);
          }

          $pageUrl = function(int $p, string $q, int $perPage): string {
              $parts = [];
              if ($q !== '') $parts[] = 'q=' . urlencode($q);
              $parts[] = 'page=' . $p;
              $parts[] = 'per_page=' . $perPage;
              return '/adiwira/admin/modal_img/list_modal.php?' . implode('&', $parts);
          };

          if ($page <= 1) {
              echo '<span class="disabled">Prev</span>';
          } else {
              echo '<a href="' . htmlspecialchars($pageUrl($page - 1, $search, $per_page), ENT_QUOTES, 'UTF-8') . '" data-page="' . ($page - 1) . '">Prev</a>';
          }

          if ($start > 1) {
              echo '<a href="' . htmlspecialchars($pageUrl(1, $search, $per_page), ENT_QUOTES, 'UTF-8') . '" data-page="1">1</a>';
              if ($start > 2) echo '<span class="disabled">…</span>';
          }

          for ($i = $start; $i <= $end; $i++) {
              if ($i === $page) {
                  echo '<span class="current" data-page="' . $i . '">' . $i . '</span>';
              } else {
                  echo '<a href="' . htmlspecialchars($pageUrl($i, $search, $per_page), ENT_QUOTES, 'UTF-8') . '" data-page="' . $i . '">' . $i . '</a>';
              }
          }

          if ($end < $total_pages) {
              if ($end < $total_pages - 1) echo '<span class="disabled">…</span>';
              echo '<a href="' . htmlspecialchars($pageUrl($total_pages, $search, $per_page), ENT_QUOTES, 'UTF-8') . '" data-page="' . $total_pages . '">' . $total_pages . '</a>';
          }

          if ($page >= $total_pages) {
              echo '<span class="disabled">Next</span>';
          } else {
              echo '<a href="' . htmlspecialchars($pageUrl($page + 1, $search, $per_page), ENT_QUOTES, 'UTF-8') . '" data-page="' . ($page + 1) . '">Next</a>';
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
  var root = document.getElementById('modalimg-list-root');
  if (!root) return;

  if (root.dataset.modalimgBound === '1') {
    return;
  }
  root.dataset.modalimgBound = '1';

  function uiToast(type, title, message, duration){
    if (typeof window.modalImgToast === 'function') {
      window.modalImgToast(type, title, message, duration);
      return;
    }
    alert(message || title || 'Terjadi sesuatu.');
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
    var nextRoot = doc.getElementById('modalimg-list-root');
    if (!nextRoot) return;

    root.innerHTML = nextRoot.innerHTML;
  }

  function requestList(url) {
    return fetch(withTs(url), {
      credentials: 'include',
      cache: 'no-store'
    })
      .then(function(res){
        if (!res.ok) throw new Error('HTTP ' + res.status);
        return res.text();
      })
      .then(function(html){
        replaceRootOnly(html);
        broadcast('modal:gallery:updated', {});
      })
      .catch(function(err){
        console.error('modal_img list fetch error', err);
        uiToast('error', 'Gallery', 'Gagal memuat gallery: ' + (err.message || err), 6000);
      });
  }

  root.addEventListener('click', function(ev){
    var target = ev.target;

    var pag = target.closest && target.closest('#pagination a');
    if (pag) {
      ev.preventDefault();
      var href = pag.getAttribute('href');
      if (!href) return;
      requestList(href);
      return;
    }

    var detailBtn = target.closest && target.closest('.btn-detail');
    if (detailBtn) {
      ev.preventDefault();

      var id = detailBtn.getAttribute('data-id');
      if (!id) {
        uiToast('warning', 'Gallery', 'ID media tidak ditemukan.', 4000);
        return;
      }

      var url = '/adiwira/admin/modal_img/single_modal.php?id=' + encodeURIComponent(id) + '&embedded=1';

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

    var insertBtn = target.closest && target.closest('.btn-insert');
    if (insertBtn) {
      ev.preventDefault();

      var thumb = insertBtn.closest && insertBtn.closest('.gallery-thumb');
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

    var searchBtn = target.closest && target.closest('#modal-search-btn');
    if (searchBtn) {
      ev.preventDefault();
      var q = '';
      var input = root.querySelector('#modal-search');
      if (input) q = input.value || '';
      requestList('/adiwira/admin/modal_img/list_modal.php?q=' + encodeURIComponent(q) + '&page=1&per_page=<?= (int)$per_page ?>');
      return;
    }
  }, false);

  root.addEventListener('keydown', function(ev){
    var target = ev.target;
    if (!target || target.id !== 'modal-search') return;

    if (ev.key === 'Enter') {
      ev.preventDefault();
      var q = target.value || '';
      requestList('/adiwira/admin/modal_img/list_modal.php?q=' + encodeURIComponent(q) + '&page=1&per_page=<?= (int)$per_page ?>');
    }
  }, false);
})();
</script>