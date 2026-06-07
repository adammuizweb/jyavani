<?php
declare(strict_types=1);
// CMS Update Manager — Check for updates, apply update packages
require_once DASH_PATH . '/admin/_deny.php';
if (!defined('DASHBOARD_CONTEXT') && !defined('ADAM_THEME')) adiwira_admin_404();
require_once DASH_PATH . '/admin/_guard.php';
require_once DASH_PATH . '/admin/_notify.php';

[$uid, $role] = adiwira_require_role($pdo, ['admin'], false);

// Load shared helpers
require_once __DIR__ . '/_update_helpers.php';

// AJAX: read progress (called via GET, returns JSON without page layout)
if (isset($_GET['action']) && $_GET['action'] === 'cms_read_progress') {
    session_write_close();
    $token = (string)($_GET['token'] ?? '');
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
        adiwira_json(['percentage' => 0, 'status' => 'Invalid token', 'done' => true, 'error' => 'Invalid token']);
    }
    $p = _cms_read_progress($token);
    adiwira_json($p ?: ['percentage' => 0, 'status' => __('Preparing…'), 'done' => false, 'error' => null]);
}

$base = ADMIN_BASE_PATH;
$selfUrl = $base . '/?page=admin/update/index';

// Dev instance detection
$defaultUpdateUrl = 'https://jyavani.com/download/latest/';
$localHost = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
$updateHost = parse_url($defaultUpdateUrl, PHP_URL_HOST);
$isDevSelfCheck = $localHost !== '' && strcasecmp($updateHost ?: '', $localHost) === 0;

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
            adiwira_redirect_with_flash($selfUrl, 'error', __('Update URL cannot be empty.'));
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
            adiwira_redirect_with_flash($selfUrl, 'error', __('Failed to fetch update info from URL:') . ' ' . htmlspecialchars($inputUrl));
        }

        $remote = json_decode($remoteJson, true);
        if (!is_array($remote) || !isset($remote['version'])) {
            adiwira_redirect_with_flash($selfUrl, 'error', __('Invalid remote response format (expected: version).'));
        }

        $localVer = $currentVersion['version'] ?? '0.0.0';
        $remoteVer = $remote['version'] ?? '0.0.0';

        if (version_compare($remoteVer, $localVer, '>')) {
            ensure_session_started(true);
            $_SESSION['cms_update_remote'] = $remote;
            adiwira_redirect_with_flash($selfUrl, 'success',
                __('Update available:') . ' v' . htmlspecialchars($localVer) . ' → v' . htmlspecialchars($remoteVer) . '. '
                . __('Total') . ' ' . ($remote['total_files'] ?? 0) . ' ' . __('files. Click "Apply Update" to start.'));
        } else {
            adiwira_redirect_with_flash($selfUrl, 'info',
                __('CMS is already the latest version') . ' (v' . htmlspecialchars($localVer) . ').');
        }
    }

    // --- Apply update from remote (downloaded and stored in session) ---
    if ($action === 'apply_update') {
        adiwira_redirect_with_flash($selfUrl, 'error', __('Use the new AJAX-based update flow.'));
    }

    // --- Upload update package ---
    if ($action === 'upload_update') {
        if (!isset($_FILES['update_package']) || $_FILES['update_package']['error'] !== UPLOAD_ERR_OK) {
            adiwira_redirect_with_flash($selfUrl, 'error', __('Invalid update file.'));
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
            adiwira_redirect_with_flash($selfUrl, 'error', __('Failed to open ZIP file.'));
        }

        $manifestJson = $zip->getFromName('cms-manifest.json');
        $versionJson = $zip->getFromName('version.json');

        if ($manifestJson === false) {
            $zip->close();
            adiwira_redirect_with_flash($selfUrl, 'error', __('cms-manifest.json not found in the update package.'));
        }

        $remoteManifest = json_decode($manifestJson, true);
        $remoteVersion = $versionJson ? json_decode($versionJson, true) : null;

        if (!is_array($remoteManifest) || !isset($remoteManifest['version'])) {
            $zip->close();
            adiwira_redirect_with_flash($selfUrl, 'error', __('Invalid cms-manifest.json format.'));
        }

        $zip->close();

        $localVer = $currentVersion['version'] ?? '0.0.0';
        $remoteVer = $remoteManifest['version'] ?? '0.0.0';

        if (!version_compare($remoteVer, $localVer, '>')) {
            adiwira_redirect_with_flash($selfUrl, 'warning',
                __('Package version') . ' (v' . htmlspecialchars($remoteVer) . ') ' . __('is not newer than current version') . ' (v' . htmlspecialchars($localVer) . ').');
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
        adiwira_redirect_with_flash($selfUrl, 'error', __('Use the new AJAX-based update flow.'));
    }

    // --- Clear pending update ---
    if ($action === 'clear_pending') {
        ensure_session_started(true);
        unset($_SESSION['cms_update_remote'], $_SESSION['cms_update_base_url'], $_SESSION['cms_update_package'], $_SESSION['cms_update_remote_url']);
        adiwira_redirect_with_flash($selfUrl, 'info', __('Pending update cancelled.'));
    }

    // --- Reinstall CMS (force overwrite same version) ---
    if ($action === 'reinstall') {
        $url = trim((string)($_POST['reinstall_url'] ?? ''));
        if ($url === '') {
            adiwira_redirect_with_flash($selfUrl, 'error', __('Reinstall URL cannot be empty.'));
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
            adiwira_redirect_with_flash($selfUrl, 'error', __('Failed to download reinstall package.'));
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
            $msg = __('Reinstall complete!') . ' ' . $result['message'];
            if (!empty($resetMessages)) {
                $msg .= ' ' . implode(' ', $resetMessages);
            }
            adiwira_redirect_with_flash($base . '/?page=admin/update/index', 'success', $msg);
        } else {
            adiwira_redirect_with_flash($selfUrl, 'error', $result['message']);
        }
    }

    adiwira_redirect_with_flash($selfUrl, 'error', __('Unknown action.'));
}

// --- Helper: hard reset DB settings ---
function _reinstall_hard_reset(PDO $pdo): array {
    $msgs = [];

    // 1. Reset active theme in settings
    $pdo->exec("UPDATE settings SET value = 'default' WHERE key = 'active_theme'");
    $msgs[] = __('Theme reset to default.');

    // 2. Reset theme active flag + slot assignments
    $pdo->exec("UPDATE themes SET is_active = 0");
    $pdo->exec("UPDATE themes SET is_active = 1 WHERE id = 1 OR folder_name = 'default'");
    $pdo->exec("DELETE FROM assignments");
    $pdo->exec("INSERT INTO assignments (slot_key, theme_id, theme_file) VALUES
        ('header', 1, 'header.php'),
        ('footer', 1, 'footer.php'),
        ('sidebar', 1, 'sidebar.php'),
        ('main.homepage', 1, 'main/homepage.php')");
    $msgs[] = __('Slot assignments reset.');

    // 3. Reset sidebar zone items
    $pdo->exec("DELETE FROM sidebar_zone_items");
    $zoneId = $pdo->query("SELECT id FROM sidebar_zones WHERE slug = 'main'")->fetchColumn();
    if ($zoneId) {
        $pdo->prepare("INSERT IGNORE INTO sidebar_zone_items (zone_id, type, title, config, ordering, active) VALUES
            (?, 'search', 'Search', '{\"title\":\"Search\",\"placeholder\":\"Search articles...\"}', 0, 1),
            (?, 'last_posts', 'Recent Articles', '{\"title\":\"Recent Articles\",\"limit\":5,\"type\":\"article\"}', 1, 1),
            (?, 'categories', 'Categories', '{\"title\":\"Categories\",\"limit\":30,\"only_parents\":true}', 2, 1)")->execute([$zoneId, $zoneId, $zoneId]);
        $msgs[] = __('Sidebar items reset.');
    }

    // 4. Reset menu items
    $menuId = $pdo->query("SELECT id FROM menus WHERE slug = 'primary'")->fetchColumn();
    if ($menuId) {
        $pdo->exec("DELETE FROM menu_items WHERE menu_id = " . (int)$menuId);
        $pdo->prepare("INSERT INTO menu_items (menu_id, parent_id, sort_order, type, label, url, target_id, target_blank) VALUES
            (?, NULL, 0, 'custom', 'Home', '/', NULL, 0),
            (?, NULL, 1, 'category', 'Blog', NULL, 1, 0),
            (?, NULL, 2, 'category', 'Services', NULL, 2, 0)")->execute([$menuId, $menuId, $menuId]);
        $msgs[] = __('Menu items reset.');
    }

    // 5. Reset auth paths to defaults
    $pdo->exec("REPLACE INTO settings (`key`, `value`, `autoload`) VALUES
        ('admin_path', 'dashboard', 1),
        ('login_path', 'login', 1),
        ('register_path', 'register', 1)");
    $msgs[] = __('Auth paths reset (admin: /dashboard/, login: /login/).');

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
        $msgs[] = count($pluginNames) . ' ' . __('plugins disabled') . '.';
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


// Compute file stats
$totalCore = $localManifest['total_files'] ?? 0;
?>
<h2 class="pg-title"><?=_e('CMS Update')?></h2>
<p class="pg-subtitle"><?=_e('Version')?> <?= htmlspecialchars($currentVersion['version'] ?? '—') ?> &mdash; <?= htmlspecialchars($currentVersion['build'] ?? '') ?></p>

<?php if ($isDevSelfCheck): ?>
<div class="up-dev-notice"><?=__('This appears to be a development instance. The update URL points to this same server. Build and publish updates from here, then deploy to production.')?></div>
<?php endif; ?>

<div class="up-grid">
    <div class="up-card">
        <div class="up-card-header"><?=_e('Current Installation')?></div>
        <table class="up-table">
            <tr><td><?=_e('CMS')?></td><td><strong><?= htmlspecialchars($currentVersion['name'] ?? 'Jyavani CMS') ?></strong></td></tr>
            <tr><td><?=_e('Version')?></td><td><strong>v<?= htmlspecialchars($currentVersion['version'] ?? '0.0.0') ?></strong></td></tr>
            <tr><td><?=_e('Build')?></td><td><?= htmlspecialchars($currentVersion['build'] ?? '—') ?></td></tr>
            <tr><td><?=_e('Core files')?></td><td><?= $totalCore ?> <?=_e('files tracked')?></td></tr>
            <tr><td><?=_e('PHP')?></td><td><?= htmlspecialchars($currentVersion['php_required'] ?? '8.1') ?>+ (server: <?= PHP_VERSION ?>)</td></tr>
            <tr><td><?=_e('MySQL')?></td><td><?= htmlspecialchars($currentVersion['mysql_required'] ?? '5.7') ?>+</td></tr>
        </table>
    </div>

    <div class="up-card">
            <div class="up-card-header"><?=_e('Check for Updates')?></div>
        <form method="post" class="up-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="action" value="check_remote">

            <label class="up-label"><?=_e('Update URL')?></label>
            <input type="url" name="update_url" class="up-input"
                   value="<?= htmlspecialchars($defaultUpdateUrl) ?>"
                   placeholder="https://example.com/download/latest/">

            <div class="up-hint"><?=_e('URL to the latest version download endpoint. Automatically appended with')?> <code>?format=json</code> <?=_e('for version checking.')?></div>

            <button type="submit" class="btn btn-primary"><?=_e('Check for Updates')?></button>
        </form>
    </div>
</div>

<?php if ($pendingUpdate): ?>
<div class="up-card up-card-warning" id="pendingUpdateCard">
    <div class="up-card-header"><?=_e('Update Ready')?></div>
    <p><?=_e('Package:')?> <strong>v<?= htmlspecialchars($pendingUpdate['version'] ?? '?') ?></strong>
       &mdash; <?= ($pendingUpdate['total_files'] ?? 0) ?> <?=_e('file')?>
       &mdash; <?=_e('Source:')?> <?= htmlspecialchars($pendingUrl ?: __('uploaded')) ?></p>

    <div class="up-flex">
        <button type="button" class="btn btn-primary" style="background:#059669;border-color:#059669" id="cmsApplyUpdateBtn"><?=_e('Apply Update')?></button>
        <form method="post" style="display:inline">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="action" value="clear_pending">
            <button type="submit" class="btn btn-outline"><?=_e('Cancel')?></button>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="up-card" style="margin-top:1.25rem">
    <div class="up-card-header"><?=_e('Manual Upload')?></div>
    <p class="up-hint"><?=_e('Upload a')?> <code>.zip</code> <?=_e('update package containing')?> <code>cms-manifest.json</code> <?=_e('in its root.')?></p>

    <form method="post" enctype="multipart/form-data" class="up-form">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
        <input type="hidden" name="action" value="upload_update">

        <div class="up-file-row">
            <input type="file" name="update_package" accept=".zip" required id="upFile">
            <button type="submit" class="btn btn-primary" id="upBtn"><?=_e('Upload &amp; Install')?></button>
        </div>
    </form>
</div>

<div class="up-card" style="margin-top:1.25rem;border-color:var(--adam-danger)">
    <div class="up-card-header" style="color:var(--adam-danger)"><?=_e('Reinstall CMS')?></div>
    <p class="up-hint"><?=_e('Overwrite all core CMS files with original versions. Suitable if files are corrupted. Data (cfg/.env, themes, plugins, uploads) remain safe.')?></p>

    <form method="post" class="up-form" onsubmit="return confirmReinstall(event)">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
        <input type="hidden" name="action" value="reinstall">
        <label class="up-label"><?=_e('Download URL')?></label>
        <input type="url" name="reinstall_url" class="up-input"
               value="<?= htmlspecialchars($defaultUpdateUrl) ?>"
               placeholder="https://example.com/download/latest/">
        <label class="up-checkline">
            <input type="checkbox" name="hard_reset" value="1" id="chkHard">
            <?=_e('Hard reset')?> &mdash; <?=_e('reset theme, auth paths, plugins, slots, sidebar, and menus to defaults')?>
        </label>
        <div class="up-hint" id="hintHard" style="display:none;margin-top:-.3rem;margin-bottom:.3rem;border-left:3px solid var(--adam-danger);padding-left:.6rem">
            <?=__('Theme → default, Auth paths → /dashboard/ /login/ /register/, all plugins disabled.')?>
            <?=__('Slot/sidebar/menu customizations will be lost. Content (posts, pages, media, users) is NOT affected.')?>
        </div>
        <button type="submit" class="btn btn-danger"><?=_e('Reinstall Now')?></button>
    </form>
</div>

<!-- Simple reinstall confirmation modal -->
<div class="adam-modal" id="reinstallModal">
    <div class="adam-modal__panel">
        <div class="adam-modal__title"><?=_e('Confirm Reinstall')?></div>
        <div class="adam-modal__text">
            <p><?=_e('Reinstall? All core CMS files will be overwritten with original versions. Automatic backup will be created.')?></p>
            <p class="up-hint" style="margin-top:.5rem"><?=_e('Data (cfg/.env, themes, plugins, uploads) remain safe.')?></p>
        </div>
        <div class="adam-modal__actions">
            <button class="adam-btn adam-btn--ghost" onclick="closeReinstallModal()"><?=_e('Cancel')?></button>
            <button class="adam-btn adam-btn--danger" id="reinstallApplyBtn"><?=_e('Yes, Reinstall')?></button>
        </div>
    </div>
</div>

<!-- Hard reset confirmation modal -->
<div class="adam-modal" id="resetModal">
    <div class="adam-modal__panel" style="max-width:480px">
        <div class="adam-modal__title"><?=_e('Confirm Hard Reset')?></div>
        <div class="adam-modal__text">
            <p style="margin-bottom:.75rem;font-weight:600"><?=_e('Hard reset will make the following changes:')?></p>
            <p style="margin-bottom:.6rem;font-weight:600"><?=_e('Check all to proceed:')?></p>
            <ul class="reset-checklist">
                <li class="reset-cb"><label><input type="checkbox" class="reset-cbox"> <?=_e('Core CMS files overwritten with original versions')?></label></li>
                <li class="reset-cb"><label><input type="checkbox" class="reset-cbox"> <?=_e('Theme reset to')?> <strong>default</strong></label></li>
                <li class="reset-cb"><label><input type="checkbox" class="reset-cbox"> <?=_e('Auth paths: admin → /dashboard/, login → /login/, register → /register/')?></label></li>
                <li class="reset-cb"><label><input type="checkbox" class="reset-cbox"> <?=_e('All plugins')?> <strong><?=_e('disabled')?></strong> (<?=_e('files remain')?>)</label></li>
                <li class="reset-cb"><label><input type="checkbox" class="reset-cbox"> <?=_e('Slot, sidebar, and menu customizations reset to defaults')?></label></li>
                <li class="reset-cb"><label><input type="checkbox" class="reset-cbox"> <?=_e('Content (posts, pages, media, users)')?> <strong><?=_e('SAFE')?></strong></label></li>
                <li class="reset-cb"><label><input type="checkbox" class="reset-cbox"> <?=_e('Database config &amp; file uploads')?> <strong><?=_e('SAFE')?></strong></label></li>
            </ul>
        </div>
        <div class="adam-modal__actions">
            <button class="adam-btn adam-btn--ghost" onclick="closeResetModal()"><?=_e('Cancel')?></button>
            <button class="adam-btn adam-btn--danger" id="resetApplyBtn" disabled><?=_e('Apply Hard Reset')?></button>
        </div>
    </div>
</div>

<!-- Confirm CMS Update modal -->
<div class="adam-modal" id="cmsUpdateConfirmModal">
    <div class="adam-modal__panel" style="max-width:440px">
        <div class="adam-modal__title"><?=_e('Confirm Update')?></div>
        <div class="adam-modal__text">
            <p><?=_e('Apply update now? Files will be backed up automatically.')?></p>
            <?php if ($pendingUpdate): ?>
            <p style="margin-top:.5rem;font-size:.85rem;color:var(--adam-muted)">
                v<?= htmlspecialchars($pendingUpdate['version'] ?? '?') ?>
                &mdash; <?= ($pendingUpdate['total_files'] ?? 0) ?> <?=_e('file')?>
            </p>
            <?php endif; ?>
        </div>
        <div class="adam-modal__actions">
            <button class="adam-btn adam-btn--ghost" onclick="closeCmsUpdateModal()"><?=_e('Cancel')?></button>
            <button class="adam-btn" style="background:#059669;border-color:#059669;color:#fff" id="cmsUpdateApplyConfirmBtn"><?=_e('Yes, Apply Update')?></button>
        </div>
    </div>
</div>

<div class="up-card" style="margin-top:1.25rem">
    <div class="up-card-header"><?=_e('What Gets Updated')?></div>
    <p class="up-hint"><?=_e('Update only affects core CMS files. The following data will NOT be touched:')?></p>
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
    <p class="up-hint"><?=_e('Changed files will be automatically backed up to')?> <code>cfg/var/backup-{timestamp}/</code>.</p>
</div>

<!-- Progress Overlay (green) -->
<div id="cmsUpdateProgress" style="display:none;position:fixed;inset:0;z-index:99999;background:rgba(0,0,0,.6);align-items:center;justify-content:center">
  <div style="background:var(--adam-surface);padding:2rem 2.5rem;border-radius:12px;text-align:center;max-width:400px;box-shadow:0 8px 32px rgba(0,0,0,.3);width:90%">
    <div id="cmsProgressSpinner" style="width:40px;height:40px;border:4px solid var(--adam-border-2);border-top-color:var(--adam-success);border-radius:50%;animation:spin .7s linear infinite;margin:0 auto 1rem"></div>
    <div id="cmsProgressStatus" style="font-weight:600;font-size:1rem;color:var(--adam-text)"><?=__('Processing…')?></div>
    <div id="cmsProgressDetail" style="margin-top:.4rem;font-size:.8rem;color:var(--adam-muted);min-height:1.2em"></div>
    <div style="margin-top:1rem;background:var(--adam-border-2);border-radius:999px;height:8px;overflow:hidden">
      <div id="cmsProgressBar" style="width:0%;height:100%;background:var(--adam-success);border-radius:999px;transition:width .4s ease"></div>
    </div>
    <div id="cmsProgressPct" style="margin-top:.3rem;font-size:.75rem;color:var(--adam-muted)">0%</div>
  </div>
</div>

<style>
@keyframes spin { to { transform:rotate(360deg); } }
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
.up-dev-notice { margin:-.5rem 0 1rem; padding:.6rem .75rem; border-radius:6px; font-size:.8rem; border:1px solid #f59e0b; background:#fffbeb; color:#92400e; }
html.theme-dark .up-dev-notice { border-color:#d97706; background:#1a1500; color:#fbbf24; }
@media (prefers-color-scheme: dark){ html:not(.theme-light):not(.theme-dark) .up-dev-notice { border-color:#d97706; background:#1a1500; color:#fbbf24; } }
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

// Progress overlay helpers
var _cmsPollTimer = null;
var _cmsToken = '';

function closeCmsUpdateModal(){
    var modal = document.getElementById('cmsUpdateConfirmModal');
    if (modal) modal.style.display = 'none';
}

function cmsUpdateProgressBar(pct, status) {
    var bar = document.getElementById('cmsProgressBar');
    var pctEl = document.getElementById('cmsProgressPct');
    var detailEl = document.getElementById('cmsProgressDetail');
    var statusEl = document.getElementById('cmsProgressStatus');
    var spinner = document.getElementById('cmsProgressSpinner');
    if (bar) bar.style.width = Math.min(pct, 100) + '%';
    if (pctEl) pctEl.textContent = pct + '%';
    if (detailEl) detailEl.textContent = status || '';
    if (pct >= 100 && statusEl) {
        statusEl.textContent = '<?= __('Done!') ?>';
        if (spinner) spinner.style.display = 'none';
    }
}

function cmsShowProgress() {
    var overlay = document.getElementById('cmsUpdateProgress');
    if (!overlay) return;
    cmsUpdateProgressBar(0, '<?= __('Starting...') ?>');
    overlay.style.display = 'flex';
}

function cmsHideProgress() {
    var overlay = document.getElementById('cmsUpdateProgress');
    if (overlay) overlay.style.display = 'none';
}

function cmsMakeToken() {
    var hex = '0123456789abcdef';
    var token = '';
    for (var i = 0; i < 32; i++) token += hex[Math.floor(Math.random() * 16)];
    return token;
}

function cmsStartUpdate() {
    closeCmsUpdateModal();
    var token = cmsMakeToken();
    _cmsToken = token;
    cmsShowProgress();
    cmsUpdateProgressBar(1, '<?= __('Preparing...') ?>');

    var baseUrl = '<?= $base ?>';
    var progressUrl = '<?= $base ?>' + '/?page=admin/update/index&action=cms_read_progress&token=' + token;
    var applyUrl = '<?= $base ?>' + '/?page=admin/update/update_apply';

    _cmsPollTimer = setInterval(function() {
        fetch(progressUrl, {
            method: 'GET',
            credentials: 'same-origin',
            cache: 'no-store',
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            cmsUpdateProgressBar(data.percentage || 0, data.status || '');
            if (data.done || data.error) {
                clearInterval(_cmsPollTimer);
                _cmsPollTimer = null;
                if (data.error) {
                    setTimeout(function() {
                        cmsHideProgress();
                        alert('<?= __('Failed: ') ?>' + data.error);
                    }, 1500);
                } else {
                    setTimeout(function() {
                        window.location.href = '<?= $selfUrl ?>&cms_update_ok=1';
                    }, 1500);
                }
            }
        })
        .catch(function() {});
    }, 1500);

    var formData = new FormData();
    formData.append('csrf_token', '<?= h(csrf_token()) ?>');
    formData.append('action', 'apply_update');
    formData.append('token', token);

    fetch(applyUrl, {
        method: 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        body: formData,
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json',
        },
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (!data.ok && data.error && !_cmsPollTimer) {
            clearInterval(_cmsPollTimer);
            _cmsPollTimer = null;
            setTimeout(function() {
                cmsHideProgress();
                alert('<?= __('Failed: ') ?>' + data.error);
            }, 1500);
        }
    })
    .catch(function(err) {
        clearInterval(_cmsPollTimer);
        _cmsPollTimer = null;
        setTimeout(function() {
            cmsHideProgress();
            alert('<?= __('Failed: ') ?>' + err.message);
        }, 1500);
    });
}

// Wire up the Apply Update button
(function(){
    var btn = document.getElementById('cmsApplyUpdateBtn');
    if (btn) {
        btn.addEventListener('click', function(e) {
            var modal = document.getElementById('cmsUpdateConfirmModal');
            if (modal) modal.style.display = 'flex';
        });
    }
})();

// Wire up the confirm button
(function(){
    var btn = document.getElementById('cmsUpdateApplyConfirmBtn');
    if (btn) {
        btn.addEventListener('click', function() {
            cmsStartUpdate();
        });
    }
})();

// Show overlay on check_remote / upload_update POST submit
(function(){
    var overlay = document.getElementById('cmsUpdateProgress');
    if (!overlay) return;
    var actions = ['check_remote', 'upload_update'];
    document.querySelectorAll('form').forEach(function(f){
        f.addEventListener('submit', function(){
            var inp = this.querySelector('input[name="action"]');
            if (!inp) return;
            if (actions.indexOf(inp.value) === -1) return;
            overlay.style.display = 'flex';
        });
    });
})();

// Flash success on ?cms_update_ok=1
document.addEventListener('DOMContentLoaded', function() {
    var urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('cms_update_ok') === '1' && window.NewNotifToast) {
        NewNotifToast.success('<?= __('Update applied successfully!') ?>');
        window.history.replaceState({}, '', '<?= $selfUrl ?>');
    }
});
</script>
