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
        adiwira_redirect_with_flash($selfUrl, 'error', __('Invalid CSRF token.'));
    }

    $action = (string)($_POST['action'] ?? '');

    // --- Check remote update ---
    if ($action === 'check_remote') {
        $inputUrl = trim((string)($_POST['update_url'] ?? ''));
        if ($inputUrl === '') {
            adiwira_redirect_with_flash($selfUrl, 'error', __('URL update tidak boleh kosong.'));
        }

        $ctx = stream_context_create([
            'http' => [
                'timeout' => 15,
                'user_agent' => 'JyavaniCMS-Update/' . ($currentVersion['version'] ?? '0.0.0'),
            ],
        ]);

        // If URL ends with .json, treat as manifest URL (backward compat).
        // Otherwise, auto-append ?format=json for version check.
        if (preg_match('/\.json$/i', $inputUrl)) {
            $checkUrl = $inputUrl;
            $_SESSION['cms_update_base_url'] = dirname($inputUrl);
        } else {
            $sep = (str_contains($inputUrl, '?')) ? '&' : '?';
            $checkUrl = $inputUrl . $sep . 'format=json';
            $_SESSION['cms_update_base_url'] = $inputUrl;
        }

        $remoteJson = @file_get_contents($checkUrl, false, $ctx);
        if ($remoteJson === false) {
            adiwira_redirect_with_flash($selfUrl, 'error', 'Gagal mengambil info update dari URL: ' . htmlspecialchars($inputUrl));
        }

        $remote = json_decode($remoteJson, true);
        if (!is_array($remote) || !isset($remote['version'])) {
            adiwira_redirect_with_flash($selfUrl, 'error', __('Format respons remote tidak valid (dibutuhkan: version).'));
        }

        // Store in session for the apply step
        ensure_session_started(true);
        $_SESSION['cms_update_remote'] = $remote;

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
        $baseUrl = $_SESSION['cms_update_base_url'] ?? '';

        if (!$remote) {
            adiwira_redirect_with_flash($selfUrl, 'error', __('Tidak ada data update di session. Lakukan "Check for Updates" dulu.'));
        }

        $result = _apply_cms_update($remote, $baseUrl, $currentVersion['version'] ?? '0.0.0');
        unset($_SESSION['cms_update_remote'], $_SESSION['cms_update_base_url']);

        if ($result['success']) {
            adiwira_redirect_with_flash($base . '/?page=admin/update/index', 'success', $result['message']);
        } else {
            adiwira_redirect_with_flash($selfUrl, 'error', $result['message']);
        }
    }

    // --- Upload update package ---
    if ($action === 'upload_update') {
        if (!isset($_FILES['update_package']) || $_FILES['update_package']['error'] !== UPLOAD_ERR_OK) {
            adiwira_redirect_with_flash($selfUrl, 'error', __('File update tidak valid.'));
        }

        $file = $_FILES['update_package'];
        $origName = basename($file['name']);
        if (!str_ends_with(strtolower($origName), '.zip')) {
            adiwira_redirect_with_flash($selfUrl, 'error', __('Hanya file .zip yang didukung.'));
        }

        $tmpZip = $file['tmp_name'];

        // Read manifest from zip
        $zip = new ZipArchive();
        if ($zip->open($tmpZip) !== true) {
            adiwira_redirect_with_flash($selfUrl, 'error', __('Gagal membuka file ZIP.'));
        }

        $manifestJson = $zip->getFromName('cms-manifest.json');
        $versionJson = $zip->getFromName('version.json');

        if ($manifestJson === false) {
            $zip->close();
            adiwira_redirect_with_flash($selfUrl, 'error', __('cms-manifest.json tidak ditemukan di dalam package update.'));
        }

        $remoteManifest = json_decode($manifestJson, true);
        $remoteVersion = $versionJson ? json_decode($versionJson, true) : null;

        if (!is_array($remoteManifest) || !isset($remoteManifest['version'])) {
            $zip->close();
            adiwira_redirect_with_flash($selfUrl, 'error', __('Format cms-manifest.json tidak valid.'));
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
            adiwira_redirect_with_flash($selfUrl, 'error', __('Tidak ada package update. Upload ulang.'));
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

    // --- Clear pending update ---
    if ($action === 'clear_pending') {
        ensure_session_started(true);
        unset($_SESSION['cms_update_remote'], $_SESSION['cms_update_base_url'], $_SESSION['cms_update_package'], $_SESSION['cms_update_remote_url']);
        adiwira_redirect_with_flash($selfUrl, 'info', __('Pending update dibatalkan.'));
    }

    // --- Reinstall CMS (force overwrite same version) ---
    if ($action === 'reinstall') {
        $url = trim((string)($_POST['reinstall_url'] ?? ''));
        if ($url === '') {
            adiwira_redirect_with_flash($selfUrl, 'error', __('URL reinstall tidak boleh kosong.'));
        }

        $hardReset = !empty($_POST['hard_reset']);

        $tmpZip = sys_get_temp_dir() . '/cms-reinstall-' . bin2hex(random_bytes(8)) . '.zip';
        $ctx = stream_context_create([
            'http' => [
                'timeout' => 120,
                'user_agent' => 'JyavaniCMS-Reinstall/' . ($currentVersion['version'] ?? '0.0.0'),
            ],
        ]);

        $zipData = @file_get_contents($url, false, $ctx);
        if ($zipData === false) {
            @unlink($tmpZip);
            adiwira_redirect_with_flash($selfUrl, 'error', __('Gagal download package reinstall.'));
        }
        file_put_contents($tmpZip, $zipData);

        $dummyManifest = [
            'name' => $currentVersion['name'] ?? 'Jyavani CMS',
            'version' => $currentVersion['version'] ?? '0.0.0',
            'build' => date('Y-m-d'),
        ];
        $result = _apply_cms_update_from_zip($tmpZip, $dummyManifest, $currentVersion['version'] ?? '0.0.0');
        @unlink($tmpZip);

        if ($hardReset) {
            $resetMessages = _reinstall_hard_reset($pdo);
        }

        if ($result['success']) {
            $msg = 'Reinstall selesai! ' . $result['message'];
            if (!empty($resetMessages)) {
                $msg .= ' ' . implode(' ', $resetMessages);
            }
            adiwira_redirect_with_flash($base . '/?page=admin/update/index', 'success', $msg);
        } else {
            adiwira_redirect_with_flash($selfUrl, 'error', $result['message']);
        }
    }

    adiwira_redirect_with_flash($selfUrl, 'error', __('Aksi tidak dikenal.'));
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

    // Build list of remote files from manifest (null = no file-level filtering)
    $remoteFiles = null;
    if (isset($remoteManifest['files']) && is_array($remoteManifest['files'])) {
        $remoteFiles = $remoteManifest['files'];
    }
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

        // If manifest provides a file list, only update files listed in it
        if ($remoteFiles !== null && !isset($remoteFiles[$filename])) continue;

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
    // (only when remote manifest provides a file list)
    $deleted = 0;
    if ($remoteFiles !== null) {
        $localManifest = _get_local_manifest();
        if ($localManifest) {
            $localFiles = $localManifest['files'] ?? [];
            foreach ($localFiles as $localRelPath => $hash) {
                if (isset($remoteFiles[$localRelPath])) continue;
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
                    $backupPath = $backupDir . '/' . $localRelPath;
                    $backupParent = dirname($backupPath);
                    if (!is_dir($backupParent)) @mkdir($backupParent, 0755, true);
                    @copy($localPath, $backupPath);
                    @unlink($localPath);
                    $deleted++;
                }
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
        '#^plugin-store/#',
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

// --- Helper: hard reset DB settings ---
function _reinstall_hard_reset(PDO $pdo): array {
    $msgs = [];

    // 1. Reset active theme in settings
    $pdo->exec("UPDATE settings SET value = 'default' WHERE key = 'active_theme'");
    $msgs[] = 'Tema direset ke default.';

    // 2. Reset theme active flag + slot assignments
    $pdo->exec("UPDATE themes SET is_active = 0");
    $pdo->exec("UPDATE themes SET is_active = 1 WHERE id = 1 OR folder_name = 'default'");
    $pdo->exec("DELETE FROM assignments");
    $pdo->exec("INSERT INTO assignments (slot_key, theme_id, theme_file) VALUES
        ('header', 1, 'header.php'),
        ('footer', 1, 'footer.php'),
        ('sidebar', 1, 'sidebar.php'),
        ('main.homepage', 1, 'main/homepage.php')");
    $msgs[] = 'Slot assignments direset.';

    // 3. Reset sidebar zone items
    $pdo->exec("DELETE FROM sidebar_zone_items");
    $zoneId = $pdo->query("SELECT id FROM sidebar_zones WHERE slug = 'main'")->fetchColumn();
    if ($zoneId) {
        $pdo->prepare("INSERT IGNORE INTO sidebar_zone_items (zone_id, type, title, config, ordering, active) VALUES
            (?, 'search', 'Cari', '{\"title\":\"Cari\",\"placeholder\":\"Cari artikel...\"}', 0, 1),
            (?, 'last_posts', 'Artikel Terbaru', '{\"title\":\"Artikel Terbaru\",\"limit\":5,\"type\":\"article\"}', 1, 1),
            (?, 'categories', 'Kategori', '{\"title\":\"Kategori\",\"limit\":30,\"only_parents\":true}', 2, 1)")->execute([$zoneId, $zoneId, $zoneId]);
        $msgs[] = 'Sidebar items direset.';
    }

    // 4. Reset menu items
    $menuId = $pdo->query("SELECT id FROM menus WHERE slug = 'primary'")->fetchColumn();
    if ($menuId) {
        $pdo->exec("DELETE FROM menu_items WHERE menu_id = " . (int)$menuId);
        $pdo->prepare("INSERT INTO menu_items (menu_id, parent_id, sort_order, type, label, url, target_id, target_blank) VALUES
            (?, NULL, 0, 'custom', 'Home', '/', NULL, 0),
            (?, NULL, 1, 'category', 'Blog', NULL, 1, 0),
            (?, NULL, 2, 'category', 'Services', NULL, 2, 0)")->execute([$menuId, $menuId, $menuId]);
        $msgs[] = 'Menu items direset.';
    }

    // 5. Reset auth paths to defaults
    $pdo->exec("REPLACE INTO settings (`key`, `value`, `autoload`) VALUES
        ('admin_path', 'dashboard', 1),
        ('login_path', 'login', 1),
        ('register_path', 'register', 1)");
    $msgs[] = 'Auth paths direset (admin: /dashboard/, login: /login/).';

    // 6. Disable all plugins (not deleted)
    $pluginDir = dirname(DASH_PATH) . '/plugins';
    $pluginNames = [];
    foreach (glob($pluginDir . '/*/plugin.json') as $manifestFile) {
        $name = basename(dirname($manifestFile));
        if ($name !== '') $pluginNames[] = $name;
    }
    if (!empty($pluginNames)) {
        $disabledFile = dirname(DASH_PATH) . '/cfg/var/plugins-disabled.json';
        file_put_contents($disabledFile, json_encode($pluginNames), LOCK_EX);
        $msgs[] = count($pluginNames) . ' plugin dinonaktifkan.';
    }

    // 7. Reset general settings
    $pdo->exec("REPLACE INTO settings (`key`, `value`, `autoload`) VALUES ('posts_per_page', '10', 1)");

    return $msgs;
}

// --- Helper: version info from session ---
ensure_session_started(false);
$pendingUpdate = $_SESSION['cms_update_remote'] ?? null;
$pendingPackage = $_SESSION['cms_update_package'] ?? null;
$pendingUrl = $_SESSION['cms_update_remote_url'] ?? $_SESSION['cms_update_base_url'] ?? '';

// Suggest default update URL
$defaultUpdateUrl = 'https://jyavani.com/download/latest/';

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

            <label class="up-label">Update URL</label>
            <input type="url" name="update_url" class="up-input"
                   value="<?= htmlspecialchars($defaultUpdateUrl) ?>"
                   placeholder="https://example.com/download/latest/">

            <div class="up-hint">URL ke download endpoint versi terbaru. Secara otomatis ditambahi <code>?format=json</code> untuk pengecekan versi.</div>

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

<div class="up-card" style="margin-top:1.25rem;border-color:var(--adam-danger)">
    <div class="up-card-header" style="color:var(--adam-danger)">Reinstall CMS</div>
    <p class="up-hint">Timpa semua file inti CMS dengan versi original. Cocok jika ada file yang rusak. Data (<code>cfg/.env</code>, tema, plugin, upload) tetap aman.</p>

    <form method="post" class="up-form" onsubmit="return confirmReinstall(event)">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
        <input type="hidden" name="action" value="reinstall">
        <label class="up-label">Download URL</label>
        <input type="url" name="reinstall_url" class="up-input"
               value="<?= htmlspecialchars($defaultUpdateUrl) ?>"
               placeholder="https://example.com/download/latest/">
        <label class="up-checkline">
            <input type="checkbox" name="hard_reset" value="1" id="chkHard">
            Hard reset &mdash; reset tema, auth paths, plugin, slot, sidebar, dan menu ke default
        </label>
        <div class="up-hint" id="hintHard" style="display:none;margin-top:-.3rem;margin-bottom:.3rem;border-left:3px solid var(--adam-danger);padding-left:.6rem">
            Tema → default, Auth paths → /dashboard/ /login/ /register/, semua plugin dinonaktifkan.
            Kustomisasi slot/sidebar/menu akan hilang. Konten (postingan, halaman, media, user) TIDAK terpengaruh.
        </div>
        <button type="submit" class="btn btn-danger">Reinstall Now</button>
    </form>
</div>

<!-- Simple reinstall confirmation modal -->
<div class="adam-modal" id="reinstallModal">
    <div class="adam-modal__panel">
        <div class="adam-modal__title">Konfirmasi Reinstall</div>
        <div class="adam-modal__text">
            <p>Yakin reinstall? Semua file inti CMS akan ditimpa dengan versi original. Backup otomatis dibuat.</p>
            <p class="up-hint" style="margin-top:.5rem">Data (<code>cfg/.env</code>, tema, plugin, upload) tetap aman.</p>
        </div>
        <div class="adam-modal__actions">
            <button class="adam-btn adam-btn--ghost" onclick="closeReinstallModal()">Batal</button>
            <button class="adam-btn adam-btn--danger" id="reinstallApplyBtn">Ya, Reinstall</button>
        </div>
    </div>
</div>

<!-- Hard reset confirmation modal -->
<div class="adam-modal" id="resetModal">
    <div class="adam-modal__panel" style="max-width:480px">
        <div class="adam-modal__title">Konfirmasi Hard Reset</div>
        <div class="adam-modal__text">
            <p style="margin-bottom:.75rem;font-weight:600">Hard reset akan melakukan perubahan berikut:</p>
            <p style="margin-bottom:.6rem;font-weight:600">Centang semua untuk melanjutkan:</p>
            <ul class="reset-checklist">
                <li class="reset-cb"><label><input type="checkbox" class="reset-cbox"> File inti CMS ditimpa dengan versi original</label></li>
                <li class="reset-cb"><label><input type="checkbox" class="reset-cbox"> Tema direset ke <strong>default</strong></label></li>
                <li class="reset-cb"><label><input type="checkbox" class="reset-cbox"> Auth paths: admin → <code>/dashboard/</code>, login → <code>/login/</code>, register → <code>/register/</code></label></li>
                <li class="reset-cb"><label><input type="checkbox" class="reset-cbox"> Semua plugin <strong>dinonaktifkan</strong> (file tetap ada)</label></li>
                <li class="reset-cb"><label><input type="checkbox" class="reset-cbox"> Kustomisasi slot, sidebar, dan menu direset ke bawaan</label></li>
                <li class="reset-cb"><label><input type="checkbox" class="reset-cbox"> Konten (postingan, halaman, media, user) <strong>AMAN</strong></label></li>
                <li class="reset-cb"><label><input type="checkbox" class="reset-cbox"> Konfigurasi database &amp; file upload <strong>AMAN</strong></label></li>
            </ul>
        </div>
        <div class="adam-modal__actions">
            <button class="adam-btn adam-btn--ghost" onclick="closeResetModal()">Batal</button>
            <button class="adam-btn adam-btn--danger" id="resetApplyBtn" disabled>Apply Hard Reset</button>
        </div>
    </div>
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
@media (prefers-color-scheme: dark){
  html:not(.theme-light):not(.theme-dark) .up-card-warning { border-color:#d97706; background:#1a1500; }
}
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
.up-checkline { display:flex; gap:.5rem; align-items:center; font-size:.85rem; color:var(--adam-text-2); cursor:pointer; padding:.3rem 0; }
.up-checkline input[type=checkbox] { accent-color:var(--adam-danger); width:16px; height:16px; }
.btn { display:inline-flex; align-items:center; gap:.35rem; padding:.4rem .75rem; font-size:.8rem; font-weight:500; border-radius:6px; cursor:pointer; border:1px solid transparent; font-family:inherit; line-height:1; text-decoration:none; }
.btn-primary { background:var(--adam-primary); color:#fff; border-color:var(--adam-primary); }
.btn-primary:hover { background:var(--adam-primary-600); }
.btn-outline { background:transparent; color:var(--adam-muted); border-color:var(--adam-border-2); }
.btn-outline:hover { background:var(--adam-surface-3); color:var(--adam-text); }
.btn-danger { background:var(--adam-danger); color:#fff; border-color:var(--adam-danger); }
.btn-danger:hover { background:var(--adam-danger-600); }
.reset-checklist { list-style:none; padding:0; margin:0 0 .5rem; font-size:.88rem; }
.reset-checklist li { padding:.2rem 0; line-height:1.4; }
.reset-cb label { display:flex; gap:.5rem; align-items:center; cursor:pointer; padding:.25rem .4rem; border-radius:6px; transition:background .12s; }
.reset-cb label:hover { background:var(--adam-hover); }
.reset-cb input[type=checkbox] { accent-color:var(--adam-danger); width:15px; height:15px; }
#resetApplyBtn:disabled { background:var(--adam-muted); border-color:var(--adam-border-2); color:#fff; cursor:not-allowed; opacity:1; }
#resetApplyBtn:disabled:hover { background:var(--adam-muted); }
</style>

<script>
(function(){
    var chk = document.getElementById('chkHard');
    var hint = document.getElementById('hintHard');
    if (chk && hint) {
        chk.addEventListener('change', function(){
            hint.style.display = this.checked ? 'block' : 'none';
        });
    }
})();

function confirmReinstall(e){
    e.preventDefault();
    var hard = document.getElementById('chkHard');
    if (hard && hard.checked) {
        showResetModal();
    } else {
        showReinstallModal();
    }
    return false;
}

function showReinstallModal(){
    var modal = document.getElementById('reinstallModal');
    if (modal) modal.style.display = 'flex';
}

function closeReinstallModal(){
    var modal = document.getElementById('reinstallModal');
    if (modal) modal.style.display = 'none';
}

function showResetModal(){
    var modal = document.getElementById('resetModal');
    var btn = document.getElementById('resetApplyBtn');
    if (!modal || !btn) return;
    var boxes = document.querySelectorAll('.reset-cbox');
    for (var i = 0; i < boxes.length; i++) boxes[i].checked = false;
    btn.disabled = true;
    modal.style.display = 'flex';
}

function closeResetModal(){
    var modal = document.getElementById('resetModal');
    if (modal) modal.style.display = 'none';
}

(function(){
    var btn = document.getElementById('resetApplyBtn');
    var boxes = document.querySelectorAll('.reset-cbox');
    if (btn && boxes.length) {
        function checkAll(){
            for (var i = 0; i < boxes.length; i++) {
                if (!boxes[i].checked) { btn.disabled = true; return; }
            }
            btn.disabled = false;
        }
        for (var i = 0; i < boxes.length; i++) {
            boxes[i].addEventListener('change', checkAll);
        }
        btn.addEventListener('click', function(){
            var form = document.querySelector('.up-card[style*="border-color:var(--adam-danger)"] form');
            if (form) form.submit();
        });
    }
})();

(function(){
    var btn = document.getElementById('reinstallApplyBtn');
    if (btn) {
        btn.addEventListener('click', function(){
            var form = document.querySelector('.up-card[style*="border-color:var(--adam-danger)"] form');
            if (form) form.submit();
        });
    }
})();

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
