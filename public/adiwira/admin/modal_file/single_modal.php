<?php
declare(strict_types=1);

// /adiwira/admin/modal_file/single_modal.php
require_once __DIR__ . '/../_guard.php';

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    adiwira_cosmetic_404_on_direct_open();
}

[$uid, $role] = adiwira_require_editorial($pdo, false);
$isAdmin = ($role === 'admin');

$embedded = isset($_GET['embedded']) && (($_GET['embedded'] === '1') || ($_GET['embedded'] === 'true'));

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    echo '<div style="padding:16px">Invalid ID</div>';
    exit;
}

$sql = "SELECT * FROM `file` WHERE id = :id";
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
    echo '<div style="padding:16px">File not found</div>';
    exit;
}

$proto   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host    = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? '');
$baseUrl = rtrim($proto . '://' . $host, '/');

$url = (string)($r['url'] ?? '');
if ($url !== '' && !preg_match('#^https?://#i', $url)) {
    if (isset($url[0]) && $url[0] === '/') {
        $url = $baseUrl . $url;
    } else {
        $url = $baseUrl . '/' . ltrim($url, '/');
    }
}

$csrfToken = '';
try {
    if (function_exists('csrf_token')) {
        $csrfToken = (string) csrf_token();
    }
} catch (Throwable $e) {
    $csrfToken = '';
}

if (!function_exists('mdlib_single_human_filesize')) {
    function mdlib_single_human_filesize($bytes, int $decimals = 1): string
    {
        $bytes = (int)$bytes;
        if ($bytes <= 0) return '0 B';
        $units = ['B','KB','MB','GB','TB'];
        $i = (int)floor(log(max(1, $bytes), 1024));
        $i = min($i, count($units) - 1);
        return sprintf("%.{$decimals}f %s", $bytes / pow(1024, $i), $units[$i]);
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

$filename = (string)($r['filename'] ?? '');
$ext = (string)($r['ext'] ?? '');
if ($ext === '' && $filename !== '' && strpos($filename, '.') !== false) {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
}
$ico  = $ext ? strtoupper(substr($ext, 0, 4)) : 'FILE';
$mime = (string)($r['mime'] ?? '');
$size = (string)($r['size'] ?? '');
$visibility = strtolower((string)($r['visibility'] ?? 'public')) ?: 'public';
$storageDisk = strtolower((string)($r['storage_disk'] ?? 'public')) ?: 'public';
$accessScope = strtolower((string)($r['access_scope'] ?? 'public')) ?: 'public';
$isDownloadable = (int)($r['is_downloadable'] ?? 1);
$clientUrl = mdlib_client_url($r);
$displayUrl = ($visibility === 'private' || $storageDisk === 'private') ? $clientUrl : $url;

if (!$embedded):
?><!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>File Detail</title>
</head>
<body>
<?php endif; ?>

<div id="mdlib-single-wrap">
  <div class="mdlib-single">
    <form
      id="mdlib-file-edit-form"
      data-id="<?= (int)$r['id'] ?>"
      data-filename="<?= htmlspecialchars($filename, ENT_QUOTES, 'UTF-8') ?>"
      data-url="<?= htmlspecialchars($displayUrl, ENT_QUOTES, 'UTF-8') ?>"
      data-mime="<?= htmlspecialchars($mime, ENT_QUOTES, 'UTF-8') ?>"
      data-size="<?= htmlspecialchars($size, ENT_QUOTES, 'UTF-8') ?>"
      data-visibility="<?= htmlspecialchars($visibility, ENT_QUOTES, 'UTF-8') ?>"
      data-storage-disk="<?= htmlspecialchars($storageDisk, ENT_QUOTES, 'UTF-8') ?>"
      data-access-scope="<?= htmlspecialchars($accessScope, ENT_QUOTES, 'UTF-8') ?>"
      data-is-downloadable="<?= (int)$isDownloadable ?>"
    >
      <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

      <div class="mdlib-s-top">
        <div class="mdlib-s-ico"><?= htmlspecialchars($ico, ENT_QUOTES, 'UTF-8') ?></div>
        <div class="mdlib-s-meta">
          <div class="mdlib-name" title="<?= htmlspecialchars($filename, ENT_QUOTES, 'UTF-8') ?>">
            <?= htmlspecialchars($filename, ENT_QUOTES, 'UTF-8') ?>
          </div>
          <div class="mdlib-s-sub">
            <?= htmlspecialchars($mime ?: '�', ENT_QUOTES, 'UTF-8') ?>
            � <?= htmlspecialchars(mdlib_single_human_filesize((int)($r['size'] ?? 0)), ENT_QUOTES, 'UTF-8') ?>
          </div>
          <div class="mdlib-badges" style="margin-top:6px">
            <span class="mdlib-pill mdlib-pill-<?= htmlspecialchars($visibility, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(strtoupper($visibility), ENT_QUOTES, 'UTF-8') ?></span>
            <span class="mdlib-pill"><?= htmlspecialchars(strtoupper($accessScope), ENT_QUOTES, 'UTF-8') ?></span>
            <?php if (!$isDownloadable): ?><span class="mdlib-pill">NO DOWNLOAD</span><?php endif; ?>
            <?php if ($storageDisk !== 'public'): ?><span class="mdlib-pill">STORAGE: <?= htmlspecialchars(strtoupper($storageDisk), ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
          </div>
          <div class="mdlib-note" style="margin-top:6px">
            <a href="<?= htmlspecialchars($displayUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener"><?= $visibility === 'private' ? 'View (Protected)' : 'Open/Download' ?></a>
          </div>
        </div>
      </div>

      <div class="mdlib-row">
        <label class="mdlib-label">Title</label>
        <input class="mdlib-input" type="text" name="title" value="<?= htmlspecialchars((string)($r['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
      </div>

      <div class="mdlib-row">
        <label class="mdlib-label">Caption</label>
        <textarea class="mdlib-textarea" name="caption"><?= htmlspecialchars((string)($r['caption'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
      </div>

      <div class="mdlib-row">
        <label class="mdlib-label">Credit</label>
        <input class="mdlib-input" type="text" name="credit" value="<?= htmlspecialchars((string)($r['credit'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
      </div>

      <div class="mdlib-row">
        <label class="mdlib-label">Access Scope</label>
        <select class="mdlib-input" name="access_scope" <?= $visibility === 'public' ? 'disabled' : '' ?>>
          <option value="public" <?= $accessScope === 'public' ? 'selected' : '' ?>>Public</option>
            <option value="editorial" <?= in_array($accessScope, ['editorial','employee','both'], true) ? 'selected' : '' ?>>Editorial</option>
            <option value="admin">Admin Only</option>
        </select>
        <?php if ($visibility === 'public'): ?><div class="mdlib-note">Public file selalu access_scope public. Untuk private, upload ulang dengan mode Private.</div><?php endif; ?>
      </div>

      <div class="mdlib-row">
        <label class="mdlib-checkline" style="display:flex;gap:8px;align-items:center;font-weight:700">
          <input type="checkbox" name="is_downloadable" value="1" <?= $isDownloadable ? 'checked' : '' ?>>
          Downloadable
        </label>
      </div>

      <div class="mdlib-row">
        <label class="mdlib-label">File URL (read-only)</label>
        <div class="mdlib-urlrow">
          <input class="mdlib-input mdlib-url" id="mdlib-file-url" type="text" readonly value="<?= htmlspecialchars($displayUrl, ENT_QUOTES, 'UTF-8') ?>">
          <button type="button" class="mdlib-btn" data-mdlib-action="copy-url">Copy</button>
        </div>
        <div class="mdlib-note">URL ini yang akan dipakai saat Insert.</div>
      </div>

      <div class="mdlib-actions">
        <button type="button" class="mdlib-btn mdlib-btn-primary" id="mdlib-file-insert">Insert</button>
        <button type="button" class="mdlib-btn mdlib-btn-primary" id="mdlib-file-save">Save</button>
        <button type="button" class="mdlib-btn mdlib-btn-danger" id="mdlib-file-delete">Delete</button>
        <button type="button" class="mdlib-btn" id="mdlib-back-btn">Back</button>
      </div>
    </form>
  </div>
</div>

<script>
(function(){
  const form = document.getElementById('mdlib-file-edit-form');
  if (!form) return;

  function uiToast(type, title, message, duration) {
    if (window.mdlibUi && typeof window.mdlibUi.toast === 'function') {
      window.mdlibUi.toast(type, title, message, duration);
      return;
    }
    alert(message || title || 'Terjadi sesuatu.');
  }

  function uiAsk(variant, opts) {
    if (window.mdlibUi && typeof window.mdlibUi.ask === 'function') {
      return window.mdlibUi.ask(variant, opts || {});
    }
    return Promise.resolve(window.confirm((opts && opts.message) ? opts.message : 'Lanjutkan aksi ini?'));
  }

  function readJsonSafe(txt) {
    if (window.mdlibUi && typeof window.mdlibUi.readJsonSafe === 'function') {
      return window.mdlibUi.readJsonSafe(txt);
    }
    try { return txt ? JSON.parse(txt) : null; }
    catch(e) { return null; }
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

  function getDetailPayload() {
    return {
      id: form.dataset.id ? parseInt(form.dataset.id, 10) : null,
      url: form.dataset.url || '',
      filename: form.dataset.filename || '',
      mime: form.dataset.mime || '',
      size: form.dataset.size || '',
      title: (form.querySelector('input[name="title"]')?.value || '').trim(),
      caption: (form.querySelector('textarea[name="caption"]')?.value || '').trim(),
      credit: (form.querySelector('input[name="credit"]')?.value || '').trim(),
      visibility: form.dataset.visibility || 'public',
      storage_disk: form.dataset.storageDisk || 'public',
      access_scope: form.dataset.accessScope || 'public',
      is_downloadable: form.dataset.isDownloadable || '1'
    };
  }

  document.getElementById('mdlib-file-insert')?.addEventListener('click', function(){
    const detail = getDetailPayload();
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
  });

  document.getElementById('mdlib-file-save')?.addEventListener('click', async function(){
    const ok = await uiAsk('warning', {
      title: 'Simpan perubahan file',
      message: 'Perubahan metadata file akan disimpan. Lanjutkan?',
      confirmText: 'Ya, simpan',
      cancelText: 'Batal'
    });
    if (!ok) return;

    const btn = this;
    btn.disabled = true;

    try {
      const fd = new FormData(form);

      const res = await fetch('/adiwira/admin/modal_file/save.php', {
        method: 'POST',
        credentials: 'include',
        body: fd
      });

      const txt = await res.text();
      const j = readJsonSafe(txt);

      if (!res.ok) {
        uiToast('error', 'Library File', 'Error: ' + ((j && j.error) ? j.error : (txt || ('HTTP ' + res.status))), 6000);
        return;
      }

      if (!j || !j.ok) {
        uiToast('error', 'Library File', 'Error: ' + ((j && j.error) ? j.error : 'Save failed'), 6000);
        return;
      }

      if (j.file) {
        form.dataset.filename = j.file.filename || form.dataset.filename || '';
        form.dataset.url = j.file.url || form.dataset.url || '';
        form.dataset.mime = j.file.mime || form.dataset.mime || '';
        form.dataset.size = j.file.size || form.dataset.size || '';
      }

      broadcast('file:updated', j.file || j);
      uiToast('success', 'Library File', 'File berhasil diperbarui.', 2200);
    } catch (err) {
      uiToast('error', 'Library File', 'Network error: ' + (err && err.message ? err.message : err), 6000);
    } finally {
      btn.disabled = false;
    }
  });

  document.getElementById('mdlib-file-delete')?.addEventListener('click', async function(){
    const ok = await uiAsk('danger', {
      title: 'Hapus file',
      message: 'File ini akan dihapus permanen. Lanjutkan?',
      confirmText: 'Ya, hapus',
      cancelText: 'Batal'
    });
    if (!ok) return;

    const btn = this;
    btn.disabled = true;

    try {
      const fd = new FormData();
      fd.append('id', String(form.querySelector('input[name="id"]')?.value || ''));
      fd.append('csrf_token', String(form.querySelector('input[name="csrf_token"]')?.value || ''));

      const res = await fetch('/adiwira/admin/modal_file/delete.php', {
        method: 'POST',
        credentials: 'include',
        body: fd
      });

      const txt = await res.text();
      const j = readJsonSafe(txt);

      if (!res.ok) {
        uiToast('error', 'Library File', 'Error: ' + ((j && j.error) ? j.error : (txt || ('HTTP ' + res.status))), 6000);
        return;
      }

      if (!j || !j.ok) {
        uiToast('error', 'Library File', 'Error: ' + ((j && j.error) ? j.error : 'Delete failed'), 6000);
        return;
      }

      const payload = Object.assign({}, j || {}, {
        id: form.dataset.id ? parseInt(form.dataset.id, 10) : (j.id || null),
        url: form.dataset.url || '',
        deleted_ids: (j && Array.isArray(j.deleted_ids))
          ? j.deleted_ids
          : (form.dataset.id ? [parseInt(form.dataset.id, 10)].filter(Boolean) : []),
        deleted_urls: (j && Array.isArray(j.deleted_urls))
          ? j.deleted_urls
          : (form.dataset.url ? [form.dataset.url] : [])
      });

      broadcast('file:deleted', payload);

      uiToast('success', 'Library File', 'File berhasil dihapus.', 2200);
      if (j.warning) {
        uiToast('warning', 'Library File', j.warning, 6000);
      }

      if (typeof window.mdlibBackToLibrary === 'function') {
        window.mdlibBackToLibrary();
        return;
      }
    } catch (err) {
      uiToast('error', 'Library File', 'Network error: ' + (err && err.message ? err.message : err), 6000);
    } finally {
      btn.disabled = false;
    }
  });

  document.getElementById('mdlib-back-btn')?.addEventListener('click', function(){
    if (typeof window.mdlibBackToLibrary === 'function') {
      window.mdlibBackToLibrary();
    }
  });

  form.querySelector('[data-mdlib-action="copy-url"]')?.addEventListener('click', async function(){
    const input = document.getElementById('mdlib-file-url');
    const value = input ? (input.value || '').trim() : '';
    if (!value) {
      uiToast('warning', 'Library File', 'URL tidak ditemukan.', 4000);
      return;
    }

    try {
      if (navigator.clipboard && navigator.clipboard.writeText && window.isSecureContext) {
        await navigator.clipboard.writeText(value);
        uiToast('success', 'Library File', 'URL berhasil disalin.', 1800);
        return;
      }
    } catch(e){}

    if (input) {
      input.select();
      try {
        document.execCommand('copy');
        uiToast('success', 'Library File', 'URL berhasil disalin.', 1800);
      } catch (e) {
        uiToast('error', 'Library File', 'Gagal menyalin URL.', 4000);
      }
    }
  });
})();
</script>

<?php if (!$embedded): ?>
</body>
</html>
<?php endif; ?>
