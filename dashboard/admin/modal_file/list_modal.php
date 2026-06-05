<?php
declare(strict_types=1);

// /adiwira/admin/modal_file/list_modal.php
ob_start();
require_once __DIR__ . '/../_guard.php';

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    adiwira_cosmetic_404_on_direct_open();
}

[$uid, $role] = adiwira_require_editorial($pdo, false);
$isAdmin = ($role === 'admin');

$proto   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host    = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? '');
$baseUrl = rtrim($proto . '://' . $host, '/');

$q        = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$p        = isset($_GET['p']) ? max(1, (int)$_GET['p']) : 1;
$per_page = isset($_GET['per_page']) ? max(1, min(100, (int)$_GET['per_page'])) : 12;
$filterVisibility = isset($_GET['visibility']) ? strtolower(trim((string)$_GET['visibility'])) : '';
if (!in_array($filterVisibility, ['public','private'], true)) $filterVisibility = '';

if (!function_exists('mdlib_to_utf8')) {
    function mdlib_to_utf8($value): string
    {
        if ($value === null) return '';

        if (is_bool($value)) {
            $str = $value ? '1' : '0';
        } elseif (is_scalar($value)) {
            $str = (string)$value;
        } else {
            $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $str = ($json !== false) ? $json : '';
        }

        if ($str === '') return '';

        if (preg_match('//u', $str)) {
            return $str;
        }

        if (function_exists('mb_convert_encoding')) {
            $fixed = @mb_convert_encoding($str, 'UTF-8', 'UTF-8');
            if (is_string($fixed) && $fixed !== '' && preg_match('//u', $fixed)) {
                return $fixed;
            }
        }

        if (function_exists('iconv')) {
            $fixed = @iconv('UTF-8', 'UTF-8//IGNORE', $str);
            if (is_string($fixed) && $fixed !== '' && preg_match('//u', $fixed)) {
                return $fixed;
            }
        }

        if (function_exists('mb_convert_encoding')) {
            $fixed = @mb_convert_encoding($str, 'UTF-8', 'ISO-8859-1');
            if (is_string($fixed) && $fixed !== '' && preg_match('//u', $fixed)) {
                return $fixed;
            }
        }

        return $str;
    }
}

if (!function_exists('mdlib_e')) {
    function mdlib_e($value): string
    {
        return htmlspecialchars(
            mdlib_to_utf8($value),
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
    }
}

if (!function_exists('mdlib_abs_url')) {
    function mdlib_abs_url(string $baseUrl, $url): string
    {
        $url = trim(mdlib_to_utf8($url));
        if ($url === '') return '';
        if (preg_match('#^https?://#i', $url)) return $url;
        if (isset($url[0]) && $url[0] === '/') return $baseUrl . $url;
        return $baseUrl . '/' . ltrim($url, '/');
    }
}

if (!function_exists('mdlib_human_bytes')) {
    function mdlib_human_bytes($bytes): string
    {
        $b = (int)$bytes;
        if ($b <= 0) return '0 B';
        $units = ['B','KB','MB','GB','TB'];
        $i = (int)floor(log($b, 1024));
        $i = max(0, min($i, count($units) - 1));
        $val = $b / (1024 ** $i);
        return number_format($val, ($i === 0 ? 0 : 1)) . ' ' . $units[$i];
    }
}

if (!function_exists('mdlib_client_url')) {
    function mdlib_client_url(array $row): string
    {
        $id = (int)($row['id'] ?? 0);
        $visibility = strtolower((string)($row['visibility'] ?? 'public'));
        $disk = strtolower((string)($row['storage_disk'] ?? 'public'));
        if ($id > 0 && ($visibility === 'private' || $disk === 'private')) {
            $mime = strtolower((string)($row['mime'] ?? ''));
            $ext = strtolower((string)($row['ext'] ?? pathinfo((string)($row['filename'] ?? ''), PATHINFO_EXTENSION)));
            if ($mime === 'application/pdf' || $ext === 'pdf') {
                return '/private/pdf/view/?id=' . $id;
            }
            return '/private/file/view/?id=' . $id;
        }
        return (string)($row['url'] ?? '');
    }
}

if (!function_exists('mdlib_has_column')) {
    function mdlib_has_column(PDO $pdo, string $column): bool
    {
        try {
            $st = $pdo->prepare("SELECT {$column} FROM `file` LIMIT 0");
            $st->execute();
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }
}

try {
    $where = [];
    $params = [];

    if (!$isAdmin) {
        $where[] = 'user_id = :uid';
        $params[':uid'] = $uid;
    }

    $hasVisibility = mdlib_has_column($pdo, 'visibility');

    if ($q !== '') {
        $searchFields = 'title LIKE :q OR filename LIKE :q OR caption LIKE :q OR mime LIKE :q';
        if ($hasVisibility) {
            $searchFields .= ' OR access_scope LIKE :q OR visibility LIKE :q';
        }
        $where[] = '(' . $searchFields . ')';
        $params[':q'] = '%' . $q . '%';
    }

    if ($filterVisibility !== '') {
        $where[] = 'visibility = :visibility';
        $params[':visibility'] = $filterVisibility;
    }

    $whereSql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM `file`" . $whereSql);
    foreach ($params as $k => $v) {
        $countStmt->bindValue($k, $v);
    }
    $countStmt->execute();
    $total = (int)$countStmt->fetchColumn();

    $total_pages = (int)max(1, (int)ceil($total / $per_page));
    if ($p > $total_pages) $p = $total_pages;
    $offset = ($p - 1) * $per_page;

    $st = $pdo->prepare("SELECT * FROM `file`" . $whereSql . " ORDER BY id DESC LIMIT :lim OFFSET :off");
    foreach ($params as $k => $v) {
        $st->bindValue($k, $v);
    }
    $st->bindValue(':lim', (int)$per_page, PDO::PARAM_INT);
    $st->bindValue(':off', (int)$offset, PDO::PARAM_INT);
    $st->execute();
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

} catch (Throwable $e) {
    error_log('modal_file/list_modal.php query error: ' . $e->getMessage());
    $rows = [];
    $total = 0;
    $total_pages = 1;
    $offset = 0;
}
?>

<div id="mdlib-lib-wrap">
  <div class="mdlib-lib" id="mdlib-lib" data-per-page="<?= (int)$per_page ?>">
    <div class="mdlib-searchrow">
      <input class="mdlib-input" id="mdlib-search" placeholder="Cari title/filename/caption/mime..." value="<?= mdlib_e($q) ?>">
      <select class="mdlib-select" id="mdlib-visibility-filter">
        <option value="" <?= $filterVisibility === '' ? 'selected' : '' ?>>Semua</option>
        <option value="public" <?= $filterVisibility === 'public' ? 'selected' : '' ?>>Public</option>
        <option value="private" <?= $filterVisibility === 'private' ? 'selected' : '' ?>>Private</option>
      </select>
      <button class="mdlib-btn mdlib-btn-primary" type="button" data-mdlib-action="search"><?= _e('Search') ?></button>
    </div>

    <?php if (!$rows): ?>
      <div class="mdlib-note" style="padding:8px 2px">Belum ada file.</div>
    <?php else: ?>
      <div class="mdlib-grid">
        <?php foreach ($rows as $r): ?>
          <?php
          try {
              $id = (int)($r['id'] ?? 0);
              $rawTitle = mdlib_to_utf8($r['title'] ?? '');
              $rawFilename = mdlib_to_utf8($r['filename'] ?? $r['name'] ?? '');
              $displayName = $rawTitle !== '' ? $rawTitle : ($rawFilename !== '' ? $rawFilename : ('File #' . $id));
              $mime = mdlib_to_utf8($r['mime'] ?? '');
              $sizeTxt = mdlib_human_bytes((int)($r['size'] ?? 0));
              $url = mdlib_to_utf8($r['url'] ?? '');
              $abs = mdlib_abs_url($baseUrl, $url);

              $ext = mdlib_to_utf8($r['ext'] ?? '');
              if ($ext === '' && strpos($rawFilename, '.') !== false) {
                  $ext = strtolower((string)pathinfo($rawFilename, PATHINFO_EXTENSION));
              }
              $ico = $ext ? strtoupper(substr($ext, 0, 4)) : 'FILE';
          } catch (Throwable $rowErr) {
              error_log('modal_file/list_modal.php row render prep error id=' . (string)($r['id'] ?? 'unknown') . ': ' . $rowErr->getMessage());
              continue;
          }
          ?>
          <?php
              $visibility = strtolower(mdlib_to_utf8($r['visibility'] ?? 'public')) ?: 'public';
              $storageDisk = strtolower(mdlib_to_utf8($r['storage_disk'] ?? 'public')) ?: 'public';
              $accessScope = strtolower(mdlib_to_utf8($r['access_scope'] ?? 'public')) ?: 'public';
              $isDownloadable = (int)($r['is_downloadable'] ?? 1);
              $clientUrl = mdlib_client_url($r);
              $displayUrl = ($visibility === 'private' || $storageDisk === 'private') ? $clientUrl : $abs;
          ?>
          <div class="mdlib-card mdlib-card-<?= mdlib_e($visibility) ?>"
               data-id="<?= (int)$id ?>"
               data-filename="<?= mdlib_e($rawFilename) ?>"
               data-url="<?= mdlib_e($displayUrl) ?>"
               data-protected-url="<?= mdlib_e($clientUrl) ?>"
               data-mime="<?= mdlib_e($mime) ?>"
               data-size="<?= mdlib_e($sizeTxt) ?>"
               data-title="<?= mdlib_e($r['title'] ?? '') ?>"
               data-caption="<?= mdlib_e($r['caption'] ?? '') ?>"
               data-credit="<?= mdlib_e($r['credit'] ?? '') ?>"
               data-visibility="<?= mdlib_e($visibility) ?>"
               data-storage-disk="<?= mdlib_e($storageDisk) ?>"
               data-access-scope="<?= mdlib_e($accessScope) ?>"
               data-is-downloadable="<?= (int)$isDownloadable ?>">
            <div class="mdlib-ico"><?= mdlib_e($ico) ?></div>
            <div class="mdlib-meta">
              <div class="mdlib-name" title="<?= mdlib_e($displayName) ?>"><?= mdlib_e($displayName) ?></div>
              <div class="mdlib-sub" title="<?= mdlib_e($displayUrl ?: $clientUrl) ?>">
                <?= mdlib_e($mime ?: '—') ?> • <?= mdlib_e($sizeTxt) ?>
              </div>
              <div class="mdlib-badges">
                <span class="mdlib-pill mdlib-pill-<?= mdlib_e($visibility) ?>"><?= mdlib_e(strtoupper($visibility)) ?></span>
                <span class="mdlib-pill"><?= mdlib_e(strtoupper($accessScope)) ?></span>
                <?php if (!$isDownloadable): ?><span class="mdlib-pill">NO DOWNLOAD</span><?php endif; ?>
              </div>
              <div class="mdlib-actions">
                <button class="mdlib-btn mdlib-btn-primary" type="button" data-mdlib-action="insert">Insert</button>
                <button class="mdlib-btn" type="button" data-mdlib-action="detail"><?= _e('Details') ?></button>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <div class="mdlib-pager" id="mdlib-pager">
        <?php
        $mk = function(int $targetPage) use ($per_page, $q, $filterVisibility): string {
            $qs = 'p=' . $targetPage . '&per_page=' . (int)$per_page;
            if ($q !== '') $qs .= '&q=' . urlencode($q);
            if ($filterVisibility !== '') $qs .= '&visibility=' . urlencode($filterVisibility);
            return ADMIN_BASE_PATH . '/admin/modal_file/list_modal.php?' . $qs;
        };

        if ($p <= 1) {
            echo '<button type="button" class="disabled" disabled>Prev</button>';
        } else {
            echo '<button type="button" data-mdlib-href="' . mdlib_e($mk($p - 1)) . '">Prev</button>';
        }

        $start = max(1, $p - 2);
        $end   = min($total_pages, $p + 2);

        if ($start > 1) {
            echo '<button type="button" data-mdlib-href="' . mdlib_e($mk(1)) . '">1</button>';
            if ($start > 2) echo '<span class="disabled">…</span>';
        }

        for ($i = $start; $i <= $end; $i++) {
            if ($i === $p) {
                echo '<button type="button" class="current" disabled>' . (int)$i . '</button>';
            } else {
                echo '<button type="button" data-mdlib-href="' . mdlib_e($mk($i)) . '">' . (int)$i . '</button>';
            }
        }

        if ($end < $total_pages) {
            if ($end < $total_pages - 1) echo '<span class="disabled">…</span>';
            echo '<button type="button" data-mdlib-href="' . mdlib_e($mk($total_pages)) . '">' . (int)$total_pages . '</button>';
        }

        if ($p >= $total_pages) {
            echo '<button type="button" class="disabled" disabled>Next</button>';
        } else {
            echo '<button type="button" data-mdlib-href="' . mdlib_e($mk($p + 1)) . '">Next</button>';
        }
        ?>

        <span class="disabled" style="border:0;background:transparent;cursor:default">
          <?= $total ? (int)($offset + 1) . '–' . (int)min($offset + $per_page, $total) . ' / ' . (int)$total : '0' ?>
        </span>
      </div>
    <?php endif; ?>
  </div>
</div>

<script>
(function(){
  const wrap = document.getElementById('mdlib-lib-wrap');
  if (!wrap) return;

  if (wrap.dataset.mdlibBound === '1') {
    return;
  }
  wrap.dataset.mdlibBound = '1';

  function uiToast(type, title, message, duration) {
    if (window.mdlibUi && typeof window.mdlibUi.toast === 'function') {
      window.mdlibUi.toast(type, title, message, duration);
      return;
    }
    alert(message || title || 'Terjadi sesuatu.');
  }

  function broadcast(name, detail) {
    try { document.dispatchEvent(new CustomEvent(name, { detail })); } catch(e){}
    try { window.dispatchEvent(new CustomEvent(name, { detail })); } catch(e){}
    try {
      if (window.parent && window.parent !== window) {
        window.parent.postMessage({ type: name, detail: detail }, '*');
      }
    } catch(e){}
  }

  function parseIncomingHtml(html) {
    const parser = new DOMParser();
    const doc = parser.parseFromString(String(html || ''), 'text/html');
    const nextWrap = doc.getElementById('mdlib-lib-wrap');
    return nextWrap ? nextWrap.innerHTML : html;
  }

  function replaceListOnly(html) {
    wrap.innerHTML = parseIncomingHtml(html);
  }

  function withTs(url) {
    const hasQuery = url.indexOf('?') !== -1;
    return url + (hasQuery ? '&' : '?') + '_ts=' + Date.now();
  }

  function buildListUrl(page) {
    const q = (document.getElementById('mdlib-search')?.value || '').trim();
    const visibility = (document.getElementById('mdlib-visibility-filter')?.value || '').trim();
    let url = '<?= ADMIN_BASE_PATH ?>/admin/modal_file/list_modal.php?q=' + encodeURIComponent(q) + '&p=' + encodeURIComponent(page || 1) + '&per_page=<?= (int)$per_page ?>';
    if (visibility) url += '&visibility=' + encodeURIComponent(visibility);
    return url;
  }

  function requestList(url) {
    if (typeof window.mdlibLoadIntoRoot === 'function') {
      return window.mdlibLoadIntoRoot(url);
    }

    return fetch(withTs(url), { credentials: 'include', cache: 'no-store' })
      .then(function(res){
        if (!res.ok) throw new Error('HTTP ' + res.status);
        return res.text();
      })
      .then(function(html){
        replaceListOnly(html);
      })
      .catch(function(err){
        console.error('mdlib list fetch error', err);
        uiToast('error', 'Library File', 'Gagal memuat daftar file: ' + String(err.message || err), 6000);
      });
  }

  wrap.addEventListener('click', function(ev){
    const btn = ev.target.closest('button');
    if (!btn) return;

    const action = btn.getAttribute('data-mdlib-action');

    if (action === 'search') {
      ev.preventDefault();
      requestList(buildListUrl(1));
      return;
    }

    if (action === 'detail') {
      ev.preventDefault();
      const card = btn.closest('.mdlib-card');
      const id = card ? card.getAttribute('data-id') : '';
      if (!id) {
        uiToast('warning', 'Library File', '<?=__('File ID not found.')?>', 4000);
        return;
      }

      if (typeof window.mdlibOpenSingle === 'function') {
        window.mdlibOpenSingle(id);
      } else {
        fetch(withTs('<?= ADMIN_BASE_PATH ?>/admin/modal_file/single_modal.php?id=' + encodeURIComponent(id) + '&embedded=1'), {
          credentials: 'include',
          cache: 'no-store'
        })
          .then(function(res){
            if (!res.ok) throw new Error('HTTP ' + res.status);
            return res.text();
          })
          .then(function(html){
            wrap.innerHTML = html;
          })
          .catch(function(err){
            console.error('mdlib single fetch error', err);
            uiToast('error', 'Library File', 'Gagal memuat detail file: ' + String(err.message || err), 6000);
          });
      }
      return;
    }

    if (action === 'insert') {
      ev.preventDefault();
      const card = btn.closest('.mdlib-card');
      if (!card) return;

      const detail = {
        id: card.getAttribute('data-id') ? parseInt(card.getAttribute('data-id'), 10) : null,
        url: card.getAttribute('data-url') || '',
        protected_url: card.getAttribute('data-protected-url') || '',
        filename: card.getAttribute('data-filename') || '',
        title: card.getAttribute('data-title') || '',
        caption: card.getAttribute('data-caption') || '',
        credit: card.getAttribute('data-credit') || '',
        mime: card.getAttribute('data-mime') || '',
        size: card.getAttribute('data-size') || '',
        visibility: card.getAttribute('data-visibility') || 'public',
        storage_disk: card.getAttribute('data-storage-disk') || 'public',
        access_scope: card.getAttribute('data-access-scope') || 'public',
        is_downloadable: card.getAttribute('data-is-downloadable') || '1'
      };

      broadcast('file:insert', detail);
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

    const pagerUrl = btn.getAttribute('data-mdlib-href');
    if (pagerUrl) {
      ev.preventDefault();
      requestList(pagerUrl);
    }
  });

  wrap.addEventListener('change', function(ev){
    const target = ev.target;
    if (target && target.id === 'mdlib-visibility-filter') requestList(buildListUrl(1));
  });

  wrap.addEventListener('keydown', function(ev){
    const target = ev.target;
    if (!target || target.id !== 'mdlib-search') return;

    if (ev.key === 'Enter') {
      ev.preventDefault();
      requestList(buildListUrl(1));
    }
  });
})();
</script>
