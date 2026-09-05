<?php
declare(strict_types=1);

require_once __DIR__ . '/_reset_service.php';
require_once __DIR__ . '/../../../app/controllers/UpdateStatusController.php';

function cms_update_handle_post(PDO $pdo, array $currentVersion, string $selfUrl, string $base): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') return;

    $csrf = (string)($_POST['csrf_token'] ?? '');
    if (!adiwira_csrf_validate($csrf)) {
        adiwira_redirect_with_flash($selfUrl, 'error', __('Invalid CSRF token.'));
    }

    $action = (string)($_POST['action'] ?? '');
    if ($action === 'check_remote') {
        cms_update_check_remote($pdo, $currentVersion, $selfUrl);
    }
    if ($action === 'upload_update') {
        cms_update_store_upload($currentVersion, $selfUrl);
    }
    if ($action === 'clear_pending') {
        cms_update_clear_pending($selfUrl);
    }
    if ($action === 'reinstall') {
        cms_update_reinstall($pdo, $currentVersion, $selfUrl, $base);
    }
    if (in_array($action, ['apply_update', 'apply_uploaded'], true)) {
        adiwira_redirect_with_flash($selfUrl, 'error', __('Use the new AJAX-based update flow.'));
    }

    adiwira_redirect_with_flash($selfUrl, 'error', __('Unknown action.'));
}

function cms_update_check_remote(PDO $pdo, array $currentVersion, string $selfUrl): void
{
    $inputUrl = trim((string)($_POST['update_url'] ?? ''));
    if ($inputUrl === '') {
        adiwira_redirect_with_flash($selfUrl, 'error', __('Update URL cannot be empty.'));
    }

    try {
        session_write_close();
        $snapshot = UpdateStatusController::checkAll($pdo, $inputUrl);
    } catch (Throwable $error) {
        ensure_session_started(true);
        adiwira_redirect_with_flash($selfUrl, 'error', __('Failed to check updates.'));
    }
    ensure_session_started(true);
    UpdateStatusController::hydrateCoreSession($snapshot);
    $core = $snapshot['components']['core'] ?? [];
    if (($core['state'] ?? 'error') === 'error') {
        adiwira_redirect_with_flash($selfUrl, 'error', __('Failed to fetch update info from URL:') . ' ' . htmlspecialchars($inputUrl));
    }
    if (($snapshot['state'] ?? 'ok') !== 'ok') {
        adiwira_redirect_with_flash(
            $selfUrl,
            'warning',
            __('Update check completed with partial results.') . ' '
                . (int)($snapshot['total'] ?? 0) . ' ' . __('update(s) available across Core, plugins, and themes.')
        );
    }
    if (($core['has_update'] ?? false) === true) {
        adiwira_redirect_with_flash(
            $selfUrl,
            'success',
            __('Update available:') . ' v' . htmlspecialchars((string)($core['current'] ?? '0.0.0')) . ' → v' . htmlspecialchars((string)($core['latest'] ?? '0.0.0')) . '. '
                . (int)($snapshot['total'] ?? 0) . ' ' . __('update(s) available across Core, plugins, and themes.')
        );
    }
    if ((int)($snapshot['total'] ?? 0) > 0) {
        adiwira_redirect_with_flash(
            $selfUrl,
            ($snapshot['state'] ?? 'ok') === 'ok' ? 'success' : 'warning',
            (int)$snapshot['total'] . ' ' . __('update(s) available across Core, plugins, and themes.')
        );
    }
    adiwira_redirect_with_flash(
        $selfUrl,
        ($snapshot['state'] ?? 'ok') === 'ok' ? 'info' : 'warning',
        ($snapshot['state'] ?? 'ok') === 'ok'
            ? __('All Core, plugins, and themes are up to date.')
            : __('Update check completed with partial results.')
    );
}

function cms_update_store_upload(array $currentVersion, string $selfUrl): void
{
    if (!isset($_FILES['update_package']) || $_FILES['update_package']['error'] !== UPLOAD_ERR_OK) {
        adiwira_redirect_with_flash($selfUrl, 'error', __('Invalid update file.'));
    }

    $file = $_FILES['update_package'];
    $originalName = basename((string)$file['name']);
    if (!str_ends_with(strtolower($originalName), '.zip')) {
        adiwira_redirect_with_flash($selfUrl, 'error', __('Only .zip files are supported.'));
    }

    $temporaryZip = (string)$file['tmp_name'];
    $zip = new ZipArchive();
    if ($zip->open($temporaryZip) !== true) {
        adiwira_redirect_with_flash($selfUrl, 'error', __('Failed to open ZIP file.'));
    }

    $manifestJson = $zip->getFromName('cms-manifest.json');
    $versionJson = $zip->getFromName('version.json');
    if ($manifestJson === false) {
        $zip->close();
        adiwira_redirect_with_flash($selfUrl, 'error', __('cms-manifest.json not found in the update package.'));
    }

    try {
        $remoteManifest = _cms_decode_json_array($manifestJson, 'cms-manifest.json');
        if ($versionJson !== false) _cms_decode_json_array($versionJson, 'version.json');
    } catch (Throwable $error) {
        $zip->close();
        adiwira_redirect_with_flash($selfUrl, 'error', $error->getMessage());
    }
    if (!isset($remoteManifest['version'])) {
        $zip->close();
        adiwira_redirect_with_flash($selfUrl, 'error', __('Invalid cms-manifest.json format.'));
    }
    $zip->close();

    $localVersion = $currentVersion['version'] ?? '0.0.0';
    $remoteVersion = $remoteManifest['version'] ?? '0.0.0';
    if (!version_compare($remoteVersion, $localVersion, '>')) {
        adiwira_redirect_with_flash(
            $selfUrl,
            'warning',
            __('Package version') . ' (v' . htmlspecialchars($remoteVersion) . ') '
                . __('is not newer than current version') . ' (v' . htmlspecialchars($localVersion) . ').'
        );
    }

    $uploadDir = dirname(DASH_PATH) . '/cfg/var/uploads';
    if (!is_dir($uploadDir) && !@mkdir($uploadDir, 0755, true)) {
        adiwira_redirect_with_flash($selfUrl, 'error', __('Failed to store update package.'));
    }
    $storedZip = $uploadDir . '/cms-update-' . bin2hex(random_bytes(8)) . '.zip';
    if (!@move_uploaded_file($temporaryZip, $storedZip)) {
        adiwira_redirect_with_flash($selfUrl, 'error', __('Failed to store update package.'));
    }

    ensure_session_started(true);
    $_SESSION['cms_update_remote'] = $remoteManifest;
    $_SESSION['cms_update_package'] = $storedZip;
    $_SESSION['cms_update_remote_url'] = '(uploaded package)';

    adiwira_redirect_with_flash(
        $selfUrl,
        'success',
        'Package v' . htmlspecialchars($remoteVersion) . ' siap. Klik "Apply Update" untuk memulai.'
    );
}

function cms_update_clear_pending(string $selfUrl): void
{
    ensure_session_started(true);
    $pendingPackagePath = (string)($_SESSION['cms_update_package'] ?? '');
    $uploadRoot = realpath(dirname(DASH_PATH) . '/cfg/var/uploads');
    $pendingRealPath = $pendingPackagePath !== '' ? realpath($pendingPackagePath) : false;
    if ($uploadRoot !== false && $pendingRealPath !== false
        && str_starts_with($pendingRealPath, $uploadRoot . DIRECTORY_SEPARATOR)) {
        @unlink($pendingRealPath);
    }
    unset(
        $_SESSION['cms_update_remote'],
        $_SESSION['cms_update_base_url'],
        $_SESSION['cms_update_package'],
        $_SESSION['cms_update_remote_url']
    );
    adiwira_redirect_with_flash($selfUrl, 'info', __('Pending update cancelled.'));
}

function cms_update_reinstall(PDO $pdo, array $currentVersion, string $selfUrl, string $base): void
{
    $url = trim((string)($_POST['reinstall_url'] ?? ''));
    if ($url === '') {
        adiwira_redirect_with_flash($selfUrl, 'error', __('Reinstall URL cannot be empty.'));
    }

    $hardReset = !empty($_POST['hard_reset']);
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

    $temporaryZip = sys_get_temp_dir() . '/cms-reinstall-' . bin2hex(random_bytes(8)) . '.zip';
    $context = stream_context_create(['http' => [
        'timeout' => 120,
        'user_agent' => 'JyavaniCMS-Reinstall/' . ($currentVersion['version'] ?? '0.0.0'),
    ]]);

    $zipData = @file_get_contents($url, false, $context);
    if ($zipData === false) {
        @unlink($temporaryZip);
        adiwira_redirect_with_flash($selfUrl, 'error', __('Failed to download reinstall package.'));
    }
    if (@file_put_contents($temporaryZip, $zipData) === false) {
        @unlink($temporaryZip);
        adiwira_redirect_with_flash($selfUrl, 'error', __('Failed to store update package.'));
    }

    $zip = new ZipArchive();
    if ($zip->open($temporaryZip) !== true) {
        @unlink($temporaryZip);
        adiwira_redirect_with_flash($selfUrl, 'error', __('Failed to open ZIP package.'));
    }
    $manifestJson = $zip->getFromName('cms-manifest.json');
    $zip->close();
    try {
        $reinstallManifest = $manifestJson === false
            ? null
            : _cms_decode_json_array($manifestJson, 'cms-manifest.json');
    } catch (Throwable $error) {
        $reinstallManifest = null;
    }
    if (!is_array($reinstallManifest)
        || !is_array($reinstallManifest['files'] ?? null)
        || $reinstallManifest['files'] === []) {
        @unlink($temporaryZip);
        adiwira_redirect_with_flash($selfUrl, 'error', __('Invalid reinstall package manifest.'));
    }

    $updateLock = update_operation_acquire_lock();
    if (!is_resource($updateLock)) {
        @unlink($temporaryZip);
        adiwira_redirect_with_flash($selfUrl, 'error', __('Another update is already running.'));
    }
    $lifecycleLocks = [];
    try {
        $lifecycleLocks = theme_operation_acquire(theme_lifecycle_lock_keys());
        $result = _apply_cms_update_from_zip(
            $temporaryZip,
            $reinstallManifest,
            $currentVersion['version'] ?? '0.0.0'
        );

        $resetMessages = [];
        if ($result['success'] && $hardReset) {
            $resetMessages = cms_reinstall_hard_reset($pdo);
        }
    } catch (Throwable $error) {
        error_log('[core-reinstall] ' . $error->getMessage());
        $result = ['success' => false, 'message' => $error->getMessage()];
        $resetMessages = [];
    } finally {
        if ($lifecycleLocks !== []) theme_operation_release($lifecycleLocks);
        update_operation_release_lock($updateLock);
        @unlink($temporaryZip);
    }

    if ($result['success']) {
        UpdateStatusController::removeUpdate('core');
        $message = __('Reinstall complete!') . ' ' . $result['message'];
        if ($resetMessages !== []) $message .= ' ' . implode(' ', $resetMessages);
        adiwira_redirect_with_flash($base . '/?page=admin/update/index', 'success', $message);
    }

    adiwira_redirect_with_flash($selfUrl, 'error', $result['message']);
}
