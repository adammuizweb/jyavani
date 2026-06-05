<?php
declare(strict_types=1);

// /adiwira/admin/file/single.php
require_once __DIR__ . '/../_guard.php';

[$uid, $role] = adiwira_require_editorial($pdo, false);
$isAdmin = ($role === 'admin');

header('Content-Type: text/html; charset=utf-8');

if (!function_exists('modalfilez_client_url')) {
    function modalfilez_client_url(array $row): string
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

$id  = (int)($_GET['id'] ?? 0);
$url = trim((string)($_GET['url'] ?? ''));

$row = null;

try {
    if ($id > 0) {
        $sql = "SELECT * FROM `file` WHERE id = :id";
        $params = [':id' => $id];
    } elseif ($url !== '') {
        $sql = "SELECT * FROM `file` WHERE url = :url";
        $params = [':url' => $url];
    } else {
        echo "<div style='padding:18px'>File not found</div>";
        exit;
    }

    if (!$isAdmin) {
        $sql .= " AND user_id = :uid";
        $params[':uid'] = $uid;
    }

    $sql .= " LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('file/single.php error: ' . $e->getMessage());
    echo "<div style='padding:18px'>File not found</div>";
    exit;
}

if (!$row) {
    echo "<div style='padding:18px'>File not found</div>";
    exit;
}

$proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host  = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? '');
$baseUrl = rtrim($proto . '://' . $host, '/');
$clientUrl = modalfilez_client_url($row);

$csrf = csrf_token();
$ext  = strtoupper((string)($row['ext'] ?? ''));
if ($ext === '') $ext = strtoupper((string)pathinfo((string)($row['filename'] ?? ''), PATHINFO_EXTENSION));
if ($ext === '') $ext = 'FILE';

$visibility = strtolower((string)($row['visibility'] ?? 'public')) ?: 'public';
$accessScope = strtolower((string)($row['access_scope'] ?? 'public')) ?: 'public';
$isDownloadable = (int)($row['is_downloadable'] ?? 1);
$isPrivate = ($visibility === 'private');
?>
<div class="single-file">
  <form id="file-edit-form">
    <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$csrf, ENT_QUOTES, 'UTF-8') ?>">

    <div class="meta">
      <div style="flex:0 0 120px">
        <div class="file-thumb"><?= htmlspecialchars($ext, ENT_QUOTES, 'UTF-8') ?></div>
      </div>

      <div style="flex:1; min-width:240px">
        <div style="font-weight:800; margin-bottom:6px">URL</div>
        <div class="small" style="margin-bottom:8px">
          <a href="<?= htmlspecialchars($clientUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">
            <?= htmlspecialchars($clientUrl, ENT_QUOTES, 'UTF-8') ?>
          </a>
        </div>

        <div class="small">
          Filename: <?= htmlspecialchars((string)($row['filename'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
          — MIME: <?= htmlspecialchars((string)($row['mime'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
          — Size: <?= (int)($row['size'] ?? 0) ?> bytes
        </div>

        <div style="margin-top:6px;display:flex;gap:5px;flex-wrap:wrap">
          <span class="badge" style="background:<?= $isPrivate ? '#fef3c7' : '#dcfce7' ?>;color:<?= $isPrivate ? '#92400e' : '#166534' ?>;padding:2px 7px;border-radius:999px;font-size:10px;font-weight:800"><?= htmlspecialchars(strtoupper($visibility), ENT_QUOTES, 'UTF-8') ?></span>
          <span class="badge" style="padding:2px 7px;border-radius:999px;font-size:10px;font-weight:800"><?= htmlspecialchars(strtoupper($accessScope), ENT_QUOTES, 'UTF-8') ?></span>
          <?php if (!$isDownloadable): ?>
            <span class="badge" style="background:#fee2e2;color:#991b1b;padding:2px 7px;border-radius:999px;font-size:10px;font-weight:800">NO DOWNLOAD</span>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <label>Title</label>
    <input type="text" name="title" value="<?= htmlspecialchars((string)($row['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">

    <label>Caption</label>
    <textarea name="caption" rows="3"><?= htmlspecialchars((string)($row['caption'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>

    <label>Credit</label>
    <input type="text" name="credit" value="<?= htmlspecialchars((string)($row['credit'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">

    <div style="margin-top:12px">
      <label>Access Scope</label>
      <select name="access_scope">
        <option value="public" <?= $accessScope === 'public' ? 'selected' : '' ?>>Public</option>
        <option value="editorial" <?= in_array($accessScope, ['editorial','employee','both'], true) ? 'selected' : '' ?>>Editorial</option>
        <option value="admin" <?= $accessScope === 'admin' ? 'selected' : '' ?>>Admin Only</option>
      </select>
    </div>

    <div style="margin-top:8px">
      <label style="display:inline-flex;align-items:center;gap:6px;cursor:pointer">
        <input type="checkbox" name="is_downloadable" value="1" <?= $isDownloadable ? 'checked' : '' ?>>
        Downloadable
      </label>
    </div>

    <div style="margin-top:12px; display:flex; gap:10px;">
      <button id="file-save-btn" class="btn" type="button">Save</button>
      <button id="file-delete-btn" class="btn danger" type="button">Delete</button>
    </div>
  </form>
</div>

<script>
(function(){
  if (window.__ADIWIRA_FILE_INDEX_INIT__) {
    return;
  }

  if (window.__ADIWIRA_FILE_SINGLE_FALLBACK_INIT__) {
    return;
  }
  window.__ADIWIRA_FILE_SINGLE_FALLBACK_INIT__ = true;

  function uiToast(type, title, message, duration) {
    if (window.NewNotifToast && typeof window.NewNotifToast.show === 'function') {
      window.NewNotifToast.show({
        type: type || 'info',
        title: title || null,
        message: message || '',
        duration: duration
      });
      return;
    }
    alert(message || title || 'Terjadi sesuatu.');
  }

  function uiAsk(variant, opts) {
    if (window.NewNotifConfirm) {
      if (variant === 'danger' && typeof window.NewNotifConfirm.danger === 'function') {
        return window.NewNotifConfirm.danger(opts || {});
      }
      if (typeof window.NewNotifConfirm.warning === 'function') {
        return window.NewNotifConfirm.warning(opts || {});
      }
    }
    return Promise.resolve(window.confirm((opts && opts.message) ? opts.message: <?= json_encode(__('Proceed with this action?')) ?>));
  }

  function getCsrfToken() {
    const el = document.querySelector('#file-edit-form input[name="csrf_token"]');
    return el && el.value ? el.value : '';
  }

  async function readJsonSafe(res){
    const txt = await res.text();
    let j = null;
    try { j = txt ? JSON.parse(txt) : null; } catch(e) {}
    return { txt, j };
  }

  function closeModalFallback() {
    if (typeof window.adamModalClose === 'function') {
      try { window.adamModalClose(); return; } catch(e){}
    }
    const bd = document.getElementById('adam-modal-backdrop');
    if (bd) bd.style.display = 'none';
  }

  const saveBtn = document.getElementById('file-save-btn');
  const deleteBtn = document.getElementById('file-delete-btn');
  const form = document.getElementById('file-edit-form');

  if (saveBtn) {
    saveBtn.addEventListener('click', async function(){
      const ok = await uiAsk('warning', {
        title: <?= json_encode(__('Save file changes')) ?>,
        message: '<?=__('File metadata changes will be saved. Continue?')?>',
        confirmText: <?= json_encode(__('Yes, save')) ?>,
        cancelText: <?= json_encode(__('Cancel')) ?>
      });
      if (!ok) return;

      saveBtn.disabled = true;

      const fd = new FormData(form);
      const csrf = getCsrfToken();
      if (csrf && !fd.get('csrf_token')) fd.append('csrf_token', csrf);

      try {
        const res = await fetch('<?= ADMIN_BASE_PATH ?>/admin/file/save.php', {
          method: 'POST',
          credentials: 'include',
          cache: 'no-store',
          body: fd
        });

        const { txt, j } = await readJsonSafe(res);

        if (!res.ok) {
          uiToast('error', 'File', ((j && j.error) ? j.error : (txt || ('HTTP ' + res.status))), 6000);
          return;
        }

        if (j && j.ok) {
          uiToast('success', 'File', 'File berhasil diperbarui.', 3000);
          document.dispatchEvent(new CustomEvent('file:updated', { detail: j.file || j }));
        } else {
          uiToast('error', 'File', ((j && j.error) ? j.error : (txt || 'unknown')), 6000);
        }
      } catch (err) {
        uiToast('error', 'File', 'Network error: ' + (err.message || err), 6000);
      } finally {
        saveBtn.disabled = false;
      }
    });
  }

  if (deleteBtn) {
    deleteBtn.addEventListener('click', async function(){
      const ok = await uiAsk('danger', {
        title: <?= json_encode(__('Delete file')) ?>,
        message: <?= json_encode(__('This file will be permanently deleted. Proceed?')) ?>,
        confirmText: <?= json_encode(__('Yes, delete')) ?>,
        cancelText: <?= json_encode(__('Cancel')) ?>
      });
      if (!ok) return;

      deleteBtn.disabled = true;

      const id = form.querySelector('input[name="id"]')?.value || '';
      const fd = new FormData();
      if (id) fd.append('id', id);

      const csrf = getCsrfToken();
      if (csrf) fd.append('csrf_token', csrf);

      try {
        const res = await fetch('<?= ADMIN_BASE_PATH ?>/admin/file/delete.php', {
          method: 'POST',
          credentials: 'include',
          cache: 'no-store',
          body: fd
        });

        const { txt, j } = await readJsonSafe(res);

        if (!res.ok) {
          uiToast('error', 'File', ((j && j.error) ? j.error : (txt || ('HTTP ' + res.status))), 6000);
          return;
        }

        if (j && j.ok) {
          uiToast('success', 'File', 'File berhasil dihapus.', 3000);
          if (j.warning) {
            uiToast('warning', 'File', j.warning, 6000);
          }
          document.dispatchEvent(new CustomEvent('file:deleted', { detail: j }));
          closeModalFallback();
        } else {
          uiToast('error', 'File', ((j && j.error) ? j.error : (txt || 'unknown')), 6000);
        }
      } catch (err) {
        uiToast('error', 'File', 'Network error: ' + (err.message || err), 6000);
      } finally {
        deleteBtn.disabled = false;
      }
    });
  }
})();
</script>