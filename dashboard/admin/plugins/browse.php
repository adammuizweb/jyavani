<?php
declare(strict_types=1);
// Plugin Store Browser — Cari & install plugin dari jyavani.com
require_once DASH_PATH . '/admin/_deny.php';
if (!defined('DASHBOARD_CONTEXT') && !defined('ADAM_THEME')) adiwira_admin_404();
require_once DASH_PATH . '/admin/_guard.php';
require_once DASH_PATH . '/admin/_notify.php';

[$uid, $role] = adiwira_require_role($pdo, ['admin'], false);

if (!function_exists('_rmdir_recursive')) {
    function _rmdir_recursive(string $dir): void {
        if (!is_dir($dir)) return;
        $it = new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS);
        foreach (new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            $path = $file->getPathname();
            if ($file->isLink() || !$file->isDir()) {
                @unlink($path);
            } else {
                @rmdir($path);
            }
        }
        @rmdir($dir);
    }
}

$base = ADMIN_BASE_PATH;
$selfUrl = $base . '/?page=admin/plugins/browse';
$listUrl = $base . '/?page=admin/plugins/index';

$apiBase = 'https://jyavani.com/plugin-store';
$cacheFile = (defined('BACKEND_PATH') ? BACKEND_PATH : __DIR__ . '/../../cfg') . '/var/store-cache.json';

$error = '';

// --- Cache helpers ---
function store_cache_read(string $file): ?array {
    if (!is_file($file)) return null;
    if (time() - filemtime($file) > 3600) return null;
    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : null;
}

function store_cache_write(string $file, array $data): void {
    $dir = dirname($file);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    file_put_contents($file, json_encode($data, JSON_UNESCAPED_SLASHES), LOCK_EX);
}

// --- Fetch from API with cache ---
$plugins = [];
$storeName = 'Jyavani Plugin Store';

$cached = store_cache_read($cacheFile);
if ($cached !== null) {
    $plugins = $cached['plugins'] ?? [];
    $storeName = $cached['store_name'] ?? $storeName;
} else {
    $ctx = stream_context_create(['http' => ['timeout' => 10, 'user_agent' => 'JyavaniCMS/2.0']]);
    $json = @file_get_contents($apiBase . '/', false, $ctx);
    if ($json === false) {
        $error = 'Gagal terhubung ke jyavani.com. Coba lagi nanti.';
    } else {
        $data = json_decode($json, true);
        if (is_array($data) && isset($data['plugins'])) {
            $plugins = $data['plugins'];
            $storeName = $data['store_name'] ?? $storeName;
            store_cache_write($cacheFile, ['store_name' => $storeName, 'plugins' => $plugins]);
        } else {
            $error = 'Respon dari jyavani.com tidak valid.';
        }
    }
}

// --- Handle install ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'install') {
    $csrf = (string)($_POST['csrf_token'] ?? '');
    if (!csrf_check($csrf)) {
        adiwira_redirect_with_flash($selfUrl, 'error', __('Invalid CSRF token.'));
    }

    $pluginName = (string)($_POST['plugin'] ?? '');
    if (!preg_match('/^[a-zA-Z0-9_-]+$/', $pluginName)) {
        adiwira_redirect_with_flash($selfUrl, 'error', __('Nama plugin tidak valid.'));
    }

    // Cari data plugin dari API list
    $pluginData = null;
    foreach ($plugins as $p) {
        if ($p['name'] === $pluginName) { $pluginData = $p; break; }
    }
    if (!$pluginData) {
        adiwira_redirect_with_flash($selfUrl, 'error', 'Plugin "' . h($pluginName) . '" tidak ditemukan di store.');
    }

    $pluginDir = PLUGIN_PATH . '/' . $pluginName;
    if (is_dir($pluginDir)) {
        adiwira_redirect_with_flash($selfUrl, 'error', 'Plugin "' . h($pluginName) . '" sudah terpasang.');
    }

    // Download zip
    $downloadUrl = $pluginData['download_url'] ?? ($apiBase . '/download/' . $pluginName . '/');
    $dlCtx = stream_context_create(['http' => ['timeout' => 120, 'user_agent' => 'JyavaniCMS/2.0']]);
    $zipContent = @file_get_contents($downloadUrl, false, $dlCtx);
    if ($zipContent === false) {
        adiwira_redirect_with_flash($selfUrl, 'error', __('Gagal mengunduh plugin dari jyavani.com.'));
    }

    $tmpZip = tempnam(sys_get_temp_dir(), 'install-') . '.zip';
    file_put_contents($tmpZip, $zipContent);

    $zip = new ZipArchive();
    $open = $zip->open($tmpZip);
    if ($open !== true) {
        @unlink($tmpZip);
        adiwira_redirect_with_flash($selfUrl, 'error', 'File ZIP tidak valid (kode: ' . $open . ').');
    }

    // Validasi plugin.json di dalam zip
    $pluginJsonRaw = $zip->getFromName('plugin.json');
    if ($pluginJsonRaw === false) {
        $zip->close(); @unlink($tmpZip);
        adiwira_redirect_with_flash($selfUrl, 'error', __('plugin.json tidak ditemukan di dalam ZIP.'));
    }

    $manifest = json_decode($pluginJsonRaw, true);
    if (!is_array($manifest) || empty($manifest['name']) || $manifest['name'] !== $pluginName) {
        $zip->close(); @unlink($tmpZip);
        adiwira_redirect_with_flash($selfUrl, 'error', __('plugin.json tidak valid.'));
    }

    // Ekstrak ke temp
    $tmpExtract = PLUGIN_PATH . '/.extract-' . bin2hex(random_bytes(8));
    if (!mkdir($tmpExtract, 0755, true)) {
        $zip->close(); @unlink($tmpZip);
        adiwira_redirect_with_flash($selfUrl, 'error', __('Gagal membuat temporary directory.'));
    }

    $extracted = $zip->extractTo($tmpExtract);
    $zip->close();
    @unlink($tmpZip);

    if (!$extracted) {
        _rmdir_recursive($tmpExtract);
        adiwira_redirect_with_flash($selfUrl, 'error', __('Gagal mengekstrak file.'));
    }

    if (!is_file($tmpExtract . '/plugin.json')) {
        _rmdir_recursive($tmpExtract);
        adiwira_redirect_with_flash($selfUrl, 'error', __('plugin.json tidak ditemukan di root ZIP.'));
    }

    $extractedManifest = json_decode(file_get_contents($tmpExtract . '/plugin.json'), true);
    if (!is_array($extractedManifest) || ($extractedManifest['name'] ?? '') !== $pluginName) {
        _rmdir_recursive($tmpExtract);
        adiwira_redirect_with_flash($selfUrl, 'error', __('plugin.json setelah ekstrak tidak valid.'));
    }

    // Pindah ke plugins/{name}/
    if (!rename($tmpExtract, $pluginDir)) {
        _rmdir_recursive($tmpExtract);
        adiwira_redirect_with_flash($selfUrl, 'error', __('Gagal memindahkan plugin.'));
    }

    // Set permissions
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
    @chgrp($pluginDir, 'www-data');
    @shell_exec('chgrp -R www-data ' . escapeshellarg($pluginDir) . ' 2>&1');

    // Copy static files
    $staticCopy = $extractedManifest['static']['copy'] ?? [];
    if (!empty($staticCopy) && is_array($staticCopy)) {
        $publicPath = dirname(PLUGIN_PATH) . '/public';
        foreach ($staticCopy as $entry) {
            $from = $entry['from'] ?? '';
            $to = $entry['to'] ?? '';
            if ($from === '' || $to === '') continue;
            $source = $pluginDir . '/' . ltrim($from, '/');
            $dest = $publicPath . '/' . ltrim($to, '/');
            if (!is_file($source)) continue;
            $destDir = dirname($dest);
            if (!is_dir($destDir)) @mkdir($destDir, 0755, true);
            @copy($source, $dest);
        }
    }

    // Hapus cache agar daftar plugin terbaru
    plugin_enable($pluginName);

    adiwira_redirect_with_flash($listUrl, 'success', 'Plugin "' . h($manifest['title'] ?? $pluginName) . '" berhasil diinstall dari store.');
}

$installedPlugins = plugins_all();
$installedNames = array_keys($installedPlugins);

$pageToasts = function_exists('adiwira_collect_query_toasts') ? adiwira_collect_query_toasts() : [];

// Hapus cache + re-fetch dari API jika ada parameter refresh (tanpa redirect — sudah terlambat karena layout terlanjur render)
if (isset($_GET['refresh'])) {
    if (is_file($cacheFile)) @unlink($cacheFile);
    $cached = null;
    $plugins = [];
    store_cache_write($cacheFile, ['store_name' => $storeName, 'plugins' => []]);
    $ctx = stream_context_create(['http' => ['timeout' => 10, 'user_agent' => 'JyavaniCMS/2.0']]);
    $json = @file_get_contents($apiBase . '/', false, $ctx);
    if ($json !== false) {
        $data = json_decode($json, true);
        if (is_array($data) && isset($data['plugins'])) {
            $plugins = $data['plugins'];
            $storeName = $data['store_name'] ?? $storeName;
            store_cache_write($cacheFile, ['store_name' => $storeName, 'plugins' => $plugins]);
        } else {
            $error = 'Respon dari jyavani.com tidak valid.';
        }
    }
}
?>
<h2 class="pg-title">Cari Plugin</h2>
<p class="pg-subtitle">Jelajahi plugin dari <a href="https://jyavani.com/" target="_blank" rel="noopener"><?= h($storeName) ?></a> — komunitas Jyavani.</p>

<div style="margin-bottom:1rem;display:flex;gap:.5rem;flex-wrap:wrap">
  <a href="<?= h($listUrl) ?>" class="btn btn-outline btn-sm">&larr; Kembali ke Plugin Terpasang</a>
  <a href="<?= h($selfUrl) ?>&refresh=1" class="btn btn-sm btn-outline" style="border-color:var(--adam-primary);color:var(--adam-primary)">↻ Muat Ulang</a>
</div>

<?php if ($error): ?>
<div class="alert alert-error">
  <strong>Gagal memuat daftar plugin.</strong><br>
  <?= h($error) ?>
  <br><br>
  <a href="<?= h($selfUrl) ?>&refresh=1" class="btn btn-sm btn-primary">Coba Lagi</a>
</div>
<?php elseif (empty($plugins)): ?>
<div class="empty-state">
  <p>Belum ada plugin tersedia di store. Silakan cek kembali nanti.</p>
</div>
<?php else: ?>
<div class="plugin-grid">
  <?php foreach ($plugins as $p):
    $isInstalled = in_array($p['name'], $installedNames, true);
    $installedManifest = $isInstalled ? ($installedPlugins[$p['name']] ?? null) : null;
    $installedVersion = $installedManifest ? ($installedManifest['version'] ?? '') : '';
    $hasUpdate = $isInstalled && $installedVersion !== '' && version_compare($p['version'] ?? '0.0.0', $installedVersion, '>');
  ?>
  <div class="plugin-card">
    <div class="plugin-card-body">
      <div class="plugin-card-title"><?= h($p['title'] ?? $p['name']) ?></div>
      <?php if (!empty($p['description'])): ?>
      <div class="plugin-card-desc"><?= h(mb_strimwidth($p['description'], 0, 120, '…')) ?></div>
      <?php endif; ?>
      <div class="plugin-card-meta">
        <span>v<?= h($p['version'] ?? '—') ?></span>
        <?php if (!empty($p['php_required'])): ?>
        <span class="badge-php">PHP <?= h($p['php_required']) ?></span>
        <?php endif; ?>
        <?php if (!empty($p['author'])): ?>
        <span>oleh <?= h($p['author']) ?></span>
        <?php endif; ?>
      </div>
    </div>
    <div class="plugin-card-actions">
      <?php if ($isInstalled): ?>
        <?php if ($hasUpdate): ?>
        <a href="<?= h($listUrl) ?>" class="btn btn-sm btn-update">Update Tersedia</a>
        <?php else: ?>
        <span class="btn btn-sm btn-disabled" style="cursor:default;opacity:.5">✓ Terpasang</span>
        <?php endif; ?>
      <?php else: ?>
      <form method="post" style="display:inline">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="install">
        <input type="hidden" name="plugin" value="<?= h($p['name']) ?>">
        <button type="submit" class="btn btn-sm btn-primary">+ Install</button>
      </form>
      <?php endif; ?>
      <?php if (!empty($p['plugin_uri'])): ?>
      <a href="<?= h($p['plugin_uri']) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline">Detail</a>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<style>
.pg-title { font-size:1.4rem; font-weight:700; margin:0 0 .25rem; color:var(--adam-text); }
.pg-subtitle { color:var(--adam-muted); font-size:.9rem; margin:0 0 1.5rem; }
.pg-subtitle a { color:var(--adam-primary); text-decoration:none; }
.pg-subtitle a:hover { text-decoration:underline; }
.alert { padding:.75rem 1rem; border-radius:6px; font-size:.875rem; margin-bottom:1rem; }
.alert-error { background:#fef2f2; color:#991b1b; border:1px solid #fecaca; }
.empty-state { padding:2rem; text-align:center; color:var(--adam-muted); }

.plugin-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(300px,1fr)); gap:1rem; }

.plugin-card { background:var(--adam-surface); border:1px solid var(--adam-border); border-radius:10px; display:flex; flex-direction:column; overflow:hidden; transition:box-shadow .15s; }
.plugin-card:hover { box-shadow:0 2px 12px rgba(0,0,0,.08); }
.plugin-card-body { flex:1; padding:1rem 1rem .75rem; }
.plugin-card-title { font-size:1rem; font-weight:600; color:var(--adam-text); margin-bottom:.35rem; }
.plugin-card-desc { font-size:.82rem; color:var(--adam-muted); line-height:1.5; margin-bottom:.5rem; }
.plugin-card-meta { display:flex; gap:.5rem; flex-wrap:wrap; font-size:.75rem; color:var(--adam-muted-2); }
.badge-php { background:var(--adam-surface-3); padding:.1rem .4rem; border-radius:4px; font-size:.72rem; }
.plugin-card-actions { display:flex; gap:.35rem; padding:.6rem 1rem; border-top:1px solid var(--adam-border); background:var(--adam-surface-4); }

.btn { display:inline-flex; align-items:center; gap:.35rem; padding:.4rem .75rem; font-size:.8rem; font-weight:500; border-radius:6px; cursor:pointer; border:1px solid transparent; font-family:inherit; line-height:1; text-decoration:none; }
.btn-sm { padding:.3rem .6rem; font-size:.75rem; }
.btn-primary { background:var(--adam-primary); color:#fff; border-color:var(--adam-primary); }
.btn-primary:hover { background:var(--adam-primary-600); }
.btn-outline { background:transparent; color:var(--adam-muted); border-color:var(--adam-border-2); }
.btn-outline:hover { background:var(--adam-surface-3); color:var(--adam-text); }
.btn-update { background:#dbeafe; color:#1e40af; border-color:#93c5fd; }
</style>

