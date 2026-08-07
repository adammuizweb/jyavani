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
$selfUrl = $base . '/?page=admin/plugins/upload';
$listUrl = $base . '/?page=admin/plugins/index';

$maxSize = 50 * 1024 * 1024; // 50MB
$error = '';
$success = '';

// --- Handle upload ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['plugin_zip'])) {
    $installAction = (string)($_POST['action'] ?? 'upload');
    if (!in_array($installAction, ['upload', 'upload_activate'], true)) {
        adiwira_redirect_with_flash($selfUrl, 'error', __('Invalid action.'));
    }

    $csrf = (string)($_POST['csrf_token'] ?? '');
    if (!csrf_check($csrf)) {
        adiwira_redirect_with_flash($selfUrl, 'error', __('Invalid CSRF token.'));
    }

    $file = $_FILES['plugin_zip'];

    // Validate upload
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $uploadErrors = [
            UPLOAD_ERR_INI_SIZE   => __('File exceeds upload_max_filesize in php.ini.'),
            UPLOAD_ERR_FORM_SIZE  => __('File exceeds the specified MAX_FILE_SIZE.'),
            UPLOAD_ERR_PARTIAL    => __('File was only partially uploaded.'),
            UPLOAD_ERR_NO_FILE    => __('No file was selected.'),
            UPLOAD_ERR_NO_TMP_DIR => __('Temporary folder not found.'),
            UPLOAD_ERR_CANT_WRITE => __('Failed to write file to disk.'),
        ];
        $msg = $uploadErrors[$file['error']] ?? 'Unknown upload error.';
        adiwira_redirect_with_flash($selfUrl, 'error', $msg);
    }

    if ($file['size'] > $maxSize) {
        adiwira_redirect_with_flash($selfUrl, 'error', __('File is too large. Maximum 50MB.'));
    }

    // Validate MIME / extension
    $origName = basename($file['name']);
    if (!str_ends_with(strtolower($origName), '.zip')) {
        adiwira_redirect_with_flash($selfUrl, 'error', __('Only .zip files are supported.'));
    }

    $zipPath = $file['tmp_name'];

    // Open zip
    $zip = new ZipArchive();
    $open = $zip->open($zipPath);
    if ($open !== true) {
        adiwira_redirect_with_flash($selfUrl, 'error', __('Failed to open ZIP file') . ' (code: ' . $open . ').');
    }

    // Read plugin.json from zip to validate
    $pluginJsonRaw = $zip->getFromName('plugin.json');
    if ($pluginJsonRaw === false) {
        $zip->close();
        adiwira_redirect_with_flash($selfUrl, 'error', __('plugin.json tidak ditemukan di dalam ZIP.'));
    }

    $manifest = json_decode($pluginJsonRaw, true);
    if (!is_array($manifest) || empty($manifest['name'])) {
        $zip->close();
        adiwira_redirect_with_flash($selfUrl, 'error', __('plugin.json tidak valid atau field "name" tidak ditemukan.'));
    }

    $pluginName = $manifest['name'];

    // Sanitize plugin name: only alphanumeric, dash, underscore
    if (!preg_match('/^[a-zA-Z0-9_-]+$/', $pluginName)) {
        $zip->close();
        adiwira_redirect_with_flash($selfUrl, 'error', __('Plugin name can only contain letters, numbers, dashes and underscores.'));
    }

    // Check if already exists
    $pluginDir = PLUGIN_PATH . '/' . $pluginName;

    // If a leftover/corrupt plugin directory exists without a valid manifest, remove it
    // so the uploaded plugin can replace it.
    if (is_dir($pluginDir) && !plugin_manifest($pluginName)) {
        _rmdir_recursive($pluginDir);
    }

    if (is_dir($pluginDir)) {
        $zip->close();
        adiwira_redirect_with_flash($selfUrl, 'error', __('Plugin already exists. Delete or rename first.') . ' "' . htmlspecialchars($pluginName) . '"');
    }

    // Extract to temp dir first
    $tmpExtract = PLUGIN_PATH . '/.extract-' . bin2hex(random_bytes(8));
    if (!mkdir($tmpExtract, 0755, true)) {
        $zip->close();
        adiwira_redirect_with_flash($selfUrl, 'error', __('Failed to create temporary directory.'));
    }

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entry = (string)($zip->statIndex($i)['name'] ?? '');
        if (str_ends_with($entry, '/')) continue;
        if (plugin_safe_path($tmpExtract, $entry) === null) {
            $zip->close();
            _rmdir_recursive($tmpExtract);
            adiwira_redirect_with_flash($selfUrl, 'error', __('ZIP contains an invalid file path.'));
        }
    }

    $extracted = $zip->extractTo($tmpExtract);
    $zip->close();

    if (!$extracted) {
        _rmdir_recursive($tmpExtract);
        adiwira_redirect_with_flash($selfUrl, 'error', __('Failed to extract file.'));
    }

    // Ensure plugin.json exists in extracted root
    if (!is_file($tmpExtract . '/plugin.json')) {
        _rmdir_recursive($tmpExtract);
        adiwira_redirect_with_flash($selfUrl, 'error', __('plugin.json tidak ditemukan di root ZIP.'));
    }

    // Validate extracted manifest matches
    $extractedManifest = json_decode(file_get_contents($tmpExtract . '/plugin.json'), true);
    if (!is_array($extractedManifest) || ($extractedManifest['name'] ?? '') !== $pluginName) {
        _rmdir_recursive($tmpExtract);
        adiwira_redirect_with_flash($selfUrl, 'error', __('plugin.json setelah ekstrak tidak valid.'));
    }

    // Move to plugins/{name}/
    if (!rename($tmpExtract, $pluginDir)) {
        _rmdir_recursive($tmpExtract);
        adiwira_redirect_with_flash($selfUrl, 'error', __('Failed to move plugin to') . ' ' . htmlspecialchars($pluginDir) . '.');
    }

    // Set permissions so www-data (PHP-FPM) can manage plugin files
    $chmodIt = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($pluginDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($chmodIt as $item) {
        if ($item->isDir()) {
            @chmod($item->getPathname(), 0775);
        } else {
            $ext = pathinfo($item->getFilename(), PATHINFO_EXTENSION);
            $mode = ($ext === 'sh') ? 0775 : 0664;
            @chmod($item->getPathname(), $mode);
        }
    }
    // Set group ownership when supported (some shared hosts disable chgrp/shell_exec).
    // The chmod loop above already makes files writable by the PHP process owner.
    if (function_exists('chgrp') && function_exists('posix_getegid')) {
        @chgrp($pluginDir, 'www-data');
    }
    if (function_exists('shell_exec')) {
        @shell_exec('chgrp -R www-data ' . escapeshellarg($pluginDir) . ' 2>&1');
    }

    $flashMessages = [];
    $flashType = 'success';

    // --- Copy static files ---
    $staticCopy = $extractedManifest['static']['copy'] ?? [];
    if (!empty($staticCopy) && is_array($staticCopy)) {
        $publicPath = defined('PUBLIC_PATH') ? PUBLIC_PATH : (dirname(PLUGIN_PATH) . '/public');
        $copied = 0;
        $failed = 0;
        foreach ($staticCopy as $entry) {
            $from = $entry['from'] ?? '';
            $to = $entry['to'] ?? '';
            if ($from === '' || $to === '') continue;

            $source = plugin_safe_path($pluginDir, $from);
            $dest = plugin_static_path($pluginName, $to);

            if (!$source || !$dest || !is_file($source) || is_link($source)) {
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
            $flashMessages[] = $copied . ' ' . __('static files copied.');
        }
        if ($failed > 0) {
            $flashMessages[] = $failed . ' ' . __('static files failed to copy.');
        }
    }

    // --- Enable / disable plugin ---
    if ($installAction === 'upload_activate' && function_exists('plugin_enable')) {
        plugin_enable($pluginName);
        $flashMessages[] = __('Plugin activated.') . ' "' . htmlspecialchars($manifest['title'] ?? $pluginName) . '"';
    } elseif (function_exists('plugin_disable')) {
        plugin_disable($pluginName);
    }

    $finalMsg = implode(' ', $flashMessages);
    adiwira_redirect_with_flash($listUrl, $flashType, $finalMsg);
}

?>
<h2 class="pg-title">Upload Plugin</h2>
<p class="pg-subtitle"><?=_e('Upload a valid plugin')?> <code>.zip</code> <?=_e('file (must have')?> <code>plugin.json</code> <?=_e('in root).')?></p>

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
        <p class="drop-zone-text"><?=_e('Drag .zip file here or click to select')?></p>
        <p class="drop-zone-hint"><?=_e('Maximum 50MB. Plugin must have')?> <code>plugin.json</code>.</p>
        <input type="file" name="plugin_zip" id="fileInput" accept=".zip" class="file-input" required>
    </div>

    <div id="fileInfo" class="file-info" style="display:none">
        <span id="fileName"></span>
        <button type="button" id="removeFile" class="btn btn-sm btn-outline"><?=_e('Delete')?></button>
    </div>

    <div class="form-actions">
        <a href="<?= htmlspecialchars($listUrl) ?>" class="btn btn-outline"><?=_e('Back')?></a>
        <input type="hidden" name="action" id="uploadAction" value="upload">
        <button type="submit" class="btn btn-outline" onclick="document.getElementById('uploadAction').value='upload'"><?=_e('Upload Plugin')?></button>
        <button type="submit" class="btn btn-primary" onclick="document.getElementById('uploadAction').value='upload_activate'"><?=_e('Upload & Activate')?></button>
    </div>
</form>

<style>
.pg-title { font-size:1.4rem; font-weight:700; margin:0 0 .25rem; color:var(--adam-text); }
.pg-subtitle { color:var(--adam-muted); font-size:.9rem; margin:0 0 1.5rem; }
.alert { padding:.75rem 1rem; border-radius:6px; font-size:.875rem; margin-bottom:1rem; }
.alert-error { background:#fef2f2; color:#991b1b; border:1px solid #fecaca; }

.upload-form { max-width:480px; }
.drop-zone { border:2px dashed var(--adam-border-2); border-radius:12px; padding:2rem; text-align:center; cursor:pointer; transition:border-color .2s, background .2s; background:var(--adam-surface-4); position:relative; }
.drop-zone:hover { border-color:var(--adam-primary); background:var(--adam-primary-soft); }
.drop-zone.dragover { border-color:var(--adam-primary); background:var(--adam-primary-soft); }
.drop-zone-icon { color:var(--adam-muted-2); margin-bottom:.5rem; }
.drop-zone.dragover .drop-zone-icon { color:var(--adam-primary); }
.drop-zone-text { font-size:.95rem; color:var(--adam-text-2); margin:0 0 .25rem; }
.drop-zone-hint { font-size:.8rem; color:var(--adam-muted-2); margin:0; }
.drop-zone-hint code { background:var(--adam-surface-3); padding:.1rem .3rem; border-radius:3px; font-size:.78rem; }
.file-input { position:absolute; inset:0; opacity:0; cursor:pointer; }

.file-info { display:flex; align-items:center; gap:.5rem; margin-top:.75rem; padding:.5rem .75rem; background:var(--adam-surface-3); border:1px solid var(--adam-border); border-radius:6px; font-size:.875rem; }
.file-info span { flex:1; color:var(--adam-text-2); }
.btn { display:inline-flex; align-items:center; gap:.35rem; padding:.4rem .75rem; font-size:.8rem; font-weight:500; border-radius:6px; cursor:pointer; border:1px solid transparent; font-family:inherit; line-height:1; text-decoration:none; }
.btn-sm { padding:.3rem .6rem; font-size:.75rem; }
.btn-primary { background:var(--adam-primary); color:#fff; border-color:var(--adam-primary); }
.btn-primary:hover { background:var(--adam-primary-600); }
.btn-primary:disabled { opacity:.5; cursor:not-allowed; }
.btn-outline { background:transparent; color:var(--adam-muted); border-color:var(--adam-border-2); }
.btn-outline:hover { background:var(--adam-surface-3); color:var(--adam-text); }
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
        submitBtn.textContent = '<?=__('Uploading...')?>';
    });

    hideFile();
})();
</script>
