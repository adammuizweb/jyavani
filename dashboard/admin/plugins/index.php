<?php
declare(strict_types=1);
// Plugin Manager — Kelola plugin (aktif/nonaktif)
// Core admin page di DASH_PATH/admin/plugins/index.php

require_once DASH_PATH . '/admin/_deny.php';
if (!defined('DASHBOARD_CONTEXT') && !defined('ADAM_THEME')) adiwira_admin_404();
require_once DASH_PATH . '/admin/_guard.php';
require_once DASH_PATH . '/admin/_notify.php';

[$uid, $role] = adiwira_require_role($pdo, ['admin'], false);

$base = ADMIN_BASE_PATH;
$selfUrl = $base . '/?page=admin/plugins/index';

// --- Handle toggle action ---
$action = (string)($_POST['action'] ?? '');
$pluginName = (string)($_POST['plugin'] ?? '');
if ($action === 'toggle' && $pluginName !== '') {
    $csrf = (string)($_POST['csrf_token'] ?? '');
    if (!csrf_check($csrf)) {
        adiwira_redirect_with_flash($selfUrl, 'error', 'CSRF token tidak valid.');
    }

    $manifest = plugin_manifest($pluginName);
    if (!$manifest) {
        adiwira_redirect_with_flash($selfUrl, 'error', 'Plugin "' . h($pluginName) . '" tidak ditemukan.');
    }

    if (plugin_is_active($pluginName)) {
        if (plugin_disable($pluginName)) {
            adiwira_redirect_with_flash($selfUrl, 'success', 'Plugin "' . h($manifest['title'] ?? $pluginName) . '" dinonaktifkan.');
        }
        adiwira_redirect_with_flash($selfUrl, 'error', 'Gagal menonaktifkan plugin.');
    } else {
        if (plugin_enable($pluginName)) {
            adiwira_redirect_with_flash($selfUrl, 'success', 'Plugin "' . h($manifest['title'] ?? $pluginName) . '" diaktifkan.');
        }
        adiwira_redirect_with_flash($selfUrl, 'error', 'Gagal mengaktifkan plugin.');
    }
}

// --- Handle delete action ---
if ($action === 'delete' && $pluginName !== '') {
    $csrf = (string)($_POST['csrf_token'] ?? '');
    if (!csrf_check($csrf)) {
        adiwira_redirect_with_flash($selfUrl, 'error', 'CSRF token tidak valid.');
    }

    $manifest = plugin_manifest($pluginName);
    if (!$manifest) {
        adiwira_redirect_with_flash($selfUrl, 'error', 'Plugin "' . h($pluginName) . '" tidak ditemukan.');
    }

    if (plugin_delete($pluginName)) {
        adiwira_redirect_with_flash($selfUrl, 'success', 'Plugin "' . h($manifest['title'] ?? $pluginName) . '" berhasil dihapus.');
    }
    adiwira_redirect_with_flash($selfUrl, 'error', 'Gagal menghapus plugin.');
}

// --- Collect plugin data ---
$allPlugins = plugins_all();
$activePlugins = plugins_active();
$pageToasts = function_exists('adiwira_collect_query_toasts') ? adiwira_collect_query_toasts() : [];
?>
<h2 class="pg-title">Plugin</h2>
<p class="pg-subtitle">Kelola plugin yang terpasang. Nonaktifkan plugin tanpa menghapus filenya.</p>

<div style="margin-bottom:1rem">
  <a href="<?= h($base) ?>/?page=admin/plugins/upload" class="btn btn-primary btn-sm">+ Upload Plugin</a>
</div>

<?php if (empty($allPlugins)): ?>
<div class="empty-state">
  <p>Belum ada plugin terpasang. Tambahkan folder plugin dengan <code>plugin.json</code> ke folder <code>plugins/</code>.</p>
</div>
<?php else: ?>
<div class="table-wrap">
<table class="data-table" data-sortable>
  <thead>
    <tr>
      <th>Plugin</th>
      <th>Versi</th>
      <th>Status</th>
      <th>Aksi</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($allPlugins as $name => $p):
      $title = $p['title'] ?? $name;
      $desc = $p['description'] ?? '';
      $version = $p['version'] ?? '—';
      $author = $p['author'] ?? '';
      $isActive = isset($activePlugins[$name]);
    ?>
    <tr>
      <td>
        <strong><?= h($title) ?></strong>
        <?php if ($desc): ?><br><span class="text-muted" style="font-size:0.85rem"><?= h($desc) ?></span><?php endif; ?>
        <?php if ($author): ?><br><span class="text-muted" style="font-size:0.8rem">oleh <?= h($author) ?></span><?php endif; ?>
      </td>
      <td><?= h($version) ?></td>
      <td>
        <?php if ($isActive): ?>
          <span class="badge badge-success">Aktif</span>
        <?php else: ?>
          <span class="badge badge-muted">Nonaktif</span>
        <?php endif; ?>
      </td>
      <td>
        <div style="display:flex;gap:.35rem;flex-wrap:wrap">
        <form method="post" style="display:inline">
          <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="action" value="toggle">
          <input type="hidden" name="plugin" value="<?= h($name) ?>">
          <?php if ($isActive): ?>
            <button type="submit" class="btn btn-sm btn-outline" onclick="return confirm('Nonaktifkan plugin &quot;<?= h($title) ?>&quot;?')">Nonaktifkan</button>
          <?php else: ?>
            <button type="submit" class="btn btn-sm btn-primary">Aktifkan</button>
          <?php endif; ?>
        </form>
        <form method="post" style="display:inline" onsubmit="return confirm('Hapus plugin &quot;<?= h($title) ?>&quot; beserta semua filenya?')">
          <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="plugin" value="<?= h($name) ?>">
          <button type="submit" class="btn btn-sm btn-danger">Hapus</button>
        </form>
        </div>
      </td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>

<style>
.pg-title { font-size:1.4rem; font-weight:700; margin:0 0 .25rem; color:var(--adam-text); }
.pg-subtitle { color:var(--adam-muted); font-size:.9rem; margin:0 0 1.5rem; }
.text-muted { color:var(--adam-muted); }
.empty-state { padding:2rem; text-align:center; color:var(--adam-muted); }
.table-wrap { overflow-x:auto; }
.data-table { width:100%; border-collapse:collapse; }
.data-table th,
.data-table td { text-align:left; padding:.6rem .75rem; border-bottom:1px solid var(--adam-border); vertical-align:middle; color:var(--adam-text); }
.data-table th { font-size:.78rem; font-weight:600; text-transform:uppercase; letter-spacing:.04em; color:var(--adam-muted); background:var(--adam-surface-3); }
.badge { display:inline-block; padding:.15rem .5rem; font-size:.75rem; font-weight:600; border-radius:999px; }
.badge-success { background:#d1fae5; color:#065f46; }
.badge-muted { background:var(--adam-surface-3); color:var(--adam-muted); }
.btn { display:inline-flex; align-items:center; gap:.35rem; padding:.4rem .75rem; font-size:.8rem; font-weight:500; border-radius:6px; cursor:pointer; border:1px solid transparent; font-family:inherit; line-height:1; text-decoration:none; }
.btn-sm { padding:.3rem .6rem; font-size:.75rem; }
.btn-primary { background:var(--adam-primary); color:#fff; border-color:var(--adam-primary); }
.btn-primary:hover { background:var(--adam-primary-600); }
.btn-outline { background:transparent; color:var(--adam-muted); border-color:var(--adam-border-2); }
.btn-outline:hover { background:var(--adam-surface-3); color:var(--adam-text); }
.btn-danger { background:var(--adam-danger); color:#fff; border-color:var(--adam-danger); }
.btn-danger:hover { background:var(--adam-danger-600); }
</style>
</parameter>
