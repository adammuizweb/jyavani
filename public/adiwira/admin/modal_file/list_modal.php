<?php
// /adiwira/admin/modal_file/list_modal.php
declare(strict_types=1);

require_once __DIR__ . '/../_guard.php';
adiwira_require_admin(false);

if (!headers_sent()) {
  header('Content-Type: text/html; charset=utf-8');
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  header('Pragma: no-cache');
}

$proto   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host    = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? '');
$baseUrl = rtrim($proto . '://' . $host, '/');

$q        = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$page     = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$per_page = isset($_GET['per_page']) ? max(1, min(100, (int)$_GET['per_page'])) : 12;

$params = [];
$whereSql = '';
if ($q !== '') {
  $whereSql = " WHERE title LIKE :q OR filename LIKE :q OR caption LIKE :q OR mime LIKE :q";
  $params[':q'] = '%' . $q . '%';
}

try {
  $countStmt = $pdo->prepare("SELECT COUNT(*) FROM `file`" . $whereSql);
  foreach ($params as $k => $v) $countStmt->bindValue($k, $v);
  $countStmt->execute();
  $total = (int)$countStmt->fetchColumn();

  $total_pages = (int)max(1, (int)ceil($total / $per_page));
  if ($page > $total_pages) $page = $total_pages;
  $offset = ($page - 1) * $per_page;

  $st = $pdo->prepare("SELECT * FROM `file`" . $whereSql . " ORDER BY id DESC LIMIT :lim OFFSET :off");
  foreach ($params as $k => $v) $st->bindValue($k, $v);
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

function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function absUrl(string $baseUrl, $url): string {
  $url = (string)$url;
  if ($url === '') return '';
  if (preg_match('#^https?://#i', $url)) return $url;
  if (isset($url[0]) && $url[0] === '/') return $baseUrl . $url;
  return $baseUrl . '/' . ltrim($url, '/');
}
function human_bytes($bytes): string {
  $b = (int)$bytes;
  if ($b <= 0) return '0 B';
  $units = ['B','KB','MB','GB','TB'];
  $i = (int)floor(log($b, 1024));
  $i = max(0, min($i, count($units)-1));
  $val = $b / (1024 ** $i);
  return number_format($val, ($i === 0 ? 0 : 1)) . ' ' . $units[$i];
}
?>

<div id="modalfilez-lib-wrap">
  <div class="modalfilez-lib" id="modalfilez-lib" data-per-page="<?= (int)$per_page ?>">
    <div class="modalfilez-searchrow">
      <input class="modalfilez-input" id="modalfilez-search"
             placeholder="Cari title/filename/caption/mime..."
             value="<?= e($q) ?>">
      <button class="modalfilez-btn" type="button" data-modalfilez-action="search">Cari</button>
    </div>

    <?php if (!$rows): ?>
      <div class="modalfilez-loading" style="font-style:normal">Belum ada file.</div>
    <?php else: ?>
      <div class="modalfilez-grid">
        <?php foreach ($rows as $r):
          $id = (int)($r['id'] ?? 0);

          $filename = (string)($r['filename'] ?? $r['name'] ?? $r['title'] ?? ('File #' . $id));
          $mime     = (string)($r['mime'] ?? $r['type'] ?? '');
          $sizeRaw  = $r['size'] ?? '';
          $sizeTxt  = ($sizeRaw === '' || $sizeRaw === null) ? '' : human_bytes((int)$sizeRaw);

          $url  = (string)($r['url'] ?? $r['path'] ?? $r['file_url'] ?? '');
          $abs  = absUrl($baseUrl, $url);

          $ext = '';
          if (!empty($r['ext'])) $ext = (string)$r['ext'];
          elseif (strpos($filename, '.') !== false) $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
          $ico = $ext ? strtoupper(substr($ext, 0, 3)) : 'FILE';
        ?>
          <div class="modalfilez-card"
               data-id="<?= (int)$id ?>"
               data-filename="<?= e($filename) ?>"
               data-url="<?= e($abs) ?>"
               data-mime="<?= e($mime) ?>"
               data-size="<?= e($sizeTxt) ?>">
            <div class="modalfilez-ico"><?= e($ico) ?></div>
            <div class="modalfilez-meta">
              <div class="modalfilez-name" title="<?= e($filename) ?>"><?= e($filename) ?></div>
              <div class="modalfilez-sub" title="<?= e($abs ?: $url) ?>">
                <?= e($mime ?: '—') ?><?= $sizeTxt !== '' ? ' • ' . e($sizeTxt) : '' ?>
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

          // Prev
          if ($page <= 1) {
            echo '<button type="button" class="disabled" disabled>Prev</button>';
          } else {
            echo '<button type="button" data-modalfilez-href="' . e($mk($page-1)) . '">Prev</button>';
          }

          $start = max(1, $page - 2);
          $end   = min($total_pages, $page + 2);

          if ($start > 1) {
            echo '<button type="button" data-modalfilez-href="' . e($mk(1)) . '">1</button>';
            if ($start > 2) echo '<span class="disabled">…</span>';
          }

          for ($i = $start; $i <= $end; $i++) {
            if ($i === $page) echo '<button type="button" class="current" disabled>' . (int)$i . '</button>';
            else echo '<button type="button" data-modalfilez-href="' . e($mk($i)) . '">' . (int)$i . '</button>';
          }

          if ($end < $total_pages) {
            if ($end < $total_pages - 1) echo '<span class="disabled">…</span>';
            echo '<button type="button" data-modalfilez-href="' . e($mk($total_pages)) . '">' . (int)$total_pages . '</button>';
          }

          // Next
          if ($page >= $total_pages) {
            echo '<button type="button" class="disabled" disabled>Next</button>';
          } else {
            echo '<button type="button" data-modalfilez-href="' . e($mk($page+1)) . '">Next</button>';
          }
        ?>

        <span class="disabled" style="border:0;background:transparent;cursor:default">
          <?= $total ? (int)($offset+1).'–'.(int)min($offset+$per_page,$total).' / '.(int)$total : '0' ?>
        </span>
      </div>
    <?php endif; ?>
  </div>
</div>