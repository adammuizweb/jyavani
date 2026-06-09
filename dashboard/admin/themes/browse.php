<?php
declare(strict_types=1);
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
            if ($file->isLink() || !$file->isDir()) { @unlink($path); } else { @rmdir($path); }
        }
        @rmdir($dir);
    }
}

$base = ADMIN_BASE_PATH;
$selfUrl = $base . '/?page=admin/themes/browse';
$listUrl = $base . '/?page=admin/themes/assign';

$apiBase = 'https://jyavani.com/theme-store';
$cacheFile = (defined('BACKEND_PATH') ? BACKEND_PATH : __DIR__ . '/../../cfg') . '/var/theme-store-cache.json';

$error = '';

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

$themes = [];
$storeName = 'Jyavani Theme Store';

$cached = store_cache_read($cacheFile);
if ($cached !== null) {
    $themes = $cached['themes'] ?? [];
    $storeName = $cached['store_name'] ?? $storeName;
} else {
    $ctx = stream_context_create(['http' => ['timeout' => 10, 'user_agent' => 'JyavaniCMS/2.0']]);
    $json = @file_get_contents($apiBase . '/', false, $ctx);
    if ($json === false) {
        $error = 'Gagal terhubung ke jyavani.com. Coba lagi nanti.';
    } else {
        $data = json_decode($json, true);
        if (is_array($data) && isset($data['themes'])) {
            $themes = $data['themes'];
            $storeName = $data['store_name'] ?? $storeName;
            store_cache_write($cacheFile, ['store_name' => $storeName, 'themes' => $themes]);
        } else {
            $error = 'Respon dari jyavani.com tidak valid.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'install') {
    $csrf = (string)($_POST['csrf_token'] ?? '');
    if (!csrf_check($csrf)) {
        adiwira_redirect_with_flash($selfUrl, 'error', __('Invalid CSRF token.'));
    }

    $themeName = (string)($_POST['theme'] ?? '');
    if (!preg_match('/^[a-zA-Z0-9_-]+$/', $themeName)) {
        adiwira_redirect_with_flash($selfUrl, 'error', __('Nama theme tidak valid.'));
    }

    $themeData = null;
    foreach ($themes as $p) {
        if ($p['name'] === $themeName) { $themeData = $p; break; }
    }
    if (!$themeData) {
        adiwira_redirect_with_flash($selfUrl, 'error', __('Theme') . ' "' . h($themeName) . '" ' . __('not found in store.'));
    }

    $themeDir = rtrim(VIEWS_BASE, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $themeName;
    if (is_dir($themeDir)) {
        adiwira_redirect_with_flash($selfUrl, 'error', 'Theme "' . h($themeName) . '" sudah terpasang.');
    }

    $downloadUrl = $themeData['download_url'] ?? ($apiBase . '/download/' . $themeName . '/');
    $dlCtx = stream_context_create(['http' => ['timeout' => 120, 'user_agent' => 'JyavaniCMS/2.0']]);
    $zipContent = @file_get_contents($downloadUrl, false, $dlCtx);
    if ($zipContent === false) {
        adiwira_redirect_with_flash($selfUrl, 'error', __('Gagal mengunduh theme dari jyavani.com.'));
    }

    $tmpZip = tempnam(sys_get_temp_dir(), 'install-') . '.zip';
    file_put_contents($tmpZip, $zipContent);

    $zip = new ZipArchive();
    $open = $zip->open($tmpZip);
    if ($open !== true) {
        @unlink($tmpZip);
        adiwira_redirect_with_flash($selfUrl, 'error', 'File ZIP tidak valid (kode: ' . $open . ').');
    }

    $manifestRaw = $zip->getFromName('theme.json');
    if ($manifestRaw === false) {
        $zip->close(); @unlink($tmpZip);
        adiwira_redirect_with_flash($selfUrl, 'error', __('theme.json tidak ditemukan di dalam ZIP.'));
    }

    $manifest = json_decode($manifestRaw, true);
    if (!is_array($manifest) || empty($manifest['name']) || $manifest['name'] !== $themeName) {
        $zip->close(); @unlink($tmpZip);
        adiwira_redirect_with_flash($selfUrl, 'error', __('theme.json tidak valid.'));
    }

    $tmpExtract = dirname(VIEWS_BASE) . '/.extract-' . bin2hex(random_bytes(8));
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

    if (!is_file($tmpExtract . '/theme.json')) {
        _rmdir_recursive($tmpExtract);
        adiwira_redirect_with_flash($selfUrl, 'error', __('theme.json tidak ditemukan di root ZIP.'));
    }

    if (!rename($tmpExtract, $themeDir)) {
        _rmdir_recursive($tmpExtract);
        adiwira_redirect_with_flash($selfUrl, 'error', __('Gagal memindahkan theme.'));
    }

    $chmodIt = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($themeDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($chmodIt as $item) {
        if ($item->isDir()) @chmod($item->getPathname(), 0775);
        else @chmod($item->getPathname(), 0664);
    }

    register_theme_in_db($pdo, $themeName, $manifest);

    adiwira_redirect_with_flash($listUrl, 'success', __('Theme') . ' "' . h($manifest['title'] ?? $themeName) . '" ' . __('installed from store successfully.'));
}

$installedThemes = get_registered_themes($pdo);
$installedFolders = array_map(fn($t) => $t['folder_name'], $installedThemes);

$pageToasts = function_exists('adiwira_collect_query_toasts') ? adiwira_collect_query_toasts() : [];

if (isset($_GET['refresh'])) {
    if (is_file($cacheFile)) @unlink($cacheFile);
    $themes = [];
    store_cache_write($cacheFile, ['store_name' => $storeName, 'themes' => []]);
    $ctx = stream_context_create(['http' => ['timeout' => 10, 'user_agent' => 'JyavaniCMS/2.0']]);
    $json = @file_get_contents($apiBase . '/', false, $ctx);
    if ($json !== false) {
        $data = json_decode($json, true);
        if (is_array($data) && isset($data['themes'])) {
            $themes = $data['themes'];
            $storeName = $data['store_name'] ?? $storeName;
            store_cache_write($cacheFile, ['store_name' => $storeName, 'themes' => $themes]);
        } else {
            $error = 'Respon dari jyavani.com tidak valid.';
        }
    }
}
?>
<h2 class="pg-title"><?=_e('Browse Themes')?></h2>
<p class="pg-subtitle"><?=_e('Explore themes from')?> <a href="https://jyavani.com/" target="_blank" rel="noopener"><?= h($storeName) ?></a> — <?=_e('Jyavani community.')?></p>

<div style="margin-bottom:1rem;display:flex;gap:.5rem;flex-wrap:wrap">
  <a href="<?= h($listUrl) ?>" class="btn btn-outline btn-sm"><?=_e('&larr; Back to Theme Manager')?></a>
  <a href="<?= h($selfUrl) ?>&refresh=1" class="btn btn-sm btn-outline" style="border-color:var(--adam-primary);color:var(--adam-primary);display:inline-flex;align-items:center;gap:4px"><?= svg_ico('refresh-cw', '', ['style' => 'width:14px;height:14px']) ?> <?=_e('Reload')?></a>
</div>

<?php if ($error): ?>
<div class="alert alert-error">
  <strong>Gagal memuat daftar theme.</strong><br>
  <?= h($error) ?>
  <br><br>
  <a href="<?= h($selfUrl) ?>&refresh=1" class="btn btn-sm btn-primary">Coba Lagi</a>
</div>
<?php elseif (empty($themes)): ?>
<div class="empty-state">
  <p><?=_e('No themes available in the store yet. Please check back later.')?></p>
</div>
<?php else: ?>
<div class="plugin-grid">
  <?php foreach ($themes as $p):
    $isInstalled = in_array($p['name'], $installedFolders, true);
    $hasUpdate = false;
    if ($isInstalled) {
      $stmt = $pdo->prepare("SELECT manifest_json FROM themes WHERE folder_name = ? LIMIT 1");
      $stmt->execute([$p['name']]);
      $row = $stmt->fetch(PDO::FETCH_ASSOC);
      $installedManifest = $row ? @json_decode($row['manifest_json'], true) : null;
      $installedVersion = $installedManifest['version'] ?? '';
      $hasUpdate = $installedVersion !== '' && version_compare($p['version'] ?? '0.0.0', $installedVersion, '>');
    }
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
        <?php if (!empty($p['avg_rating'])): ?>
        <span>★ <?= number_format((float)$p['avg_rating'], 1) ?></span>
        <?php endif; ?>
      </div>
    </div>
    <div class="plugin-card-actions">
      <?php if ($isInstalled): ?>
        <?php if ($hasUpdate): ?>
        <a href="<?= h($listUrl) ?>" class="btn btn-sm btn-update">Update Tersedia</a>
        <?php else: ?>
        <span class="btn btn-sm btn-disabled" style="cursor:default;opacity:.5;display:inline-flex;align-items:center;gap:4px"><?= svg_ico('circle-check', '', ['style' => 'width:14px;height:14px']) ?> Terpasang</span>
        <?php endif; ?>
      <?php else: ?>
      <form method="post" style="display:inline">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="install">
        <input type="hidden" name="theme" value="<?= h($p['name']) ?>">
        <button type="submit" class="btn btn-sm btn-primary">+ Install</button>
      </form>
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
