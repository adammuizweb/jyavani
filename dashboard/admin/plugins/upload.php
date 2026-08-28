<?php
declare(strict_types=1);
// Plugin Uploader — Upload plugin ZIP, extract, install static files & enable
require_once DASH_PATH . '/admin/_deny.php';
if (!defined('DASHBOARD_CONTEXT') && !defined('ADAM_THEME')) adiwira_admin_404();
require_once DASH_PATH . '/admin/_guard.php';
require_once DASH_PATH . '/admin/_notify.php';

[$uid] = adiwira_require_permission($pdo, 'core.plugins.manage', false);
adiwira_require_site_owner($pdo, false);

if (!function_exists('_rmdir_recursive')) {
    function _rmdir_recursive(string $dir): bool {
        if (!is_dir($dir)) return true;
        $it = new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS);
        foreach (new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            $path = $file->getPathname();
            @chmod($path, $file->isDir() ? 0777 : 0666);
            if ($file->isLink() || !$file->isDir()) {
                @unlink($path);
            } else {
                @rmdir($path);
            }
        }
        @chmod($dir, 0777);
        if (@rmdir($dir) || !is_dir($dir)) return true;

        if (function_exists('exec')) {
            exec('rm -rf ' . escapeshellarg($dir) . ' 2>&1', $output, $code);
        }
        return !is_dir($dir);
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
    $activatePlugin = $installAction === 'upload_activate';

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
    $prepared = plugin_prepare_package_stage($zipPath, null, $activatePlugin);
    if (!($prepared['success'] ?? false)) adiwira_redirect_with_flash($selfUrl, 'error', (string)$prepared['error']);
    $pluginName = (string)$prepared['name'];
    $manifest = $prepared['manifest'];

    $installLocks = plugin_lifecycle_locks($pluginName);
    if ($installLocks === null) {
        package_remove_tree((string)$prepared['stage']);
        adiwira_redirect_with_flash($selfUrl, 'error', plugin_last_error() ?: __('Unable to lock plugin lifecycle operation.'));
    }
    register_shutdown_function(static function () use (&$installLocks): void {
        if (is_array($installLocks) && $installLocks !== []) theme_operation_release($installLocks);
    });

    $pluginDir = PLUGIN_PATH . '/' . $pluginName;
    if (file_exists($pluginDir) || is_link($pluginDir)) {
        package_remove_tree((string)$prepared['stage']);
        adiwira_redirect_with_flash($selfUrl, 'error', __('Plugin already exists. Delete or rename first.') . ' "' . htmlspecialchars($pluginName) . '"');
    }
    $result = plugin_publish_staged_install_already_locked($prepared, $activatePlugin, $pdo);
    if (!($result['success'] ?? false)) adiwira_redirect_with_flash($selfUrl, 'error', (string)$result['error']);
    $finalMsg = $activatePlugin ? __('Plugin uploaded and activated.') : __('Plugin uploaded and kept inactive.');
    theme_operation_release($installLocks);
    $installLocks = [];
    adiwira_redirect_with_flash($listUrl, 'success', $finalMsg);
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
