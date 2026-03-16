<?php
declare(strict_types=1);

// /adiwira/admin/modal_img/single_modal.php
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

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    echo '<div>Invalid ID</div>';
    exit;
}

$sql = "SELECT * FROM media WHERE id = :id";
$params = [':id' => $id];

if (!$isAdmin) {
    $sql .= " AND user_id = :uid";
    $params[':uid'] = $uid;
}

$sql .= " LIMIT 1";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$r = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$r) {
    echo '<div>Media not found</div>';
    exit;
}

$proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host  = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? '');
$baseUrl = rtrim($proto . '://' . $host, '/');

$url = (string)($r['url'] ?? '');
if ($url !== '' && !preg_match('#^https?://#i', $url)) {
    if (substr($url, 0, 1) === '/') $url = $baseUrl . $url;
    else $url = $baseUrl . '/' . ltrim($url, '/');
}

$csrfToken = '';
try {
    if (function_exists('csrf_token')) {
        $csrfToken = (string) csrf_token();
    }
} catch (Throwable $e) {
    $csrfToken = '';
}

$linkUrlValue = (string)($r['link_url'] ?? '');
$linkTargetValue = (string)($r['link_target'] ?? '');

if (!function_exists('modalimg_human_filesize')) {
    function modalimg_human_filesize($bytes, $decimals = 1) {
        $bytes = (int) $bytes;
        if ($bytes <= 0) return '0 B';
        $units = ['B','KB','MB','GB','TB'];
        $i = floor(log(max(1, $bytes), 1024));
        $i = min($i, count($units) - 1);
        return sprintf("%.{$decimals}f %s", $bytes / pow(1024, $i), $units[$i]);
    }
}
?>
<div class="media-wrap">
  <div class="media-left">
    <div class="img-frame" title="<?= htmlspecialchars((string)($r['filename'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
      <img src="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars((string)($r['alt'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
    </div>

    <div class="meta-row">
      <div><strong>Filename:</strong> <?= htmlspecialchars((string)($r['filename'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
      <div><strong>MIME:</strong> <?= htmlspecialchars((string)($r['mime'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></div>
      <div><strong>Size:</strong> <?= htmlspecialchars(modalimg_human_filesize((int)($r['size'] ?? 0)), ENT_QUOTES, 'UTF-8') ?></div>
      <?php if (!empty($r['width']) || !empty($r['height'])): ?>
        <div><strong>Dim:</strong> <?= (int)$r['width'] ?> × <?= (int)$r['height'] ?></div>
      <?php endif; ?>
      <div style="margin-top:6px" class="small">Uploaded: <?= htmlspecialchars((string)($r['created_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
    </div>
  </div>

  <div class="media-right">
    <form id="media-edit-form">
      <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

      <div class="field">
        <label for="field-title">Title</label>
        <input id="field-title" type="text" name="title" value="<?= htmlspecialchars((string)($r['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
      </div>

      <div class="field">
        <label for="field-alt">Alt</label>
        <input id="field-alt" type="text" name="alt" value="<?= htmlspecialchars((string)($r['alt'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
      </div>

      <div class="field">
        <label for="field-caption">Caption</label>
        <textarea id="field-caption" name="caption"><?= htmlspecialchars((string)($r['caption'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
      </div>

      <div class="field">
        <label for="field-credit">Credit <span class="small">(Optional)</span></label>
        <input id="field-credit" type="text" name="credit" value="<?= htmlspecialchars((string)($r['credit'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="Nama Photographer — Sumber / Lisensi">
      </div>

      <div class="field">
        <label for="field-target-url">Target URL <span class="small">(Optional — full URL, http/https)</span></label>
        <input id="field-target-url" type="text" name="target_url" value="<?= htmlspecialchars($linkUrlValue, ENT_QUOTES, 'UTF-8') ?>" placeholder="https://example.com/page">
      </div>

      <div class="field">
        <label for="field-target-attr">Open behavior</label>
        <select id="field-target-attr" name="target_attribute">
          <option value="" <?= $linkTargetValue === '' ? 'selected' : '' ?>>Default</option>
          <option value="_self" <?= $linkTargetValue === '_self' ? 'selected' : '' ?>>Open in same tab (_self)</option>
          <option value="_blank" <?= $linkTargetValue === '_blank' ? 'selected' : '' ?>>Open in new tab (_blank)</option>
          <option value="_parent" <?= $linkTargetValue === '_parent' ? 'selected' : '' ?>>_parent</option>
          <option value="_top" <?= $linkTargetValue === '_top' ? 'selected' : '' ?>>_top</option>
        </select>
      </div>

      <div class="field">
        <label>File URL (read-only)</label>
        <div class="media-url-row">
          <span class="media-url-prefix" id="media-url-prefix"><?= htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8') ?></span>
          <input type="text" id="media-url-path" class="media-url-path" readonly value="<?= htmlspecialchars((string)(parse_url((string)($r['url'] ?? ''), PHP_URL_PATH) ?: ($r['url'] ?? '')), ENT_QUOTES, 'UTF-8') ?>">
          <button type="button" class="copy-btn" data-action="copy-url">Copy</button>
        </div>
      </div>

      <input type="hidden" name="url" value="<?= htmlspecialchars((string)($r['url'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">

      <div class="actions">
        <button type="button" class="btn btn-save" id="media-save-btn">Save</button>
        <button type="button" class="btn btn-delete" id="media-delete-btn">Delete</button>
      </div>
    </form>
  </div>
</div>

<script>
(function(){
  function getToastApi(){
    try {
      if (window.NewNotifToast && typeof window.NewNotifToast.show === 'function') return window.NewNotifToast;
      if (window.parent && window.parent !== window && window.parent.NewNotifToast && typeof window.parent.NewNotifToast.show === 'function') return window.parent.NewNotifToast;
    } catch(e){}
    return null;
  }

  function getConfirmApi(){
    try {
      if (window.NewNotifConfirm) return window.NewNotifConfirm;
      if (window.parent && window.parent !== window && window.parent.NewNotifConfirm) return window.parent.NewNotifConfirm;
    } catch(e){}
    return null;
  }

  function uiToast(type, title, message, duration){
    const api = getToastApi();
    if (api) {
      api.show({
        type: type || 'info',
        title: title || null,
        message: message || '',
        duration: duration
      });
      return;
    }
    alert(message || title || 'Terjadi sesuatu.');
  }

  function uiAsk(variant, opts){
    const api = getConfirmApi();
    if (api) {
      if (variant === 'danger' && typeof api.danger === 'function') return api.danger(opts || {});
      if (typeof api.warning === 'function') return api.warning(opts || {});
    }
    return Promise.resolve(window.confirm((opts && opts.message) ? opts.message : 'Lanjutkan aksi ini?'));
  }

  function broadcast(name, detail){
    try { document.dispatchEvent(new CustomEvent(name, { detail })); } catch(e){}
    try { window.dispatchEvent(new CustomEvent(name, { detail })); } catch(e){}
    try {
      if (window.parent && window.parent !== window) {
        window.parent.postMessage({ type: name, detail }, '*');
      }
    } catch(e){}
  }

  function getCsrf(){
    const el = document.querySelector('input[name="csrf_token"]');
    return el && el.value ? el.value : '';
  }

  document.getElementById('media-save-btn')?.addEventListener('click', async function(){
    const ok = await uiAsk('warning', {
      title: 'Simpan perubahan media',
      message: 'Perubahan metadata media akan disimpan. Lanjutkan?',
      confirmText: 'Ya, simpan',
      cancelText: 'Batal'
    });
    if (!ok) return;

    const btn = this;
    const form = document.getElementById('media-edit-form');
    if (!form) return;

    btn.disabled = true;

    const fd = new FormData(form);

    fetch('/adiwira/admin/media/save.php', {
      method: 'POST',
      credentials: 'include',
      body: fd
    })
      .then(res => res.text().then(t => {
        let j = null;
        try { j = t ? JSON.parse(t) : null; } catch(e){}
        return { ok: res.ok, json: j, text: t, status: res.status };
      }))
      .then(resp => {
        if (!resp.ok || (resp.json && resp.json.ok === false)) {
          const msg = resp.json && resp.json.error
            ? resp.json.error
            : ('HTTP ' + resp.status + ' — ' + (resp.text || ''));
          throw new Error(msg);
        }

        const j = resp.json || {};
        uiToast('success', 'Gallery', 'Media berhasil diperbarui.', 2500);
        broadcast('media:updated', j);
      })
      .catch(err => {
        console.error('Save error', err);
        uiToast('error', 'Gallery', 'Save error: ' + (err.message || 'Gagal'), 6000);
      })
      .finally(() => {
        btn.disabled = false;
      });
  });

  document.getElementById('media-delete-btn')?.addEventListener('click', async function(){
    const ok = await uiAsk('danger', {
      title: 'Hapus media',
      message: 'Media ini akan dihapus permanen dari gallery. Lanjutkan?',
      confirmText: 'Ya, hapus',
      cancelText: 'Batal'
    });
    if (!ok) return;

    const btn = this;
    btn.disabled = true;

    const form = document.getElementById('media-edit-form');
    const fd = new FormData();
    const idEl = form.querySelector('input[name="id"]');
    const urlEl = form.querySelector('input[name="url"]');

    if (idEl) fd.append('id', idEl.value);
    if (urlEl) fd.append('url', urlEl.value);

    const csrf = getCsrf();
    if (csrf) fd.append('csrf_token', csrf);

    fetch('/adiwira/admin/media/delete.php', {
      method: 'POST',
      credentials: 'include',
      body: fd
    })
      .then(res => res.text().then(t => {
        let j = null;
        try { j = t ? JSON.parse(t) : null; } catch(e){}
        return { ok: res.ok, json: j, text: t, status: res.status };
      }))
      .then(resp => {
        if (!resp.ok || (resp.json && resp.json.ok === false)) {
          const msg = resp.json && resp.json.error
            ? resp.json.error
            : ('HTTP ' + resp.status + ' — ' + (resp.text || ''));
          throw new Error(msg);
        }

        const j = resp.json || {};
        const finalUrl = urlEl ? String(urlEl.value || '') : '';

        uiToast('success', 'Gallery', 'Media berhasil dihapus.', 2500);
        if (j.warning) uiToast('warning', 'Gallery', j.warning, 6000);

        const payload = Object.assign({}, j || {}, {
          id: idEl ? parseInt(idEl.value || '0', 10) || null : (j.id || null),
          url: finalUrl,
          deleted_ids: (j && Array.isArray(j.deleted_ids)) ? j.deleted_ids : (idEl ? [parseInt(idEl.value || '0', 10)].filter(Boolean) : []),
          deleted_urls: finalUrl ? [finalUrl] : []
        });

        broadcast('media:deleted', payload);

        try {
          if (window.parent && window.parent.adamModalClose) window.parent.adamModalClose();
        } catch(e){}

        try {
          if (window.adamModalClose) window.adamModalClose();
        } catch(e){}
      })
      .catch(err => {
        console.error('Delete error', err);
        uiToast('error', 'Gallery', 'Delete error: ' + (err.message || 'Gagal'), 6000);
      })
      .finally(() => {
        btn.disabled = false;
      });
  });

  document.querySelector('[data-action="copy-url"]')?.addEventListener('click', function(ev){
    ev.preventDefault();

    const prefixEl = document.getElementById('media-url-prefix');
    const pathEl = document.getElementById('media-url-path');
    const prefix = prefixEl ? (prefixEl.textContent || '').trim() : window.location.origin;
    const path = pathEl ? (pathEl.value || '').trim() : '';

    if (!path) {
      uiToast('warning', 'Gallery', 'URL tidak ditemukan.', 4000);
      return;
    }

    let full = path;
    if (!/^https?:\/\//i.test(path)) {
      full = prefix.replace(/\/$/, '') + path;
    }

    if (navigator.clipboard && navigator.clipboard.writeText && window.isSecureContext) {
      navigator.clipboard.writeText(full)
        .then(() => uiToast('success', 'Gallery', 'URL berhasil disalin.', 2000))
        .catch(() => fallbackCopy(full));
    } else {
      fallbackCopy(full);
    }

    function fallbackCopy(text){
      const ta = document.createElement('textarea');
      ta.value = text;
      ta.setAttribute('readonly', 'readonly');
      ta.style.position = 'fixed';
      ta.style.left = '-9999px';
      document.body.appendChild(ta);
      ta.select();
      try {
        document.execCommand('copy');
        uiToast('success', 'Gallery', 'URL berhasil disalin.', 2000);
      } catch(e){
        uiToast('error', 'Gallery', 'Gagal menyalin URL.', 4000);
      }
      document.body.removeChild(ta);
    }
  });
})();
</script>