<?php
declare(strict_types=1);

// /adiwira/admin/modal_file/list_modal.php
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
$page     = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$per_page = isset($_GET['per_page']) ? max(1, min(100, (int)$_GET['per_page'])) : 12;

if (!function_exists('modalfilez_e')) {
    function modalfilez_e($s): string
    {
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('modalfilez_abs_url')) {
    function modalfilez_abs_url(string $baseUrl, $url): string
    {
        $url = (string)$url;
        if ($url === '') return '';
        if (preg_match('#^https?://#i', $url)) return $url;
        if (isset($url[0]) && $url[0] === '/') return $baseUrl . $url;
        return $baseUrl . '/' . ltrim($url, '/');
    }
}

if (!function_exists('modalfilez_human_bytes')) {
    function modalfilez_human_bytes($bytes): string
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

$where = [];
$params = [];

if (!$isAdmin) {
    $where[] = 'user_id = :uid';
    $params[':uid'] = $uid;
}

if ($q !== '') {
    $where[] = '(title LIKE :q OR filename LIKE :q OR caption LIKE :q OR mime LIKE :q)';
    $params[':q'] = '%' . $q . '%';
}

$whereSql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';

try {
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM `file`" . $whereSql);
    foreach ($params as $k => $v) {
        $countStmt->bindValue($k, $v);
    }
    $countStmt->execute();
    $total = (int)$countStmt->fetchColumn();

    $total_pages = (int)max(1, (int)ceil($total / $per_page));
    if ($page > $total_pages) $page = $total_pages;
    $offset = ($page - 1) * $per_page;

    $st = $pdo->prepare("SELECT * FROM `file`" . $whereSql . " ORDER BY id DESC LIMIT :lim OFFSET :off");
    foreach ($params as $k => $v) {
        $st->bindValue($k, $v);
    }
    $st->bindValue(':lim', (int)$per_page, PDO::PARAM_INT);
    $st->bindValue(':off', (int)$offset, PDO::PARAM_INT);
    $st->execute();
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    echo '<div style="padding:12px;color:#c00">DB error: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</div>';
    $rows = [];
    $total = 0;
    $total_pages = 1;
    $offset = 0;
}
?>

<div id="modalfilez-lib-wrap">
  <div class="modalfilez-lib" id="modalfilez-lib" data-per-page="<?= (int)$per_page ?>">
    <div class="modalfilez-searchrow">
      <input class="modalfilez-input" id="modalfilez-search" placeholder="Cari title/filename/caption/mime..." value="<?= modalfilez_e($q) ?>">
      <button class="modalfilez-btn modalfilez-btn-primary" type="button" data-modalfilez-action="search">Cari</button>
    </div>

    <?php if (!$rows): ?>
      <div class="modalfilez-note" style="padding:8px 2px">Belum ada file.</div>
    <?php else: ?>
      <div class="modalfilez-grid">
        <?php foreach ($rows as $r): ?>
          <?php
            $id = (int)($r['id'] ?? 0);
            $filename = (string)($r['filename'] ?? $r['name'] ?? $r['title'] ?? ('File #' . $id));
            $mime = (string)($r['mime'] ?? '');
            $sizeTxt = modalfilez_human_bytes((int)($r['size'] ?? 0));
            $url = (string)($r['url'] ?? '');
            $abs = modalfilez_abs_url($baseUrl, $url);

            $ext = (string)($r['ext'] ?? '');
            if ($ext === '' && strpos($filename, '.') !== false) {
                $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            }
            $ico = $ext ? strtoupper(substr($ext, 0, 4)) : 'FILE';
          ?>
          <div class="modalfilez-card"
               data-id="<?= (int)$id ?>"
               data-filename="<?= modalfilez_e($filename) ?>"
               data-url="<?= modalfilez_e($abs) ?>"
               data-mime="<?= modalfilez_e($mime) ?>"
               data-size="<?= modalfilez_e($sizeTxt) ?>"
               data-title="<?= modalfilez_e((string)($r['title'] ?? '')) ?>"
               data-caption="<?= modalfilez_e((string)($r['caption'] ?? '')) ?>"
               data-credit="<?= modalfilez_e((string)($r['credit'] ?? '')) ?>">
            <div class="modalfilez-ico"><?= modalfilez_e($ico) ?></div>
            <div class="modalfilez-meta">
              <div class="modalfilez-name" title="<?= modalfilez_e($filename) ?>"><?= modalfilez_e($filename) ?></div>
              <div class="modalfilez-sub" title="<?= modalfilez_e($abs ?: $url) ?>">
                <?= modalfilez_e($mime ?: '—') ?> • <?= modalfilez_e($sizeTxt) ?>
              </div>
              <div class="modalfilez-actions">
                <button class="modalfilez-btn modalfilez-btn-primary" type="button" data-modalfilez-action="insert">Insert</button>
                <button class="modalfilez-btn" type="button" data-modalfilez-action="detail">Detail</button>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <div class="modalfilez-pager" id="modalfilez-pager">
        <?php
          $mk = function(int $p) use ($per_page, $q): string {
              $qs = 'page=' . $p . '&per_page=' . (int)$per_page;
              if ($q !== '') $qs .= '&q=' . urlencode($q);
              return '/adiwira/admin/modal_file/list_modal.php?' . $qs;
          };

          if ($page <= 1) {
              echo '<button type="button" class="disabled" disabled>Prev</button>';
          } else {
              echo '<button type="button" data-modalfilez-href="' . modalfilez_e($mk($page - 1)) . '">Prev</button>';
          }

          $start = max(1, $page - 2);
          $end   = min($total_pages, $page + 2);

          if ($start > 1) {
              echo '<button type="button" data-modalfilez-href="' . modalfilez_e($mk(1)) . '">1</button>';
              if ($start > 2) echo '<span class="disabled">…</span>';
          }

          for ($i = $start; $i <= $end; $i++) {
              if ($i === $page) {
                  echo '<button type="button" class="current" disabled>' . (int)$i . '</button>';
              } else {
                  echo '<button type="button" data-modalfilez-href="' . modalfilez_e($mk($i)) . '">' . (int)$i . '</button>';
              }
          }

          if ($end < $total_pages) {
              if ($end < $total_pages - 1) echo '<span class="disabled">…</span>';
              echo '<button type="button" data-modalfilez-href="' . modalfilez_e($mk($total_pages)) . '">' . (int)$total_pages . '</button>';
          }

          if ($page >= $total_pages) {
              echo '<button type="button" class="disabled" disabled>Next</button>';
          } else {
              echo '<button type="button" data-modalfilez-href="' . modalfilez_e($mk($page + 1)) . '">Next</button>';
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
  const wrap = document.getElementById('modalfilez-lib-wrap');
  if (!wrap) return;

  function broadcast(name, detail) {
    try { document.dispatchEvent(new CustomEvent(name, { detail })); } catch(e){}
    try { window.dispatchEvent(new CustomEvent(name, { detail })); } catch(e){}
    try {
      if (window.parent && window.parent !== window) {
        window.parent.postMessage({ type: name, detail: detail }, '*');
      }
    } catch(e){}
  }

  function inject(html) {
    if (typeof window.modalfilezInjectHtmlWithScripts === 'function') {
      window.modalfilezInjectHtmlWithScripts(wrap, html);
      return;
    }

    wrap.innerHTML = html;
  }

  function fetchAndReplace(url) {
    fetch(url, { credentials: 'include', cache: 'no-store' })
      .then(function(res){
        if (!res.ok) throw new Error('HTTP ' + res.status);
        return res.text();
      })
      .then(function(html){
        inject(html);
      })
      .catch(function(err){
        alert('Gagal memuat daftar file: ' + String(err.message || err));
      });
  }

  wrap.addEventListener('click', function(ev){
    const btn = ev.target.closest('button');
    if (!btn) return;

    const action = btn.getAttribute('data-modalfilez-action');
    if (action === 'search') {
      ev.preventDefault();
      const q = (document.getElementById('modalfilez-search')?.value || '').trim();
      fetchAndReplace('/adiwira/admin/modal_file/list_modal.php?q=' + encodeURIComponent(q) + '&page=1&per_page=<?= (int)$per_page ?>');
      return;
    }

    if (action === 'detail') {
      ev.preventDefault();
      const card = btn.closest('.modalfilez-card');
      const id = card ? card.getAttribute('data-id') : '';
      if (!id) return;

      if (typeof window.modalfilezOpenSingle === 'function') {
        window.modalfilezOpenSingle(id);
      } else {
        fetchAndReplace('/adiwira/admin/modal_file/single_modal.php?id=' + encodeURIComponent(id) + '&embedded=1');
      }
      return;
    }

    if (action === 'insert') {
      ev.preventDefault();
      const card = btn.closest('.modalfilez-card');
      if (!card) return;

      const detail = {
        id: card.getAttribute('data-id') ? parseInt(card.getAttribute('data-id'), 10) : null,
        url: card.getAttribute('data-url') || '',
        filename: card.getAttribute('data-filename') || '',
        title: card.getAttribute('data-title') || '',
        caption: card.getAttribute('data-caption') || '',
        credit: card.getAttribute('data-credit') || '',
        mime: card.getAttribute('data-mime') || '',
        size: card.getAttribute('data-size') || ''
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

    const pagerBtn = btn.getAttribute('data-modalfilez-href');
    if (pagerBtn) {
      ev.preventDefault();
      fetchAndReplace(pagerBtn);
    }
  });

  const searchEl = document.getElementById('modalfilez-search');
  if (searchEl) {
    searchEl.addEventListener('keydown', function(ev){
      if (ev.key === 'Enter') {
        ev.preventDefault();
        const q = (searchEl.value || '').trim();
        fetchAndReplace('/adiwira/admin/modal_file/list_modal.php?q=' + encodeURIComponent(q) + '&page=1&per_page=<?= (int)$per_page ?>');
      }
    });
  }
})();
</script>