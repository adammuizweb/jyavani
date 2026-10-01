<?php
declare(strict_types=1);

/** Safe inspection and existing-file editing for registered installed themes. */
final class ThemeSourceService
{
    private const MAX_ENTRIES = 10000;
    private const MAX_DEPTH = 32;
    private const MAX_FILES = 1000;
    private const MAX_PATH_BYTES = 512;
    private const MAX_SOURCE_BYTES = 5242880;
    private const MAX_TOTAL_BYTES = 67108864;
    private const MAX_METADATA_BYTES = 1048576;
    private const MAX_REVISIONS = 200;
    private const MAX_REVISION_BYTES = 268435456;
    private const MAX_EXPORT_BYTES = 134217728;
    private array $readSnapshotCache = [];
    private array $readSourceCache = [];
    private static array $privateLocks = [];

    public function __construct(private PDO $pdo)
    {
    }

    public function inventory(string $folder): array
    {
        $row = $this->registeredTheme($folder);
        $root = $this->themeRoot($folder);
        $files = $this->readSnapshot($root, $folder);
        return [
            'theme' => $this->themeSummary($row),
            'files' => array_values($files),
        ];
    }

    public function source(string $folder, string $opaqueFileId): ?array
    {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $opaqueFileId) !== 1) return null;
        $row = $this->registeredTheme($folder);
        $root = $this->themeRoot($folder);
        $rootStat = $this->directoryIdentity($root);
        $cacheKey = $folder . "\0" . $opaqueFileId;
        if (isset($this->readSourceCache[$cacheKey])) return $this->readSourceCache[$cacheKey];
        foreach ($this->readSnapshot($root, $folder) as $file) {
            if (!hash_equals((string)$file['id'], $opaqueFileId)) continue;
            $path = $this->resolveRegularPath($root, (string)$file['path']);
            $state = $this->readRegular($path, $root, self::MAX_SOURCE_BYTES);
            $stat = $state['stat'];
            return $this->readSourceCache[$cacheKey] = $file + [
                'source' => $state['content'],
                'sha256' => $state['sha256'],
                'utf8' => preg_match('//u', $state['content']) === 1,
                'lines' => substr_count($state['content'], "\n") + 1,
                'target_token' => $this->targetToken((int)$row['id'], $folder, $rootStat, (string)$file['path'], $stat),
            ];
        }
        return null;
    }

    public function editState(string $folder, array $context = []): array
    {
        try {
            $row = $this->registeredTheme($folder);
            $this->themeRoot($folder);
            $state = $this->coreEditState($row);
            if (is_array($context['file'] ?? null) && ($context['file']['utf8'] ?? true) !== true) {
                $state['allowed'] = false;
                $state['message'] = $this->translate('PHP source that is not valid UTF-8 is read-only.');
            }
            $context = $this->policyContext($row, $context + ['operation' => 'edit']);
            return $this->applyEditPolicy($state, $row, $context);
        } catch (Throwable $error) {
            return [
                'allowed' => false,
                'message' => $this->safeError($error),
                'active' => false,
                'assigned' => false,
                'store' => false,
                'system' => false,
            ];
        }
    }

    public function dirtyState(string $folder): array
    {
        try {
            $row = $this->registeredTheme($folder);
            $root = $this->themeRoot($folder);
            $identity = $this->directoryIdentity($root);
            $current = $this->readSnapshot($root, $folder);
            $baseline = $this->readBaseline($folder, (int)$row['id'], $identity);
            return $this->dirtyStateFrom($row, $identity, $baseline, $current);
        } catch (Throwable $error) {
            return [
                'tracked' => false,
                'locally_modified' => false,
                'changed_count' => 0,
                'counts' => [],
                'files' => [],
                'error' => $this->safeError($error),
            ];
        }
    }

    public function savePhp(
        string $folder,
        string $opaqueFileId,
        string $expectedHash,
        string $targetToken,
        string $source,
        int $actorId,
        array $acknowledgements,
        string $note = ''
    ): array {
        return $this->replacePhp($folder, $opaqueFileId, $expectedHash, $targetToken, $source, $actorId, $acknowledgements, $note, 'save', null);
    }

    public function revisions(string $folder, string $opaqueFileId): array
    {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $opaqueFileId) !== 1) return [];
        try {
            $row = $this->registeredTheme($folder);
            $root = $this->themeRoot($folder);
            $rootIdentity = $this->directoryIdentity($root);
            $source = $this->source($folder, $opaqueFileId);
            if ($source === null) return [];
            $directory = $this->revisionFileDirectory((int)$row['id'], $rootIdentity, $opaqueFileId, false);
            if (!file_exists($directory)) return [];
            $result = [];
            foreach (scandir($directory, SCANDIR_SORT_DESCENDING) ?: [] as $revisionId) {
                if ($revisionId === '.' || $revisionId === '..') continue;
                $record = $this->readRevision($directory . '/' . $revisionId, $folder, (int)$row['id'], $opaqueFileId, (string)$source['path'], $rootIdentity);
                unset($record['source']);
                $result[] = $record;
                if (count($result) >= 50) break;
            }
            return $result;
        } catch (Throwable) {
            return [];
        }
    }

    public function restorePhp(
        string $folder,
        string $opaqueFileId,
        string $revisionId,
        string $expectedHash,
        string $targetToken,
        int $actorId,
        array $acknowledgements,
        string $note = ''
    ): array {
        if (preg_match('/\A\d{8}T\d{6}Z-[a-f0-9]{16}\z/D', $revisionId) !== 1) {
            return ['success' => false, 'code' => 'invalid_request', 'error' => 'Invalid source revision request.'];
        }
        return $this->replacePhp($folder, $opaqueFileId, $expectedHash, $targetToken, '', $actorId, $acknowledgements, $note, 'restore', $revisionId);
    }

    public function captureBaseline(string $folder, string $origin, int $actorId): array
    {
        if (!in_array($origin, ['core_install', 'core_update'], true) || $actorId < 0) {
            throw new InvalidArgumentException('Invalid theme baseline request.');
        }
        $coreLocks = [];
        $privateLock = null;
        try {
            if (!$this->globalLifecycleWriterHeld()) $coreLocks = $this->acquireCoreLocks($folder);
            $privateLock = $this->acquirePrivateLock($folder);
            $row = $this->registeredTheme($folder);
            $root = $this->themeRoot($folder);
            $identity = $this->directoryIdentity($root);
            $first = $this->snapshot($root, $folder, false);
            $second = $this->snapshot($root, $folder, false);
            if ($first !== $second) throw new RuntimeException('Theme PHP source changed during baseline capture.');
            return $this->publishBaseline($folder, $row, $identity, $second, $origin, $actorId, true, [
                'trusted' => true,
                'kind' => 'core_lifecycle',
                'version' => (string)($row['version'] ?? ''),
            ]);
        } finally {
            $this->releasePrivateLock($privateLock);
            if ($coreLocks !== []) theme_operation_release($coreLocks);
        }
    }

    public function exportPhpSource(string $folder, int $actorId): array
    {
        if ($actorId < 1 || !class_exists('ZipArchive')) throw new RuntimeException('Protected ZIP export is unavailable.');
        $locks = [];
        $privateLock = null;
        $temporary = '';
        try {
            $locks = $this->acquireCoreLocks($folder);
            $privateLock = $this->acquirePrivateLock($folder);
            $row = $this->registeredTheme($folder);
            $root = $this->themeRoot($folder);
            $identity = $this->directoryIdentity($root);
            $snapshot = $this->snapshot($root, $folder, false);
            $baseline = $this->readBaseline($folder, (int)$row['id'], $identity);
            $entries = 0;
            $bytes = 0;
            $expectedEntries = [];
            $exportDirectory = $this->storageDirectory('exports');
            $temporary = $exportDirectory . '/theme-source-' . bin2hex(random_bytes(16)) . '.zip';
            $this->writeFileExclusive($temporary, '', 0600);
            $oldUmask = umask(0077);
            try {
                $zip = new ZipArchive();
                if ($zip->open($temporary, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Could not create protected source export.');
                $zipOpen = true;
                try {
                    foreach ($snapshot as $relative => $record) {
                        $state = $this->readRegular($this->resolveRegularPath($root, $relative), $root, self::MAX_SOURCE_BYTES);
                        $this->reserveExport($entries, $bytes, strlen($state['content']));
                        $name = 'current-php/' . $relative;
                        if (!$zip->addFromString($name, $state['content'])) throw new RuntimeException('Could not add source to export.');
                        $expectedEntries[$name] = ['size' => strlen($state['content']), 'sha256' => hash('sha256', $state['content'])];
                    }
                    $revisionRoot = $this->revisionGenerationDirectory((int)$row['id'], $identity, false);
                    if (is_dir($revisionRoot)) {
                        foreach (scandir($revisionRoot, SCANDIR_SORT_ASCENDING) ?: [] as $fileId) {
                            if ($fileId === '.' || $fileId === '..' || preg_match('/\A[a-f0-9]{64}\z/D', $fileId) !== 1) continue;
                            $fileDir = $this->revisionFileDirectory((int)$row['id'], $identity, $fileId, false);
                            if (!is_dir($fileDir)) throw new RuntimeException('Source revision storage is unsafe.');
                            foreach (scandir($fileDir, SCANDIR_SORT_ASCENDING) ?: [] as $revisionId) {
                                if ($revisionId === '.' || $revisionId === '..') continue;
                                $revisionDir = $fileDir . '/' . $revisionId;
                                $meta = $this->readPrivateRegular($revisionDir . '/revision.json', $revisionDir, self::MAX_METADATA_BYTES);
                                $decoded = json_decode($meta, true, 32, JSON_THROW_ON_ERROR);
                                if (!is_array($decoded)
                                    || (string)($decoded['root_identity']['dev'] ?? '') !== (string)$identity['dev']
                                    || (string)($decoded['root_identity']['ino'] ?? '') !== (string)$identity['ino']) continue;
                                $validated = $this->readRevision($revisionDir, $folder, (int)$row['id'], $fileId,
                                    (string)($decoded['relative_path'] ?? ''), $identity);
                                $old = (string)$validated['source'];
                                $this->reserveExport($entries, $bytes, strlen($meta));
                                $this->reserveExport($entries, $bytes, strlen($old));
                                $prefix = 'revisions/' . $fileId . '/' . $revisionId . '/';
                                if (!$zip->addFromString($prefix . 'revision.json', $meta) || !$zip->addFromString($prefix . 'source.php', $old)) {
                                    throw new RuntimeException('Could not add revision to export.');
                                }
                                $expectedEntries[$prefix . 'revision.json'] = ['size' => strlen($meta), 'sha256' => hash('sha256', $meta)];
                                $expectedEntries[$prefix . 'source.php'] = ['size' => strlen($old), 'sha256' => hash('sha256', $old)];
                            }
                        }
                    }
                    if ($baseline !== null) {
                        $json = json_encode($baseline, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
                        $this->reserveExport($entries, $bytes, strlen($json));
                        if (!$zip->addFromString('baseline.json', $json)) throw new RuntimeException('Could not add baseline to export.');
                        $expectedEntries['baseline.json'] = ['size' => strlen($json), 'sha256' => hash('sha256', $json)];
                    }
                    $manifest = [
                        'schema' => 1,
                        'scope' => 'installed_theme_php_source',
                        'exported_at' => gmdate('c'),
                        'exported_by' => $actorId,
                        'theme' => ['folder' => $folder, 'registered_id' => (int)$row['id'], 'version' => (string)($row['version'] ?? '')],
                        'dirty' => $this->dirtyStateFrom($row, $identity, $baseline, $snapshot),
                    ];
                    $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
                    $this->reserveExport($entries, $bytes, strlen($json));
                    if (!$zip->addFromString('export.json', $json)) throw new RuntimeException('Could not add metadata to export.');
                    $expectedEntries['export.json'] = ['size' => strlen($json), 'sha256' => hash('sha256', $json)];
                } finally {
                    if ($zipOpen) {
                        if (!$zip->close()) throw new RuntimeException('Could not finalize protected source export.');
                        $zipOpen = false;
                    }
                }
            } finally {
                umask($oldUmask);
            }
            if (!chmod($temporary, 0600)) throw new RuntimeException('Could not protect source export.');
            $stat = @lstat($temporary);
            if (!is_array($stat) || is_link($temporary) || (($stat['mode'] & 0170000) !== 0100000)
                || ($stat['mode'] & 0777) !== 0600 || (int)$stat['nlink'] !== 1 || (int)$stat['ino'] < 1) {
                throw new RuntimeException('Protected source export verification failed.');
            }
            if ((int)$stat['size'] < 1 || (int)$stat['size'] > self::MAX_EXPORT_BYTES) {
                throw new RuntimeException('Protected source export identity or size is invalid.');
            }
            $this->verifyExportArchive($temporary, $expectedEntries);
            clearstatcache(true, $temporary);
            $verifiedStat = @lstat($temporary);
            if (!is_array($verifiedStat) || !$this->sameFile($stat, $verifiedStat) || is_link($temporary)) {
                throw new RuntimeException('Protected source export changed during verification.');
            }
            $result = [
                'path' => $temporary,
                'size' => (int)$stat['size'],
                'sha256' => (string)hash_file('sha256', $temporary),
                'dev' => (int)$stat['dev'],
                'ino' => (int)$stat['ino'],
                'download_name' => $folder . '-php-source-' . gmdate('Ymd-His') . '.zip',
            ];
            $temporary = '';
            return $result;
        } finally {
            if ($temporary !== '' && !$this->removeExport($temporary)) error_log('[theme-source-export] Failed export cleanup requires operator attention.');
            $this->releasePrivateLock($privateLock);
            if ($locks !== []) theme_operation_release($locks);
        }
    }

    public function removeExport(string $path): bool
    {
        try {
            $directory = $this->storageDirectory('exports', false);
            $real = realpath($path);
            if ($real === false || dirname($real) !== $directory
                || preg_match('/\Atheme-source-[a-f0-9]{32}\.zip\z/D', basename($real)) !== 1) return false;
            $before = @lstat($real);
            $handle = @fopen($real, 'rb');
            $opened = is_resource($handle) ? fstat($handle) : false;
            clearstatcache(true, $real);
            $after = @lstat($real);
            if (!is_array($before) || !is_resource($handle) || !is_array($opened) || !is_array($after)
                || is_link($real) || !$this->sameFile($before, $opened) || !$this->sameFile($opened, $after)
                || ($opened['mode'] & 0777) !== 0600 || (int)$opened['nlink'] !== 1 || (int)$opened['size'] > self::MAX_EXPORT_BYTES) {
                if (is_resource($handle)) fclose($handle);
                return false;
            }
            try {
                return @unlink($real);
            } finally {
                fclose($handle);
            }
        } catch (Throwable) {
            return false;
        }
    }

    public function updatePreflight(array $state, string $folder, array $cachedUpdate, array $manifest): array
    {
        if (($state['schema'] ?? null) !== 1 || !is_array($state['issues'] ?? null) || !is_array($state['decisions'] ?? null)) {
            throw new RuntimeException('Malformed theme update preflight state.');
        }
        $row = $this->registeredTheme($folder);
        $root = $this->themeRoot($folder);
        $identity = $this->directoryIdentity($root);
        $privateLock = $this->acquirePrivateLock($folder);
        try {
            $baseline = $this->readBaseline($folder, (int)$row['id'], $identity);
            if ($baseline === null) $baseline = $this->captureLazyBaseline($folder, $row, $root, $identity, $manifest);
            $current = $this->snapshot($root, $folder, false);
            $dirty = $this->dirtyStateFrom($row, $identity, $baseline, $current);
        } finally {
            $this->releasePrivateLock($privateLock);
        }
        if (($dirty['authoritative'] ?? false) && ($dirty['tracked'] ?? false) && !($dirty['locally_modified'] ?? false)) return $state;

        $tokenPayload = [
            'folder' => $folder,
            'theme_id' => (int)$row['id'],
            'root' => [(string)$identity['dev'], (string)$identity['ino']],
            'version' => (string)($row['version'] ?? ''),
            'files' => $dirty['files'],
            'update' => [(string)($cachedUpdate['current_version'] ?? ''), (string)($cachedUpdate['new_version'] ?? '')],
        ];
        $stateToken = hash('sha256', json_encode($tokenPayload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $decision = $state['decisions']['core.theme-source'] ?? null;
        $resolved = is_array($decision) && ($decision['choice'] ?? null) === 'replace'
            && is_string($decision['state_token'] ?? null) && hash_equals($stateToken, strtolower($decision['state_token']));
        $state['issues'][] = [
            'id' => 'core.theme-source',
            'label' => $this->translate('Local PHP source changes'),
            'message' => $this->translate('This update replaces the complete theme. Export or fork local PHP source before choosing destructive replacement; Core never merges or reapplies it.'),
            'blocking' => true,
            'resolved' => $resolved,
            'state_token' => $stateToken,
            'choices' => [[
                'id' => 'replace',
                'label' => $this->translate('Replace the theme and discard local PHP source changes'),
                'destructive' => true,
            ]],
            'links' => [[
                'label' => $this->translate('Export PHP source'),
                'method' => 'POST',
                'url' => (defined('ADMIN_BASE_PATH') ? (string)ADMIN_BASE_PATH : '') . '/admin/themes/source_export.php',
                'params' => ['folder' => $folder],
            ]],
            'details' => [
                'tracked' => (bool)($dirty['tracked'] ?? false),
                'changed_count' => (int)($dirty['changed_count'] ?? count($dirty['files'] ?? [])),
            ],
        ];
        return $state;
    }

    private function replacePhp(string $folder, string $fileId, string $expectedHash, string $targetToken, string $content,
        int $actorId, array $acknowledgements, string $note, string $operation, ?string $revisionToRestore): array
    {
        $ackKeys = array_keys($acknowledgements);
        sort($ackKeys, SORT_STRING);
        if (preg_match('/\A[a-f0-9]{64}\z/D', $fileId) !== 1 || preg_match('/\A[a-f0-9]{64}\z/D', $expectedHash) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/D', $targetToken) !== 1 || $actorId < 1 || strlen($note) > 500
            || preg_match('//u', $note) !== 1 || $ackKeys !== ['direct', 'live', 'store']
            || !is_bool($acknowledgements['direct']) || !is_bool($acknowledgements['live']) || !is_bool($acknowledgements['store'])) {
            return ['success' => false, 'code' => 'invalid_request', 'error' => 'Invalid installed source request.'];
        }
        if ($operation === 'save' && !$this->validSource($content)) {
            return ['success' => false, 'code' => 'invalid_source', 'error' => 'PHP source must be valid UTF-8 without NUL bytes and within the size limit.'];
        }
        $coreLocks = [];
        $privateLock = null;
        $temporary = '';
        $createdRevision = null;
        $committed = false;
        $result = null;
        $event = null;
        try {
            $this->clearReadCache($folder);
            $coreLocks = $this->acquireCoreLocks($folder);
            $privateLock = $this->acquirePrivateLock($folder);
            $row = $this->registeredTheme($folder);
            $root = $this->themeRoot($folder);
            $rootIdentity = $this->directoryIdentity($root);
            $files = $this->snapshot($root, $folder, true);
            $file = null;
            foreach ($files as $candidate) if (hash_equals((string)$candidate['id'], $fileId)) { $file = $candidate; break; }
            if ($file === null) throw new RuntimeException('Theme source is unavailable. Reload before saving.');
            $relative = (string)$file['path'];
            $target = $this->resolveRegularPath($root, $relative);
            $current = $this->readRegular($target, $root, self::MAX_SOURCE_BYTES);
            if (preg_match('//u', $current['content']) !== 1) throw new RuntimeException('Existing PHP source is not valid UTF-8 and is read-only.');
            if (!hash_equals($expectedHash, $current['sha256'])
                || !hash_equals($targetToken, $this->targetToken((int)$row['id'], $folder, $rootIdentity, $relative, $current['stat']))) {
                throw new RuntimeException('Theme source identity changed. Reload before saving.');
            }
            $policy = $this->applyEditPolicy($this->coreEditState($row), $row, $this->policyContext($row, [
                'operation' => $operation,
                'actor_id' => $actorId,
                'file' => ['id' => $fileId, 'path' => $relative, 'sha256' => $current['sha256']],
            ]));
            if (!$policy['allowed']) throw new RuntimeException((string)$policy['message']);
            if (!$acknowledgements['direct']) {
                throw new RuntimeException($this->translate($operation === 'restore'
                    ? 'Revision restore requires explicit risk acknowledgement.'
                    : 'Direct PHP editing requires explicit risk acknowledgement.'));
            }
            if (($policy['active'] || $policy['assigned']) && !$acknowledgements['live']) {
                throw new RuntimeException($this->translate('Live theme editing requires explicit risk acknowledgement.'));
            }
            if ($policy['store'] && !$acknowledgements['store']) {
                throw new RuntimeException($this->translate('Store-managed theme editing requires explicit risk acknowledgement.'));
            }

            if ($operation === 'restore') {
                $revisionDir = $this->revisionFileDirectory((int)$row['id'], $rootIdentity, $fileId, false) . '/' . $revisionToRestore;
                $restored = $this->readRevision($revisionDir, $folder, (int)$row['id'], $fileId, $relative, $rootIdentity);
                $content = (string)$restored['source'];
                if (!$this->validSource($content)) throw new RuntimeException('Stored source revision is invalid.');
            }
            $resultHash = hash('sha256', $content);
            if (hash_equals($current['sha256'], $resultHash)) {
                return ['success' => true, 'unchanged' => true, 'sha256' => $resultHash, 'target_token' => $targetToken];
            }

            $baseline = $this->readBaseline($folder, (int)$row['id'], $rootIdentity);
            if ($baseline === null) $baseline = $this->captureLazyBaseline($folder, $row, $root, $rootIdentity, $this->readThemeManifest($root, $folder));

            $directory = dirname($target);
            $directoryStat = $this->directoryIdentity($directory);
            $temporary = $directory . '/.theme-source-save-' . bin2hex(random_bytes(12));
            $oldUmask = umask(0077);
            try {
                $handle = @fopen($temporary, 'xb');
            } finally {
                umask($oldUmask);
            }
            if (!is_resource($handle)) throw new RuntimeException('Could not create an atomic source stage.');
            try {
                $protectedStage = @fstat($handle);
                if (!is_array($protectedStage) || (($protectedStage['mode'] & 0170000) !== 0100000)
                    || (($protectedStage['mode'] & 0777) !== 0600) || (int)($protectedStage['nlink'] ?? 0) !== 1) {
                    throw new RuntimeException('Source stage is unsafe.');
                }
                $this->writeAll($handle, $content);
                if (!fflush($handle) || (function_exists('fsync') && !fsync($handle))) throw new RuntimeException('Could not sync the source stage.');
                $stageStat = fstat($handle);
                if (!is_array($stageStat) || (($stageStat['mode'] & 0170000) !== 0100000)
                    || (($stageStat['mode'] & 0777) !== 0600) || (int)($stageStat['nlink'] ?? 0) !== 1) throw new RuntimeException('Source stage is unsafe.');
            } finally {
                fclose($handle);
            }
            $safeMode = (int)$current['stat']['mode'] & 0777;
            if (($safeMode & 0002) !== 0) throw new RuntimeException('Existing PHP source permissions are unsafe.');
            $stageStat = @stat($temporary);
            if (!is_array($stageStat)) throw new RuntimeException('Could not inspect source stage.');
            if ((int)$stageStat['uid'] !== (int)$current['stat']['uid'] && !@chown($temporary, (int)$current['stat']['uid'])) {
                throw new RuntimeException('Could not preserve source ownership.');
            }
            if ((int)$stageStat['gid'] !== (int)$current['stat']['gid'] && !@chgrp($temporary, (int)$current['stat']['gid'])) {
                throw new RuntimeException('Could not preserve source group.');
            }
            if (!chmod($temporary, $safeMode)) throw new RuntimeException('Could not preserve safe source permissions.');
            $stageIdentity = $this->captureStageIdentity($temporary, $directory, $resultHash,
                (int)$current['stat']['uid'], (int)$current['stat']['gid'], $safeMode);
            $this->lintPhp($temporary);
            $this->assertStageIdentity($temporary, $directory, $stageIdentity, $resultHash);

            $revisionId = $this->createRevision($folder, (int)$row['id'], $fileId, $relative, $current['content'],
                $current['sha256'], $resultHash, $current['stat'], $actorId, trim($note), (string)($row['version'] ?? ''),
                $rootIdentity, $operation, $revisionToRestore, (string)($baseline['baseline_id'] ?? ''));
            $createdRevision = [(int)$row['id'], $rootIdentity, $fileId, $revisionId];

            $this->assertDirectoryIdentity($root, $rootIdentity);
            $this->assertDirectoryIdentity($directory, $directoryStat);
            $freshRow = $this->registeredTheme($folder);
            if ((int)$freshRow['id'] !== (int)$row['id']) throw new RuntimeException('Theme registration changed. Reload before saving.');
            $freshTarget = $this->resolveRegularPath($root, $relative);
            $fresh = $this->readRegular($freshTarget, $root, self::MAX_SOURCE_BYTES);
            if (!hash_equals($target, $freshTarget) || !hash_equals($current['sha256'], $fresh['sha256'])
                || (int)$fresh['stat']['dev'] !== (int)$current['stat']['dev'] || (int)$fresh['stat']['ino'] !== (int)$current['stat']['ino']) {
                throw new RuntimeException('Theme source changed before replacement. Reload before saving.');
            }
            if (!function_exists('site_health_invalidate_report') || !site_health_invalidate_report()) {
                throw new RuntimeException('Site Health report could not be invalidated before source replacement.');
            }
            $this->assertDirectoryIdentity($root, $rootIdentity);
            $this->assertDirectoryIdentity($directory, $directoryStat);
            $fresh = $this->readRegular($this->resolveRegularPath($root, $relative), $root, self::MAX_SOURCE_BYTES);
            if (!hash_equals($current['sha256'], $fresh['sha256']) || (int)$fresh['stat']['dev'] !== (int)$current['stat']['dev']
                || (int)$fresh['stat']['ino'] !== (int)$current['stat']['ino']) throw new RuntimeException('Theme source changed before replacement. Reload before saving.');
            $this->assertStageIdentity($temporary, $directory, $stageIdentity, $resultHash);
            if (!rename($temporary, $target)) throw new RuntimeException('Could not atomically replace the source file.');
            $temporary = '';
            $this->syncDirectory($directory);
            clearstatcache(true, $target);
            if (function_exists('opcache_invalidate')) @opcache_invalidate($target, true);
            try {
                $after = $this->readRegular($target, $root, self::MAX_SOURCE_BYTES);
                $this->assertPublishedStage($after, $stageIdentity, $resultHash);
            } catch (Throwable $verificationError) {
                if (!$this->rollbackPublishedStage($target, $directory, $stageIdentity, $current)) {
                    throw new RuntimeException('Saved source verification failed and guarded rollback was incomplete.', 0, $verificationError);
                }
                throw new RuntimeException('Saved source verification failed; exact previous source and metadata were restored.', 0, $verificationError);
            }
            $committed = true;
            $event = [
                'schema' => 1,
                'operation' => $operation,
                'theme_id' => (int)$row['id'],
                'folder' => $folder,
                'file_id' => $fileId,
                'relative_path' => $relative,
                'before_sha256' => $current['sha256'],
                'after_sha256' => $resultHash,
                'revision_id' => $revisionId,
                'actor_id' => $actorId,
                'changed_at' => gmdate('c'),
            ];
            $result = [
                'success' => true,
                'sha256' => $resultHash,
                'target_token' => $this->targetToken((int)$row['id'], $folder, $rootIdentity, $relative, $after['stat']),
                'revision_id' => $revisionId,
            ];
            if ($revisionToRestore !== null) $result['restored_from_revision_id'] = $revisionToRestore;
        } catch (Throwable $error) {
            if (!$committed && is_array($createdRevision)) $this->removeRevision(...$createdRevision);
            $message = $this->safeError($error);
            $stale = str_contains(strtolower($message), 'reload');
            $result = ['success' => false, 'code' => $stale ? 'stale_source' : 'source_save_failed', 'error' => $message, 'reload_required' => $stale];
        } finally {
            if ($temporary !== '' && is_file($temporary) && !is_link($temporary)) @unlink($temporary);
            $this->releasePrivateLock($privateLock);
            if ($coreLocks !== []) theme_operation_release($coreLocks);
        }
        if ($committed && is_array($event) && function_exists('do_action_isolated')) {
            foreach (do_action_isolated('theme_source_changed', $event, $this->pdo) as $hookError) {
                error_log('[theme_source_changed] ' . $hookError['message']);
            }
        }
        if ($committed && is_array($result)) {
            try {
                $dirty = $this->dirtyState($folder);
                if (!isset($dirty['error'])) $result['dirty'] = $dirty;
            } catch (Throwable $error) {
                error_log('[theme-source] Optional dirty-state refresh failed after committed replacement: ' . $error->getMessage());
            }
        }
        return is_array($result) ? $result : ['success' => false, 'code' => 'source_save_failed', 'error' => 'Theme source operation failed safely.'];
    }

    private function captureStageIdentity(string $path, string $directory, string $expectedHash, int $uid, int $gid, int $mode): array
    {
        $state = $this->readRegular($path, $directory, self::MAX_SOURCE_BYTES);
        $stat = $state['stat'];
        if (!hash_equals($expectedHash, $state['sha256']) || (int)$stat['uid'] !== $uid || (int)$stat['gid'] !== $gid
            || ((int)$stat['mode'] & 0777) !== $mode || (int)($stat['nlink'] ?? 0) !== 1) throw new RuntimeException('Source stage metadata verification failed.');
        return ['dev' => (int)$stat['dev'], 'ino' => (int)$stat['ino'], 'size' => (int)$stat['size'], 'mtime' => (int)$stat['mtime'],
            'uid' => $uid, 'gid' => $gid, 'mode' => $mode, 'sha256' => $state['sha256']];
    }

    private function assertStageIdentity(string $path, string $directory, array $identity, string $expectedHash): void
    {
        $fresh = $this->captureStageIdentity($path, $directory, $expectedHash, (int)$identity['uid'], (int)$identity['gid'], (int)$identity['mode']);
        foreach (['dev', 'ino', 'size', 'mtime', 'uid', 'gid', 'mode', 'sha256'] as $key) {
            if ($fresh[$key] !== $identity[$key]) throw new RuntimeException('Linted source stage identity changed before replacement.');
        }
    }

    private function assertPublishedStage(array $after, array $stage, string $expectedHash): void
    {
        $stat = $after['stat'];
        if (!hash_equals($expectedHash, (string)$after['sha256']) || (int)$stat['dev'] !== (int)$stage['dev']
            || (int)$stat['ino'] !== (int)$stage['ino'] || (int)$stat['uid'] !== (int)$stage['uid']
            || (int)$stat['gid'] !== (int)$stage['gid'] || ((int)$stat['mode'] & 0777) !== (int)$stage['mode']) {
            throw new RuntimeException('Published source stage verification failed.');
        }
    }

    private function rollbackPublishedStage(string $target, string $directory, array $published, array $previous): bool
    {
        $rollback = $directory . '/.theme-source-rollback-' . bin2hex(random_bytes(12));
        try {
            $live = @lstat($target);
            if (!is_array($live) || is_link($target) || (int)$live['dev'] !== (int)$published['dev'] || (int)$live['ino'] !== (int)$published['ino']) return false;
            $oldUmask = umask(0077);
            try {
                $handle = @fopen($rollback, 'xb');
            } finally {
                umask($oldUmask);
            }
            if (!is_resource($handle)) return false;
            try {
                $protectedRollback = @fstat($handle);
                if (!is_array($protectedRollback) || (($protectedRollback['mode'] & 0170000) !== 0100000)
                    || (($protectedRollback['mode'] & 0777) !== 0600) || (int)($protectedRollback['nlink'] ?? 0) !== 1) return false;
                $this->writeAll($handle, (string)$previous['content']);
                if (!fflush($handle) || (function_exists('fsync') && !fsync($handle))) return false;
            } finally {
                fclose($handle);
            }
            $rollbackStat = @stat($rollback);
            if (!is_array($rollbackStat)
                || ((int)$rollbackStat['uid'] !== (int)$previous['stat']['uid'] && !@chown($rollback, (int)$previous['stat']['uid']))
                || ((int)$rollbackStat['gid'] !== (int)$previous['stat']['gid'] && !@chgrp($rollback, (int)$previous['stat']['gid']))
                || !@chmod($rollback, (int)$previous['stat']['mode'] & 0777)) return false;
            $rollbackIdentity = $this->captureStageIdentity($rollback, $directory, (string)$previous['sha256'],
                (int)$previous['stat']['uid'], (int)$previous['stat']['gid'], (int)$previous['stat']['mode'] & 0777);
            clearstatcache(true, $target);
            $live = @lstat($target);
            if (!is_array($live) || (int)$live['dev'] !== (int)$published['dev'] || (int)$live['ino'] !== (int)$published['ino']) return false;
            if (!rename($rollback, $target)) return false;
            $rollback = '';
            $this->syncDirectory($directory);
            clearstatcache(true, $target);
            $verified = $this->readRegular($target, $directory, self::MAX_SOURCE_BYTES);
            $this->assertPublishedStage($verified, $rollbackIdentity, (string)$previous['sha256']);
            if (function_exists('opcache_invalidate')) @opcache_invalidate($target, true);
            return true;
        } catch (Throwable $error) {
            error_log('[theme-source] Guarded source rollback failed: ' . $error->getMessage());
            return false;
        } finally {
            if ($rollback !== '' && is_file($rollback) && !is_link($rollback)) @unlink($rollback);
        }
    }

    private function validSource(string $source): bool
    {
        return strlen($source) <= self::MAX_SOURCE_BYTES && !str_contains($source, "\0") && preg_match('//u', $source) === 1;
    }

    private function coreEditState(array $row): array
    {
        $folder = (string)$row['folder_name'];
        $system = !empty($row['is_system']) || $folder === (defined('DEFAULT_THEME_FOLDER') ? (string)DEFAULT_THEME_FOLDER : 'default');
        $state = [
            'allowed' => !$system,
            'message' => $system ? $this->translate('Default and system themes are read-only.') : '',
            'active' => !empty($row['is_active']),
            'assigned' => $this->assignmentCount((int)$row['id']) > 0,
            'store' => trim((string)($row['store_url'] ?? '')) !== '' || trim((string)($row['store_slug'] ?? '')) !== '',
            'system' => $system,
        ];
        if (!$state['store']) {
            try {
                $manifest = $this->readThemeManifest($this->themeRoot($folder), $folder);
                $state['store'] = trim((string)($manifest['store']['url'] ?? '')) !== '' || trim((string)($manifest['store']['slug'] ?? '')) !== '';
            } catch (Throwable) {
            }
        }
        return $state;
    }

    private function policyContext(array $row, array $context): array
    {
        $core = $this->coreEditState($row);
        return [
            'schema' => 1,
            'operation' => in_array($context['operation'] ?? null, ['edit', 'save', 'restore'], true) ? $context['operation'] : 'edit',
            'folder' => (string)$row['folder_name'],
            'file' => is_array($context['file'] ?? null) ? $context['file'] : null,
            'active' => $core['active'],
            'assigned' => $core['assigned'],
            'store' => $core['store'],
            'system' => $core['system'],
            'actor_id' => max(0, (int)($context['actor_id'] ?? 0)),
        ];
    }

    private function applyEditPolicy(array $coreState, array $row, array $context): array
    {
        $state = ['allowed' => (bool)$coreState['allowed'], 'message' => (string)$coreState['message']];
        try {
            $callbacks = $GLOBALS['_hooks']['filters']['theme_source_edit_policy'] ?? [];
            ksort($callbacks);
            foreach ($callbacks as $listeners) {
                foreach ($listeners as $listener) {
                    $candidate = call_user_func($listener, $state, $row, $context, $this->pdo);
                    $keys = is_array($candidate) ? array_keys($candidate) : [];
                    sort($keys, SORT_STRING);
                    if (!is_array($candidate) || $keys !== ['allowed', 'message']
                        || !is_bool($candidate['allowed']) || !is_string($candidate['message']) || strlen($candidate['message']) > 2000
                        || (!$state['allowed'] && $candidate['allowed'])) {
                        throw new RuntimeException('Theme source edit policy returned an invalid or non-monotonic state.');
                    }
                    $state = $candidate;
                }
            }
        } catch (Throwable $error) {
            error_log('[theme_source_edit_policy] ' . $error->getMessage());
            $state = ['allowed' => false, 'message' => $this->translate('Theme source editing was denied because policy validation failed.')];
        }
        return $state + [
            'active' => (bool)$coreState['active'],
            'assigned' => (bool)$coreState['assigned'],
            'store' => (bool)$coreState['store'],
            'system' => (bool)$coreState['system'],
        ];
    }

    private function registeredTheme(string $folder): array
    {
        $this->assertFolder($folder);
        $stmt = $this->pdo->prepare('SELECT * FROM themes WHERE folder_name = ? LIMIT 1');
        $stmt->execute([$folder]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || !is_string($row['folder_name'] ?? null) || !hash_equals($folder, $row['folder_name'])) {
            throw new RuntimeException('Theme is not registered with this exact folder identity.');
        }
        return $row;
    }

    private function assignmentCount(int $themeId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM assignments WHERE theme_id = ?');
        $stmt->execute([$themeId]);
        return (int)$stmt->fetchColumn();
    }

    private function themeSummary(array $row): array
    {
        $state = $this->coreEditState($row);
        return [
            'id' => (int)$row['id'],
            'folder' => (string)$row['folder_name'],
            'name' => (string)($row['name'] ?? $row['folder_name']),
            'version' => (string)($row['version'] ?? ''),
            'active' => $state['active'],
            'assigned' => $state['assigned'],
            'store' => $state['store'],
            'system' => $state['system'],
        ];
    }

    private function assertFolder(string $folder): void
    {
        if (strlen($folder) > 128 || preg_match('/\A[A-Za-z0-9_-][A-Za-z0-9._-]*\z/D', $folder) !== 1 || in_array($folder, ['.', '..'], true)) {
            throw new InvalidArgumentException('Invalid theme folder.');
        }
    }

    private function themeRoot(string $folder): string
    {
        if (!defined('VIEWS_BASE')) throw new RuntimeException('Theme root is unavailable.');
        $base = realpath((string)VIEWS_BASE);
        $candidate = rtrim((string)VIEWS_BASE, '/\\') . DIRECTORY_SEPARATOR . $folder;
        $root = realpath($candidate);
        if ($base === false || $root === false || is_link($candidate) || !is_dir($root) || dirname($root) !== $base) {
            throw new RuntimeException('Theme directory is unavailable or unsafe.');
        }
        return $root;
    }

    /** @return array<string,array<string,mixed>> */
    private function snapshot(string $root, string $folder, bool $inventory): array
    {
        $result = [];
        $entries = 0;
        $bytes = 0;
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $entry) {
            if (++$entries > self::MAX_ENTRIES) throw new RuntimeException('Theme tree is too large to inspect.');
            $path = $entry->getPathname();
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', $iterator->getSubPathName());
            $this->assertRelativePath($relative);
            if (substr_count($relative, '/') + 1 > self::MAX_DEPTH) throw new RuntimeException('Theme tree nesting is too deep.');
            $stat = @lstat($path);
            if (!is_array($stat) || is_link($path)) throw new RuntimeException('Theme tree contains a symlink or unreadable entry.');
            $type = $stat['mode'] & 0170000;
            if ($type === 0040000) {
                if (!is_readable($path)) throw new RuntimeException('Theme tree contains an unreadable directory.');
                continue;
            }
            if ($type !== 0100000) throw new RuntimeException('Theme tree contains a special filesystem entry.');
            if (strtolower(pathinfo($relative, PATHINFO_EXTENSION)) !== 'php') continue;
            if (count($result) >= self::MAX_FILES || (int)$stat['size'] > self::MAX_SOURCE_BYTES) throw new RuntimeException('Theme PHP inventory exceeds its safety limits.');
            $bytes += (int)$stat['size'];
            if ($bytes > self::MAX_TOTAL_BYTES) throw new RuntimeException('Theme PHP inventory exceeds its total size limit.');
            $state = $this->readRegular($path, $root, self::MAX_SOURCE_BYTES, false);
            $record = [
                'id' => hash('sha256', $folder . "\0" . $relative),
                'path' => $relative,
                'name' => basename($relative),
                'directory' => dirname($relative) === '.' ? '' : dirname($relative),
                'size' => $state['size'],
                'sha256' => $state['sha256'],
            ];
            if ($inventory) {
                $record += [
                    'modified_at' => (int)$state['stat']['mtime'],
                    'mode' => sprintf('%04o', $state['stat']['mode'] & 07777),
                    'writable' => is_writable($path) && is_writable(dirname($path)),
                ];
            }
            $result[$relative] = $record;
        }
        ksort($result, SORT_STRING);
        return $result;
    }

    /** Request-local render cache only; mutation paths always call snapshot() directly. */
    private function readSnapshot(string $root, string $folder): array
    {
        $identity = $this->directoryIdentity($root);
        $key = $folder . "\0" . $identity['dev'] . "\0" . $identity['ino'];
        return $this->readSnapshotCache[$key] ??= $this->snapshot($root, $folder, true);
    }

    private function clearReadCache(string $folder): void
    {
        foreach (array_keys($this->readSnapshotCache) as $key) {
            if (str_starts_with($key, $folder . "\0")) unset($this->readSnapshotCache[$key]);
        }
        foreach (array_keys($this->readSourceCache) as $key) {
            if (str_starts_with($key, $folder . "\0")) unset($this->readSourceCache[$key]);
        }
    }

    private function resolveRegularPath(string $root, string $relative): string
    {
        $this->assertRelativePath($relative);
        $path = $root;
        foreach (explode('/', $relative) as $segment) {
            $path .= DIRECTORY_SEPARATOR . $segment;
            if (is_link($path)) throw new RuntimeException('Theme path contains a symlink.');
        }
        $real = realpath($path);
        $stat = @lstat($path);
        if ($real === false || !is_array($stat) || (($stat['mode'] & 0170000) !== 0100000) || !$this->isWithin($root, $real)) {
            throw new RuntimeException('Theme source file is unavailable.');
        }
        return $real;
    }

    private function readRegular(string $path, string $root, int $limit, bool $content = true): array
    {
        $before = @lstat($path);
        if (!is_array($before) || is_link($path) || (($before['mode'] & 0170000) !== 0100000) || (int)$before['size'] > $limit) {
            throw new RuntimeException('Theme source is unsafe or too large.');
        }
        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) throw new RuntimeException('Theme source could not be opened.');
        $data = '';
        $hash = hash_init('sha256');
        $bytes = 0;
        try {
            $opened = fstat($handle);
            if (!is_array($opened) || !$this->sameFile($before, $opened)) throw new RuntimeException('Theme source changed while opening.');
            while (!feof($handle)) {
                $chunk = fread($handle, 65536);
                if ($chunk === false) throw new RuntimeException('Theme source could not be read.');
                $bytes += strlen($chunk);
                if ($bytes > $limit) throw new RuntimeException('Theme source exceeds the size limit.');
                hash_update($hash, $chunk);
                if ($content) $data .= $chunk;
            }
            $afterRead = fstat($handle);
        } finally {
            fclose($handle);
        }
        clearstatcache(true, $path);
        $after = @lstat($path);
        $real = realpath($path);
        if (!is_array($afterRead) || !is_array($after) || $real === false || is_link($path)
            || !$this->sameFile($opened, $afterRead) || !$this->sameFile($afterRead, $after) || !$this->isWithin($root, $real)) {
            throw new RuntimeException('Theme source changed during inspection.');
        }
        return ['content' => $data, 'size' => $bytes, 'sha256' => hash_final($hash), 'stat' => $after];
    }

    private function targetToken(int $themeId, string $folder, array $root, string $relative, array $file): string
    {
        return hash('sha256', implode("\0", ['theme-source-target-v1', (string)$themeId, $folder,
            (string)$root['dev'], (string)$root['ino'], $relative, (string)$file['dev'], (string)$file['ino']]));
    }

    private function baselinePath(string $folder, int $themeId, array $identity): string
    {
        return $this->storageDirectory('baselines') . '/' . hash('sha256', implode("\0", [$folder, (string)$themeId,
            (string)$identity['dev'], (string)$identity['ino']])) . '.json';
    }

    private function readBaseline(string $folder, int $themeId, array $identity): ?array
    {
        $path = $this->baselinePath($folder, $themeId, $identity);
        if (!file_exists($path)) return null;
        $raw = $this->readPrivateRegular($path, dirname($path), self::MAX_METADATA_BYTES);
        $baseline = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($baseline) || ($baseline['schema'] ?? null) !== 1 || ($baseline['theme']['folder'] ?? null) !== $folder
            || ($baseline['theme']['registered_id'] ?? null) !== $themeId
            || (string)($baseline['theme']['root_identity']['dev'] ?? '') !== (string)$identity['dev']
            || (string)($baseline['theme']['root_identity']['ino'] ?? '') !== (string)$identity['ino']
            || !is_array($baseline['authority'] ?? null)
            || !is_bool($baseline['authority']['trusted'] ?? null)
            || !in_array($baseline['authority']['kind'] ?? null, ['core_lifecycle', 'canonical_exact_store', 'observation'], true)
            || !is_string($baseline['authority']['version'] ?? null) || strlen($baseline['authority']['version']) > 255
            || (($baseline['authority']['trusted'] ?? false) !== in_array($baseline['authority']['kind'] ?? null, ['core_lifecycle', 'canonical_exact_store'], true))
            || !is_array($baseline['files'] ?? null) || count($baseline['files']) > self::MAX_FILES) {
            throw new RuntimeException('Theme source baseline is invalid.');
        }
        foreach ($baseline['files'] as $relative => $record) {
            $this->assertRelativePath((string)$relative);
            if (!is_array($record) || ($record['file_id'] ?? null) !== hash('sha256', $folder . "\0" . $relative)
                || preg_match('/\A[a-f0-9]{64}\z/D', (string)($record['sha256'] ?? '')) !== 1) {
                throw new RuntimeException('Theme source baseline is invalid.');
            }
        }
        return $baseline;
    }

    private function captureLazyBaseline(string $folder, array $row, string $root, array $identity, array $manifest): ?array
    {
        $existing = $this->readBaseline($folder, (int)$row['id'], $identity);
        if ($existing !== null) return $existing;
        $snapshot = $this->snapshot($root, $folder, false);
        $origin = 'pre_first_edit';
        $authority = ['trusted' => false, 'kind' => 'observation', 'version' => $version = trim((string)($manifest['version'] ?? $row['version'] ?? ''))];
        $store = is_array($manifest['store'] ?? null) ? $manifest['store'] : [];
        $storeUrl = trim((string)($store['url'] ?? $row['store_url'] ?? ''));
        $slug = trim((string)($store['slug'] ?? $row['store_slug'] ?? ''));
        $canonicalStore = function_exists('extension_release_manifest_canonical_store')
            && extension_release_manifest_canonical_store('theme', $storeUrl);
        if ($canonicalStore) {
            if (!extension_release_manifest_slug_valid($slug) || !extension_release_manifest_version_valid($version)) return null;
            $resolved = extension_release_manifest_fetch('theme', $slug, $version, microtime(true) + 5.0);
            if (is_array($resolved['manifest'] ?? null)) {
                $canonical = [];
                foreach ($resolved['manifest']['files'] as $relative => $hash) {
                    if (strtolower(pathinfo((string)$relative, PATHINFO_EXTENSION)) !== 'php') continue;
                    $this->assertRelativePath((string)$relative);
                    $canonical[$relative] = ['id' => hash('sha256', $folder . "\0" . $relative), 'sha256' => $hash, 'size' => 0];
                }
                $snapshot = $canonical;
                $origin = 'canonical_store_lazy';
                $authority = ['trusted' => true, 'kind' => 'canonical_exact_store', 'version' => $version];
            } else return null;
        }
        return $this->publishBaseline($folder, $row, $identity, $snapshot, $origin, 0, false, $authority);
    }

    private function publishBaseline(string $folder, array $row, array $identity, array $snapshot, string $origin, int $actorId,
        bool $replace, array $authority): array
    {
        $files = [];
        foreach ($snapshot as $relative => $record) {
            $files[$relative] = [
                'file_id' => hash('sha256', $folder . "\0" . $relative),
                'sha256' => (string)$record['sha256'],
                'size' => (int)($record['size'] ?? 0),
            ];
        }
        ksort($files, SORT_STRING);
        $baseline = [
            'schema' => 1,
            'baseline_id' => bin2hex(random_bytes(16)),
            'theme' => ['folder' => $folder, 'registered_id' => (int)$row['id'],
                'root_identity' => ['dev' => (string)$identity['dev'], 'ino' => (string)$identity['ino']]],
            'installed' => ['version' => (string)($row['version'] ?? ''), 'store_url' => (string)($row['store_url'] ?? ''), 'store_slug' => (string)($row['store_slug'] ?? '')],
            'scope' => 'physical_php',
            'origin' => $origin,
            'authority' => $authority,
            'captured_at' => gmdate('c'),
            'captured_by' => $actorId,
            'files' => $files,
        ];
        $path = $this->baselinePath($folder, (int)$row['id'], $identity);
        if (!$replace && (file_exists($path) || is_link($path))) throw new RuntimeException('Theme source baseline publication conflicted.');
        $this->writeJsonAtomic($path, $baseline, 0660);
        $verified = $this->readBaseline($folder, (int)$row['id'], $identity);
        if ($verified !== $baseline) throw new RuntimeException('Theme source baseline verification failed.');
        return $baseline;
    }

    private function dirtyStateFrom(array $row, array $identity, ?array $baseline, array $current): array
    {
        $authoritative = $baseline !== null && ($baseline['authority']['trusted'] ?? false) === true
            && hash_equals((string)($baseline['authority']['version'] ?? ''), (string)($row['version'] ?? ''));
        if ($baseline === null || !$authoritative) {
            $files = [];
            foreach ($current as $path => $record) $files[$path] = ['status' => 'untracked', 'baseline_sha256' => null, 'current_sha256' => $record['sha256']];
            return ['tracked' => false, 'authoritative' => false, 'observed' => $baseline !== null,
                'baseline_id' => $baseline['baseline_id'] ?? null, 'baseline_origin' => $baseline['origin'] ?? null,
                'locally_modified' => false, 'changed_count' => 0, 'counts' => ['untracked' => count($files)],
                'files' => $files, 'registered_theme_id' => (int)$row['id'], 'current_version' => (string)($row['version'] ?? '')];
        }
        $counts = ['clean' => 0, 'modified' => 0, 'added' => 0, 'deleted' => 0];
        $files = [];
        foreach ($baseline['files'] as $path => $old) {
            $currentHash = isset($current[$path]) ? (string)$current[$path]['sha256'] : null;
            $status = $currentHash === null ? 'deleted' : (hash_equals((string)$old['sha256'], $currentHash) ? 'clean' : 'modified');
            $counts[$status]++;
            $files[$path] = ['status' => $status, 'baseline_sha256' => $old['sha256'], 'current_sha256' => $currentHash];
        }
        foreach ($current as $path => $record) {
            if (isset($baseline['files'][$path])) continue;
            $counts['added']++;
            $files[$path] = ['status' => 'added', 'baseline_sha256' => null, 'current_sha256' => $record['sha256']];
        }
        ksort($files, SORT_STRING);
        $changed = $counts['modified'] + $counts['added'] + $counts['deleted'];
        return ['tracked' => true, 'authoritative' => true, 'observed' => false, 'baseline_id' => $baseline['baseline_id'], 'baseline_origin' => $baseline['origin'],
            'locally_modified' => $changed > 0, 'changed_count' => $changed, 'counts' => $counts, 'files' => $files,
            'registered_theme_id' => (int)$row['id'], 'baseline_version' => (string)$baseline['installed']['version'],
            'current_version' => (string)($row['version'] ?? ''), 'root_identity' => ['dev' => (string)$identity['dev'], 'ino' => (string)$identity['ino']]];
    }

    private function createRevision(string $folder, int $themeId, string $fileId, string $relative, string $previous,
        string $previousHash, string $resultHash, array $targetStat, int $actorId, string $note, string $version,
        array $rootIdentity, string $operation, ?string $restoredFrom, string $baselineId): string
    {
        $fileDir = $this->revisionFileDirectory($themeId, $rootIdentity, $fileId, true);
        $count = 0;
        $bytes = strlen($previous);
        foreach (scandir($fileDir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            if (preg_match('/\A\d{8}T\d{6}Z-[a-f0-9]{16}\z/D', $entry) !== 1) throw new RuntimeException('Source revision storage is unsafe.');
            $revisionDir = $fileDir . '/' . $entry;
            $this->assertPrivateDirectory($revisionDir, $fileDir);
            $source = $revisionDir . '/source.php';
            $stat = @lstat($source);
            if (!is_array($stat) || is_link($source) || (($stat['mode'] & 0170000) !== 0100000)) throw new RuntimeException('Source revision storage is unsafe.');
            $count++;
            $bytes += (int)$stat['size'];
        }
        if ($count >= self::MAX_REVISIONS || $bytes > self::MAX_REVISION_BYTES) throw new RuntimeException('Source revision retention limit was reached.');
        $revisionId = gmdate('Ymd\THis\Z') . '-' . bin2hex(random_bytes(8));
        $directory = $this->ensurePrivateChild($fileDir, $revisionId);
        try {
            $this->writeFileExclusive($directory . '/source.php', $previous, 0660);
            if (!hash_equals($previousHash, (string)hash_file('sha256', $directory . '/source.php'))) throw new RuntimeException('Source revision verification failed.');
            $metadata = [
                'schema' => 1, 'revision_id' => $revisionId, 'theme_folder' => $folder, 'theme_id' => $themeId,
                'file_id' => $fileId, 'relative_path' => $relative, 'previous_sha256' => $previousHash,
                'result_sha256' => $resultHash, 'actor_user_id' => $actorId, 'created_at' => gmdate('c'),
                'owner' => (int)$targetStat['uid'], 'group' => (int)$targetStat['gid'], 'mode' => sprintf('%04o', $targetStat['mode'] & 07777),
                'source_version' => $version, 'change_note' => $note, 'operation' => $operation,
                'restored_from_revision_id' => $restoredFrom, 'baseline_id' => $baselineId,
                'root_identity' => ['dev' => (string)$rootIdentity['dev'], 'ino' => (string)$rootIdentity['ino']],
            ];
            $this->writeJsonAtomic($directory . '/revision.json', $metadata, 0660);
            $this->syncDirectory($directory);
            $this->syncDirectory($fileDir);
            return $revisionId;
        } catch (Throwable $error) {
            @unlink($directory . '/revision.json');
            @unlink($directory . '/source.php');
            @rmdir($directory);
            throw $error;
        }
    }

    private function readRevision(string $directory, string $folder, int $themeId, string $fileId, string $relative, array $rootIdentity): array
    {
        $revisionId = basename($directory);
        if (preg_match('/\A\d{8}T\d{6}Z-[a-f0-9]{16}\z/D', $revisionId) !== 1) throw new RuntimeException('Source revision identity is invalid.');
        $fileDirectory = $this->revisionFileDirectory($themeId, $rootIdentity, $fileId, false);
        if (dirname($directory) !== $fileDirectory) throw new RuntimeException('Source revision identity is invalid.');
        $this->assertPrivateDirectory($directory, $fileDirectory);
        $children = array_values(array_filter(scandir($directory) ?: [], static fn(string $v): bool => $v !== '.' && $v !== '..'));
        sort($children, SORT_STRING);
        if ($children !== ['revision.json', 'source.php']) throw new RuntimeException('Source revision is incomplete.');
        $source = $this->readPrivateRegular($directory . '/source.php', $directory, self::MAX_SOURCE_BYTES);
        $metadata = json_decode($this->readPrivateRegular($directory . '/revision.json', $directory, self::MAX_METADATA_BYTES), true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($metadata) || ($metadata['schema'] ?? null) !== 1 || ($metadata['revision_id'] ?? null) !== $revisionId
            || ($metadata['theme_folder'] ?? null) !== $folder || ($metadata['theme_id'] ?? null) !== $themeId
            || ($metadata['file_id'] ?? null) !== $fileId || ($metadata['relative_path'] ?? null) !== $relative
            || !hash_equals((string)($metadata['previous_sha256'] ?? ''), hash('sha256', $source))
            || (string)($metadata['root_identity']['dev'] ?? '') !== (string)$rootIdentity['dev']
            || (string)($metadata['root_identity']['ino'] ?? '') !== (string)$rootIdentity['ino']) {
            throw new RuntimeException('Source revision metadata is invalid.');
        }
        $metadata['source'] = $source;
        return $metadata;
    }

    private function removeRevision(int $themeId, array $rootIdentity, string $fileId, string $revisionId): void
    {
        if ($themeId < 1 || preg_match('/\A[a-f0-9]{64}\z/D', $fileId) !== 1 || preg_match('/\A\d{8}T\d{6}Z-[a-f0-9]{16}\z/D', $revisionId) !== 1) return;
        try {
            $directory = $this->revisionFileDirectory($themeId, $rootIdentity, $fileId, false) . '/' . $revisionId;
            $this->assertPrivateDirectory($directory, dirname($directory));
            @unlink($directory . '/revision.json');
            @unlink($directory . '/source.php');
            @rmdir($directory);
        } catch (Throwable) {
        }
    }

    private function readThemeManifest(string $root, string $folder): array
    {
        $raw = $this->readRegular($this->resolveRegularPath($root, 'theme.json'), $root, self::MAX_METADATA_BYTES)['content'];
        $manifest = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($manifest) || array_is_list($manifest) || (isset($manifest['folder']) && $manifest['folder'] !== $folder)) {
            throw new RuntimeException('Theme manifest identity is invalid.');
        }
        $manifest['folder'] = $folder;
        return $manifest;
    }

    private function revisionThemeDirectory(int $themeId, bool $create): string
    {
        if ($themeId < 1) throw new RuntimeException('Source revision storage identity is invalid.');
        $root = $this->storageDirectory('revisions', $create);
        $path = $root . '/' . $themeId;
        if (!$create && !file_exists($path) && !is_link($path)) return $path;
        return $create ? $this->ensurePrivateChild($root, (string)$themeId) : $this->validatedPrivateChild($root, (string)$themeId);
    }

    private function revisionGenerationDirectory(int $themeId, array $rootIdentity, bool $create): string
    {
        if (!isset($rootIdentity['dev'], $rootIdentity['ino'])) throw new RuntimeException('Source revision root identity is invalid.');
        $theme = $this->revisionThemeDirectory($themeId, $create);
        $generation = hash('sha256', (string)$rootIdentity['dev'] . "\0" . (string)$rootIdentity['ino']);
        $path = $theme . '/' . $generation;
        if (!$create && !file_exists($path) && !is_link($path)) return $path;
        return $create ? $this->ensurePrivateChild($theme, $generation) : $this->validatedPrivateChild($theme, $generation);
    }

    private function revisionFileDirectory(int $themeId, array $rootIdentity, string $fileId, bool $create): string
    {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $fileId) !== 1) throw new RuntimeException('Source revision file identity is invalid.');
        $generation = $this->revisionGenerationDirectory($themeId, $rootIdentity, $create);
        $path = $generation . '/' . $fileId;
        if (!$create && !file_exists($path) && !is_link($path)) return $path;
        return $create ? $this->ensurePrivateChild($generation, $fileId) : $this->validatedPrivateChild($generation, $fileId);
    }

    private function validatedPrivateChild(string $parent, string $name): string
    {
        $this->assertOwnedDirectory($parent);
        $parentReal = realpath($parent);
        $path = $parent . '/' . $name;
        $this->assertPrivateDirectory($path, $parent);
        $real = realpath($path);
        if ($parentReal === false || $real === false || dirname($real) !== $parentReal || basename($real) !== $name) {
            throw new RuntimeException('Private theme source storage is unsafe.');
        }
        return $real;
    }

    private function storageRoot(): string
    {
        $backend = defined('BACKEND_PATH') ? rtrim((string)BACKEND_PATH, '/\\') : dirname(__DIR__);
        $cfg = realpath($backend);
        $public = defined('PUBLIC_PATH') ? realpath((string)PUBLIC_PATH) : false;
        if ($cfg === false || is_link($backend) || ($public !== false && $this->isWithin($public, $cfg))) {
            throw new RuntimeException('Private theme source storage is unavailable.');
        }
        $this->assertSafeDirectory($cfg);
        $var = $cfg . '/var';
        if (is_link($var) || (file_exists($var) && !is_dir($var))) throw new RuntimeException('Private theme source storage is unavailable.');
        if (!is_dir($var) && !@mkdir($var, 0770)) throw new RuntimeException('Private theme source storage is unavailable.');
        $this->assertOwnedDirectory($var, false);
        $root = $var . '/theme-source';
        if (is_link($root) || (file_exists($root) && !is_dir($root))) throw new RuntimeException('Private theme source storage is unavailable.');
        if (!is_dir($root) && !@mkdir($root, 0770)) throw new RuntimeException('Private theme source storage is unavailable.');
        @chmod($root, 0770);
        $this->assertOwnedDirectory($root);
        $real = realpath($root);
        if ($real === false || dirname($real) !== realpath($var) || ($public !== false && $this->isWithin($public, $real))) {
            throw new RuntimeException('Private theme source storage escaped its root.');
        }
        return $real;
    }

    private function storageDirectory(string $name, bool $create = true): string
    {
        if (!in_array($name, ['baselines', 'revisions', 'exports', 'locks'], true)) throw new RuntimeException('Private storage identity is invalid.');
        $root = $this->storageRoot();
        $path = $root . '/' . $name;
        if (is_link($path) || (file_exists($path) && !is_dir($path))) throw new RuntimeException('Private theme source storage is unavailable.');
        if (!$create && !file_exists($path)) return $path;
        if (!is_dir($path) && !@mkdir($path, 0770)) throw new RuntimeException('Private theme source storage is unavailable.');
        @chmod($path, 0770);
        $this->assertOwnedDirectory($path);
        $real = realpath($path);
        if ($real === false || dirname($real) !== $root) throw new RuntimeException('Private theme source storage escaped its root.');
        return $real;
    }

    private function ensurePrivateChild(string $parent, string $name): string
    {
        if (preg_match('/\A(?:[a-f0-9]{16,64}|[1-9]\d{0,18}|\d{8}T\d{6}Z-[a-f0-9]{16})\z/D', $name) !== 1) throw new RuntimeException('Private storage identity is invalid.');
        $this->assertOwnedDirectory($parent);
        $parentReal = realpath($parent);
        $path = $parentReal . '/' . $name;
        if (is_link($path) || (file_exists($path) && !is_dir($path))) throw new RuntimeException('Private theme source storage is unavailable.');
        if (!is_dir($path) && !@mkdir($path, 0770)) throw new RuntimeException('Private theme source storage is unavailable.');
        @chmod($path, 0770);
        $this->assertPrivateDirectory($path, $parentReal);
        return (string)realpath($path);
    }

    private function assertSafeDirectory(string $path): void
    {
        $stat = @lstat($path);
        if (!is_array($stat) || is_link($path) || (($stat['mode'] & 0170000) !== 0040000) || ($stat['mode'] & 0002) !== 0) {
            throw new RuntimeException('Private theme source storage permissions are unsafe.');
        }
    }

    private function assertOwnedDirectory(string $path, bool $exactMode = true): void
    {
        $this->assertSafeDirectory($path);
        $stat = @lstat($path);
        $mode = is_array($stat) ? ((int)$stat['mode'] & 0777) : -1;
        if (($mode & 0007) !== 0 || ($exactMode && $mode !== 0770)) {
            throw new RuntimeException('Private theme source storage permissions are unsafe.');
        }
    }

    private function assertPrivateDirectory(string $path, string $parent): void
    {
        $this->assertOwnedDirectory($path);
        $real = realpath($path);
        if ($real === false || dirname($real) !== realpath($parent)) throw new RuntimeException('Private theme source storage is unsafe.');
    }

    private function readPrivateRegular(string $path, string $root, int $limit): string
    {
        $state = $this->readRegular($path, $root, $limit);
        if ((int)($state['stat']['nlink'] ?? 0) !== 1 || ($state['stat']['mode'] & 0007) !== 0) {
            throw new RuntimeException('Private theme source file is unsafe.');
        }
        return $state['content'];
    }

    private function writeJsonAtomic(string $path, array $data, int $mode): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
        $temporary = dirname($path) . '/.theme-source-json-' . bin2hex(random_bytes(10));
        try {
            $this->writeFileExclusive($temporary, $json, $mode);
            if (!rename($temporary, $path)) throw new RuntimeException('Could not publish protected theme source metadata.');
            $temporary = '';
            $this->syncDirectory(dirname($path));
        } finally {
            if ($temporary !== '' && is_file($temporary) && !is_link($temporary)) @unlink($temporary);
        }
    }

    private function writeFileExclusive(string $path, string $content, int $mode): void
    {
        $oldUmask = umask($mode === 0600 ? 0077 : 0007);
        try {
            $handle = @fopen($path, 'xb');
        } finally {
            umask($oldUmask);
        }
        if (!is_resource($handle)) throw new RuntimeException('Could not create a protected theme source file.');
        $opened = false;
        try {
            $opened = @fstat($handle);
            if (!is_array($opened) || (($opened['mode'] & 0170000) !== 0100000) || (($opened['mode'] & 0777) !== $mode)
                || (int)($opened['nlink'] ?? 0) !== 1) throw new RuntimeException('Could not protect theme source data.');
            $this->writeAll($handle, $content);
            if (!fflush($handle) || (function_exists('fsync') && !fsync($handle))) throw new RuntimeException('Could not sync protected theme source data.');
        } finally {
            fclose($handle);
        }
        $stat = @lstat($path);
        if (!is_array($stat) || (($stat['mode'] & 0170000) !== 0100000) || (($stat['mode'] & 0777) !== $mode)
            || (int)($stat['nlink'] ?? 0) !== 1 || !is_array($opened)
            || (int)$stat['dev'] !== (int)$opened['dev'] || (int)$stat['ino'] !== (int)$opened['ino']
            || (int)$stat['size'] !== strlen($content)) throw new RuntimeException('Could not protect theme source data.');
    }

    private function acquireCoreLocks(string $folder): array
    {
        if (!function_exists('theme_operation_acquire') || !function_exists('theme_lifecycle_lock_keys')) throw new RuntimeException('Core theme lifecycle locking is unavailable.');
        return theme_operation_acquire(theme_lifecycle_lock_keys([$folder]));
    }

    private function globalLifecycleWriterHeld(): bool
    {
        if (!defined('THEME_LIFECYCLE_LOCK_KEY')) return false;
        $resourceId = $GLOBALS['_theme_operation_held_keys'][(string)THEME_LIFECYCLE_LOCK_KEY] ?? null;
        return is_int($resourceId) && ($GLOBALS['_theme_operation_lock_modes'][$resourceId] ?? null) === LOCK_EX;
    }

    private function acquirePrivateLock(string $folder)
    {
        $path = $this->storageDirectory('locks') . '/' . hash('sha256', $folder) . '.lock';
        if (isset(self::$privateLocks[$path])) throw new RuntimeException('Private theme source lock is already held by this request.');
        if (is_link($path)) throw new RuntimeException('Private theme source lock is unsafe.');
        if (!file_exists($path)) {
            $created = @fopen($path, 'x+b');
            if (is_resource($created)) { @chmod($path, 0660); fclose($created); }
        }
        $before = @lstat($path);
        $handle = @fopen($path, 'r+b');
        $opened = is_resource($handle) ? fstat($handle) : false;
        if (!is_array($before) || !is_resource($handle) || !is_array($opened) || is_link($path)
            || (($opened['mode'] & 0170000) !== 0100000) || (($opened['mode'] & 0777) !== 0660) || (int)$opened['nlink'] !== 1
            || (int)$before['dev'] !== (int)$opened['dev'] || (int)$before['ino'] !== (int)$opened['ino'] || !flock($handle, LOCK_EX)) {
            if (is_resource($handle)) fclose($handle);
            throw new RuntimeException('Could not acquire the private theme source lock.');
        }
        clearstatcache(true, $path);
        $locked = @lstat($path);
        if (!is_array($locked) || is_link($path) || (int)$locked['dev'] !== (int)$opened['dev']
            || (int)$locked['ino'] !== (int)$opened['ino'] || (($locked['mode'] & 0777) !== 0660)
            || (int)($locked['nlink'] ?? 0) !== 1) {
            flock($handle, LOCK_UN);
            fclose($handle);
            throw new RuntimeException('Private theme source lock changed during acquisition.');
        }
        self::$privateLocks[$path] = get_resource_id($handle);
        self::$privateLocks['resource:' . get_resource_id($handle)] = $path;
        return $handle;
    }

    private function releasePrivateLock(mixed $lock): void
    {
        if (!is_resource($lock)) return;
        $resourceId = get_resource_id($lock);
        $path = self::$privateLocks['resource:' . $resourceId] ?? null;
        flock($lock, LOCK_UN);
        fclose($lock);
        if (is_string($path)) unset(self::$privateLocks[$path]);
        unset(self::$privateLocks['resource:' . $resourceId]);
    }

    private function lintPhp(string $path): void
    {
        if (!function_exists('proc_open') || !is_file(PHP_BINARY) || !is_executable(PHP_BINARY)) throw new RuntimeException('PHP syntax validation is unavailable.');
        $pipes = [];
        $process = proc_open([PHP_BINARY, '-l', $path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($process)) throw new RuntimeException('Could not start PHP syntax validation.');
        $output = '';
        foreach ($pipes as $pipe) { $output .= stream_get_contents($pipe, 16384) ?: ''; fclose($pipe); }
        if (proc_close($process) !== 0) {
            $safe = trim(str_replace([$path, basename($path)], 'theme source', $output));
            throw new RuntimeException($safe !== '' ? $safe : 'PHP syntax validation failed.');
        }
    }

    private function reserveExport(int &$entries, int &$bytes, int $incoming): void
    {
        $entries++;
        $bytes += $incoming;
        if ($entries > self::MAX_ENTRIES || $bytes > self::MAX_EXPORT_BYTES) throw new RuntimeException('Theme source export exceeds its safety limits.');
    }

    private function verifyExportArchive(string $path, array $expected): void
    {
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::RDONLY) !== true) throw new RuntimeException('Protected source export could not be reopened.');
        try {
            if ($zip->numFiles !== count($expected)) throw new RuntimeException('Protected source export entry count is invalid.');
            $seen = [];
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                $name = is_array($stat) ? ($stat['name'] ?? null) : null;
                if (!is_string($name) || isset($seen[$name]) || !isset($expected[$name])
                    || (int)($stat['size'] ?? -1) !== (int)$expected[$name]['size']) {
                    throw new RuntimeException('Protected source export contains an unexpected entry.');
                }
                $content = $zip->getFromIndex($index, (int)$expected[$name]['size'] + 1);
                if (!is_string($content) || strlen($content) !== (int)$expected[$name]['size']
                    || !hash_equals((string)$expected[$name]['sha256'], hash('sha256', $content))) {
                    throw new RuntimeException('Protected source export content verification failed.');
                }
                $seen[$name] = true;
            }
            if (count($seen) !== count($expected)) throw new RuntimeException('Protected source export is incomplete.');
        } finally {
            $zip->close();
        }
    }

    private function assertRelativePath(string $path): void
    {
        if ($path === '' || strlen($path) > self::MAX_PATH_BYTES || str_contains($path, "\0") || str_contains($path, '\\')
            || str_starts_with($path, '/') || preg_match('/\A[A-Za-z]:/', $path) === 1 || preg_match('/[\x00-\x1F\x7F]/', $path) === 1
            || preg_match('//u', $path) !== 1) throw new RuntimeException('Theme tree contains an unsafe path.');
        foreach (explode('/', $path) as $segment) if ($segment === '' || $segment === '.' || $segment === '..') throw new RuntimeException('Theme tree contains an unsafe path.');
    }

    private function directoryIdentity(string $path): array
    {
        $stat = @lstat($path);
        if (!is_array($stat) || is_link($path) || (($stat['mode'] & 0170000) !== 0040000)) throw new RuntimeException('Directory identity is unsafe.');
        return ['dev' => (int)$stat['dev'], 'ino' => (int)$stat['ino']];
    }

    private function assertDirectoryIdentity(string $path, array $identity): void
    {
        $fresh = $this->directoryIdentity($path);
        if ($fresh !== $identity) throw new RuntimeException('Theme directory identity changed. Reload before saving.');
    }

    private function sameFile(array $left, array $right): bool
    {
        return (int)$left['dev'] === (int)$right['dev'] && (int)$left['ino'] === (int)$right['ino']
            && (int)$left['size'] === (int)$right['size'] && (int)$left['mtime'] === (int)$right['mtime']
            && (($right['mode'] & 0170000) === 0100000);
    }

    private function isWithin(string $root, string $path): bool
    {
        return $path === $root || str_starts_with($path, rtrim($root, '/\\') . DIRECTORY_SEPARATOR);
    }

    private function writeAll($handle, string $content): void
    {
        $offset = 0;
        while ($offset < strlen($content)) {
            $written = fwrite($handle, substr($content, $offset));
            if (!is_int($written) || $written < 1) throw new RuntimeException('Could not write complete source bytes.');
            $offset += $written;
        }
    }

    private function syncDirectory(string $directory): void
    {
        if (!function_exists('fsync')) return;
        $handle = @fopen($directory, 'r');
        if (is_resource($handle)) { @fsync($handle); fclose($handle); }
    }

    private function translate(string $text): string
    {
        return function_exists('__') ? __($text) : $text;
    }

    private function safeError(Throwable $error): string
    {
        $message = trim($error->getMessage());
        return $message !== '' && strlen($message) <= 2000 ? $message : 'Theme source operation failed safely.';
    }
}

function theme_source_service(PDO $pdo): ThemeSourceService
{
    static $services = [];
    $key = spl_object_id($pdo);
    return $services[$key] ??= new ThemeSourceService($pdo);
}

add_action('theme_install_completed', static function (string $folder, array $manifest): void {
    $pdo = $GLOBALS['pdo'] ?? null;
    if ($pdo instanceof PDO) theme_source_service($pdo)->captureBaseline($folder, 'core_install', 0);
});

add_action('theme_update_completed', static function (string $folder, string $oldVersion, string $newVersion, array $manifest): void {
    $pdo = $GLOBALS['pdo'] ?? null;
    if ($pdo instanceof PDO) theme_source_service($pdo)->captureBaseline($folder, 'core_update', 0);
});

add_filter('theme_update_preflight', static function (array $state, string $folder, array $cachedUpdate, array $manifest, PDO $pdo): array {
    return theme_source_service($pdo)->updatePreflight($state, $folder, $cachedUpdate, $manifest);
}, 10);
