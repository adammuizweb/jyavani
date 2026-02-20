<?php
// /adiwira/admin/file/single.php
if (!defined('DASHBOARD_CONTEXT')) define('DASHBOARD_CONTEXT', true);
require_once __DIR__ . '/../../bootstrap.php';
if (session_status() === PHP_SESSION_NONE) session_start();

header('Content-Type: text/html; charset=utf-8');

$id = isset($_GET['id']) ? (int)$_GET['id'] : null;
$url = isset($_GET['url']) ? trim((string)$_GET['url']) : null;
$row = null;

try {
    if ($id) {
        $stmt = $pdo->prepare("SELECT * FROM `file` WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } elseif ($url) {
        $stmt = $pdo->prepare("SELECT * FROM `file` WHERE url = :url LIMIT 1");
        $stmt->execute([':url' => $url]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    echo "<div style='color:red'>DB Error: " . htmlspecialchars($e->getMessage()) . "</div>";
    exit;
}

if (!$row) {
    echo "<div style='padding:18px'>File not found</div>";
    exit;
}
?>

<style>
.single-file { font-family:system-ui, -apple-system, "Segoe UI", Roboto; }
.single-file .meta { display:flex; gap:12px; align-items:center; margin-bottom:12px; }
.single-file label { display:block; font-weight:600; margin-top:8px; }
.single-file input[type=text], .single-file textarea { width:100%; padding:8px; border:1px solid #ddd; border-radius:6px; }
.single-file .btn { padding:8px 12px; border-radius:6px; border:none; cursor:pointer; background:#2d8cf0; color:#fff; }
.single-file .btn.danger { background:#e53935; }
</style>

<div class="single-file">
  <form id="file-edit-form">
    <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
    <div class="meta">
      <div style="flex:0 0 120px">
        <div style="width:120px;height:90px;display:flex;align-items:center;justify-content:center;background:#f7f7f7;border-radius:8px;border:1px solid #eee;font-weight:700">
          <?= htmlspecialchars(strtoupper($row['ext'] ?? pathinfo($row['filename'] ?? '', PATHINFO_EXTENSION) ?: '')) ?>
        </div>
      </div>
      <div style="flex:1">
        <div><strong>URL</strong></div>
        <div style="margin-bottom:8px"><a href="<?= htmlspecialchars($row['url']) ?>" target="_blank"><?= htmlspecialchars($row['url']) ?></a></div>
        <div class="small">Filename: <?= htmlspecialchars($row['filename']) ?> — MIME: <?= htmlspecialchars($row['mime']) ?> — Size: <?= (int)$row['size'] ?> bytes</div>
      </div>
    </div>

    <label>Title</label>
    <input type="text" name="title" value="<?= htmlspecialchars($row['title'] ?? '') ?>">

    <label>Caption</label>
    <textarea name="caption" rows="3"><?= htmlspecialchars($row['caption'] ?? '') ?></textarea>

    <label>Credit</label>
    <input type="text" name="credit" value="<?= htmlspecialchars($row['credit'] ?? '') ?>">

    <div style="margin-top:12px; display:flex; gap:8px;">
      <button id="file-save-btn" class="btn" type="button">Save</button>
      <button id="file-delete-btn" class="btn danger" type="button">Delete</button>
    </div>
  </form>
</div>

<script>
(function(){
  document.getElementById('file-save-btn').addEventListener('click', async function(){
    const btn = this;
    btn.disabled = true;
    const form = document.getElementById('file-edit-form');
    const fd = new FormData(form);

    try {
      const res = await fetch('/adiwira/admin/file/save.php', {
        method: 'POST',
        credentials: 'include',
        body: fd
      });
      const j = await res.json();
      if (j.ok) {
        alert('Saved ✔');
        try { document.dispatchEvent(new CustomEvent('file:updated', { detail: j.file })); } catch(e){}
        try { document.dispatchEvent(new CustomEvent('media:updated', { detail: j.file })); } catch(e){}
      } else {
        alert('Error: ' + (j.error || JSON.stringify(j.errors || j)));
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

    try {
      const res = await fetch('/adiwira/admin/file/delete.php', {
        method: 'POST',
        credentials: 'include',
        body: fd
      });
      const j = await res.json();
      if (j.ok) {
        alert('Deleted ✔');
        try { document.dispatchEvent(new CustomEvent('file:deleted', { detail: j })); } catch(e){}
        try { document.dispatchEvent(new CustomEvent('media:deleted', { detail: j })); } catch(e){}
        // close modal (fallback)
        const bd = document.getElementById('adam-modal-backdrop');
        if (bd) bd.style.display = 'none';
      } else {
        alert('Error: ' + (j.error || JSON.stringify(j.errors || j)));
      }
    } catch (err) {
      alert('Network error: ' + err.message);
    }
  });
})();
</script>
