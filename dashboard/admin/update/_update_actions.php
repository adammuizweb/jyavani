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
        $policy = cms_update_operation_policy('upload', ['source' => 'uploaded']);
        if (!$policy['allowed']) adiwira_redirect_with_flash($selfUrl, 'error', $policy['message']);
        cms_update_store_upload($currentVersion, $selfUrl);
    }
    if ($action === 'clear_pending') {
        cms_update_clear_pending($selfUrl);
    }
    if ($action === 'reinstall') {
        $operation = !empty($_POST['hard_reset']) ? 'hard_reset' : 'reinstall';
        $policy = cms_update_operation_policy($operation, ['source' => 'official_remote']);
        if (!$policy['allowed']) adiwira_redirect_with_flash($selfUrl, 'error', $policy['message']);
        cms_update_reinstall($pdo, $currentVersion, $selfUrl, $base);
    }
    if (in_array($action, ['apply_update', 'apply_uploaded'], true)) {
        adiwira_redirect_with_flash($selfUrl, 'error', __('Use the new AJAX-based update flow.'));
    }

    adiwira_redirect_with_flash($selfUrl, 'error', __('Unknown action.'));
}

function cms_update_check_remote(PDO $pdo, array $currentVersion, string $selfUrl): void
{
    $officialUrl = UpdateStatusController::officialCoreUrl();

    try {
        session_write_close();
        $snapshot = UpdateStatusController::checkAll($pdo);
    } catch (Throwable $error) {
        ensure_session_started(true);
        adiwira_redirect_with_flash($selfUrl, 'error', __('Failed to check updates.'));
    }
    ensure_session_started(true);
    UpdateStatusController::hydrateCoreSession($snapshot);
    $core = $snapshot['components']['core'] ?? [];
    if (($core['state'] ?? 'error') === 'error') {
        adiwira_redirect_with_flash($selfUrl, 'error', __('Failed to fetch update info from URL:') . ' ' . htmlspecialchars($officialUrl));
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
    $archiveValidation = package_archive_validate($zip);
    if (!$archiveValidation['success']) {
        $zip->close();
        adiwira_redirect_with_flash($selfUrl, 'error', __('Update package archive is unsafe or exceeds resource limits.'));
    }

    $manifestJson = _cms_zip_read_bounded($zip, 'cms-manifest.json', 4 * 1024 * 1024);
    if ($manifestJson === false) {
        $zip->close();
        adiwira_redirect_with_flash($selfUrl, 'error', __('cms-manifest.json not found in the update package.'));
    }

    try {
        $remoteManifest = cms_manifest_decode($manifestJson, 'cms-manifest.json');
        _cms_validate_core_package_metadata($zip, $remoteManifest);
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
    $hardReset = !empty($_POST['hard_reset']);
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

    try {
        $installedVersion = jyavani_descriptor_version($currentVersion['version'] ?? null, 'Installed Core version');
        $releaseMetadata = update_metadata_fetch_json(
            UpdateStatusController::officialCoreMetadataUrl($installedVersion),
            'JyavaniCMS-Reinstall/' . $installedVersion,
            microtime(true) + 8.0
        );
        if (!is_array($releaseMetadata)) throw new RuntimeException('Official reinstall metadata is unavailable.');
        $releaseMetadata = UpdateStatusController::validateOfficialCoreMetadata($releaseMetadata, $installedVersion);
        $expectedHash = (string)($releaseMetadata['package_sha256'] ?? '');
        if (preg_match('/\A[a-f0-9]{64}\z/D', $expectedHash) !== 1) {
            throw new RuntimeException('Official reinstall metadata does not provide a package checksum.');
        }
        $url = $releaseMetadata['download_url'];
    } catch (Throwable $error) {
        adiwira_redirect_with_flash($selfUrl, 'error', __('Failed to validate reinstall package metadata.'));
    }

    $temporaryZip = package_download(
        $url,
        'cms-reinstall-',
        'JyavaniCMS-Reinstall/' . $installedVersion,
        null,
        false
    );
    if ($temporaryZip === null) {
        $diagnostic = package_download_last_error_code();
        $message = __('Failed to download reinstall package.');
        if ($diagnostic !== '' && $diagnostic !== 'cancelled') $message .= ' [download:' . $diagnostic . ']';
        adiwira_redirect_with_flash($selfUrl, 'error', $message);
    }

    if (!hash_equals($expectedHash, (string)hash_file('sha256', $temporaryZip))) {
        @unlink($temporaryZip);
        adiwira_redirect_with_flash($selfUrl, 'error', __('Package checksum verification failed.'));
    }

    $zip = new ZipArchive();
    if ($zip->open($temporaryZip) !== true) {
        @unlink($temporaryZip);
        adiwira_redirect_with_flash($selfUrl, 'error', __('Failed to open ZIP package.'));
    }
    try {
        $archiveValidation = package_archive_validate($zip);
        if (!$archiveValidation['success']) throw new RuntimeException('Unsafe reinstall package archive.');
        $manifestJson = _cms_zip_read_bounded($zip, 'cms-manifest.json', 4 * 1024 * 1024);
        $reinstallManifest = $manifestJson === false ? null : cms_manifest_decode($manifestJson, 'cms-manifest.json');
        if (is_array($reinstallManifest)) _cms_validate_core_package_metadata($zip, $reinstallManifest);
    } catch (Throwable $error) {
        $reinstallManifest = null;
    } finally {
        $zip->close();
    }
    if (!is_array($reinstallManifest)
        || !is_array($reinstallManifest['files'] ?? null)
        || $reinstallManifest['files'] === []
        || !hash_equals($installedVersion, (string)($reinstallManifest['version'] ?? ''))) {
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
        $resetPluginNames = $hardReset ? cms_reinstall_hard_reset_plugin_names() : [];
        $lifecycleLocks = theme_operation_acquire(theme_lifecycle_lock_keys($resetPluginNames));
        $lockedInstalledVersion = _cms_installed_version();
        if (!hash_equals($lockedInstalledVersion, (string)$reinstallManifest['version'])) {
            throw new RuntimeException(__('Installed Core version changed while the reinstall package was downloading.'));
        }
        $result = _apply_cms_update_from_zip(
            $temporaryZip,
            $reinstallManifest,
            $lockedInstalledVersion
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
