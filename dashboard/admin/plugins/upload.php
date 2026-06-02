<?php
declare(strict_types=1);
// Plugin Uploader — Upload plugin ZIP, extract, install static files & enable
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
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($dir);
    }
}

$base = ADMIN_BASE_PATH;
$selfUrl = $base . '/?page=admin/plugins/upload';
$listUrl = $base . '/?page=admin/plugins/index';

$maxSize = 50 * 1024 * 1024; // 50MB
$error = '';
$success = '';

// --- Process upload ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['plugin_zip'])) {
    $csrf = (string)($_POST['csrf_token'] ?? '');
    if (!adiwira_csrf_validate($csrf)) {
        adiwira_redirect_with_flash($selfUrl, 'error', 'CSRF token tidak valid.');
    }

    $file = $_FILES['plugin_zip'];

    // Validate upload
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $uploadErrors = [
            UPLOAD_ERR_INI_SIZE   => 'File melebihi upload_max_filesize di php.ini.',
            UPLOAD_ERR_FORM_SIZE  => 'File melebihi MAX_FILE_SIZE yang ditentukan.',
            UPLOAD_ERR_PARTIAL    => 'File hanya terupload sebagian.',
            UPLOAD_ERR_NO_FILE    => 'Tidak ada file yang dipilih.',
            UPLOAD_ERR_NO_TMP_DIR => 'Folder temporary tidak ditemukan.',
            UPLOAD_ERR_CANT_WRITE => 'Gagal menulis file ke disk.',
        ];
        $msg = $uploadErrors[$file['error']] ?? 'Unknown upload error.';
        adiwira_redirect_with_flash($selfUrl, 'error', $msg);
    }

    if ($file['size'] > $maxSize) {
        adiwira_redirect_with_flash($selfUrl, 'error', 'File terlalu besar. Maksimal 50MB.');
    }

    // Validate MIME / extension
    $origName = basename($file['name']);
    if (!str_ends_with(strtolower($origName), '.zip')) {
        adiwira_redirect_with_flash($selfUrl, 'error', 'Hanya file .zip yang didukung.');
    }

    $zipPath = $file['tmp_name'];

    // Open zip
    $zip = new ZipArchive();
    $open = $zip->open($zipPath);
    if ($open !== true) {
        adiwira_redirect_with_flash($selfUrl, 'error', 'Gagal membuka file ZIP (kode: ' . $open . ').');
    }

    // Read plugin.json from zip to validate
    $pluginJsonRaw = $zip->getFromName('plugin.json');
    if ($pluginJsonRaw === false) {
        $zip->close();
        adiwira_redirect_with_flash($selfUrl, 'error', 'plugin.json tidak ditemukan di dalam ZIP.');
    }

    $manifest = json_decode($pluginJsonRaw, true);
    if (!is_array($manifest) || empty($manifest['name'])) {
        $zip->close();
        adiwira_redirect_with_flash($selfUrl, 'error', 'plugin.json tidak valid atau field "name" tidak ditemukan.');
    }

    $pluginName = $manifest['name'];

    // Sanitize plugin name: only alphanumeric, dash, underscore
    if (!preg_match('/^[a-zA-Z0-9_-]+$/', $pluginName)) {
        $zip->close();
        adiwira_redirect_with_flash($selfUrl, 'error', 'Nama plugin hanya boleh huruf, angka, dash dan underscore.');
    }

    // Check if already exists
    $pluginDir = PLUGIN_PATH . '/' . $pluginName;
    if (is_dir($pluginDir)) {
        $zip->close();
        adiwira_redirect_with_flash($selfUrl, 'error', 'Plugin "' . htmlspecialchars($pluginName) . '" sudah ada. Hapus atau rename dulu.');
    }

    // Extract to temp dir first
    $tmpExtract = sys_get_temp_dir() . '/plugin-extract-' . bin2hex(random_bytes(8));
    if (!mkdir($tmpExtract, 0755, true)) {
        $zip->close();
        adiwira_redirect_with_flash($selfUrl, 'error', 'Gagal membuat temporary directory.');
    }

    $extracted = $zip->extractTo($tmpExtract);
    $zip->close();

    if (!$extracted) {
        _rmdir_recursive($tmpExtract);
        adiwira_redirect_with_flash($selfUrl, 'error', 'Gagal mengekstrak file.');
    }

    // Ensure plugin.json exists in extracted root
    if (!is_file($tmpExtract . '/plugin.json')) {
        _rmdir_recursive($tmpExtract);
        adiwira_redirect_with_flash($selfUrl, 'error', 'plugin.json tidak ditemukan di root ZIP.');
    }

    // Validate extracted manifest matches
    $extractedManifest = json_decode(file_get_contents($tmpExtract . '/plugin.json'), true);
    if (!is_array($extractedManifest) || ($extractedManifest['name'] ?? '') !== $pluginName) {
        _rmdir_recursive($tmpExtract);
        adiwira_redirect_with_flash($selfUrl, 'error', 'plugin.json setelah ekstrak tidak valid.');
    }

    // Move to plugins/{name}/
    if (!rename($tmpExtract, $pluginDir)) {
        _rmdir_recursive($tmpExtract);
        adiwira_redirect_with_flash($selfUrl, 'error', 'Gagal memindahkan plugin ke ' . htmlspecialchars($pluginDir) . '.');
    }

    $flashMessages = [];
    $flashType = 'success';

    // --- Copy static files ---
    $staticCopy = $extractedManifest['static']['copy'] ?? [];
    if (!empty($staticCopy) && is_array($staticCopy)) {
        $publicPath = dirname(PLUGIN_PATH) . '/public';
        $copied = 0;
        $failed = 0;
        foreach ($staticCopy as $entry) {
            $from = $entry['from'] ?? '';
            $to = $entry['to'] ?? '';
            if ($from === '' || $to === '') continue;

            $source = $pluginDir . '/' . ltrim($from, '/');
            $dest = $publicPath . '/' . ltrim($to, '/');

            if (!is_file($source)) {
                $failed++;
                continue;
            }

            $destDir = dirname($dest);
            if (!is_dir($destDir)) {
                @mkdir($destDir, 0755, true);
            }

            if (@copy($source, $dest)) {
                $copied++;
            } else {
                $failed++;
            }
        }
        if ($copied > 0) {
            $flashMessages[] = $copied . ' file static disalin.';
        }
        if ($failed > 0) {
            $flashMessages[] = $failed . ' file static gagal disalin.';
        }
    }

    // --- Run install.sh if present ---
    $installScript = $pluginDir . '/install.sh';
    if (is_file($installScript) && is_executable($installScript)) {
        $outputLines = [];
        $returnCode = -1;
        exec('bash ' . escapeshellarg($installScript) . ' 2>&1', $outputLines, $returnCode);
        if ($returnCode === 0) {
            $flashMessages[] = 'install.sh berhasil dijalankan.';
        } else {
            $flashType = 'warning';
            $flashMessages[] = 'install.sh selesai dengan kode ' . $returnCode . '. Output: ' . implode("\n", array_slice($outputLines, -5));
        }
    } elseif (is_file($installScript)) {
        $flashMessages[] = 'install.sh ditemukan tapi tidak executable. Jalankan manual: chmod +x ' . htmlspecialchars($pluginDir . '/install.sh');
    }

    // --- Enable plugin ---
    if (function_exists('plugin_enable')) {
        plugin_enable($pluginName);
        $flashMessages[] = 'Plugin "' . htmlspecialchars($manifest['title'] ?? $pluginName) . '" diaktifkan.';
    }

    $finalMsg = implode(' ', $flashMessages);
    adiwira_redirect_with_flash($listUrl, $flashType, $finalMsg);
}

?>
<h2 class="pg-title">Upload Plugin</h2>
<p class="pg-subtitle">Upload file <code>.zip</code> plugin yang valid (wajib memiliki <code>plugin.json</code> di root).</p>

<?php if ($error): ?>
<div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" class="upload-form">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">

    <div class="drop-zone" id="dropZone">
        <div class="drop-zone-icon">
            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                <polyline points="17 8 12 3 7 8"/>
                <line x1="12" y1="3" x2="12" y2="15"/>
            </svg>
        </div>
        <p class="drop-zone-text">Seret file .zip ke sini atau klik untuk pilih</p>
        <p class="drop-zone-hint">Maksimal 50MB. Plugin harus memiliki <code>plugin.json</code>.</p>
        <input type="file" name="plugin_zip" id="fileInput" accept=".zip" class="file-input" required>
    </div>

    <div id="fileInfo" class="file-info" style="display:none">
        <span id="fileName"></span>
        <button type="button" id="removeFile" class="btn btn-sm btn-outline">Hapus</button>
    </div>

    <div class="form-actions">
        <a href="<?= htmlspecialchars($listUrl) ?>" class="btn btn-outline">Kembali</a>
        <button type="submit" class="btn btn-primary" id="submitBtn">Upload & Install</button>
    </div>
</form>

<style>
.pg-title { font-size:1.4rem; font-weight:700; margin:0 0 .25rem; }
.pg-subtitle { color:#6b7280; font-size:.9rem; margin:0 0 1.5rem; }
.alert { padding:.75rem 1rem; border-radius:6px; font-size:.875rem; margin-bottom:1rem; }
.alert-error { background:#fef2f2; color:#991b1b; border:1px solid #fecaca; }

.upload-form { max-width:480px; }
.drop-zone { border:2px dashed #d1d5db; border-radius:12px; padding:2rem; text-align:center; cursor:pointer; transition:border-color .2s, background .2s; background:#fafafa; position:relative; }
.drop-zone:hover { border-color:#2563eb; background:#f0f5ff; }
.drop-zone.dragover { border-color:#2563eb; background:#eff6ff; }
.drop-zone-icon { color:#9ca3af; margin-bottom:.5rem; }
.drop-zone.dragover .drop-zone-icon { color:#2563eb; }
.drop-zone-text { font-size:.95rem; color:#374151; margin:0 0 .25rem; }
.drop-zone-hint { font-size:.8rem; color:#9ca3af; margin:0; }
.drop-zone-hint code { background:#f3f4f6; padding:.1rem .3rem; border-radius:3px; font-size:.78rem; }
.file-input { position:absolute; inset:0; opacity:0; cursor:pointer; }

.file-info { display:flex; align-items:center; gap:.5rem; margin-top:.75rem; padding:.5rem .75rem; background:#f9fafb; border:1px solid #e5e7eb; border-radius:6px; font-size:.875rem; }
.file-info span { flex:1; color:#374151; }
.btn { display:inline-flex; align-items:center; gap:.35rem; padding:.4rem .75rem; font-size:.8rem; font-weight:500; border-radius:6px; cursor:pointer; border:1px solid transparent; font-family:inherit; line-height:1; text-decoration:none; }
.btn-sm { padding:.3rem .6rem; font-size:.75rem; }
.btn-primary { background:#2563eb; color:#fff; border-color:#2563eb; }
.btn-primary:hover { background:#1d4ed8; }
.btn-primary:disabled { opacity:.5; cursor:not-allowed; }
.btn-outline { background:transparent; color:#6b7280; border-color:#d1d5db; }
.btn-outline:hover { background:#f3f4f6; color:#374151; }
.form-actions { display:flex; gap:.5rem; margin-top:1.25rem; justify-content:flex-start; }
</style>

<script>
(function(){
    const dropZone = document.getElementById('dropZone');
    const fileInput = document.getElementById('fileInput');
    const fileInfo = document.getElementById('fileInfo');
    const fileName = document.getElementById('fileName');
    const removeBtn = document.getElementById('removeFile');
    const submitBtn = document.getElementById('submitBtn');

    function showFile(name) {
        fileInfo.style.display = 'flex';
        fileName.textContent = name;
        submitBtn.disabled = false;
    }

    function hideFile() {
        fileInfo.style.display = 'none';
        fileName.textContent = '';
        fileInput.value = '';
        submitBtn.disabled = true;
    }

    fileInput.addEventListener('change', function() {
        if (this.files && this.files[0]) {
            showFile(this.files[0].name);
        } else {
            hideFile();
        }
    });

    removeBtn.addEventListener('click', function(e) {
        e.preventDefault();
        hideFile();
    });

    dropZone.addEventListener('dragover', function(e) {
        e.preventDefault();
        this.classList.add('dragover');
    });

    dropZone.addEventListener('dragleave', function(e) {
        e.preventDefault();
        this.classList.remove('dragover');
    });

    dropZone.addEventListener('drop', function(e) {
        e.preventDefault();
        this.classList.remove('dragover');
        if (e.dataTransfer.files && e.dataTransfer.files[0]) {
            fileInput.files = e.dataTransfer.files;
            showFile(e.dataTransfer.files[0].name);
        }
    });

    // Form submit loading state
    document.querySelector('.upload-form').addEventListener('submit', function() {
        submitBtn.disabled = true;
        submitBtn.textContent = 'Mengupload...';
    });

    hideFile();
})();
</script>
