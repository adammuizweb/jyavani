<?php
// /adiwira/admin/modal_file/single_modal.php
declare(strict_types=1);

require_once __DIR__ . '/../_guard.php';
adiwira_require_admin(false);

header('Content-Type: text/html; charset=utf-8');

$embedded = isset($_GET['embedded']) && (($_GET['embedded'] === '1') || ($_GET['embedded'] === 'true'));

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) { echo '<div style="padding:16px">Invalid ID</div>'; exit; }

$stmt = $pdo->prepare("SELECT * FROM `file` WHERE id=:id LIMIT 1");
$stmt->execute([':id'=>$id]);
$r = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$r) { echo '<div style="padding:16px">File not found</div>'; exit; }

$proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host  = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? '');
$baseUrl = rtrim($proto . '://' . $host, '/');

$url = (string)($r['url'] ?? '');
if ($url !== '' && !preg_match('#^https?://#i', $url)) {
  if (isset($url[0]) && $url[0] === '/') $url = $baseUrl . $url;
  else $url = $baseUrl . '/' . ltrim($url, '/');
}

$csrfToken = '';
try { if (function_exists('csrf_token')) $csrfToken = (string)csrf_token(); } catch (Throwable $e) { $csrfToken = ''; }

function human_filesize($bytes, $decimals = 1) {
  $bytes = (int)$bytes;
  if ($bytes <= 0) return '0 B';
  $units = ['B','KB','MB','GB','TB'];
  $i = (int)floor(log($bytes, 1024));
  $i = min($i, count($units)-1);
  return sprintf("%.{$decimals}f %s", $bytes / pow(1024, $i), $units[$i]);
}

$filename = (string)($r['filename'] ?? '');
$ext = (string)($r['ext'] ?? '');
if ($ext === '' && $filename !== '' && strpos($filename, '.') !== false) $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
$ico = $ext ? strtoupper(substr($ext,0,3)) : 'FILE';

$mime = (string)($r['mime'] ?? '');
$size = (string)($r['size'] ?? '');

if (!$embedded):
?><!doctype html>
<html lang="id"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>File Detail</title>
</head><body>
<?php endif; ?>
<div id="modalfilez-single-wrap">
  <div class="modalfilez-single">
    <form id="modalfilez-file-edit-form"
          data-id="<?= (int)$r['id'] ?>"
          data-filename="<?= htmlspecialchars($filename, ENT_QUOTES, 'UTF-8') ?>"
          data-url="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>"
          data-mime="<?= htmlspecialchars($mime, ENT_QUOTES, 'UTF-8') ?>"
          data-size="<?= htmlspecialchars($size, ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

      <div class="modalfilez-s-top">
        <div class="modalfilez-s-ico"><?= htmlspecialchars($ico, ENT_QUOTES, 'UTF-8') ?></div>
        <div class="modalfilez-s-meta">
          <div class="modalfilez-s-name" title="<?= htmlspecialchars($filename, ENT_QUOTES, 'UTF-8') ?>">
            <?= htmlspecialchars($filename, ENT_QUOTES, 'UTF-8') ?>
          </div>
          <div class="modalfilez-s-sub">
            <?= htmlspecialchars($mime ?: '—', ENT_QUOTES, 'UTF-8') ?>
            • <?= htmlspecialchars(human_filesize((int)($r['size'] ?? 0)), ENT_QUOTES, 'UTF-8') ?>
          </div>
          <div class="modalfilez-s-link">
            <a href="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">Open/Download</a>
          </div>
        </div>
      </div>

      <div class="modalfilez-row">
        <label class="modalfilez-label">Title</label>
        <input class="modalfilez-input" type="text" name="title" value="<?= htmlspecialchars((string)($r['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
      </div>

      <div class="modalfilez-row">
        <label class="modalfilez-label">Caption</label>
        <textarea class="modalfilez-textarea" name="caption"><?= htmlspecialchars((string)($r['caption'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
      </div>

      <div class="modalfilez-row">
        <label class="modalfilez-label">Credit</label>
        <input class="modalfilez-input" type="text" name="credit" value="<?= htmlspecialchars((string)($r['credit'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
      </div>

      <div class="modalfilez-row">
        <label class="modalfilez-label">File URL (read-only)</label>
        <div class="modalfilez-urlrow">
          <input class="modalfilez-input modalfilez-url" id="modalfilez-file-url" type="text" readonly
                 value="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>">
          <button type="button" class="modalfilez-btn modalfilez-btn-ghost" data-modalfilez-action="copy-url">Copy</button>
        </div>
        <div class="modalfilez-note">URL ini yang akan dipakai saat Insert.</div>
      </div>

      <div class="modalfilez-actions">
        <button type="button" class="modalfilez-btn modalfilez-btn-primary" id="modalfilez-file-insert">Insert</button>
        <button type="button" class="modalfilez-btn modalfilez-btn-primary" id="modalfilez-file-save">Save</button>
        <button type="button" class="modalfilez-btn modalfilez-btn-danger"  id="modalfilez-file-delete">Delete</button>
      </div>
    </form>
  </div>
</div>

<?php if (!$embedded): ?>
</body></html>
<?php endif; ?>