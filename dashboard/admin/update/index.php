<?php
declare(strict_types=1);
// CMS Update Manager — Check for updates, apply update packages
require_once DASH_PATH . '/admin/_deny.php';
if (!defined('DASHBOARD_CONTEXT') && !defined('ADAM_THEME')) adiwira_admin_404();
require_once DASH_PATH . '/admin/_guard.php';
require_once DASH_PATH . '/admin/_notify.php';

[$uid, $role] = adiwira_require_role($pdo, ['admin'], false);

$base = ADMIN_BASE_PATH;
$selfUrl = $base . '/?page=admin/update/index';

// Load current version
$versionFile = dirname(DASH_PATH) . '/version.json';
$currentVersion = ['version' => '0.0.0', 'name' => 'Jyavani CMS', 'build' => ''];
if (is_file($versionFile)) {
    $v = json_decode(file_get_contents($versionFile), true);
    if (is_array($v)) $currentVersion = array_merge($currentVersion, $v);
}

// Load local manifest
$manifestFile = dirname(DASH_PATH) . '/tools/cms-manifest.json';
$localManifest = null;
if (is_file($manifestFile)) {
    $localManifest = json_decode(file_get_contents($manifestFile), true);
}

// --- Handle actions ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = (string)($_POST['csrf_token'] ?? '');
    if (!adiwira_csrf_validate($csrf)) {
        adiwira_redirect_with_flash($selfUrl, 'error', 'CSRF token tidak valid.');
    }

    $action = (string)($_POST['action'] ?? '');

    // --- Check remote update ---
    if ($action === 'check_remote') {
        $remoteUrl = trim((string)($_POST['update_url'] ?? ''));
        if ($remoteUrl === '') {
            adiwira_redirect_with_flash($selfUrl, 'error', 'URL update manifest tidak boleh kosong.');
        }

        $ctx = stream_context_create([
            'http' => [
                'timeout' => 15,
                'user_agent' => 'JyavaniCMS-Update/' . ($currentVersion['version'] ?? '0.0.0'),
            ],
        ]);

        $remoteJson = @file_get_contents($remoteUrl, false, $ctx);
        if ($remoteJson === false) {
            adiwira_redirect_with_flash($selfUrl, 'error', 'Gagal mengambil update manifest dari URL: ' . htmlspecialchars($remoteUrl));
        }

        $remote = json_decode($remoteJson, true);
        if (!is_array($remote) || !isset($remote['version'])) {
            adiwira_redirect_with_flash($selfUrl, 'error', 'Format manifest remote tidak valid.');
        }

        // Store in session for the apply step
        ensure_session_started(true);
        $_SESSION['cms_update_remote'] = $remote;
        $_SESSION['cms_update_remote_url'] = $remoteUrl;

        $localVer = $currentVersion['version'] ?? '0.0.0';
        $remoteVer = $remote['version'] ?? '0.0.0';

        if (version_compare($remoteVer, $localVer, '>')) {
            adiwira_redirect_with_flash($selfUrl, 'success',
                'Update tersedia: v' . htmlspecialchars($localVer) . ' → v' . htmlspecialchars($remoteVer) . '. '
                . 'Total ' . ($remote['total_files'] ?? 0) . ' file. Klik "Apply Update" untuk memulai.');
        } else {
            adiwira_redirect_with_flash($selfUrl, 'info',
                'CMS sudah versi terbaru (v' . htmlspecialchars($localVer) . ').');
        }
    }

    // --- Apply update from remote (downloaded and stored in session) ---
    if ($action === 'apply_update') {
        ensure_session_started(true);
        $remote = $_SESSION['cms_update_remote'] ?? null;
        $remoteUrl = $_SESSION['cms_update_remote_url'] ?? '';

        if (!$remote) {
            adiwira_redirect_with_flash($selfUrl, 'error', 'Tidak ada data update di session. Lakukan "Check for Updates" dulu.');
        }

        $result = _apply_cms_update($remote, $remoteUrl, $currentVersion['version'] ?? '0.0.0');
        unset($_SESSION['cms_update_remote'], $_SESSION['cms_update_remote_url']);

        if ($result['success']) {
            adiwira_redirect_with_flash($base . '/?page=admin/update/index', 'success', $result['message']);
        } else {
            adiwira_redirect_with_flash($selfUrl, 'error', $result['message']);
        }
    }

    // --- Upload update package ---
    if ($action === 'upload_update') {
        if (!isset($_FILES['update_package']) || $_FILES['update_package']['error'] !== UPLOAD_ERR_OK) {
            adiwira_redirect_with_flash($selfUrl, 'error', 'File update tidak valid.');
        }

        $file = $_FILES['update_package'];
        $origName = basename($file['name']);
        if (!str_ends_with(strtolower($origName), '.zip')) {
            adiwira_redirect_with_flash($selfUrl, 'error', 'Hanya file .zip yang didukung.');
        }

        $tmpZip = $file['tmp_name'];

        // Read manifest from zip
        $zip = new ZipArchive();
        if ($zip->open($tmpZip) !== true) {
            adiwira_redirect_with_flash($selfUrl, 'error', 'Gagal membuka file ZIP.');
        }

        $manifestJson = $zip->getFromName('cms-manifest.json');
        $versionJson = $zip->getFromName('version.json');

        if ($manifestJson === false) {
            $zip->close();
            adiwira_redirect_with_flash($selfUrl, 'error', 'cms-manifest.json tidak ditemukan di dalam package update.');
        }

        $remoteManifest = json_decode($manifestJson, true);
        $remoteVersion = $versionJson ? json_decode($versionJson, true) : null;

        if (!is_array($remoteManifest) || !isset($remoteManifest['version'])) {
            $zip->close();
            adiwira_redirect_with_flash($selfUrl, 'error', 'Format cms-manifest.json tidak valid.');
        }

        $zip->close();

        $localVer = $currentVersion['version'] ?? '0.0.0';
        $remoteVer = $remoteManifest['version'] ?? '0.0.0';

        if (!version_compare($remoteVer, $localVer, '>')) {
            adiwira_redirect_with_flash($selfUrl, 'warning',
                'Versi package (v' . htmlspecialchars($remoteVer) . ') tidak lebih baru dari versi saat ini (v' . htmlspecialchars($localVer) . ').');
        }

        // Store in session and redirect to apply
        ensure_session_started(true);
        $_SESSION['cms_update_remote'] = $remoteManifest;
        $_SESSION['cms_update_package'] = $tmpZip;
        $_SESSION['cms_update_remote_url'] = '(uploaded package)';

        adiwira_redirect_with_flash($selfUrl, 'success',
            'Package v' . htmlspecialchars($remoteVer) . ' siap. Klik "Apply Update" untuk memulai.');
    }

    // --- Apply update from uploaded ZIP ---
    if ($action === 'apply_uploaded') {
        ensure_session_started(true);
        $remote = $_SESSION['cms_update_remote'] ?? null;
        $packageZip = $_SESSION['cms_update_package'] ?? '';

        if (!$remote || !$packageZip || !is_file($packageZip)) {
            adiwira_redirect_with_flash($selfUrl, 'error', 'Tidak ada package update. Upload ulang.');
        }

        $result = _apply_cms_update_from_zip($packageZip, $remote, $currentVersion['version'] ?? '0.0.0');
        @unlink($packageZip);
        unset($_SESSION['cms_update_remote'], $_SESSION['cms_update_package'], $_SESSION['cms_update_remote_url']);

        if ($result['success']) {
            adiwira_redirect_with_flash($base . '/?page=admin/update/index', 'success', $result['message']);
        } else {
            adiwira_redirect_with_flash($selfUrl, 'error', $result['message']);
        }
    }

    adiwira_redirect_with_flash($selfUrl, 'error', 'Aksi tidak dikenal.');
}

// --- Helper: remote download + apply ---
function _apply_cms_update(array $remoteManifest, string $remoteUrl, string $currentVer): array {
    $projectRoot = dirname(DASH_PATH);
    $backupDir = $projectRoot . '/cfg/var/backup-' . date('Ymd-His');
    $preservePatterns = _get_preserve_patterns();

    try {
        // Download update zip
        $tmpZip = sys_get_temp_dir() . '/cms-update-' . bin2hex(random_bytes(8)) . '.zip';

        $ctx = stream_context_create([
            'http' => [
                'timeout' => 120,
                'user_agent' => 'JyavaniCMS-Update/' . $currentVer,
            ],
        ]);

        $zipData = @file_get_contents($remoteUrl, false, $ctx);
        if ($zipData === false) {
            return ['success' => false, 'message' => 'Gagal download package update.'];
        }

        file_put_contents($tmpZip, $zipData);

        $result = _apply_cms_update_from_zip($tmpZip, $remoteManifest, $currentVer);
        @unlink($tmpZip);
        return $result;

    } catch (Throwable $e) {
        return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
    }
}

// --- Helper: apply update from zip ---
function _apply_cms_update_from_zip(string $zipPath, array $remoteManifest, string $currentVer): array {
    $projectRoot = dirname(DASH_PATH);
    $backupDir = $projectRoot . '/cfg/var/backup-' . date('Ymd-His');

    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        return ['success' => false, 'message' => 'Gagal membuka ZIP package.'];
    }

    $totalFiles = $zip->numFiles;
    if ($totalFiles === 0) {
        $zip->close();
        return ['success' => false, 'message' => 'Package update kosong.'];
    }

    // Build list of remote files from manifest
    $remoteFiles = $remoteManifest['files'] ?? [];
    $preservePatterns = _get_preserve_patterns();

    // Create backup dir
    if (!is_dir($backupDir)) {
        @mkdir($backupDir, 0755, true);
    }

    $updated = 0;
    $backedUp = 0;
    $errors = [];

    // Extract all files from zip
    for ($i = 0; $i < $totalFiles; $i++) {
        $filename = $zip->getNameIndex($i);
        if ($filename === false) continue;

        // Skip directories
        if (str_ends_with($filename, '/')) continue;

        // Skip preserved paths
        $isPreserved = false;
        foreach ($preservePatterns as $pattern) {
            if (preg_match($pattern, $filename)) {
                $isPreserved = true;
                break;
            }
        }
        if ($isPreserved) continue;

        // Check if this file is in the remote manifest
        if (!isset($remoteFiles[$filename])) continue;

        $targetPath = $projectRoot . '/' . $filename;

        // Backup existing file if it exists
        if (is_file($targetPath)) {
            $backupPath = $backupDir . '/' . $filename;
            $backupParent = dirname($backupPath);
            if (!is_dir($backupParent)) {
                @mkdir($backupParent, 0755, true);
            }
            if (@copy($targetPath, $backupPath)) {
                $backedUp++;
            }
        }

        // Ensure target parent dir exists
        $targetParent = dirname($targetPath);
        if (!is_dir($targetParent)) {
            @mkdir($targetParent, 0755, true);
        }

        // Extract file
        $extracted = @file_put_contents($targetPath, $zip->getFromIndex($i));
        if ($extracted === false) {
            $errors[] = 'Gagal menulis: ' . $filename;
        } else {
            $updated++;
        }
    }

    $zip->close();

    // Delete files that exist locally but not in remote manifest
    $localManifest = _get_local_manifest();
    if ($localManifest) {
        $localFiles = $localManifest['files'] ?? [];
        $remoteFileKeys = array_keys($remoteFiles);
        $deleted = 0;

        foreach ($localFiles as $localRelPath => $hash) {
            // Skip if still in remote
            if (isset($remoteFiles[$localRelPath])) continue;

            // Skip preserved paths
            $isPreserved = false;
            foreach ($preservePatterns as $pattern) {
                if (preg_match($pattern, $localRelPath)) {
                    $isPreserved = true;
                    break;
                }
            }
            if ($isPreserved) continue;

            $localPath = $projectRoot . '/' . $localRelPath;
            if (is_file($localPath)) {
                // Backup first
                $backupPath = $backupDir . '/' . $localRelPath;
                $backupParent = dirname($backupPath);
                if (!is_dir($backupParent)) @mkdir($backupParent, 0755, true);
                @copy($localPath, $backupPath);

                @unlink($localPath);
                $deleted++;
            }
        }
    }

    // Update version.json
    $newVersion = [
        'name' => $remoteManifest['name'] ?? 'Jyavani CMS',
        'version' => $remoteManifest['version'] ?? $currentVer,
        'build' => $remoteManifest['build'] ?? date('Y-m-d'),
        'php_required' => $remoteManifest['php_required'] ?? '8.1',
        'mysql_required' => $remoteManifest['mysql_required'] ?? '5.7',
    ];
    file_put_contents($projectRoot . '/version.json', json_encode($newVersion, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    // Regenerate local manifest
    if (is_file($projectRoot . '/tools/generate-manifest.php')) {
        @shell_exec('php ' . escapeshellarg($projectRoot . '/tools/generate-manifest.php') . ' 2>&1');
    }

    $msg = 'Update selesai: ' . $updated . ' file diperbarui, ' . $backedUp . ' file dibackup.';
    if (!empty($errors)) {
        $msg .= ' Error: ' . implode('; ', array_slice($errors, 0, 5));
    }
    if (isset($deleted) && $deleted > 0) {
        $msg .= ' ' . $deleted . ' file usang dihapus.';
    }
    $msg .= ' Backup: ' . basename($backupDir);

    return ['success' => true, 'message' => $msg];
}

// --- Helper: get preserve regex patterns ---
function _get_preserve_patterns(): array {
    return [
        '#^cfg/\.env$#',
        '#^cfg/var/#',
        '#^cfg/session_debug\.log$#',
        '#^cfg/php-noteloc\.ini$#',
        '#^private_files/#',
        '#^public/static/img/#',
        '#^public/static/files/#',
        '#^public/sitemaps/#',
        '#^public/pdf/#',
        '#^public/views/themes/[^/]+/.+#',
        '#^plugins/[^/]+/.+#',
        '#node_modules/#',
        '#\.git/#',
        '#^\.gitignore$#',
    ];
}

// --- Helper: load local manifest ---
function _get_local_manifest(): ?array {
    $f = dirname(DASH_PATH) . '/tools/cms-manifest.json';
    if (!is_file($f)) return null;
    $d = json_decode(file_get_contents($f), true);
    return is_array($d) ? $d : null;
}

// --- Helper: version info from session ---
ensure_session_started(false);
$pendingUpdate = $_SESSION['cms_update_remote'] ?? null;
$pendingPackage = $_SESSION['cms_update_package'] ?? null;
$pendingUrl = $_SESSION['cms_update_remote_url'] ?? '';

// Suggest default update URL
$defaultUpdateUrl = 'https://raw.githubusercontent.com/adammuiz/jyavani-cms/main/tools/cms-manifest.json';

// Compute file stats
$totalCore = $localManifest['total_files'] ?? 0;
?>
<h2 class="pg-title">CMS Update</h2>
<p class="pg-subtitle">Version <?= htmlspecialchars($currentVersion['version'] ?? '—') ?> &mdash; <?= htmlspecialchars($currentVersion['build'] ?? '') ?></p>

<div class="up-grid">
    <div class="up-card">
        <div class="up-card-header">Current Installation</div>
        <table class="up-table">
            <tr><td>CMS</td><td><strong><?= htmlspecialchars($currentVersion['name'] ?? 'Jyavani CMS') ?></strong></td></tr>
            <tr><td>Version</td><td><strong>v<?= htmlspecialchars($currentVersion['version'] ?? '0.0.0') ?></strong></td></tr>
            <tr><td>Build</td><td><?= htmlspecialchars($currentVersion['build'] ?? '—') ?></td></tr>
            <tr><td>Core files</td><td><?= $totalCore ?> files tracked</td></tr>
            <tr><td>PHP</td><td><?= htmlspecialchars($currentVersion['php_required'] ?? '8.1') ?>+ (server: <?= PHP_VERSION ?>)</td></tr>
            <tr><td>MySQL</td><td><?= htmlspecialchars($currentVersion['mysql_required'] ?? '5.7') ?>+</td></tr>
        </table>
    </div>

    <div class="up-card">
        <div class="up-card-header">Check for Updates</div>
        <form method="post" class="up-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="action" value="check_remote">

            <label class="up-label">Update Manifest URL</label>
            <input type="url" name="update_url" class="up-input"
                   value="<?= htmlspecialchars($defaultUpdateUrl) ?>"
                   placeholder="https://example.com/cms-manifest.json">

            <div class="up-hint">URL ke file <code>cms-manifest.json</code> dari versi terbaru. Bisa dari GitHub, CDN, atau server sendiri.</div>

            <button type="submit" class="btn btn-primary">Check for Updates</button>
        </form>
    </div>
</div>

<?php if ($pendingUpdate): ?>
<div class="up-card up-card-warning">
    <div class="up-card-header">Update Ready</div>
    <p>Package: <strong>v<?= htmlspecialchars($pendingUpdate['version'] ?? '?') ?></strong>
       &mdash; <?= ($pendingUpdate['total_files'] ?? 0) ?> file
       &mdash; Source: <?= htmlspecialchars($pendingUrl ?: 'uploaded') ?></p>

    <div class="up-flex">
        <form method="post" style="display:inline" onsubmit="return confirm('Apply update now? Files will be backed up automatically.')">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
            <?php if ($pendingPackage): ?>
                <input type="hidden" name="action" value="apply_uploaded">
            <?php else: ?>
                <input type="hidden" name="action" value="apply_update">
            <?php endif; ?>
            <button type="submit" class="btn btn-primary" style="background:#059669;border-color:#059669">Apply Update</button>
        </form>
        <form method="post" style="display:inline">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="action" value="clear_pending">
            <button type="submit" class="btn btn-outline">Cancel</button>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="up-card" style="margin-top:1.25rem">
    <div class="up-card-header">Manual Upload</div>
    <p class="up-hint">Upload file <code>.zip</code> package update yang berisi <code>cms-manifest.json</code> di root-nya.</p>

    <form method="post" enctype="multipart/form-data" class="up-form">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
        <input type="hidden" name="action" value="upload_update">

        <div class="up-file-row">
            <input type="file" name="update_package" accept=".zip" required id="upFile">
            <button type="submit" class="btn btn-primary" id="upBtn">Upload &amp; Install</button>
        </div>
    </form>
</div>

<div class="up-card" style="margin-top:1.25rem">
    <div class="up-card-header">What Gets Updated</div>
    <p class="up-hint">Update hanya memengaruhi file inti CMS. Data berikut TIDAK akan disentuh:</p>
    <ul class="up-list">
        <li><code>cfg/.env</code> &mdash; database &amp; session config</li>
        <li><code>cfg/var/</code> &mdash; sessions, tokens, runtime data</li>
        <li><code>private_files/</code> &mdash; protected files</li>
        <li><code>public/static/img/</code> &amp; <code>files/</code> &mdash; uploaded media</li>
        <li><code>public/views/themes/</code> &mdash; installed themes</li>
        <li><code>plugins/</code> &mdash; installed plugins</li>
        <li><code>public/pdf/</code> &mdash; PWA / static PDF files</li>
        <li><code>public/sitemaps/</code> &mdash; generated sitemaps</li>
    </ul>
    <p class="up-hint">File yang diubah akan otomatis dibackup ke <code>cfg/var/backup-{timestamp}/</code>.</p>
</div>

<style>
.pg-title { font-size:1.4rem; font-weight:700; margin:0 0 .25rem; color:var(--adam-text); }
.pg-subtitle { color:var(--adam-muted); font-size:.9rem; margin:0 0 1.5rem; }
.up-grid { display:grid; grid-template-columns:1fr 1fr; gap:1rem; }
@media (max-width:768px){ .up-grid { grid-template-columns:1fr; } }
.up-card { border:1px solid var(--adam-border); border-radius:var(--adam-radius); padding:1rem; background:var(--adam-card); }
.up-card-warning { border-color:#f59e0b; background:#fffbeb; }
html.theme-dark .up-card-warning { border-color:#d97706; background:#1a1500; }
.up-card-header { font-size:.9rem; font-weight:600; margin-bottom:.75rem; text-transform:uppercase; letter-spacing:.03em; color:var(--adam-muted); }
.up-table { width:100%; border-collapse:collapse; font-size:.875rem; }
.up-table td { padding:.35rem .5rem; border-bottom:1px solid var(--adam-surface-3); color:var(--adam-text); }
.up-table td:first-child { color:var(--adam-muted); width:35%; }
.up-form { display:flex; flex-direction:column; gap:.6rem; }
.up-label { font-size:.8rem; font-weight:500; color:var(--adam-text-2); }
.up-input { padding:.5rem .65rem; border:1px solid var(--adam-border-2); border-radius:6px; font-size:.85rem; font-family:inherit; background:var(--adam-card); color:var(--adam-text); }
.up-input:focus { outline:none; border-color:var(--adam-primary); box-shadow:0 0 0 2px var(--adam-primary-soft); }
.up-hint { font-size:.8rem; color:var(--adam-muted-2); margin:.25rem 0; }
.up-hint code { background:var(--adam-surface-3); padding:.1rem .3rem; border-radius:3px; font-size:.78rem; }
.up-flex { display:flex; gap:.5rem; margin-top:.5rem; }
.up-file-row { display:flex; gap:.5rem; align-items:center; }
.up-list { margin:.5rem 0; padding-left:1.25rem; font-size:.85rem; color:var(--adam-text-2); }
.up-list li { margin-bottom:.3rem; }
.up-list code { background:var(--adam-surface-3); padding:.1rem .3rem; border-radius:3px; font-size:.8rem; }
.btn { display:inline-flex; align-items:center; gap:.35rem; padding:.4rem .75rem; font-size:.8rem; font-weight:500; border-radius:6px; cursor:pointer; border:1px solid transparent; font-family:inherit; line-height:1; text-decoration:none; }
.btn-primary { background:var(--adam-primary); color:#fff; border-color:var(--adam-primary); }
.btn-primary:hover { background:var(--adam-primary-600); }
.btn-outline { background:transparent; color:var(--adam-muted); border-color:var(--adam-border-2); }
.btn-outline:hover { background:var(--adam-surface-3); color:var(--adam-text); }
</style>

<script>
(function(){
    var fileInput = document.getElementById('upFile');
    var upBtn = document.getElementById('upBtn');
    if (fileInput && upBtn) {
        fileInput.addEventListener('change', function(){
            upBtn.disabled = !this.files || !this.files[0];
        });
        upBtn.disabled = true;
    }
})();
</script>
