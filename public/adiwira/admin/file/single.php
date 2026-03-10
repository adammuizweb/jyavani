<?php
declare(strict_types=1);

// /adiwira/admin/file/single.php
require_once __DIR__ . '/../_guard.php';

[$uid, $role] = adiwira_require_editorial($pdo, false);
$isAdmin = ($role === 'admin');

header('Content-Type: text/html; charset=utf-8');

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
$path = parse_url((string)$row['url'], PHP_URL_PATH) ?: (string)$row['url'];

$csrf = csrf_token();
$ext  = strtoupper((string)($row['ext'] ?? ''));
if ($ext === '') $ext = strtoupper((string)pathinfo((string)($row['filename'] ?? ''), PATHINFO_EXTENSION));
if ($ext === '') $ext = 'FILE';
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
          <a href="<?= htmlspecialchars((string)$row['url'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">
            <?= htmlspecialchars((string)$row['url'], ENT_QUOTES, 'UTF-8') ?>
          </a>
        </div>

        <div class="small">
          Filename: <?= htmlspecialchars((string)($row['filename'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
          — MIME: <?= htmlspecialchars((string)($row['mime'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
          — Size: <?= (int)($row['size'] ?? 0) ?> bytes
        </div>

        <div style="margin-top:10px">
          <div style="font-weight:800;">File URL (read-only)</div>
          <div class="url-row">
            <span class="url-prefix" id="file-url-prefix"><?= htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8') ?></span>
            <input type="text" id="file-url-path" class="url-path" readonly value="<?= htmlspecialchars((string)$path, ENT_QUOTES, 'UTF-8') ?>">
            <button type="button" class="copy-btn" data-action="copy-url">Copy</button>
          </div>
        </div>
      </div>
    </div>

    <label>Title</label>
    <input type="text" name="title" value="<?= htmlspecialchars((string)($row['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">

    <label>Caption</label>
    <textarea name="caption" rows="3"><?= htmlspecialchars((string)($row['caption'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>

    <label>Credit</label>
    <input type="text" name="credit" value="<?= htmlspecialchars((string)($row['credit'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">

    <div style="margin-top:12px; display:flex; gap:10px;">
      <button id="file-save-btn" class="btn" type="button">Save</button>
      <button id="file-delete-btn" class="btn danger" type="button">Delete</button>
    </div>
  </form>
</div>

<script>
(function(){
  // Kalau dibuka dari File Manager, handler sudah ditangani oleh /admin/file/index.php
  if (window.__ADIWIRA_FILE_INDEX_INIT__) {
    return;
  }

  if (window.__ADIWIRA_FILE_SINGLE_FALLBACK_INIT__) {
    return;
  }
  window.__ADIWIRA_FILE_SINGLE_FALLBACK_INIT__ = true;

  function notify(message, type = 'info', duration = 4000) {
    if (typeof window.showToast === 'function') {
      window.showToast(message, type, duration);
      return;
    }
    alert(message);
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
      saveBtn.disabled = true;

      const fd = new FormData(form);
      const csrf = getCsrfToken();
      if (csrf && !fd.get('csrf_token')) fd.append('csrf_token', csrf);

      try {
        const res = await fetch('/adiwira/admin/file/save.php', {
          method: 'POST',
          credentials: 'include',
          cache: 'no-store',
          body: fd
        });

        const { txt, j } = await readJsonSafe(res);

        if (!res.ok) {
          notify('Error: ' + ((j && j.error) ? j.error : (txt || ('HTTP ' + res.status))), 'error', 6000);
          return;
        }

        if (j && j.ok) {
          notify('Saved ✔', 'success', 3000);
          document.dispatchEvent(new CustomEvent('file:updated', { detail: j.file || j }));
        } else {
          notify('Error: ' + ((j && j.error) ? j.error : (txt || 'unknown')), 'error', 6000);
        }
      } catch (err) {
        notify('Network error: ' + (err.message || err), 'error', 6000);
      } finally {
        saveBtn.disabled = false;
      }
    });
  }

  if (deleteBtn) {
    deleteBtn.addEventListener('click', async function(){
      if (!confirm('Hapus file ini secara permanen?')) return;

      deleteBtn.disabled = true;

      const id = form.querySelector('input[name="id"]')?.value || '';
      const fd = new FormData();
      if (id) fd.append('id', id);

      const csrf = getCsrfToken();
      if (csrf) fd.append('csrf_token', csrf);

      try {
        const res = await fetch('/adiwira/admin/file/delete.php', {
          method: 'POST',
          credentials: 'include',
          cache: 'no-store',
          body: fd
        });

        const { txt, j } = await readJsonSafe(res);

        if (!res.ok) {
          notify('Error: ' + ((j && j.error) ? j.error : (txt || ('HTTP ' + res.status))), 'error', 6000);
          return;
        }

        if (j && j.ok) {
          notify('Deleted ✔', 'success', 3000);
          document.dispatchEvent(new CustomEvent('file:deleted', { detail: j }));
          closeModalFallback();
        } else {
          notify('Error: ' + ((j && j.error) ? j.error : (txt || 'unknown')), 'error', 6000);
        }
      } catch (err) {
        notify('Network error: ' + (err.message || err), 'error', 6000);
      } finally {
        deleteBtn.disabled = false;
      }
    });
  }
})();
</script>