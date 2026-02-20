<?php
// /adiwira/admin/modal_img/single_modal.php
if (!defined('DASHBOARD_CONTEXT')) define('DASHBOARD_CONTEXT', true);
require_once __DIR__ . '/../../bootstrap.php';
if (session_status() === PHP_SESSION_NONE) session_start();

header('Content-Type: text/html; charset=utf-8');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) { echo '<div>Invalid ID</div>'; exit; }

$stmt = $pdo->prepare("SELECT * FROM media WHERE id=:id LIMIT 1");
$stmt->execute([':id' => $id]);
$r = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$r) { echo '<div>Media not found</div>'; exit; }

// compute absolute URL
$proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host  = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? '');
$baseUrl = rtrim($proto . '://' . $host, '/');

$url = $r['url'];
if ($url && !preg_match('#^https?://#i', $url)) {
  if (substr($url,0,1) === '/') $url = $baseUrl . $url;
  else $url = $baseUrl . '/' . ltrim($url, '/');
}

// helper
function human_filesize($bytes, $decimals = 1) {
  if ($bytes <= 0) return '0 B';
  $units = ['B','KB','MB','GB','TB'];
  $i = floor(log(max(1,$bytes), 1024));
  $i = min($i, count($units)-1);
  return sprintf("%.{$decimals}f %s", $bytes / pow(1024, $i), $units[$i]);
}
?>
<style>
/* Improved single modal styles */
.media-wrap{display:flex;gap:18px;align-items:flex-start;font-family:system-ui,-apple-system,Segoe UI,Roboto; color:#222}
.media-left{flex:0 0 360px; max-width:360px}
.media-left .img-frame{background:#fff;border:1px solid #eee;border-radius:10px;padding:12px;display:flex;align-items:center;justify-content:center}
.media-left img{display:block;max-width:100%;max-height:420px;object-fit:contain;border-radius:8px}
.media-right{flex:1;min-width:260px}
.field{margin-bottom:12px}
.field label{display:block;font-weight:600;margin-bottom:6px;color:#111}
.field input[type="text"], .field textarea, .field select { width:100%; padding:10px; border:1px solid #d6dbe6; border-radius:8px; background:#fff; box-sizing:border-box; font-size:14px }
.field textarea{min-height:100px; resize:vertical}
.meta-row { color:#555; font-size:13px; margin-top:8px }
.media-url-row { display:flex; gap:8px; align-items:center; margin-top:6px }
.media-url-prefix { padding:9px 12px; border:1px solid #d6dbe6; border-radius:8px 0 0 8px; background:#fafafa; font-size:13px }
.media-url-path { padding:9px; border:1px solid #d6dbe6; border-left:0; border-radius:0 8px 8px 0; background:#f6f7f9; min-width:0; flex:1; overflow:hidden; text-overflow:ellipsis }
.actions { display:flex; gap:10px; margin-top:12px }
.btn { padding:10px 14px; border-radius:10px; border:0; cursor:pointer; font-weight:700 }
.btn-save { background: #0b80ff; color:#fff }
.btn-delete { background: #e53935; color:#fff }
.copy-btn { padding:8px 10px; border-radius:8px; border:1px solid #d6dbe6; background:#fff; cursor:pointer }
.small{font-size:.88rem;color:#666}
</style>

<div class="media-wrap">
  <div class="media-left">
    <div class="img-frame" title="<?= htmlspecialchars($r['filename'], ENT_QUOTES) ?>">
      <img src="<?= htmlspecialchars($url, ENT_QUOTES) ?>" alt="<?= htmlspecialchars($r['alt'] ?? '', ENT_QUOTES) ?>">
    </div>
    <div class="meta-row">
      <div><strong>Filename:</strong> <?= htmlspecialchars($r['filename']) ?></div>
      <div><strong>MIME:</strong> <?= htmlspecialchars($r['mime'] ?? '-') ?></div>
      <div><strong>Size:</strong> <?= htmlspecialchars(human_filesize((int)$r['size'])) ?></div>
      <?php if (!empty($r['width']) || !empty($r['height'])): ?>
        <div><strong>Dim:</strong> <?= (int)$r['width'] ?> × <?= (int)$r['height'] ?></div>
      <?php endif; ?>
      <div style="margin-top:6px" class="small">Uploaded: <?= htmlspecialchars($r['created_at']) ?></div>
    </div>
  </div>

  <div class="media-right">
    <form id="media-edit-form">
      <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">

      <div class="field">
        <label for="field-title">Title</label>
        <input id="field-title" type="text" name="title" value="<?= htmlspecialchars($r['title']) ?>">
      </div>

      <div class="field">
        <label for="field-alt">Alt</label>
        <input id="field-alt" type="text" name="alt" value="<?= htmlspecialchars($r['alt']) ?>">
      </div>

      <div class="field">
        <label for="field-caption">Caption</label>
        <textarea id="field-caption" name="caption"><?= htmlspecialchars($r['caption']) ?></textarea>
      </div>

      <div class="field">
        <label for="field-credit">Credit <span class="small">(Optional)</span></label>
        <input id="field-credit" type="text" name="credit" value="<?= htmlspecialchars($r['credit']) ?>" placeholder="Nama Photographer — Sumber / Lisensi">
      </div>

      <!-- NEW: target url & attribute -->
      <div class="field">
        <label for="field-target-url">Target URL <span class="small">(Optional — full URL, http/https)</span></label>
        <input id="field-target-url" type="text" name="target_url" value="<?= htmlspecialchars($r['target_url'] ?? '') ?>" placeholder="https://example.com/page">
      </div>

      <div class="field">
        <label for="field-target-attr">Open behavior</label>
        <select id="field-target-attr" name="target_attribute">
          <option value="" <?= (empty($r['target_attribute']) ? 'selected' : '') ?>>Default</option>
          <option value="_self" <?= (isset($r['target_attribute']) && $r['target_attribute'] === '_self') ? 'selected' : '' ?>>Open in same tab (_self)</option>
          <option value="_blank" <?= (isset($r['target_attribute']) && $r['target_attribute'] === '_blank') ? 'selected' : '' ?>>Open in new tab (_blank)</option>
          <option value="_parent" <?= (isset($r['target_attribute']) && $r['target_attribute'] === '_parent') ? 'selected' : '' ?>>_parent</option>
          <option value="_top" <?= (isset($r['target_attribute']) && $r['target_attribute'] === '_top') ? 'selected' : '' ?>>_top</option>
        </select>
      </div>
      <!-- END NEW -->

      <div class="field">
        <label>File URL (read-only)</label>
        <div class="media-url-row">
          <span class="media-url-prefix"><?= htmlspecialchars($baseUrl) ?></span>
          <input type="text" id="media-url-path" class="media-url-path" readonly value="<?= htmlspecialchars(parse_url($r['url'], PHP_URL_PATH) ?: $r['url']) ?>">
          <button type="button" class="copy-btn" data-action="copy-url">Copy</button>
        </div>
      </div>

      <!-- hidden original url maintained for save/delete handlers -->
      <input type="hidden" name="url" value="<?= htmlspecialchars($r['url']) ?>">
      <input type="hidden" name="filename" value="<?= htmlspecialchars($r['filename']) ?>">
      <input type="hidden" name="mime" value="<?= htmlspecialchars($r['mime']) ?>">
      <input type="hidden" name="size" value="<?= htmlspecialchars($r['size']) ?>">

      <div class="actions">
        <button type="button" class="btn btn-save" id="media-save-btn">Save</button>
        <button type="button" class="btn btn-delete" id="media-delete-btn">Delete</button>
      </div>
    </form>
  </div>
</div>

<script>
(function(){
  // helper: broadcast to various contexts
  function broadcast(name, detail){
    try { document.dispatchEvent(new CustomEvent(name, { detail })); } catch(e){}
    try { window.dispatchEvent(new CustomEvent(name, { detail })); } catch(e){}
    try { if (window.parent && window.parent !== window) window.parent.postMessage({ type: name, detail }, '*'); } catch(e){}
  }

  // Save
  document.getElementById('media-save-btn')?.addEventListener('click', function(){
    const btn = this;
    const form = document.getElementById('media-edit-form');
    if (!form) return;
    btn.disabled = true;
    const fd = new FormData(form);
    fetch('/adiwira/admin/media/save.php', { method:'POST', credentials:'include', body: fd })
      .then(res => res.text().then(t => {
        let j = null;
        try { j = t ? JSON.parse(t) : null; } catch(e) { j = null; }
        return { ok: res.ok, json: j, text: t, status: res.status };
      }))
      .then(resp => {
        if (!resp.ok || (resp.json && resp.json.ok === false)) {
          const msg = resp.json && resp.json.error ? resp.json.error : ('HTTP ' + resp.status + ' — ' + (resp.text||''));
          throw new Error(msg);
        }
        const j = resp.json || {};
        alert('Updated ✔');
        broadcast('media:updated', j);
      })
      .catch(err => {
        console.error('Save error', err);
        alert('Save error: ' + (err.message || 'Gagal'));
      })
      .finally(()=> { btn.disabled = false; });
  });

  // Delete
  document.getElementById('media-delete-btn')?.addEventListener('click', function(){
    if (!confirm('Delete this media?')) return;
    const btn = this;
    btn.disabled = true;
    const form = document.getElementById('media-edit-form');
    const fd = new FormData();
    const idEl = form.querySelector('input[name="id"]');
    const urlEl = form.querySelector('input[name="url"]');
    if (idEl) fd.append('id', idEl.value);
    if (urlEl) fd.append('url', urlEl.value);

    fetch('/adiwira/admin/media/delete.php', { method:'POST', credentials:'include', body: fd })
      .then(res => res.text().then(t => {
        let j = null;
        try { j = t ? JSON.parse(t) : null; } catch(e){ j = null; }
        return { ok: res.ok, json: j, text: t, status: res.status };
      }))
      .then(resp => {
        if (!resp.ok || (resp.json && resp.json.ok === false)) {
          const msg = resp.json && resp.json.error ? resp.json.error : ('HTTP ' + resp.status + ' — ' + (resp.text||''));
          throw new Error(msg);
        }
        const j = resp.json || {};
        alert('Deleted ✔');
        broadcast('media:deleted', j);
        // close modal if possible
        try { if (window.parent && window.parent.adamModalClose) window.parent.adamModalClose(); } catch(e){}
        try { if (window.adamModalClose) window.adamModalClose(); } catch(e){}
      })
      .catch(err => {
        console.error('Delete error', err);
        alert('Delete error: ' + (err.message || 'Gagal'));
      })
      .finally(()=> { btn.disabled = false; });
  });

  // copy-url delegate
  document.querySelector('[data-action="copy-url"]')?.addEventListener('click', function(){
    const pathInput = document.getElementById('media-url-path');
    const prefixEl = document.querySelector('.media-url-prefix');
    const prefix = prefixEl ? prefixEl.textContent.trim() : '';
    const path = pathInput ? pathInput.value.trim() : '';
    if (!path) return alert('URL tidak ditemukan');
    let full = path;
    if (path.match(/^https?:\/\//i)) full = path;
    else full = prefix.replace(/\/$/, '') + path;
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(full).then(()=> alert('Copied: ' + full)).catch(()=> alert('Gagal menyalin'));
    } else {
      // fallback
      try {
        const tmp = document.createElement('textarea');
        tmp.value = full;
        document.body.appendChild(tmp);
        tmp.select();
        document.execCommand('copy');
        tmp.remove();
        alert('Copied: ' + full);
      } catch (e) {
        alert('Clipboard tidak tersedia');
      }
    }
  });

})();
</script>
<script>
(function(){
  // override close-x hanya untuk view single (tanpa mengubah backdrop/ESC)
  try {
    // cari close button modal (dibuat oleh modal-helpers)
    var closeBtn = document.querySelector('#adam-modal-box button');
    if (!closeBtn || String(closeBtn.textContent).trim() !== '×') {
      // fallback: kalau tidak ada, nothing to do
    } else {
      // clone untuk menghilangkan listener lama sehingga tidak memicu adamModalClose()
      var customBtn = closeBtn.cloneNode(true);
      closeBtn.parentNode.replaceChild(customBtn, closeBtn);

      customBtn.addEventListener('click', function(ev){
        ev.preventDefault();
        ev.stopPropagation();

        var content = document.getElementById('adam-modal-content');
        var indexUrl = '/adiwira/admin/modal_img/index.php?embedded=1';

        // jika tidak ada container, fallback ke redirect (standalone)
        if (!content) {
          window.location.href = indexUrl;
          return;
        }

        // loading state
        content.innerHTML = '<div style="padding:18px;color:#666;font-style:italic">Kembali ke gallery…</div>';

        fetch(indexUrl, { credentials: 'include' })
          .then(function(res){
            if (!res.ok) throw new Error('HTTP ' + res.status);
            return res.text();
          })
          .then(function(html){
            // inject html (menggunakan helper yg tersedia di parent)
            if (typeof injectHtmlWithScriptsTo === 'function') {
              injectHtmlWithScriptsTo(content, html);
            } else {
              content.innerHTML = html;
            }

            // restore close button ke perilaku "tutup modal" setelah index dimuat
            try {
              var cb = document.querySelector('#adam-modal-box button');
              if (cb && String(cb.textContent).trim() === '×') {
                var restore = cb.cloneNode(true);
                cb.parentNode.replaceChild(restore, cb);
                restore.addEventListener('click', function(){
                  if (typeof window.adamModalClose === 'function') return window.adamModalClose();
                  // fallback
                  var bd = document.getElementById('adam-modal-backdrop');
                  if (bd && bd.parentNode) bd.parentNode.removeChild(bd);
                });
                restore.setAttribute('title','Close');
              }
            } catch (err) {
              console.warn('restore close btn failed', err);
            }
          })
          .catch(function(err){
            console.error('load index failed', err);
            content.innerHTML = '<div style="color:#c00;padding:12px">Gagal memuat gallery</div>';
          });
      });

      // optional: beri tooltip agar user paham fungsi tombol saat di single view
      customBtn.setAttribute('title', 'Kembali ke gallery');
    }
  } catch (e) {
    console.error('override close-x error', e);
  }
})();
</script>
