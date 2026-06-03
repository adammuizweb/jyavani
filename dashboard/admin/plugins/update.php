<?php
declare(strict_types=1);
// Plugin Updater — Check for plugin updates from the Plugin Store
require_once DASH_PATH . '/admin/_deny.php';
if (!defined('DASHBOARD_CONTEXT') && !defined('ADAM_THEME')) adiwira_admin_404();
require_once DASH_PATH . '/admin/_guard.php';
require_once DASH_PATH . '/admin/_notify.php';

[$uid, $role] = adiwira_require_role($pdo, ['admin'], false);

$base = ADMIN_BASE_PATH;
$selfUrl = $base . '/?page=admin/plugins/update';
$listUrl = $base . '/?page=admin/plugins/index';

$defaultStoreUrl = 'https://jyavani.com/plugin-store/';

// --- Helpers ---
if (!function_exists('_pup_rmdir')) {
    function _pup_rmdir(string $dir): void {
        if (!is_dir($dir)) return;
        $it = new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS);
        foreach (new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $path = $f->getPathname();
            if ($f->isLink() || !$f->isDir()) { @unlink($path); }
            else { @rmdir($path); }
        }
        @rmdir($dir);
    }
}

if (!function_exists('_pup_copydir')) {
    function _pup_copydir(string $src, string $dst): void {
        @mkdir($dst, 0755, true);
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($src, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $f) {
            $rel = $it->getSubPathname();
            if ($f->isDir()) {
                @mkdir($dst . '/' . $rel, 0755);
            } else {
                @copy($f->getPathname(), $dst . '/' . $rel);
            }
        }
    }
}

// --- Handle actions ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = (string)($_POST['csrf_token'] ?? '');
    if (!adiwira_csrf_validate($csrf)) {
        adiwira_redirect_with_flash($selfUrl, 'error', 'CSRF token tidak valid.');
    }

    $action = (string)($_POST['action'] ?? '');

    // --- Check store for updates ---
    if ($action === 'check_updates') {
        $storeUrl = trim((string)($_POST['store_url'] ?? ''));
        if ($storeUrl === '') {
            adiwira_redirect_with_flash($selfUrl, 'error', 'URL Plugin Store tidak boleh kosong.');
        }

        $ctx = stream_context_create([
            'http' => [
                'timeout' => 15,
                'user_agent' => 'JyavaniCMS-PluginUpdater/2.0',
            ],
        ]);

        $remoteJson = @file_get_contents($storeUrl, false, $ctx);
        if ($remoteJson === false) {
            adiwira_redirect_with_flash($selfUrl, 'error', 'Gagal mengambil data dari Plugin Store: ' . htmlspecialchars($storeUrl));
        }

        $storeData = json_decode($remoteJson, true);
        if (!is_array($storeData) || !isset($storeData['plugins'])) {
            adiwira_redirect_with_flash($selfUrl, 'error', 'Format response Plugin Store tidak valid.');
        }

        ensure_session_started(true);
        $_SESSION['plugin_store_data'] = $storeData;
        $_SESSION['plugin_store_url'] = rtrim($storeUrl, '/') . '/';

        adiwira_redirect_with_flash($selfUrl, 'success', 'Data Plugin Store berhasil dimuat. ' . count($storeData['plugins']) . ' plugin tersedia.');
    }

    // --- Update a single plugin ---
    if ($action === 'update_plugin') {
        ensure_session_started(true);
        $storeData = $_SESSION['plugin_store_data'] ?? null;
        if (!$storeData) {
            adiwira_redirect_with_flash($selfUrl, 'error', 'Tidak ada data store. Lakukan "Check for Updates" dulu.');
        }

        $pluginName = (string)($_POST['plugin_name'] ?? '');
        if ($pluginName === '' || !preg_match('/^[a-zA-Z0-9_-]+$/', $pluginName)) {
            adiwira_redirect_with_flash($selfUrl, 'error', 'Nama plugin tidak valid.');
        }

        // Find plugin in store data
        $storePlugin = null;
        foreach ($storeData['plugins'] as $p) {
            if ($p['name'] === $pluginName) {
                $storePlugin = $p;
                break;
            }
        }
        if (!$storePlugin) {
            adiwira_redirect_with_flash($selfUrl, 'error', 'Plugin "' . htmlspecialchars($pluginName) . '" tidak ditemukan di store.');
        }

        $downloadUrl = $storePlugin['download_url'] ?? '';
        if ($downloadUrl === '') {
            adiwira_redirect_with_flash($selfUrl, 'error', 'Download URL tidak tersedia untuk plugin ini.');
        }

        // Check local existence
        $pluginDir = PLUGIN_PATH . '/' . $pluginName;
        $isActive = plugin_is_active($pluginName);

        // Download zip
        $tmpZip = sys_get_temp_dir() . '/plugin-update-' . bin2hex(random_bytes(8)) . '.zip';
        $ctx = stream_context_create([
            'http' => [
                'timeout' => 120,
                'user_agent' => 'JyavaniCMS-PluginUpdater/2.0',
            ],
        ]);

        $zipData = @file_get_contents($downloadUrl, false, $ctx);
        if ($zipData === false) {
            @unlink($tmpZip);
            adiwira_redirect_with_flash($selfUrl, 'error', 'Gagal download package update dari ' . htmlspecialchars($downloadUrl));
        }
        file_put_contents($tmpZip, $zipData);

        // Backup existing plugin
        $backupDir = dirname(PLUGIN_PATH) . '/cfg/var/backup-' . date('Ymd-His') . '/plugins';
        if (is_dir($pluginDir)) {
            _pup_copydir($pluginDir, $backupDir . '/' . $pluginName);
        }

        // Remove existing plugin directory
        if (is_dir($pluginDir)) {
            _pup_rmdir($pluginDir);
        }

        // Extract new plugin
        $zip = new ZipArchive();
        $open = $zip->open($tmpZip);
        if ($open !== true) {
            // Restore backup
            if (is_dir($backupDir . '/' . $pluginName)) {
                _pup_copydir($backupDir . '/' . $pluginName, $pluginDir);
                _pup_rmdir($backupDir . '/' . $pluginName);
            }
            @unlink($tmpZip);
            adiwira_redirect_with_flash($selfUrl, 'error', 'Gagal membuka file ZIP.');
        }

        // Extract to temp, detect + strip wrapping directory
        $tmpExtract = dirname($pluginDir) . '/.pup-' . bin2hex(random_bytes(8));
        @mkdir($tmpExtract, 0755, true);
        $zip->extractTo($tmpExtract);
        $zip->close();

        // Detect if all files share a common root dir (wrapping directory)
        $entries = scandir($tmpExtract);
        $entries = array_values(array_filter($entries, fn($e) => $e !== '.' && $e !== '..'));
        $wrappingDir = (count($entries) === 1 && is_dir($tmpExtract . '/' . $entries[0])) ? $entries[0] : null;

        if ($wrappingDir) {
            // Strip wrapping dir: rename inner contents to plugin dir
            $inner = $tmpExtract . '/' . $wrappingDir;
            $innerEntries = scandir($inner);
            $innerEntries = array_values(array_filter($innerEntries, fn($e) => $e !== '.' && $e !== '..'));

            // Create plugin dir if not exists
            if (!is_dir($pluginDir)) @mkdir($pluginDir, 0755, true);

            foreach ($innerEntries as $entry) {
                $src = $inner . '/' . $entry;
                $dst = $pluginDir . '/' . $entry;
                if (is_dir($src)) {
                    _pup_copydir($src, $dst);
                } else {
                    @copy($src, $dst);
                }
            }
        } else {
            // No wrapping dir — move all entries directly
            if (!is_dir($pluginDir)) @mkdir($pluginDir, 0755, true);
            foreach ($entries as $entry) {
                $src = $tmpExtract . '/' . $entry;
                $dst = $pluginDir . '/' . $entry;
                if (is_dir($src)) {
                    rename($src, $dst);
                } else {
                    @copy($src, $dst);
                }
            }
        }
        _pup_rmdir($tmpExtract);

        // Set permissions
        if (is_dir($pluginDir)) {
            $chmodIt = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($pluginDir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($chmodIt as $item) {
                if ($item->isDir()) {
                    @chmod($item->getPathname(), 0775);
                } else {
                    $ext = pathinfo($item->getFilename(), PATHINFO_EXTENSION);
                    @chmod($item->getPathname(), ($ext === 'sh') ? 0775 : 0664);
                }
            }
            @shell_exec('chgrp -R www-data ' . escapeshellarg($pluginDir) . ' 2>&1');
        }

        // Run install.sh if present
        if (is_file($pluginDir . '/install.sh') && is_executable($pluginDir . '/install.sh')) {
            @shell_exec('cd ' . escapeshellarg($pluginDir) . ' && bash install.sh 2>&1');
        }

        // Re-enable if it was active
        if ($isActive && function_exists('plugin_enable')) {
            plugin_enable($pluginName);
        }

        @unlink($tmpZip);

        // Copy static assets if manifest has static.copy
        $manifest = [];
        if (is_file($pluginDir . '/plugin.json')) {
            $m = json_decode(file_get_contents($pluginDir . '/plugin.json'), true);
            if (is_array($m)) $manifest = $m;
        }
        $staticCopy = $manifest['static']['copy'] ?? [];
        if (!empty($staticCopy) && is_array($staticCopy)) {
            $publicPath = dirname(PLUGIN_PATH) . '/public';
            foreach ($staticCopy as $entry) {
                $from = $entry['from'] ?? '';
                $to = $entry['to'] ?? '';
                if ($from === '' || $to === '') continue;
                $source = $pluginDir . '/' . ltrim($from, '/');
                $dest = $publicPath . '/' . ltrim($to, '/');
                if (is_file($source)) {
                    @mkdir(dirname($dest), 0755, true);
                    @copy($source, $dest);
                }
            }
        }

        adiwira_redirect_with_flash($listUrl, 'success', 'Plugin "' . htmlspecialchars($storePlugin['title'] ?? $pluginName) . '" berhasil diperbarui ke v' . htmlspecialchars($storePlugin['version'] ?? '?') . '.');
    }

    // --- Clear store data ---
    if ($action === 'clear_store_data') {
        ensure_session_started(true);
        unset($_SESSION['plugin_store_data'], $_SESSION['plugin_store_url']);
        adiwira_redirect_with_flash($selfUrl, 'info', 'Data store dibersihkan.');
    }

    adiwira_redirect_with_flash($selfUrl, 'error', 'Aksi tidak dikenal.');
}

// --- Load session data ---
ensure_session_started(false);
$storeData = $_SESSION['plugin_store_data'] ?? null;
$storeUrl = $_SESSION['plugin_store_url'] ?? '';

// --- Compare local plugins with store ---
$comparison = [];
$needsUpdateCount = 0;
if ($storeData && !empty($storeData['plugins'])) {
    $localPlugins = plugins_all();
    foreach ($storeData['plugins'] as $sp) {
        $name = $sp['name'] ?? '';
        if ($name === '') continue;
        $storeVer = $sp['version'] ?? '0.0.0';
        $local = $localPlugins[$name] ?? null;
        $localVer = $local ? ($local['version'] ?? '0.0.0') : null;
        $isNewer = $localVer ? version_compare($storeVer, $localVer, '>') : true;
        if ($isNewer && $localVer !== null) $needsUpdateCount++;
        $comparison[] = [
            'name' => $name,
            'title' => $sp['title'] ?? $name,
            'store_version' => $storeVer,
            'local_version' => $localVer,
            'installed' => $local !== null,
            'needs_update' => $isNewer,
            'download_url' => $sp['download_url'] ?? '',
        ];
    }
}
?>
<h2 class="pg-title">Plugin Updater</h2>
<p class="pg-subtitle">Periksa pembaruan plugin dari Plugin Store.</p>

<div class="up-grid">
    <div class="up-card">
        <div class="up-card-header">Plugin Store</div>
        <form method="post" class="up-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="action" value="check_updates">

            <label class="up-label">Store URL</label>
            <input type="url" name="store_url" class="up-input"
                   value="<?= htmlspecialchars($storeUrl ?: $defaultStoreUrl) ?>"
                   placeholder="https://example.com/plugin-store/">

            <div class="up-hint">URL ke Plugin Store JSON endpoint. Data akan dibandingkan dengan plugin yang terpasang.</div>

            <div class="up-flex">
                <button type="submit" class="btn btn-primary">Check for Updates</button>
            </div>
        </form>
        <?php if ($storeData): ?>
        <form method="post" style="margin-top:.5rem">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="action" value="clear_store_data">
            <button type="submit" class="btn btn-outline">Clear Data</button>
        </form>
        <?php endif; ?>
    </div>

    <?php if ($storeData): ?>
    <div class="up-card">
        <div class="up-card-header">Store Info</div>
        <table class="up-table">
            <tr><td>Store</td><td><strong><?= htmlspecialchars($storeData['store_name'] ?? 'Plugin Store') ?></strong></td></tr>
            <tr><td>Plugins</td><td><?= count($storeData['plugins'] ?? []) ?> tersedia</td></tr>
            <tr><td>Update</td><td><?= $needsUpdateCount ?> plugin membutuhkan update</td></tr>
            <tr><td>Terpasang</td><td><?= count(array_filter($comparison, fn($c) => $c['installed'])) ?> plugin local</td></tr>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php if (!empty($comparison)): ?>
<div class="up-card" style="margin-top:1.25rem">
    <div class="up-card-header">Perbandingan Plugin</div>
    <div class="table-wrap">
    <table class="data-table">
        <thead>
            <tr>
                <th>Plugin</th>
                <th>Local</th>
                <th>Store</th>
                <th>Status</th>
                <th>Aksi</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($comparison as $c):
                $class = $c['needs_update'] ? ($c['installed'] ? 'row-update' : 'row-new') : 'row-ok';
            ?>
            <tr class="<?= $class ?>">
                <td><strong><?= htmlspecialchars($c['title']) ?></strong></td>
                <td><?= $c['local_version'] ? 'v' . htmlspecialchars($c['local_version']) : '<span class="text-muted">—</span>' ?></td>
                <td>v<?= htmlspecialchars($c['store_version']) ?></td>
                <td>
                    <?php if (!$c['installed']): ?>
                        <span class="badge badge-info">Baru</span>
                    <?php elseif ($c['needs_update']): ?>
                        <span class="badge badge-warning">Update</span>
                    <?php else: ?>
                        <span class="badge badge-success">Terbaru</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($c['installed'] && $c['needs_update']): ?>
                    <form method="post" onsubmit="return confirm('Update plugin &quot;<?= htmlspecialchars($c['title']) ?>&quot; ke v<?= htmlspecialchars($c['store_version']) ?>?')">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                        <input type="hidden" name="action" value="update_plugin">
                        <input type="hidden" name="plugin_name" value="<?= htmlspecialchars($c['name']) ?>">
                        <button type="submit" class="btn btn-sm btn-primary">Update</button>
                    </form>
                    <?php elseif (!$c['installed']): ?>
                        <span class="text-muted" style="font-size:.8rem">Upload manual</span>
                    <?php else: ?>
                        <span class="text-muted" style="font-size:.8rem">✓</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php elseif ($storeData && empty($comparison)): ?>
<div class="up-card" style="margin-top:1.25rem">
    <div class="up-card-header">Hasil</div>
    <p class="up-hint">Tidak ada plugin yang cocok antara store dan instalasi lokal. Mungkin plugin yang terpasang tidak tersedia di store, atau sebaliknya.</p>
</div>
<?php endif; ?>

<style>
.pg-title { font-size:1.4rem; font-weight:700; margin:0 0 .25rem; color:var(--adam-text); }
.pg-subtitle { color:var(--adam-muted); font-size:.9rem; margin:0 0 1.5rem; }
.up-grid { display:grid; grid-template-columns:1fr 1fr; gap:1rem; }
@media (max-width:768px){ .up-grid { grid-template-columns:1fr; } }
.up-card { border:1px solid var(--adam-border); border-radius:var(--adam-radius); padding:1rem; background:var(--adam-card); }
.up-card-header { font-size:.9rem; font-weight:600; margin-bottom:.75rem; text-transform:uppercase; letter-spacing:.03em; color:var(--adam-muted); }
.up-table { width:100%; border-collapse:collapse; font-size:.875rem; }
.up-table td { padding:.35rem .5rem; border-bottom:1px solid var(--adam-surface-3); color:var(--adam-text); }
.up-table td:first-child { color:var(--adam-muted); width:35%; }
.up-form { display:flex; flex-direction:column; gap:.6rem; }
.up-label { font-size:.8rem; font-weight:500; color:var(--adam-text-2); }
.up-input { padding:.5rem .65rem; border:1px solid var(--adam-border-2); border-radius:6px; font-size:.85rem; font-family:inherit; background:var(--adam-card); color:var(--adam-text); }
.up-input:focus { outline:none; border-color:var(--adam-primary); box-shadow:0 0 0 2px var(--adam-primary-soft); }
.up-hint { font-size:.8rem; color:var(--adam-muted-2); margin:.25rem 0; }
.up-flex { display:flex; gap:.5rem; align-items:center; flex-wrap:wrap; }
.text-muted { color:var(--adam-muted); }
.table-wrap { overflow-x:auto; }
.data-table { width:100%; border-collapse:collapse; font-size:.875rem; }
.data-table th,
.data-table td { text-align:left; padding:.5rem .65rem; border-bottom:1px solid var(--adam-border); vertical-align:middle; color:var(--adam-text); }
.data-table th { font-size:.78rem; font-weight:600; text-transform:uppercase; letter-spacing:.04em; color:var(--adam-muted); background:var(--adam-surface-3); }
.row-ok td { background:var(--adam-card); }
.row-update td { background:#fffbeb; }
.row-new td { background:#eff6ff; }
html.theme-dark .row-update td { background:#1a1500; }
html.theme-dark .row-new td { background:#0a1a2e; }
.badge { display:inline-block; padding:.15rem .5rem; font-size:.72rem; font-weight:600; border-radius:999px; white-space:nowrap; }
.badge-success { background:#d1fae5; color:#065f46; }
.badge-warning { background:#fef3c7; color:#92400e; }
.badge-info { background:#dbeafe; color:#1e40af; }
.btn { display:inline-flex; align-items:center; gap:.35rem; padding:.4rem .75rem; font-size:.8rem; font-weight:500; border-radius:6px; cursor:pointer; border:1px solid transparent; font-family:inherit; line-height:1; text-decoration:none; }
.btn-sm { padding:.3rem .6rem; font-size:.75rem; }
.btn-primary { background:var(--adam-primary); color:#fff; border-color:var(--adam-primary); }
.btn-primary:hover { background:var(--adam-primary-600); }
.btn-outline { background:transparent; color:var(--adam-muted); border-color:var(--adam-border-2); }
.btn-outline:hover { background:var(--adam-surface-3); color:var(--adam-text); }
.btn-xs { padding:.2rem .45rem; font-size:.7rem; }
</style>
