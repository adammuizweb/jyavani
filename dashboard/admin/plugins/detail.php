<?php
declare(strict_types=1);
// Plugin Detail — Status checks & setup steps
require_once DASH_PATH . '/admin/_deny.php';
if (!defined('DASHBOARD_CONTEXT') && !defined('ADAM_THEME')) adiwira_admin_404();
require_once DASH_PATH . '/admin/_guard.php';
require_once DASH_PATH . '/admin/_notify.php';

[$uid, $role] = adiwira_require_role($pdo, ['admin'], false);

$base = ADMIN_BASE_PATH;
$listUrl = $base . '/?page=admin/plugins/index';

$pluginName = (string)($_GET['name'] ?? '');
if ($pluginName === '' || !preg_match('/^[a-zA-Z0-9_-]+$/', $pluginName)) {
    adiwira_redirect_with_flash($listUrl, 'error', __('Nama plugin tidak valid.'));
}

$manifest = plugin_manifest($pluginName);
if (!$manifest) {
    adiwira_redirect_with_flash($listUrl, 'error', 'Plugin "' . h($pluginName) . '" tidak ditemukan.');
}

$title = $manifest['title'] ?? $pluginName;
$desc = $manifest['description'] ?? '';
$version = $manifest['version'] ?? '—';
$author = $manifest['author'] ?? '';
$homepage = $manifest['homepage'] ?? '';
$isActive = plugin_is_active($pluginName);
$checks = plugin_checks($pluginName);
$allPassed = !empty($checks) && count(array_filter($checks, fn($c) => $c['passed'])) === count($checks);
?>
<h2 class="pg-title"><?= h($title) ?></h2>
<p class="pg-subtitle">Detail dan status setup plugin.</p>

<div class="detail-grid">
  <div class="detail-card">
    <h3 class="card-title">Informasi Plugin</h3>
    <table class="info-table">
      <tr><th>Nama</th><td><?= h($pluginName) ?></td></tr>
      <tr><th>Versi</th><td><?= h($version) ?></td></tr>
      <?php if ($author): ?><tr><th>Penulis</th><td><?= h($author) ?></td></tr><?php endif; ?>
      <?php if ($desc): ?><tr><th>Deskripsi</th><td><?= h($desc) ?></td></tr><?php endif; ?>
      <?php if ($homepage): ?><tr><th>Situs</th><td><a href="<?= h($homepage) ?>" target="_blank" rel="noopener"><?= h($homepage) ?></a></td></tr><?php endif; ?>
      <tr><th>Status</th><td><?php if ($isActive): ?><span class="badge badge-success">Aktif</span><?php else: ?><span class="badge badge-muted">Nonaktif</span><?php endif; ?></td></tr>
    </table>
  </div>

  <?php if (!empty($checks)): ?>
  <div class="detail-card">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem">
      <h3 class="card-title" style="margin:0">Setup Checklist</h3>
      <a href="<?= h($base) ?>/?page=admin/plugins/detail&name=<?= h($pluginName) ?>" class="btn btn-sm btn-outline" title="Periksa ulang semua status">⟳ Periksa Ulang</a>
    </div>
    <?php if ($allPassed): ?>
      <div class="notice notice-success">Semua langkah setup telah selesai. Plugin siap digunakan.</div>
    <?php else: ?>
      <div class="notice notice-warning">Beberapa langkah setup belum selesai. Jalankan perintah di bawah.</div>
    <?php endif; ?>
    <div class="checks-list">
      <?php foreach ($checks as $i => $c): ?>
      <div class="check-item <?= $c['passed'] ? 'check-pass' : 'check-fail' ?>" id="check-<?= $i ?>">
        <div class="check-icon"><?= $c['passed'] ? '&#10003;' : '&#10007;' ?></div>
        <div class="check-body">
          <div class="check-label"><?= h($c['label']) ?></div>
          <?php if ($c['command']): ?>
            <div class="check-command">
              <code><?= h($c['command']) ?></code>
              <button type="button" class="btn-copy btn btn-xs btn-ghost" data-cmd="<?= h($c['command']) ?>" title="Salin perintah">&#128203;</button>
            </div>
          <?php endif; ?>
          <?php if ($c['doc']): ?>
            <div class="check-doc"><?= h($c['doc']) ?></div>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <?php $storeInfo = $manifest['store'] ?? null; if ($storeInfo): ?>
  <?php
    require_once __DIR__ . '/../../../app/controllers/PluginStoreController.php';
    $availableUpdates = PluginStoreController::getCachedUpdates();
    $hasUpdate = isset($availableUpdates[$pluginName]);
  ?>
  <div class="detail-card">
    <h3 class="card-title">Informasi Store</h3>
    <table class="info-table">
      <tr><th>Store URL</th><td><a href="<?= h($storeInfo['url'] ?? '') ?>" target="_blank" rel="noopener"><?= h(rtrim($storeInfo['url'] ?? '', '/')) ?></a></td></tr>
      <tr><th>Versi Store</th><td>
        <?php if ($hasUpdate): ?>
          v<?= h($availableUpdates[$pluginName]['new_version']) ?> <span class="badge badge-update">Update tersedia</span>
        <?php else: ?>
          <span style="color:var(--adam-muted)">v<?= h($version) ?> (terbaru)</span>
        <?php endif; ?>
      </td></tr>
      <?php if ($hasUpdate && !empty($availableUpdates[$pluginName]['changelog'])): ?>
      <tr><th>Changelog</th><td style="white-space:pre-wrap;font-size:.8rem;line-height:1.5"><?= h($availableUpdates[$pluginName]['changelog']) ?></td></tr>
      <?php endif; ?>
    </table>
  </div>
  <?php endif; ?>
</div>

<div class="form-actions">
  <a href="<?= h($listUrl) ?>" class="btn btn-outline">&larr; Kembali</a>
</div>

<style>
.pg-title { font-size:1.4rem; font-weight:700; margin:0 0 .25rem; color:var(--adam-text); }
.pg-subtitle { color:var(--adam-muted); font-size:.9rem; margin:0 0 1.5rem; }
.detail-grid { display:flex; flex-direction:column; gap:1.5rem; max-width:720px; }
.detail-card { background:var(--adam-surface-4); border:1px solid var(--adam-border); border-radius:10px; padding:1.25rem; }
.card-title { font-size:1rem; font-weight:600; margin:0 0 1rem; color:var(--adam-text); }
.info-table { width:100%; border-collapse:collapse; }
.info-table th, .info-table td { padding:.4rem .5rem; text-align:left; border-bottom:1px solid var(--adam-border); font-size:.875rem; }
.info-table th { width:100px; color:var(--adam-muted); font-weight:500; }
.info-table td { color:var(--adam-text); }
.info-table a { color:var(--adam-primary); }
.notice { padding:.6rem .9rem; border-radius:6px; font-size:.85rem; margin-bottom:1rem; }
.notice-success { background:#d1fae5; color:#065f46; border:1px solid #a7f3d0; }
.notice-warning { background:#fef3c7; color:#92400e; border:1px solid #fde68a; }
.checks-list { display:flex; flex-direction:column; gap:.6rem; }
.check-item { display:flex; gap:.6rem; padding:.6rem .75rem; border-radius:6px; border:1px solid var(--adam-border); }
.check-pass { background:var(--adam-surface-4); border-color:#a7f3d0; }
.check-fail { background:var(--adam-surface-4); border-color:#fecaca; }
.check-icon { font-size:1.1rem; line-height:1.4; flex-shrink:0; width:1.2rem; text-align:center; }
.check-pass .check-icon { color:#059669; }
.check-fail .check-icon { color:#dc2626; }
.check-body { flex:1; min-width:0; }
.check-label { font-size:.875rem; font-weight:500; color:var(--adam-text); margin-bottom:.25rem; }
.check-command { display:flex; align-items:center; gap:.35rem; background:var(--adam-surface-3); border:1px solid var(--adam-border); border-radius:4px; padding:.3rem .5rem; font-size:.8rem; margin-top:.25rem; }
.check-command code { flex:1; min-width:0; overflow-x:auto; color:var(--adam-text-2); white-space:nowrap; }
.check-doc { font-size:.78rem; color:var(--adam-muted); margin-top:.25rem; }
.btn-copy { background:transparent; border:1px solid var(--adam-border-2); cursor:pointer; padding:.15rem .35rem; border-radius:3px; font-size:.75rem; line-height:1; color:var(--adam-muted); }
.btn-copy:hover { background:var(--adam-surface-3); color:var(--adam-text); }
.btn-copy.copied { background:#d1fae5; color:#065f46; border-color:#a7f3d0; }
.btn { display:inline-flex; align-items:center; gap:.35rem; padding:.4rem .75rem; font-size:.8rem; font-weight:500; border-radius:6px; cursor:pointer; border:1px solid transparent; font-family:inherit; line-height:1; text-decoration:none; }
.btn-sm { padding:.3rem .6rem; font-size:.75rem; }
.btn-xs { padding:.2rem .45rem; font-size:.7rem; }
.btn-outline { background:transparent; color:var(--adam-muted); border-color:var(--adam-border-2); }
.btn-outline:hover { background:var(--adam-surface-3); color:var(--adam-text); }
.btn-ghost { background:transparent; border-color:transparent; }
.form-actions { display:flex; gap:.5rem; margin-top:1.25rem; }
.badge { display:inline-block; padding:.15rem .5rem; font-size:.75rem; font-weight:600; border-radius:999px; }
.badge-success { background:#d1fae5; color:#065f46; }
.badge-update { background:#fef3c7; color:#92400e; }
.badge-muted { background:var(--adam-surface-3); color:var(--adam-muted); }
</style>

<script>
(function(){
  function copyText(text, btn) {
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.style.position = 'fixed';
    ta.style.left = '-9999px';
    ta.style.top = '0';
    document.body.appendChild(ta);
    ta.select();
    ta.setSelectionRange(0, ta.value.length);
    var ok = false;
    try { ok = document.execCommand('copy'); } catch(e) {}
    document.body.removeChild(ta);
    if (ok) {
      btn.textContent = '\u2713';
      btn.classList.add('copied');
      setTimeout(function() {
        btn.textContent = '\uD83D\uDCCB';
        btn.classList.remove('copied');
      }, 2000);
    }
  }

  document.querySelectorAll('.btn-copy').forEach(function(btn) {
    btn.addEventListener('click', function() {
      var cmd = this.getAttribute('data-cmd');
      if (!cmd) return;
      // Try clipboard API first (async)
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(cmd).then(function() {
          btn.textContent = '\u2713';
          btn.classList.add('copied');
          setTimeout(function() {
            btn.textContent = '\uD83D\uDCCB';
            btn.classList.remove('copied');
          }, 2000);
        }).catch(function() {
          copyText(cmd, btn);
        });
      } else {
        copyText(cmd, btn);
      }
    });
  });
})();
</script>
