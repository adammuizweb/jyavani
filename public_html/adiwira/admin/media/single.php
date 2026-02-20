<?php
// /adiwira/admin/media/single.php
if (!defined('DASHBOARD_CONTEXT')) define('DASHBOARD_CONTEXT', true);
require_once __DIR__ . '/../../bootstrap.php';
if (session_status() === PHP_SESSION_NONE) session_start();

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

// path part (so we can show prefix separately)
$path = parse_url($r['url'], PHP_URL_PATH) ?: $r['url'];

// helper human filesize
function human_filesize($bytes, $decimals = 1) {
  if ($bytes <= 0) return '0 B';
  $units = ['B','KB','MB','GB','TB'];
  $i = floor(log($bytes, 1024));
  $i = min($i, count($units)-1);
  return sprintf("%.{$decimals}f %s", $bytes / pow(1024, $i), $units[$i]);
}
?>

<style>
/* container */
.media-single-wrap {
  padding:16px;
  font-family: Inter, system-ui, -apple-system, "Segoe UI", Roboto, Arial;
  color: #222;
  box-sizing: border-box;
}

/* two-column responsive layout */
.media-grid {
  display:flex;
  gap:20px;
  align-items:flex-start;
  flex-wrap:wrap;
}

/* left column: image */
.media-left {
  flex: 0 0 360px; /* preferred width */
  max-width: 42%;
  min-width: 220px;
  box-sizing: border-box;
}
.media-left .img-frame {
  width:100%;
  background: #fff;
  border: 1px solid #eee;
  border-radius:10px;
  padding:12px;
  display:flex;
  align-items:center;
  justify-content:center;
}
.media-left img {
  display:block;
  max-width:100%;
  max-height:360px;
  object-fit:contain;
  border-radius:6px;
}

/* right column: details */
.media-right {
  flex: 1 1 300px;
  min-width: 260px;
  box-sizing: border-box;
}

/* field styles */
.media-right label { display:block; margin-bottom:8px; font-weight:600; font-size:14px; color:#222; }
.media-right input[type="text"],
.media-right textarea {
  width:100%;
  padding:10px;
  border:1px solid #d0d7de;
  border-radius:8px;
  font-size:14px;
  box-sizing:border-box;
  background:#fff;
}
.media-right textarea { min-height:90px; resize:vertical; }

/* file URL group */
.media-url-row { display:flex; gap:8px; align-items:center; margin-bottom:12px; }
.media-url-prefix {
  display:inline-block;
  padding:10px 12px;
  border:1px solid #d0d7de;
  border-radius:8px 0 0 8px;
  background:#fafafa;
  color:#444;
  font-size:13px;
  white-space:nowrap;
}
.media-url-path {
  padding:10px;
  border:1px solid #d0d7de;
  border-left:0;
  border-radius:0 8px 8px 0;
  background:#f6f7f9; /* slightly darker to look readonly */
  color:#333;
  flex:1;
  min-width:0;
  font-size:13px;
  overflow:hidden;
  text-overflow:ellipsis;
}

/* small meta row */
.meta-row { margin-bottom:12px; color:#555; font-size:13px; }

/* buttons */
.actions { display:flex; gap:10px; margin-top:6px; }
.media-btn { padding:8px 14px; border-radius:8px; border:0; cursor:pointer; font-weight:600; }
.media-btn-save { background:#0b80ff; color:#fff; }
.media-btn-delete { background:#e53935; color:#fff; }

/* copy button */
.copy-btn {
  padding:8px 10px;
  border-radius:8px;
  border:1px solid #d0d7de;
  background:#fff;
  cursor:pointer;
}

/* responsive: stack on small screens */
@media (max-width:900px) {
  .media-grid { flex-direction:column; }
  .media-left { max-width:100%; flex-basis: auto; }
  .media-right { width:100%; }
  .media-url-row { flex-direction:column; align-items:stretch; }
  .media-url-prefix { border-radius:8px; border-right:1px solid #d0d7de; }
  .media-url-path { border-radius:8px; border-left:1px solid #d0d7de; }
}
</style>

<div class="media-single-wrap">
  <div class="media-grid">
    <div class="media-left">
      <div class="img-frame" title="<?= htmlspecialchars($r['filename']) ?>">
        <img src="<?= htmlspecialchars($r['url']) ?>"
             alt="<?= htmlspecialchars($r['alt']) ?>">
      </div>

      <div style="margin-top:10px;" class="meta-row">
        <div><strong>Filename:</strong> <?= htmlspecialchars($r['filename']) ?></div>
        <div><strong>MIME:</strong> <?= htmlspecialchars($r['mime'] ?? '-') ?></div>
        <div><strong>Size:</strong> <?= htmlspecialchars(human_filesize((int)$r['size'])) ?></div>
        <?php if (!empty($r['width']) || !empty($r['height'])): ?>
          <div><strong>Dim:</strong> <?= (int)$r['width'] ?> × <?= (int)$r['height'] ?></div>
        <?php endif; ?>
        <div style="margin-top:6px; color:#777; font-size:12px;">Uploaded: <?= htmlspecialchars($r['created_at']) ?></div>
      </div>
    </div>

    <div class="media-right">
      <form id="media-edit-form" data-media-id="<?= (int)$r['id'] ?>">
        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">

        <label for="field-title">Title</label>
        <input id="field-title" type="text" name="title" value="<?= htmlspecialchars($r['title']) ?>">

        <label for="field-alt">Alt</label>
        <input id="field-alt" type="text" name="alt" value="<?= htmlspecialchars($r['alt']) ?>">

        <label for="field-caption">Caption</label>
        <textarea id="field-caption" name="caption"><?= htmlspecialchars($r['caption']) ?></textarea>

        <label for="field-credit">Credit <span class="small">(Optional — contoh: "Nama Photographer / Agency")</span></label>
        <input id="field-credit" type="text" name="credit" value="<?= htmlspecialchars($r['credit']) ?>" placeholder="Nama Photographer — Sumber / Lisensi">
        
        <!-- ... sudah ada input lainnya ... -->

<label for="field-target-url">Target URL <span class="small">(Optional — full URL, http/https)</span></label>
<input id="field-target-url" type="text" name="target_url" value="<?= htmlspecialchars($r['target_url'] ?? '') ?>" placeholder="https://example.com/page">

<label for="field-target-attr">Open behavior</label>
<select id="field-target-attr" name="target_attribute">
  <option value="">Default</option>
  <option value="_self" <?= (isset($r['target_attribute']) && $r['target_attribute'] === '_self') ? 'selected' : '' ?>>Open in same tab (_self)</option>
  <option value="_blank" <?= (isset($r['target_attribute']) && $r['target_attribute'] === '_blank') ? 'selected' : '' ?>>Open in new tab (_blank)</option>
  <option value="_parent" <?= (isset($r['target_attribute']) && $r['target_attribute'] === '_parent') ? 'selected' : '' ?>>_parent</option>
  <option value="_top" <?= (isset($r['target_attribute']) && $r['target_attribute'] === '_top') ? 'selected' : '' ?>>_top</option>
</select>

<!-- hidden original url (tetap ada) -->
<input type="hidden" name="url" value="<?= htmlspecialchars($r['url']) ?>">

        <label>File URL (read-only)</label>
        <div class="media-url-row">
          <span class="media-url-prefix" id="media-url-prefix"><?= htmlspecialchars($baseUrl) ?></span>
          <input type="text" id="media-url-path" class="media-url-path" readonly value="<?= htmlspecialchars($path) ?>">
          <button type="button" class="copy-btn" data-action="copy-url">Copy</button>
        </div>

        <!-- hidden original url maintained for save/delete handlers -->
        <input type="hidden" name="url" value="<?= htmlspecialchars($r['url']) ?>">
        <input type="hidden" name="filename" value="<?= htmlspecialchars($r['filename']) ?>">
        <input type="hidden" name="mime" value="<?= htmlspecialchars($r['mime']) ?>">
        <input type="hidden" name="size" value="<?= htmlspecialchars($r['size']) ?>">

        <div class="actions">
          <button type="button" class="media-btn media-btn-save" id="media-save-btn">Save</button>
          <button type="button" class="media-btn media-btn-delete" id="media-delete-btn">Delete</button>
        </div>
      </form>
    </div>
  </div>
</div>
<!--
  Note: tidak menyertakan inline JS untuk save/delete karena kita mengandalkan delegation di index.php.
  Pastikan handler copy-url ada di script global (index.php). Jika belum, tambahkan snippet copy handler berikut.
-->