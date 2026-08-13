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
        echo "<div style='padding:18px'>" . __('File not found') . "</div>";
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
    echo "<div style='padding:18px'>" . __('File not found') . "</div>";
    exit;
}

if (!$row) {
    echo "<div style='padding:18px'>" . __('File not found') . "</div>";
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

if (!function_exists('human_filesize')) {
    function human_filesize(int $bytes, int $decimals = 1): string {
        if ($bytes <= 0) return '0 B';
        $units = ['B','KB','MB','GB','TB'];
        $i = (int)floor(log(max(1, $bytes), 1024));
        $i = min($i, count($units) - 1);
        return sprintf("%.{$decimals}f %s", $bytes / pow(1024, $i), $units[$i]);
    }
}
?>
<div class="single-file asset-detail">
  <div class="single-file-card asset-detail-card">
    <div class="single-file-header">
      <div class="single-file-thumb-wrap">
        <div class="file-thumb file-thumb--large"><?= htmlspecialchars($ext, ENT_QUOTES, 'UTF-8') ?></div>
      </div>
      <div class="file-meta">
        <div class="asset-detail-kicker"><?=_e('File Detail')?></div>
        <h3 class="asset-detail-title"><?= htmlspecialchars((string)($row['title'] ?: $row['filename']), ENT_QUOTES, 'UTF-8') ?></h3>
        <div class="asset-detail-subtitle"><?= htmlspecialchars((string)($row['filename'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
        <div class="file-meta-row">
          <span class="file-meta-label"><?=_e('Filename')?></span>
          <span class="file-meta-value"><?= htmlspecialchars((string)($row['filename'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <div class="file-meta-row">
          <span class="file-meta-label"><?=_e('MIME')?></span>
          <span class="file-meta-value"><?= htmlspecialchars((string)($row['mime'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <div class="file-meta-row">
          <span class="file-meta-label"><?=_e('Size')?></span>
          <span class="file-meta-value"><?= htmlspecialchars(human_filesize((int)($row['size'] ?? 0)), ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <div class="file-meta-row">
          <span class="file-meta-label"><?=_e('Uploaded')?></span>
          <span class="file-meta-value"><?= htmlspecialchars((string)($row['created_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <div class="file-meta-badges">
          <span class="badge badge--<?= $isPrivate ? 'warn' : 'ok' ?>"><?= htmlspecialchars(strtoupper($visibility), ENT_QUOTES, 'UTF-8') ?></span>
          <span class="badge badge--info"><?= htmlspecialchars(content_access_scope_label($accessScope), ENT_QUOTES, 'UTF-8') ?></span>
          <?php if (!$isDownloadable): ?>
            <span class="badge badge--danger"><?=_e('NO DOWNLOAD')?></span>
          <?php endif; ?>
        </div>
        <a class="asset-detail-open" href="<?= htmlspecialchars($clientUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener"><?=_e('Open in new tab')?> <span aria-hidden="true">&nearr;</span></a>
      </div>
    </div>

    <div class="single-file-body">
      <div class="single-file-section asset-detail-editor">
        <div class="file-section-title"><?=_e('Metadata')?></div>

        <form id="file-edit-form" class="asset-detail-fields">
          <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$csrf, ENT_QUOTES, 'UTF-8') ?>">

          <label for="file-field-title"><?=_e('Title')?></label>
          <input id="file-field-title" type="text" name="title" value="<?= htmlspecialchars((string)($row['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">

          <label for="file-field-caption"><?=_e('Caption')?></label>
          <textarea id="file-field-caption" name="caption" rows="3"><?= htmlspecialchars((string)($row['caption'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>

          <label for="file-field-credit"><?=_e('Credit')?></label>
          <input id="file-field-credit" type="text" name="credit" value="<?= htmlspecialchars((string)($row['credit'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">

          <label for="file-field-access-scope"><?=_e('Access Scope')?></label>
          <select id="file-field-access-scope" name="access_scope" <?= $visibility === 'public' ? 'disabled' : '' ?>>
            <option value="public" <?= $accessScope === 'public' ? 'selected' : '' ?>><?=_e('Public')?></option>
            <option value="editorial" <?= in_array($accessScope, ['editorial','employee','both'], true) ? 'selected' : '' ?>><?=_e('Content Team')?></option>
            <option value="admin" <?= $accessScope === 'admin' ? 'selected' : '' ?>><?=_e('Administrator')?></option>
          </select>
          <?php if ($visibility === 'public'): ?><div class="file-url-hint"><?=_e('Public file always has public access scope. For private, re-upload in Private mode.')?></div><?php endif; ?>

          <label class="file-check-label">
            <input type="checkbox" name="is_downloadable" value="1" <?= $isDownloadable ? 'checked' : '' ?>>
            <?=_e('Allow download')?>
          </label>

          <div class="actions">
            <button id="file-save-btn" class="btn" type="button"><?=_e('Save')?></button>
            <button id="file-delete-btn" class="btn danger" type="button"><?=_e('Delete')?></button>
          </div>
        </form>
      </div>

      <div class="single-file-section">
        <div class="file-section-title"><?=_e('File URL')?></div>
        <div class="url-row">
          <span class="url-prefix" id="file-url-prefix"><?= htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8') ?></span>
          <input type="text" class="url-path" id="file-url-path" readonly value="<?= htmlspecialchars($clientUrl, ENT_QUOTES, 'UTF-8') ?>">
          <button type="button" class="copy-btn" data-action="copy-url"><?=_e('Copy')?></button>
        </div>
        <div class="file-url-hint"><?=_e('This URL will be used when inserting.')?></div>
      </div>

    </div>
  </div>
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
    alert(message || title || <?= json_encode(__('Something happened.')) ?>);
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
          uiToast('error', '<?=__('File')?>', ((j && j.error) ? j.error : (txt || ('HTTP ' + res.status))), 6000);
          return;
        }

        if (j && j.ok) {
          uiToast('success', '<?=__('File')?>', '<?=__('File updated successfully.')?>', 3000);
          document.dispatchEvent(new CustomEvent('file:updated', { detail: j.file || j }));
        } else {
          uiToast('error', '<?=__('File')?>', ((j && j.error) ? j.error : (txt || 'unknown')), 6000);
        }
      } catch (err) {
        uiToast('error', '<?=__('File')?>', '<?=__('Network error:')?> ' + (err.message || err), 6000);
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
          uiToast('error', '<?=__('File')?>', ((j && j.error) ? j.error : (txt || ('HTTP ' + res.status))), 6000);
          return;
        }

        if (j && j.ok) {
          uiToast('success', '<?=__('File')?>', '<?=__('File deleted successfully.')?>', 3000);
          if (j.warning) {
            uiToast('warning', '<?=__('File')?>', j.warning, 6000);
          }
          document.dispatchEvent(new CustomEvent('file:deleted', { detail: j }));
          closeModalFallback();
        } else {
          uiToast('error', '<?=__('File')?>', ((j && j.error) ? j.error : (txt || 'unknown')), 6000);
        }
      } catch (err) {
        uiToast('error', '<?=__('File')?>', '<?=__('Network error:')?> ' + (err.message || err), 6000);
      } finally {
        deleteBtn.disabled = false;
      }
    });
  }
})();
</script>
