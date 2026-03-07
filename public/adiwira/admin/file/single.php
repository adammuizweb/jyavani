<?php
declare(strict_types=1);

// /adiwira/admin/file/single.php
require_once __DIR__ . '/../_guard.php';
adiwira_require_admin(false);

header('Content-Type: text/html; charset=utf-8');

$id  = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$url = isset($_GET['url']) ? trim((string)$_GET['url']) : '';

$row = null;

try {
    if ($id > 0) {
        $stmt = $pdo->prepare("SELECT * FROM `file` WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } elseif ($url !== '') {
        $stmt = $pdo->prepare("SELECT * FROM `file` WHERE url = :url LIMIT 1");
        $stmt->execute([':url' => $url]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    echo "<div style='color:red'>DB Error: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . "</div>";
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

$csrf = function_exists('csrf_token') ? csrf_token() : '';
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
            <span class="url-prefix" id="media-url-prefix"><?= htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8') ?></span>
            <input type="text" id="media-url-path" class="url-path" readonly value="<?= htmlspecialchars((string)$path, ENT_QUOTES, 'UTF-8') ?>">
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
  // ✅ Jika dashboard sudah punya delegation, jangan double-bind
  if (window.__fileDelegationInstalled) return;

  function getCsrfToken() {
    const el = document.querySelector('input[name="csrf_token"]');
    return el && el.value ? el.value : '';
  }

  async function readJsonSafe(res){
    const txt = await res.text();
    let j = null;
    try { j = txt ? JSON.parse(txt) : null; } catch(e) {}
    return { txt, j };
  }

  document.getElementById('file-save-btn').addEventListener('click', async function(){
    const btn = this;
    btn.disabled = true;
    const form = document.getElementById('file-edit-form');
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
        alert('Error: ' + ((j && j.error) ? j.error : (txt || ('HTTP ' + res.status))));
        return;
      }

      if (j && j.ok) {
        alert('Saved ✔');
        try { document.dispatchEvent(new CustomEvent('file:updated', { detail: j.file || j })); } catch(e){}
        try { document.dispatchEvent(new CustomEvent('media:updated', { detail: j.file || j })); } catch(e){}
      } else {
        alert('Error: ' + ((j && j.error) ? j.error : (txt || 'unknown')));
      }
    } catch (err) {
      alert('Network error: ' + err.message);
    } finally {
      btn.disabled = false;
    }
  });

  document.getElementById('file-delete-btn').addEventListener('click', async function(){
    if (!confirm('Hapus file ini secara permanen?')) return;

    const form = document.getElementById('file-edit-form');
    const id = form.querySelector('input[name="id"]').value;
    const fd = new FormData();
    fd.append('id', id);

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
        alert('Error: ' + ((j && j.error) ? j.error : (txt || ('HTTP ' + res.status))));
        return;
      }

      if (j && j.ok) {
        alert('Deleted ✔');
        try { document.dispatchEvent(new CustomEvent('file:deleted', { detail: j })); } catch(e){}
        try { document.dispatchEvent(new CustomEvent('media:deleted', { detail: j })); } catch(e){}
        // close modal fallback
        const bd = document.getElementById('adam-modal-backdrop');
        if (bd) bd.style.display = 'none';
      } else {
        alert('Error: ' + ((j && j.error) ? j.error : (txt || 'unknown')));
      }
    } catch (err) {
      alert('Network error: ' + err.message);
    }
  });
})();
</script>