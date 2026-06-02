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
    if (!adiwira_csrf_validate($csrf)) {
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
      </td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>

<style>
.pg-title { font-size:1.4rem; font-weight:700; margin:0 0 .25rem; }
.pg-subtitle { color:#6b7280; font-size:.9rem; margin:0 0 1.5rem; }
.text-muted { color:#6b7280; }
.empty-state { padding:2rem; text-align:center; color:#6b7280; }
.table-wrap { overflow-x:auto; }
.data-table { width:100%; border-collapse:collapse; }
.data-table th,
.data-table td { text-align:left; padding:.6rem .75rem; border-bottom:1px solid #e5e7eb; vertical-align:middle; }
.data-table th { font-size:.78rem; font-weight:600; text-transform:uppercase; letter-spacing:.04em; color:#6b7280; background:#f9fafb; }
.badge { display:inline-block; padding:.15rem .5rem; font-size:.75rem; font-weight:600; border-radius:999px; }
.badge-success { background:#d1fae5; color:#065f46; }
.badge-muted { background:#f3f4f6; color:#6b7280; }
.btn { display:inline-flex; align-items:center; gap:.35rem; padding:.4rem .75rem; font-size:.8rem; font-weight:500; border-radius:6px; cursor:pointer; border:1px solid transparent; font-family:inherit; line-height:1; }
.btn-sm { padding:.3rem .6rem; font-size:.75rem; }
.btn-primary { background:#2563eb; color:#fff; border-color:#2563eb; }
.btn-primary:hover { background:#1d4ed8; }
.btn-outline { background:transparent; color:#6b7280; border-color:#d1d5db; }
.btn-outline:hover { background:#f3f4f6; color:#374151; }
</style>
</parameter>
