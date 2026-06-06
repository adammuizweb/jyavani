<?php
declare(strict_types=1);

// /adiwira/admin/modal_img/single_modal.php
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
    echo '<div>' . __('Media not found') . '</div>';
    exit;
}

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

$proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host  = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? '');
$baseUrl = rtrim($proto . '://' . $host, '/');

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
<div class="mdlib-media-wrap">
  <div class="mdlib-media-left">
    <div class="mdlib-img-frame" title="<?= htmlspecialchars((string)($r['filename'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
      <img src="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars((string)($r['alt'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
    </div>

    <div class="mdlib-meta-row">
      <div><strong><?=_e('Filename:')?></strong> <?= htmlspecialchars((string)($r['filename'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
      <div><strong><?=_e('MIME:')?></strong> <?= htmlspecialchars((string)($r['mime'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></div>
      <div><strong><?=_e('Size:')?></strong> <?= htmlspecialchars(modalimg_human_filesize((int)($r['size'] ?? 0)), ENT_QUOTES, 'UTF-8') ?></div>
      <?php if (!empty($r['width']) || !empty($r['height'])): ?>
        <div><strong><?=_e('Dim:')?></strong> <?= (int)$r['width'] ?> × <?= (int)$r['height'] ?></div>
      <?php endif; ?>
      <?php if ($hasVisibility): ?>
        <div><strong><?=_e('Visibility:')?></strong> <?= htmlspecialchars(strtoupper($visibility), ENT_QUOTES, 'UTF-8') ?></div>
        <div><strong><?=_e('Scope:')?></strong> <?= htmlspecialchars(strtoupper($accessScope), ENT_QUOTES, 'UTF-8') ?></div>
      <?php endif; ?>
      <div class="mdlib-meta-time"><?=_e('Uploaded:')?> <?= htmlspecialchars((string)($r['created_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
    </div>
  </div>

  <div class="mdlib-media-right">
    <form id="mdlib-media-edit-form">
      <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

      <div class="mdlib-field">
        <label for="mdlib-field-title"><?=_e('Title')?></label>
        <input id="mdlib-field-title" type="text" name="title" value="<?= htmlspecialchars((string)($r['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
      </div>

      <div class="mdlib-field">
        <label for="mdlib-field-alt"><?=_e('Alt')?></label>
        <input id="mdlib-field-alt" type="text" name="alt" value="<?= htmlspecialchars((string)($r['alt'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
      </div>

      <div class="mdlib-field">
        <label for="mdlib-field-caption"><?=_e('Caption')?></label>
        <textarea id="mdlib-field-caption" name="caption"><?= htmlspecialchars((string)($r['caption'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
      </div>

      <div class="mdlib-field">
        <label for="mdlib-field-credit"><?=_e('Credit')?></label>
        <input id="mdlib-field-credit" type="text" name="credit" value="<?= htmlspecialchars((string)($r['credit'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="<?=_e('Photographer Name — Source / License')?>">
      </div>

      <div class="mdlib-field">
        <label for="mdlib-field-target-url"><?=_e('Target URL')?></label>
        <input id="mdlib-field-target-url" type="text" name="target_url" value="<?= htmlspecialchars($linkUrlValue, ENT_QUOTES, 'UTF-8') ?>" placeholder="https://example.com/page">
      </div>

      <div class="mdlib-field">
        <label for="mdlib-field-target-attr"><?=_e('Open behavior')?></label>
        <select id="mdlib-field-target-attr" name="target_attribute">
          <option value="" <?= $linkTargetValue === '' ? 'selected' : '' ?>><?=_e('Default')?></option>
          <option value="_self" <?= $linkTargetValue === '_self' ? 'selected' : '' ?>><?=_e('Open in same tab (_self)')?></option>
          <option value="_blank" <?= $linkTargetValue === '_blank' ? 'selected' : '' ?>><?=_e('Open in new tab (_blank)')?></option>
          <option value="_parent" <?= $linkTargetValue === '_parent' ? 'selected' : '' ?>><?=_e('_parent')?></option>
          <option value="_top" <?= $linkTargetValue === '_top' ? 'selected' : '' ?>><?=_e('_top')?></option>
        </select>
      </div>

      <?php if ($hasVisibility): ?>
      <div class="mdlib-field">
        <label for="mdlib-field-access-scope"><?=_e('Access Scope')?></label>
        <select id="mdlib-field-access-scope" name="access_scope" <?= $visibility === 'public' ? 'disabled' : '' ?>>
          <option value="public" <?= $accessScope === 'public' ? 'selected' : '' ?>><?=_e('Public')?></option>
            <option value="editorial" <?= in_array($accessScope, ['editorial','employee','both'], true) ? 'selected' : '' ?>><?=_e('Editorial')?></option>
            <option value="admin"><?=_e('Admin Only')?></option>
        </select>
        <?php if ($visibility === 'public'): ?><div class="mdlib-note"><?=_e('Public media always has public access scope. For private, re-upload in Private mode.')?></div><?php endif; ?>
      </div>

      <div class="mdlib-field">
        <label class="mdlib-checkline" style="display:flex;gap:8px;align-items:center;font-weight:700">
          <input type="checkbox" name="is_downloadable" value="1" <?= $isDownloadable ? 'checked' : '' ?>>
          <?=_e('Downloadable')?>
        </label>
      </div>
      <?php endif; ?>

      <div class="mdlib-field">
        <label><?=_e('File URL (read-only)')?></label>
        <div class="mdlib-urlrow">
          <span class="mdlib-url-prefix" id="mdlib-url-prefix"><?= htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8') ?></span>
          <input type="text" id="mdlib-url-path" class="mdlib-url" readonly value="<?= htmlspecialchars((string)(parse_url((string)($r['url'] ?? ''), PHP_URL_PATH) ?: ($r['url'] ?? '')), ENT_QUOTES, 'UTF-8') ?>">
          <button type="button" class="mdlib-btn-copy" data-action="copy-url"><?=_e('Copy')?></button>
        </div>
      </div>

      <input type="hidden" name="url" value="<?= htmlspecialchars((string)($r['url'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">

      <div class="mdlib-actions">
        <button type="button" class="mdlib-btn" id="mdlib-back-btn"><?=_e('← Back to Gallery')?></button>
        <button type="button" class="mdlib-btn mdlib-btn-primary" id="mdlib-media-save-btn"><?=_e('Save')?></button>
        <button type="button" class="mdlib-btn mdlib-btn-danger" id="mdlib-media-delete-btn"><?=_e('Delete')?></button>
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
    alert(message || title || <?= json_encode(__('Something happened.')) ?>);
  }

  function uiAsk(variant, opts){
    const api = getConfirmApi();
    if (api) {
      if (variant === 'danger' && typeof api.danger === 'function') return api.danger(opts || {});
      if (typeof api.warning === 'function') return api.warning(opts || {});
    }
    return Promise.resolve(window.confirm((opts && opts.message) ? opts.message: <?= json_encode(__('Proceed with this action?')) ?>));
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

  document.getElementById('mdlib-media-save-btn')?.addEventListener('click', async function(){
    const ok = await uiAsk('warning', {
      title: <?= json_encode(__('Save media changes')) ?>,
      message: <?= json_encode(__('Media metadata changes will be saved. Proceed?')) ?>,
      confirmText: <?= json_encode(__('Yes, save')) ?>,
      cancelText: <?= json_encode(__('Cancel')) ?>
    });
    if (!ok) return;

    const btn = this;
    const form = document.getElementById('mdlib-media-edit-form');
    if (!form) return;

    btn.disabled = true;

    const fd = new FormData(form);

    fetch('<?= ADMIN_BASE_PATH ?>/admin/media/save.php', {
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
        uiToast('success', 'Gallery', <?= json_encode(__('Media updated successfully.')) ?>, 2500);
        broadcast('media:updated', j);
      })
      .catch(err => {
        console.error('Save error', err);
        uiToast('error', 'Gallery', <?= json_encode(__('Save error:')) ?> + ' ' + (err.message || <?= json_encode(__('Failed')) ?>), 6000);
      })
      .finally(() => {
        btn.disabled = false;
      });
  });

  document.getElementById('mdlib-media-delete-btn')?.addEventListener('click', async function(){
    const ok = await uiAsk('danger', {
      title: <?= json_encode(__('Delete media')) ?>,
      message: <?= json_encode(__('This media will be permanently deleted from the gallery. Proceed?')) ?>,
      confirmText: <?= json_encode(__('Yes, delete')) ?>,
      cancelText: <?= json_encode(__('Cancel')) ?>
    });
    if (!ok) return;

    const btn = this;
    btn.disabled = true;

    const form = document.getElementById('mdlib-media-edit-form');
    const fd = new FormData();
    const idEl = form.querySelector('input[name="id"]');
    const urlEl = form.querySelector('input[name="url"]');

    if (idEl) fd.append('id', idEl.value);
    if (urlEl) fd.append('url', urlEl.value);

    const csrf = getCsrf();
    if (csrf) fd.append('csrf_token', csrf);

    fetch('<?= ADMIN_BASE_PATH ?>/admin/media/delete.php', {
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

        uiToast('success', 'Gallery', <?= json_encode(__('Media deleted successfully.')) ?>, 2500);
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
        uiToast('error', 'Gallery', <?= json_encode(__('Delete error:')) ?> + ' ' + (err.message || <?= json_encode(__('Failed')) ?>), 6000);
      })
      .finally(() => {
        btn.disabled = false;
      });
  });

  document.getElementById('mdlib-back-btn')?.addEventListener('click', function(){
    var listUrl = '<?= ADMIN_BASE_PATH ?>/admin/modal_img/list_modal.php?embedded=1';

    var content = null;
    try { content = document.getElementById('adam-modal-content'); } catch(e){}
    if (!content) {
      try { content = window.parent.document.getElementById('adam-modal-content'); } catch(e){}
    }

    if (content && typeof window.injectHtmlWithScriptsTo === 'function') {
      fetch(listUrl, { credentials: 'include', cache: 'no-store' })
        .then(function(r){ if (!r.ok) throw new Error('HTTP ' + r.status); return r.text(); })
        .then(function(html){ return window.injectHtmlWithScriptsTo(content, html); })
        .catch(function(err){
          console.error('back-to-list error', err);
          uiToast('error', 'Gallery', <?= json_encode(__('Failed to load media list.')) ?>, 4000);
        });
    } else {
      try {
        if (window.parent && window.parent.adamModalOpen) {
          window.parent.adamModalOpen(listUrl, { maxWidth: '980px' });
        }
      } catch(e){}
    }
  });

  document.querySelector('[data-action="copy-url"]')?.addEventListener('click', function(ev){
    ev.preventDefault();

    const prefixEl = document.getElementById('mdlib-url-prefix');
    const pathEl = document.getElementById('mdlib-url-path');
    const prefix = prefixEl ? (prefixEl.textContent || '').trim() : window.location.origin;
    const path = pathEl ? (pathEl.value || '').trim() : '';

    if (!path) {
      uiToast('warning', 'Gallery', <?= json_encode(__('URL not found.')) ?>, 4000);
      return;
    }

    let full = path;
    if (!/^https?:\/\//i.test(path)) {
      full = prefix.replace(/\/$/, '') + path;
    }

    if (navigator.clipboard && navigator.clipboard.writeText && window.isSecureContext) {
      navigator.clipboard.writeText(full)
        .then(() => uiToast('success', 'Gallery', <?= json_encode(__('URL copied successfully.')) ?>, 2000))
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
        uiToast('success', 'Gallery', <?= json_encode(__('URL copied successfully.')) ?>, 2000);
      } catch(e){
        uiToast('error', 'Gallery', <?= json_encode(__('Failed to copy URL.')) ?>, 4000);
      }
      document.body.removeChild(ta);
    }
  });
})();
</script>
