<?php
declare(strict_types=1);
// CMS Update Manager — Check for updates, apply update packages
require_once DASH_PATH . '/admin/_deny.php';
if (!defined('DASHBOARD_CONTEXT') && !defined('ADAM_THEME')) adiwira_admin_404();
require_once DASH_PATH . '/admin/_guard.php';
require_once DASH_PATH . '/admin/_notify.php';

[$uid] = adiwira_require_permission($pdo, 'core.updates.manage', false);

// Load shared helpers
require_once __DIR__ . '/_update_helpers.php';
require_once __DIR__ . '/_update_actions.php';
require_once dirname(DASH_PATH) . '/app/controllers/UpdateStatusController.php';

adiwira_require_site_owner($pdo, false);
$base = ADMIN_BASE_PATH;
$selfUrl = $base . '/?page=admin/update/index';

// Dev instance detection
$defaultUpdateUrl = 'https://jyavani.com/download/latest/';
$localHost = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
$updateHost = parse_url($defaultUpdateUrl, PHP_URL_HOST);
$isDevSelfCheck = $localHost !== '' && strcasecmp($updateHost ?: '', $localHost) === 0;

// Load current version
$versionFile = dirname(DASH_PATH) . '/version.json';
$currentVersion = ['version' => '0.0.0', 'name' => 'Jyavani CMS', 'build' => '', 'edition' => ''];
if (is_file($versionFile)) {
    $currentVersion = array_merge($currentVersion, _cms_decode_json_array((string)file_get_contents($versionFile), 'version.json'));
}

// Load local manifest
$manifestFile = dirname(DASH_PATH) . '/tools/cms-manifest.json';
$localManifest = null;
if (is_file($manifestFile)) {
    $localManifest = _cms_decode_json_array((string)file_get_contents($manifestFile), 'tools/cms-manifest.json');
}

// Pre-flight permission check — warn if www-data can't write key paths
$permErrors = [];
$permTestPaths = [
    'version.json',
    'tools/cms-manifest.json',
    'app/bootstrap_core.php',
    'public/router.php',
    'cfg/var',
];
$projectRoot = dirname(DASH_PATH);
foreach ($permTestPaths as $rel) {
    $abs = _cms_target_path($rel, $projectRoot);
    if ($abs === null) {
        $permErrors[] = $rel;
        continue;
    }
    if (file_exists($abs) && !is_writable($abs)) {
        $permErrors[] = $rel;
    }
}


cms_update_handle_post($pdo, $currentVersion, $selfUrl, $base);

// --- Helper: version info from session ---
ensure_session_started(false);
$updateSnapshot = UpdateStatusController::getSnapshot();
UpdateStatusController::hydrateCoreSession($updateSnapshot);
$pendingUpdate = $_SESSION['cms_update_remote'] ?? null;
$pendingPackage = $_SESSION['cms_update_package'] ?? null;
$pendingUrl = $_SESSION['cms_update_remote_url'] ?? $_SESSION['cms_update_base_url'] ?? '';
$coreUpdateStatus = $updateSnapshot['components']['core'] ?? [];
$cmsLatest = ($coreUpdateStatus['state'] ?? 'unknown') === 'ok' && ($coreUpdateStatus['has_update'] ?? false) !== true
    ? '<span class="up-latest">' . __('Latest') . '</span>'
    : '';


// Compute file stats
$totalCore = $localManifest['total_files'] ?? 0;
?>
<div class="up-page">
<h2 class="pg-title"><?=_e('CMS Update')?></h2>
<div data-update-status-page hidden></div>
<p class="pg-subtitle"><?=_e('Version')?> <?= htmlspecialchars($currentVersion['version'] ?? '—') ?> &mdash; <?= htmlspecialchars($currentVersion['build'] ?? '') ?></p>

<?php if ($isDevSelfCheck): ?>
<div class="up-dev-notice"><?=__('This appears to be a development instance. The update URL points to this same server. Build and publish updates from here, then deploy to production.')?></div>
<?php endif; ?>

<?php if (!empty($permErrors)): ?>
<div class="up-card" style="margin-bottom:1rem;border-color:var(--adam-danger)">
    <div class="up-card-header" style="color:var(--adam-danger)"><?=_e('Permission Warning')?></div>
    <p class="up-hint"><?=_e('The following paths are not writable by the web server process. The update will fail.')?></p>
    <ul style="margin:.5rem 0;padding-left:1.25rem">
        <?php foreach ($permErrors as $p): ?>
        <li style="margin-bottom:.25rem"><code><?= htmlspecialchars($p) ?></code></li>
        <?php endforeach; ?>
    </ul>
    <p class="up-hint"><?=_e('Fix:')?> <code>sudo chgrp -R www-data <?= htmlspecialchars($projectRoot) ?> <?= htmlspecialchars((string)PUBLIC_PATH) ?> &amp;&amp; sudo chmod -R g+w <?= htmlspecialchars($projectRoot) ?> <?= htmlspecialchars((string)PUBLIC_PATH) ?></code></p>
</div>
<?php endif; ?>

<div class="up-grid">
    <div class="up-card">
        <div class="up-card-header"><?=_e('Current Installation')?></div>
        <table class="up-table">
            <tr><td><?=_e('CMS')?></td><td><strong><?= htmlspecialchars($currentVersion['name'] ?? 'Jyavani CMS') ?></strong></td></tr>
            <tr><td><?=_e('Version')?></td><td><strong>v<?= htmlspecialchars($currentVersion['version'] ?? '0.0.0') ?></strong> <span data-cms-latest data-latest-class="up-latest"><?= $cmsLatest ?></span></td></tr>
            <tr><td><?=_e('Edition')?></td><td><span class="edition-badge"><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z"/></svg><?= htmlspecialchars($currentVersion['edition'] ?? '—') ?></span></td></tr>
            <tr><td><?=_e('Build')?></td><td><?= htmlspecialchars($currentVersion['build'] ?? '—') ?></td></tr>
            <tr><td><?=_e('Core files')?></td><td><?= $totalCore ?> <?=_e('files tracked')?></td></tr>
            <tr><td><?=_e('PHP')?></td><td><?= htmlspecialchars($currentVersion['php_required'] ?? '8.1') ?>+ (server: <?= PHP_VERSION ?>)</td></tr>
            <tr><td><?=_e('MySQL')?></td><td><?= htmlspecialchars($currentVersion['mysql_required'] ?? '5.7') ?>+</td></tr>
        </table>
    </div>

    <div class="up-card">
            <div class="up-card-header"><?=_e('Check All Updates')?></div>
        <form method="post" class="up-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="action" value="check_remote">

            <label class="up-label"><?=_e('Update URL')?></label>
            <input type="url" name="update_url" class="up-input" autocomplete="off" spellcheck="false"
                   value="<?= htmlspecialchars($defaultUpdateUrl) ?>"
                   placeholder="https://example.com/download/latest/">

            <div class="up-hint"><?=_e('Checks Core, plugins, and themes in one operation. The URL is used for Core update metadata.')?></div>

            <button type="submit" class="btn btn-primary"><?=_e('Check All Updates')?></button>
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
            <input type="file" name="update_package" accept=".zip" required id="upFile" class="up-file-input">
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
        <input type="url" name="reinstall_url" class="up-input" autocomplete="off" spellcheck="false"
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
        <li><code>public/static/img/YYYY/</code> &amp; <code>files/</code> &mdash; <?=_e('Uploaded media')?></li>
        <li><code>public/views/themes/</code> &mdash; <?=_e('Installed third-party themes')?></li>
        <li><code>plugins/</code> &mdash; installed plugins</li>
        <li><code>public/pdf/</code> &mdash; PWA / static PDF files</li>
        <li><code>public/sitemaps/</code> &mdash; generated sitemaps</li>
    </ul>
    <p class="up-hint"><?=_e('Changed files will be automatically backed up to')?> <code>cfg/var/backup-{timestamp}/</code>.</p>
</div>

<!-- Blocking update process modal. It is closed only by its terminal action. -->
<div id="cmsUpdateProgress" class="update-process-overlay" role="dialog" aria-modal="true" aria-labelledby="cmsProgressTitle" style="display:none">
  <div class="update-process-panel" data-update-process-panel tabindex="-1">
    <h3 id="cmsProgressTitle" class="update-process-title" data-update-process-title><?=_e('CMS update in progress')?></h3>
    <div id="cmsProgressSpinner" class="update-process-spinner" data-update-process-spinner aria-hidden="true"></div>
    <div class="update-process-stage" data-update-process-stage><?=_e('Stage:')?> <?=_e('Starting...')?></div>
    <div id="cmsProgressStatus" class="update-process-status" data-update-process-status aria-live="polite"><?=__('Starting...')?></div>
    <p class="update-process-warning"><?=_e('Do not close or leave this page while the update is running.')?></p>
    <div class="update-process-track"><div id="cmsProgressBar" class="update-process-bar" data-update-process-bar style="width:0%"></div></div>
    <div id="cmsProgressPct" class="update-process-pct" data-update-process-pct>0%</div>
    <div class="update-process-actions" data-update-process-actions>
      <button type="button" class="btn btn-outline" data-update-process-cancel disabled><?=_e('Cancel update')?></button>
    </div>
  </div>
</div>
</div>

<script>
window.CMS_UPDATE_CONFIG = <?= json_encode([
    'progressUrl' => $base . '/admin/update/process.php?token=',
    'applyUrl' => $base . '/?page=admin/update/update_apply',
    'successUrl' => $base . '/?cms_update_ok=1',
    'selfUrl' => $selfUrl,
    'csrfToken' => csrf_token(),
    'context' => __('Core'),
    'updateFailed' => __('Update failed.'),
    'invalidResponse' => __('The update server returned an invalid response.'),
    'labels' => [
        'runningTitle' => __('CMS update in progress'),
        'completeTitle' => __('CMS update complete'),
        'failedTitle' => __('CMS update failed'),
        'cancelledTitle' => __('CMS update cancelled'),
        'stage' => __('Stage:'),
        'starting' => __('Starting...'),
        'cancel' => __('Cancel update'),
        'cancelling' => __('Cancelling...'),
        'finishing' => __('Finishing process...'),
        'done' => __('Done'),
        'timeout' => __('The update is taking longer than expected. Waiting for a confirmed result.'),
        'invalidResponse' => __('The update server returned an invalid response.'),
        'requestFailed' => __('The update request failed.'),
        'cancelFailed' => __('Unable to request cancellation. The update is still running.'),
    ],
], JSON_UNESCAPED_SLASHES
    | JSON_UNESCAPED_UNICODE
    | JSON_HEX_TAG
    | JSON_HEX_AMP
    | JSON_HEX_APOS
    | JSON_HEX_QUOT) ?>;
</script>
<script src="/static/dashboard/js/update.js?v=<?= (int)(@filemtime(PUBLIC_PATH . '/static/dashboard/js/update.js') ?: 0) ?>"></script>
