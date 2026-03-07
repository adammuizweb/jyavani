<?php
declare(strict_types=1);

// /adiwira/admin/media/single.php
require_once __DIR__ . '/../_guard.php'; // /adiwira/admin/_guard.php
adiwira_require_admin(false);

header('Content-Type: text/html; charset=utf-8');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) { echo "<div>Invalid ID</div>"; exit; }

$stmt = $pdo->prepare("SELECT * FROM media WHERE id=:id LIMIT 1");
$stmt->execute([':id' => $id]);
$r = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$r) { echo "<div>Media not found</div>"; exit; }

// compute base URL (protocol + host)
$proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host  = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? '');
$baseUrl = rtrim($proto . '://' . $host, '/');

// path part
$path = parse_url((string)$r['url'], PHP_URL_PATH) ?: (string)$r['url'];

function human_filesize($bytes, $decimals = 1) {
  if ($bytes <= 0) return '0 B';
  $units = ['B','KB','MB','GB','TB'];
  $i = floor(log($bytes, 1024));
  $i = min($i, count($units)-1);
  return sprintf("%.{$decimals}f %s", $bytes / pow(1024, $i), $units[$i]);
}

// CSRF token for direct open (optional, but safe)
$csrf = function_exists('csrf_token') ? csrf_token() : '';
?>
<div class="media-single-wrap">
  <div class="media-grid">
    <div class="media-left">
      <div class="img-frame" title="<?= htmlspecialchars((string)$r['filename'], ENT_QUOTES, 'UTF-8') ?>">
        <img src="<?= htmlspecialchars((string)$r['url'], ENT_QUOTES, 'UTF-8') ?>"
             alt="<?= htmlspecialchars((string)$r['alt'], ENT_QUOTES, 'UTF-8') ?>">
      </div>

      <div style="margin-top:10px;" class="meta-row">
        <div><strong>Filename:</strong> <?= htmlspecialchars((string)$r['filename'], ENT_QUOTES, 'UTF-8') ?></div>
        <div><strong>MIME:</strong> <?= htmlspecialchars((string)($r['mime'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></div>
        <div><strong>Size:</strong> <?= htmlspecialchars(human_filesize((int)($r['size'] ?? 0)), ENT_QUOTES, 'UTF-8') ?></div>
        <?php if (!empty($r['width']) || !empty($r['height'])): ?>
          <div><strong>Dim:</strong> <?= (int)$r['width'] ?> × <?= (int)$r['height'] ?></div>
        <?php endif; ?>
        <div style="margin-top:6px; color:#777; font-size:12px;">Uploaded: <?= htmlspecialchars((string)($r['created_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
      </div>
    </div>

    <div class="media-right">
      <form id="media-edit-form" data-media-id="<?= (int)$r['id'] ?>">
        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$csrf, ENT_QUOTES, 'UTF-8') ?>">

        <label for="field-title">Title</label>
        <input id="field-title" type="text" name="title" value="<?= htmlspecialchars((string)$r['title'], ENT_QUOTES, 'UTF-8') ?>">

        <label for="field-alt">Alt</label>
        <input id="field-alt" type="text" name="alt" value="<?= htmlspecialchars((string)$r['alt'], ENT_QUOTES, 'UTF-8') ?>">

        <label for="field-caption">Caption</label>
        <textarea id="field-caption" name="caption"><?= htmlspecialchars((string)$r['caption'], ENT_QUOTES, 'UTF-8') ?></textarea>

        <label for="field-credit">Credit <span class="small">(Optional — contoh: "Nama Photographer / Agency")</span></label>
        <input id="field-credit" type="text" name="credit" value="<?= htmlspecialchars((string)$r['credit'], ENT_QUOTES, 'UTF-8') ?>" placeholder="Nama Photographer — Sumber / Lisensi">

        <label for="field-target-url">Target URL <span class="small">(Optional — full URL, http/https)</span></label>
        <input id="field-target-url" type="text" name="target_url" value="<?= htmlspecialchars((string)($r['target_url'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="https://example.com/page">

        <label for="field-target-attr">Open behavior</label>
        <select id="field-target-attr" name="target_attribute">
          <option value="">Default</option>
          <option value="_self" <?= (isset($r['target_attribute']) && $r['target_attribute'] === '_self') ? 'selected' : '' ?>>Open in same tab (_self)</option>
          <option value="_blank" <?= (isset($r['target_attribute']) && $r['target_attribute'] === '_blank') ? 'selected' : '' ?>>Open in new tab (_blank)</option>
          <option value="_parent" <?= (isset($r['target_attribute']) && $r['target_attribute'] === '_parent') ? 'selected' : '' ?>>_parent</option>
          <option value="_top" <?= (isset($r['target_attribute']) && $r['target_attribute'] === '_top') ? 'selected' : '' ?>>_top</option>
        </select>

        <input type="hidden" name="url" value="<?= htmlspecialchars((string)$r['url'], ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="filename" value="<?= htmlspecialchars((string)$r['filename'], ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="mime" value="<?= htmlspecialchars((string)$r['mime'], ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="size" value="<?= htmlspecialchars((string)($r['size'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">

        <label>File URL (read-only)</label>
        <div class="media-url-row">
          <span class="media-url-prefix" id="media-url-prefix"><?= htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8') ?></span>
          <input type="text" id="media-url-path" class="media-url-path" readonly value="<?= htmlspecialchars($path, ENT_QUOTES, 'UTF-8') ?>">
          <button type="button" class="copy-btn" data-action="copy-url">Copy</button>
        </div>

        <div class="actions">
          <button type="button" class="media-btn media-btn-save" id="media-save-btn">Save</button>
          <button type="button" class="media-btn media-btn-delete" id="media-delete-btn">Delete</button>
        </div>
      </form>
    </div>
  </div>