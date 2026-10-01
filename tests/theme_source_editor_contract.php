<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$fixture = sys_get_temp_dir() . '/jy-theme-source-' . bin2hex(random_bytes(6));
$public = $fixture . '/public';
$views = $public . '/views/themes';
$backend = $fixture . '/backend';
mkdir($views . '/local', 0770, true);
mkdir($views . '/default', 0770, true);
mkdir($views . '/storetheme', 0770, true);
mkdir($views . '/badstore', 0770, true);
mkdir($views . '/observed', 0770, true);
mkdir($views . '/generation', 0770, true);
mkdir($backend . '/var', 0770, true);
file_put_contents($views . '/local/theme.json', json_encode(['folder' => 'local', 'name' => 'Local', 'version' => '1.0.0']));
file_put_contents($views . '/local/index.php', "<?php\necho 'one';\n");
file_put_contents($views . '/default/theme.json', json_encode(['folder' => 'default', 'name' => 'Default', 'version' => '1.0.0']));
file_put_contents($views . '/default/index.php', "<?php\necho 'default';\n");
file_put_contents($views . '/storetheme/theme.json', json_encode(['folder' => 'storetheme', 'name' => 'Store', 'version' => '1.0.0', 'store' => ['url' => 'https://jyavani.com/theme-store', 'slug' => 'storetheme']]));
file_put_contents($views . '/storetheme/index.php', "<?php\necho 'store';\n");
file_put_contents($views . '/badstore/theme.json', json_encode(['folder' => 'badstore', 'name' => 'Bad Store', 'version' => 'bad version', 'store' => ['url' => 'https://jyavani.com/theme-store', 'slug' => 'badstore']]));
file_put_contents($views . '/badstore/index.php', "<?php\necho 'unverified';\n");
file_put_contents($views . '/observed/theme.json', json_encode(['folder' => 'observed', 'name' => 'Observed', 'version' => '1.0.0', 'store' => ['url' => 'https://example.com/themes', 'slug' => 'observed']]));
file_put_contents($views . '/observed/index.php', "<?php\necho 'observed';\n");
file_put_contents($views . '/generation/theme.json', json_encode(['folder' => 'generation', 'name' => 'Generation', 'version' => '1.0.0']));
file_put_contents($views . '/generation/index.php', "<?php\necho 'generation one';\n");

define('PUBLIC_PATH', $public);
define('VIEWS_BASE', $views);
define('BACKEND_PATH', $backend);
define('DEFAULT_THEME_FOLDER', 'default');

require_once $root . '/cfg/helpers/hooks.php';
require_once $root . '/cfg/helpers/package_archive.php';
require_once $root . '/cfg/helpers/theme_helper.php';
require_once $root . '/cfg/helpers/extension_release_manifest.php';

$GLOBALS['theme_source_health_invalidations'] = 0;
$GLOBALS['theme_source_health_invalidation_result'] = true;
function site_health_invalidate_report(): bool
{
    $GLOBALS['theme_source_health_invalidations']++;
    return ($GLOBALS['theme_source_health_invalidation_result'] ?? true) === true;
}

require_once $root . '/cfg/helpers/theme_source.php';

$failures = [];
$checks = 0;
$check = static function (bool $condition, string $message) use (&$failures, &$checks): void {
    $checks++;
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};
$throws = static function (callable $callback): bool {
    try { $callback(); } catch (Throwable) { return true; }
    return false;
};
$removeTree = static function (string $path) use (&$removeTree): void {
    if (is_link($path) || is_file($path)) { @unlink($path); return; }
    if (!is_dir($path)) return;
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $removeTree($path . '/' . $entry);
    }
    @rmdir($path);
};

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    $removeTree($fixture);
    echo "Theme source editor contract skipped: pdo_sqlite unavailable.\n";
    exit(0);
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE themes (id INTEGER PRIMARY KEY, folder_name TEXT UNIQUE, name TEXT, version TEXT, is_active INTEGER, is_system INTEGER, store_url TEXT, store_slug TEXT)');
$pdo->exec('CREATE TABLE assignments (id INTEGER PRIMARY KEY AUTOINCREMENT, slot_key TEXT, theme_id INTEGER)');
$insert = $pdo->prepare('INSERT INTO themes (id,folder_name,name,version,is_active,is_system,store_url,store_slug) VALUES (?,?,?,?,?,?,?,?)');
$insert->execute([900001, 'local', 'Local', '1.0.0', 0, 0, '', '']);
$insert->execute([900002, 'default', 'Default', '1.0.0', 1, 1, '', '']);
$insert->execute([900003, 'storetheme', 'Store', '1.0.0', 0, 0, 'https://jyavani.com/theme-store', 'storetheme']);
$insert->execute([900004, 'badstore', 'Bad Store', 'bad version', 0, 0, 'https://jyavani.com/theme-store', 'badstore']);
$insert->execute([900005, 'observed', 'Observed', '1.0.0', 0, 0, 'https://example.com/themes', 'observed']);
$insert->execute([900006, 'generation', 'Generation', '1.0.0', 0, 0, '', '']);
$GLOBALS['pdo'] = $pdo;
$service = theme_source_service($pdo);
$acknowledgements = ['direct' => true, 'live' => false, 'store' => false];

try {
    $api = [
        'inventory' => 1, 'source' => 2, 'editState' => 2, 'dirtyState' => 1, 'savePhp' => 8,
        'revisions' => 2, 'restorePhp' => 8, 'captureBaseline' => 3, 'exportPhpSource' => 2,
    ];
    foreach ($api as $method => $parameters) {
        $reflection = new ReflectionMethod(ThemeSourceService::class, $method);
        $check($reflection->isPublic() && $reflection->getNumberOfParameters() === $parameters,
            'public service contract exposes ThemeSourceService::' . $method);
    }
    $inventory = $service->inventory('local');
    $check(count($inventory['files']) === 1 && preg_match('/\A[a-f0-9]{64}\z/', $inventory['files'][0]['id']) === 1,
        'inventory is bounded to regular PHP and exposes an opaque identity');
    $check(!str_contains(json_encode($inventory), $fixture), 'inventory does not expose absolute filesystem paths');
    $fileId = (string)$inventory['files'][0]['id'];
    $opened = $service->source('local', $fileId);
    $check(is_array($opened) && $opened['path'] === 'index.php' && str_contains($opened['source'], "echo 'one'"),
        'opaque source lookup returns the exact bounded PHP bytes');
    $check($service->source('local', str_repeat('0', 64)) === null, 'unknown opaque IDs do not resolve to paths');
    $check($service->editState('local', ['actor_id' => 7])['allowed'] === true, 'ordinary registered themes are editable');
    $check($service->editState('default', ['actor_id' => 7])['allowed'] === false, 'default and system themes remain read-only');

    $acquirePrivateLock = new ReflectionMethod(ThemeSourceService::class, 'acquirePrivateLock');
    $releasePrivateLock = new ReflectionMethod(ThemeSourceService::class, 'releasePrivateLock');
    $heldPrivateLock = $acquirePrivateLock->invoke($service, 'local');
    $secondService = new ThemeSourceService($pdo);
    $check($throws(static fn() => $acquirePrivateLock->invoke($service, 'local'))
        && $throws(static fn() => $acquirePrivateLock->invoke($secondService, 'local')),
        'same-request private source lock re-entry fails fast across service instances instead of blocking');
    $releasePrivateLock->invoke($service, $heldPrivateLock);

    $originalStat = stat($views . '/local/index.php');
    chmod($views . '/local/index.php', 0644);
    $service->captureBaseline('local', 'core_install', 0);
    $events = 0;
    $eventLifecycleReleased = [];
    $eventPrivateReleased = [];
    add_action('theme_source_changed', static function (): void { throw new RuntimeException('isolated observer'); }, 5);
    add_action('theme_source_changed', static function () use (&$events, &$eventLifecycleReleased, &$eventPrivateReleased,
        $service, $acquirePrivateLock, $releasePrivateLock): void {
        $events++;
        $eventLifecycleReleased[] = !theme_operation_holds_lock(THEME_LIFECYCLE_LOCK_KEY, LOCK_EX);
        try {
            $eventLock = $acquirePrivateLock->invoke($service, 'local');
            $eventPrivateReleased[] = true;
            $releasePrivateLock->invoke($service, $eventLock);
        } catch (Throwable) {
            $eventPrivateReleased[] = false;
        }
    }, 10);
    $GLOBALS['theme_source_health_invalidation_result'] = false;
    $missingAck = $service->savePhp('local', $fileId, $opened['sha256'], $opened['target_token'], "<?php\necho 'missing ack';\n", 7,
        ['direct' => false, 'live' => false, 'store' => false]);
    $check(($missingAck['success'] ?? true) === false && str_contains((string)($missingAck['error'] ?? ''), 'explicit risk acknowledgement')
        && file_get_contents($views . '/local/index.php') === $opened['source'],
        'service enforces direct-risk acknowledgement inside the writer lock');
    $pdo->exec("INSERT INTO assignments (slot_key, theme_id) VALUES ('header', 900001)");
    $missingLiveAck = $service->savePhp('local', $fileId, $opened['sha256'], $opened['target_token'], "<?php\necho 'missing live ack';\n", 7,
        $acknowledgements);
    $pdo->exec('DELETE FROM assignments WHERE theme_id = 900001');
    $check(($missingLiveAck['success'] ?? true) === false && str_contains((string)($missingLiveAck['error'] ?? ''), 'Live theme editing')
        && file_get_contents($views . '/local/index.php') === $opened['source'],
        'service revalidates live-impact acknowledgement against locked assignment state');
    $blocked = $service->savePhp('local', $fileId, $opened['sha256'], $opened['target_token'], "<?php\necho 'blocked';\n", 7,
        $acknowledgements, 'blocked save');
    $check(($blocked['success'] ?? true) === false && str_contains((string)($blocked['error'] ?? ''), 'could not be invalidated')
        && file_get_contents($views . '/local/index.php') === $opened['source'] && $events === 0,
        'failed Site Health invalidation prevents rename and post-commit events');
    $GLOBALS['theme_source_health_invalidation_result'] = true;
    $saved = $service->savePhp('local', $fileId, $opened['sha256'], $opened['target_token'], "<?php\necho 'two';\n", 7,
        $acknowledgements, 'contract save');
    $check(($saved['success'] ?? false) === true && preg_match('/\A[a-f0-9]{64}\z/', (string)($saved['target_token'] ?? '')) === 1,
        'save returns a fresh hash-bound target identity');
    $savedStat = stat($views . '/local/index.php');
    $check(($savedStat['mode'] & 0777) === 0644 && $savedStat['uid'] === $originalStat['uid'] && $savedStat['gid'] === $originalStat['gid'],
        'atomic save preserves safe mode, owner, and group metadata');
    $check($events === 1, 'theme_source_changed observers are isolated after replacement');
    $check($eventLifecycleReleased === [true] && $eventPrivateReleased === [true],
        'theme_source_changed runs only after lifecycle and private locks are released');
    $check($GLOBALS['theme_source_health_invalidations'] === 2, 'source publication requires a successful Site Health invalidation');

    $stale = $service->savePhp('local', $fileId, $opened['sha256'], $opened['target_token'], "<?php\necho 'stale';\n", 7, $acknowledgements);
    $check(($stale['success'] ?? true) === false && ($stale['code'] ?? '') === 'stale_source', 'stale SHA and target tokens fail closed');
    $fresh = $service->source('local', $fileId);
    $badToken = $service->savePhp('local', $fileId, $fresh['sha256'], str_repeat('0', 64), "<?php\necho 'wrong target';\n", 7, $acknowledgements);
    $check(($badToken['success'] ?? true) === false && ($badToken['code'] ?? '') === 'stale_source', 'a stale target token fails even with the current source hash');
    $invalid = $service->savePhp('local', $fileId, $fresh['sha256'], $fresh['target_token'], "<?php\nif (\n", 7, $acknowledgements);
    $check(($invalid['success'] ?? true) === false && str_contains(file_get_contents($views . '/local/index.php'), "echo 'two'"),
        'invalid PHP is rejected before the existing file changes');

    $revisions = $service->revisions('local', $fileId);
    $check(count($revisions) === 1 && $revisions[0]['previous_sha256'] === $opened['sha256'], 'save durably records the exact displaced bytes');
    $restored = $service->restorePhp('local', $fileId, $revisions[0]['revision_id'], $fresh['sha256'], $fresh['target_token'], 7,
        $acknowledgements, 'undo');
    $check(($restored['success'] ?? false) === true && str_contains(file_get_contents($views . '/local/index.php'), "echo 'one'"),
        'revision restore atomically restores exact prior source');
    $check(count($service->revisions('local', $fileId)) === 2, 'restore creates a new undo revision for displaced bytes');
    $dirty = $service->dirtyState('local');
    $check(($dirty['tracked'] ?? false) === true && ($dirty['locally_modified'] ?? true) === false,
        'pre-edit baseline and restored source produce a clean dirty state');

    $currentAfterRestore = $service->source('local', $fileId);
    $revisionThemeDirectory = $backend . '/var/theme-source/revisions/900001';
    $localRootStat = lstat($views . '/local');
    $revisionGenerationDirectory = $revisionThemeDirectory . '/' . hash('sha256', (string)$localRootStat['dev'] . "\0" . (string)$localRootStat['ino']);
    $revisionFileDirectory = $revisionGenerationDirectory . '/' . $fileId;
    $revisionId = (string)$revisions[0]['revision_id'];
    $revisionDirectory = $revisionFileDirectory . '/' . $revisionId;
    $revisionStorage = $backend . '/var/theme-source/revisions';
    $outsideRevisionStorage = $backend . '/outside-revisions';
    mkdir($outsideRevisionStorage, 0750);
    rename($revisionStorage, $revisionStorage . '.real');
    symlink($outsideRevisionStorage, $revisionStorage);
    $storageAncestorRestore = $service->restorePhp('local', $fileId, $revisionId, $currentAfterRestore['sha256'], $currentAfterRestore['target_token'], 7, $acknowledgements);
    $check($service->revisions('local', $fileId) === [] && ($storageAncestorRestore['success'] ?? true) === false
        && (fileperms($outsideRevisionStorage) & 0777) === 0750,
        'symlinked revision storage roots fail closed without changing the target directory');
    unlink($revisionStorage);
    rename($revisionStorage . '.real', $revisionStorage);
    rmdir($outsideRevisionStorage);
    rename($revisionThemeDirectory, $revisionThemeDirectory . '.real');
    symlink($revisionThemeDirectory . '.real', $revisionThemeDirectory);
    $themeAncestorRestore = $service->restorePhp('local', $fileId, $revisionId, $currentAfterRestore['sha256'], $currentAfterRestore['target_token'], 7, $acknowledgements);
    $check($service->revisions('local', $fileId) === [] && ($themeAncestorRestore['success'] ?? true) === false,
        'symlinked revision theme ancestors fail closed for listing and restore');
    unlink($revisionThemeDirectory);
    rename($revisionThemeDirectory . '.real', $revisionThemeDirectory);
    rename($revisionFileDirectory, $revisionFileDirectory . '.real');
    symlink($revisionFileDirectory . '.real', $revisionFileDirectory);
    $fileAncestorRestore = $service->restorePhp('local', $fileId, $revisionId, $currentAfterRestore['sha256'], $currentAfterRestore['target_token'], 7, $acknowledgements);
    $check($service->revisions('local', $fileId) === [] && ($fileAncestorRestore['success'] ?? true) === false,
        'symlinked revision file ancestors fail closed for listing and restore');
    unlink($revisionFileDirectory);
    rename($revisionFileDirectory . '.real', $revisionFileDirectory);
    rename($revisionDirectory, $revisionDirectory . '.real');
    symlink($revisionDirectory . '.real', $revisionDirectory);
    $revisionAncestorRestore = $service->restorePhp('local', $fileId, $revisionId, $currentAfterRestore['sha256'], $currentAfterRestore['target_token'], 7, $acknowledgements);
    $check($service->revisions('local', $fileId) === [] && ($revisionAncestorRestore['success'] ?? true) === false,
        'symlinked revision record ancestors fail closed for listing and restore');
    unlink($revisionDirectory);
    rename($revisionDirectory . '.real', $revisionDirectory);

    $service->captureBaseline('generation', 'core_install', 0);
    $generationSource = $service->inventory('generation')['files'][0];
    $generationOpened = $service->source('generation', $generationSource['id']);
    $generationSaved = $service->savePhp('generation', $generationSource['id'], $generationOpened['sha256'], $generationOpened['target_token'],
        "<?php\necho 'generation edited';\n", 7, $acknowledgements);
    $oldGenerationRevision = $service->revisions('generation', $generationSource['id'])[0]['revision_id'] ?? '';
    rename($views . '/generation', $views . '/generation-old');
    mkdir($views . '/generation', 0770);
    file_put_contents($views . '/generation/theme.json', json_encode(['folder' => 'generation', 'name' => 'Generation', 'version' => '1.1.0']));
    file_put_contents($views . '/generation/index.php', "<?php\necho 'generation two';\n");
    $pdo->exec("UPDATE themes SET version = '1.1.0' WHERE id = 900006");
    $generationService = new ThemeSourceService($pdo);
    $generationService->captureBaseline('generation', 'core_update', 0);
    $newGenerationSource = $generationService->inventory('generation')['files'][0];
    $newGenerationOpened = $generationService->source('generation', $newGenerationSource['id']);
    $newGenerationSaved = $generationService->savePhp('generation', $newGenerationSource['id'], $newGenerationOpened['sha256'],
        $newGenerationOpened['target_token'], "<?php\necho 'generation three';\n", 7, $acknowledgements);
    $newGenerationRevisions = $generationService->revisions('generation', $newGenerationSource['id']);
    $oldGenerationRestore = $generationService->restorePhp('generation', $newGenerationSource['id'], $oldGenerationRevision,
        $newGenerationSaved['sha256'], $newGenerationSaved['target_token'], 7, $acknowledgements);
    $check(($generationSaved['success'] ?? false) && ($newGenerationSaved['success'] ?? false)
        && count($newGenerationRevisions) === 1
        && ($newGenerationRevisions[0]['previous_sha256'] ?? '') === $newGenerationOpened['sha256']
        && ($oldGenerationRestore['success'] ?? true) === false,
        'physical theme replacement starts an isolated usable revision generation');

    $service->captureBaseline('storetheme', 'core_install', 0);
    file_put_contents($views . '/storetheme/index.php', "<?php\necho 'local change';\n");
    $preflight = $service->updatePreflight(['schema' => 1, 'issues' => [], 'decisions' => []], 'storetheme',
        ['current_version' => '1.0.0', 'new_version' => '1.1.0'], ['folder' => 'storetheme', 'version' => '1.0.0']);
    $issue = $preflight['issues'][0] ?? [];
    $storeDirty = $service->dirtyState('storetheme');
    $check(($storeDirty['locally_modified'] ?? false) === true && ($storeDirty['counts']['modified'] ?? 0) === 1,
        'baseline dirty state distinguishes modified Store PHP');
    $check(($issue['id'] ?? '') === 'core.theme-source' && ($issue['resolved'] ?? true) === false
        && preg_match('/\A[a-f0-9]{64}\z/', (string)($issue['state_token'] ?? '')) === 1,
        'Store update preflight blocks PHP drift with a state token');
    $wrong = $service->updatePreflight(['schema' => 1, 'issues' => [], 'decisions' => ['core.theme-source' => ['choice' => 'replace', 'state_token' => str_repeat('0', 64)]]],
        'storetheme', ['current_version' => '1.0.0', 'new_version' => '1.1.0'], ['folder' => 'storetheme', 'version' => '1.0.0']);
    $right = $service->updatePreflight(['schema' => 1, 'issues' => [], 'decisions' => ['core.theme-source' => ['choice' => 'replace', 'state_token' => $issue['state_token']]]],
        'storetheme', ['current_version' => '1.0.0', 'new_version' => '1.1.0'], ['folder' => 'storetheme', 'version' => '1.0.0']);
    $check($wrong['issues'][0]['resolved'] === false && $right['issues'][0]['resolved'] === true,
        'destructive replacement accepts only the current exact state token');
    $unverified = $service->updatePreflight(['schema' => 1, 'issues' => [], 'decisions' => []], 'badstore',
        ['current_version' => 'bad version', 'new_version' => '2.0.0'], ['folder' => 'badstore', 'version' => 'bad version']);
    $check(($unverified['issues'][0]['details']['tracked'] ?? true) === false,
        'invalid or unavailable canonical Store identity stays unverified instead of blessing observed drift');
    $observed = $service->updatePreflight(['schema' => 1, 'issues' => [], 'decisions' => []], 'observed',
        ['current_version' => '1.0.0', 'new_version' => '1.1.0'], ['folder' => 'observed', 'version' => '1.0.0',
            'store' => ['url' => 'https://example.com/themes', 'slug' => 'observed']]);
    $observedDirty = $service->dirtyState('observed');
    $check(($observedDirty['tracked'] ?? true) === false && ($observedDirty['observed'] ?? false) === true
        && ($observed['issues'][0]['resolved'] ?? true) === false,
        'matching noncanonical Store observations remain unverified and cannot authorize clean replacement');

    if (class_exists('ZipArchive')) {
        $export = $service->exportPhpSource('local', 7);
        $zip = new ZipArchive();
        $openedZip = $zip->open($export['path']) === true;
        $names = [];
        if ($openedZip) {
            for ($i = 0; $i < $zip->numFiles; $i++) $names[] = (string)$zip->getNameIndex($i);
            $zip->close();
        }
        $check($openedZip && in_array('current-php/index.php', $names, true) && in_array('baseline.json', $names, true)
            && count(array_filter($names, static fn(string $name): bool => str_ends_with($name, '/source.php'))) >= 2,
            'protected export contains current PHP, baseline, and revision source');
        $exportStat = lstat($export['path']);
        $check(is_array($exportStat) && ($exportStat['mode'] & 0777) === 0600 && $exportStat['nlink'] === 1
            && $exportStat['dev'] === $export['dev'] && $exportStat['ino'] === $export['ino']
            && hash_file('sha256', $export['path']) === $export['sha256'],
            'protected export returns its verified private mode, inode, size, and content identity');
        chmod($export['path'], 0602);
        $check(!$service->removeExport($export['path']), 'world-writable private export records fail closed');
        chmod($export['path'], 0600);
        $check($service->removeExport($export['path']) && !file_exists($export['path']), 'private exports are removed through identity-checked cleanup');
    } else {
        $check(true, 'protected export check skipped because ZipArchive is unavailable');
        $check(true, 'world-writable private export check skipped because ZipArchive is unavailable');
        $check(true, 'private export cleanup check skipped because ZipArchive is unavailable');
    }

    $privateRoot = realpath($backend . '/var/theme-source');
    $check(is_string($privateRoot) && !str_starts_with($privateRoot, realpath($public) . DIRECTORY_SEPARATOR),
        'theme source state remains outside the public root');

    symlink($views . '/local/index.php', $views . '/local/linked.php');
    $adversarialReadService = new ThemeSourceService($pdo);
    $check($throws(static fn() => $adversarialReadService->inventory('local')), 'theme inventories reject symbolic links instead of following them');
    unlink($views . '/local/linked.php');
    if (function_exists('posix_mkfifo') && @posix_mkfifo($views . '/local/special.php', 0600)) {
        $check($throws(static fn() => $adversarialReadService->inventory('local')), 'theme inventories reject special filesystem entries');
        unlink($views . '/local/special.php');
    } else {
        $check(true, 'special filesystem entry check skipped because FIFO creation is unavailable');
    }

    $deny = static fn(array $state): array => ['allowed' => false, 'message' => 'contract denial'];
    $reverse = static fn(array $state): array => ['allowed' => true, 'message' => 'bad reversal'];
    add_filter('theme_source_edit_policy', $deny, 20);
    add_filter('theme_source_edit_policy', $reverse, 30);
    $policy = $service->editState('local', ['actor_id' => 7, 'operation' => 'edit']);
    $check($policy['allowed'] === false && str_contains($policy['message'], 'policy validation failed'),
        'edit policy is monotonic and fails closed on attempted denial reversal');
    remove_filter('theme_source_edit_policy', $deny, 20);
    remove_filter('theme_source_edit_policy', $reverse, 30);

    $validLocalSource = (string)file_get_contents($views . '/local/index.php');
    file_put_contents($views . '/local/index.php', "<?php\necho \"\xFF\";\n");
    $utf8Service = new ThemeSourceService($pdo);
    $invalidUtf8 = $utf8Service->source('local', $fileId);
    $utf8Policy = $utf8Service->editState('local', ['actor_id' => 7, 'operation' => 'edit', 'file' => [
        'id' => $fileId, 'path' => 'index.php', 'sha256' => $invalidUtf8['sha256'], 'utf8' => $invalidUtf8['utf8'],
    ]]);
    $utf8Save = $utf8Service->savePhp('local', $fileId, $invalidUtf8['sha256'], $invalidUtf8['target_token'], "<?php\necho 'valid';\n", 7, $acknowledgements);
    $check($invalidUtf8['utf8'] === false && $utf8Policy['allowed'] === false
        && ($utf8Save['success'] ?? true) === false && str_contains((string)$utf8Save['error'], 'read-only'),
        'existing non-UTF-8 PHP is readable for inspection but denied at render policy and mutation time');
    file_put_contents($views . '/local/index.php', $validLocalSource);

    $rollbackTarget = $views . '/local/rollback.php';
    file_put_contents($rollbackTarget, "<?php\necho 'previous';\n");
    chmod($rollbackTarget, 0640);
    $readRegular = new ReflectionMethod(ThemeSourceService::class, 'readRegular');
    $captureStageIdentity = new ReflectionMethod(ThemeSourceService::class, 'captureStageIdentity');
    $rollbackPublishedStage = new ReflectionMethod(ThemeSourceService::class, 'rollbackPublishedStage');
    $previousRollback = $readRegular->invoke($service, $rollbackTarget, $views . '/local', 5242880);
    $publishedStage = $views . '/local/.published-stage';
    file_put_contents($publishedStage, "<?php\necho 'published';\n");
    chmod($publishedStage, 0640);
    $publishedStat = stat($publishedStage);
    $publishedHash = hash_file('sha256', $publishedStage);
    $publishedIdentity = $captureStageIdentity->invoke($service, $publishedStage, $views . '/local', $publishedHash,
        $publishedStat['uid'], $publishedStat['gid'], 0640);
    rename($publishedStage, $rollbackTarget);
    $rolledBack = $rollbackPublishedStage->invoke($service, $rollbackTarget, $views . '/local', $publishedIdentity, $previousRollback);
    $rolledBackStat = stat($rollbackTarget);
    $check($rolledBack === true && file_get_contents($rollbackTarget) === $previousRollback['content']
        && ($rolledBackStat['mode'] & 0777) === 0640 && $rolledBackStat['uid'] === $previousRollback['stat']['uid']
        && $rolledBackStat['gid'] === $previousRollback['stat']['gid'],
        'guarded post-publication rollback restores exact previous bytes and safe metadata');
    unlink($rollbackTarget);

    $routes = [];
    foreach (['source.php', 'source_save.php', 'source_restore.php', 'source_export.php', 'source_revisions.php'] as $name) {
        $routes[$name] = (string)file_get_contents($root . '/dashboard/admin/themes/' . $name);
    }
    foreach ($routes as $name => $route) {
        $check(str_contains($route, "'core.themes.manage'") && str_contains($route, 'adiwira_require_site_owner'), $name . ' requires theme permission and Site Owner authority');
    }
    foreach (['source_save.php', 'source_restore.php', 'source_export.php'] as $name) {
        $check(str_contains($routes[$name], "REQUEST_METHOD'] ?? '') !== 'POST'") && str_contains($routes[$name], 'adiwira_csrf_validate'),
            $name . ' requires POST and CSRF validation');
    }
    foreach (['source_save.php', 'source_restore.php'] as $name) {
        $check(str_contains($routes[$name], "require_once __DIR__ . '/../_notify.php'")
            && str_contains($routes[$name], 'adiwira_redirect_with_flash'),
            $name . ' loads its redirect helper before returning a mutation result');
    }
    $check(str_contains($routes['source.php'], 'CodeMirror.fromTextArea') && !str_contains($routes['source.php'], '<iframe'),
        'editor uses Core CodeMirror without executable unsaved preview');
    $sourceControls = strpos($routes['source.php'], 'class="theme-source__tools"');
    $sourceTextarea = strpos($routes['source.php'], 'id="theme-source-code"');
    $check($sourceControls !== false && $sourceTextarea !== false && $sourceControls < $sourceTextarea
        && str_contains($routes['source.php'], 'class="theme-source__tabs" role="tablist"')
        && str_contains($routes['source.php'], 'role="tabpanel"')
        && str_contains($routes['source.php'], 'class="theme-source__tools-toggle"')
        && str_contains($routes['source.php'], 'form="theme-source-form" type="checkbox"')
        && str_contains($routes['source.php'], 'theme-source-action-group--core')
        && str_contains($routes['source.php'], 'theme-source-action-owner')
        && str_contains($routes['source.php'], 'position:sticky;')
        && !str_contains($routes['source.php'], '--theme-source-workspace-height')
        && str_contains($routes['source.php'], 'editor.setSize(null,height)')
        && str_contains($routes['source.php'], 'new ResizeObserver(syncEditorHeight).observe(files)')
        && str_contains($routes['source.php'], ".theme-source__heading{\n  margin-bottom:14px;")
        && str_contains($routes['source.php'], 'text-decoration:none!important;'),
        'editor keeps one spaced sticky toolbar and follows the natural source-file height with proper action links');
    $check(str_contains($routes['source.php'], 'class="theme-source__identity"')
        && str_contains($routes['source.php'], "svg_ico('palette')")
        && str_contains($routes['source.php'], 'strcasecmp($themeDisplayName, $themeFolder)')
        && str_contains($routes['source.php'], 'class="theme-source__identity-copy"')
        && !str_contains($routes['source.php'], '<p class="adam-muted"><?=htmlspecialchars((string)$theme[\'name\']'),
        'Source Editor presents one compact theme identity without repeating identical name and folder values');
    $check(str_contains($routes['source.php'], "theme_slot_definitions(\$pdo, ['scope' => 'theme-source-editor'")
        && str_contains($routes['source.php'], "'slot_keys' => \$sourceSlotKeys")
        && str_contains($routes['source.php'], 'count($slotDefinitions) <= 256'),
        'source action context exposes bounded exact slot identities without extension-owned file categories');
    $check(str_contains($routes['source.php'], "'utf8' => (bool)\$source['utf8']")
        && str_contains($routes['source.php'], 'ENT_SUBSTITUTE'),
        'render-time policy receives selected-file UTF-8 context and safely renders arbitrary inspected bytes');
    $check(str_contains((string)file_get_contents($root . '/dashboard/admin/themes/assign.php'), 'Inspect / Edit Source')
        && str_contains((string)file_get_contents($root . '/dashboard/admin/themes/assign.php'), 'sourceDirtyCount'),
        'Theme Manager cards expose source inspection and dirty state');
    $assignSource = (string)file_get_contents($root . '/dashboard/admin/themes/assign.php');
    $check(str_contains($assignSource, '$sourceService->inventory($themeFolder)')
        && str_contains($assignSource, "'page' => 'admin/themes/source'")
        && str_contains($assignSource, "'file' => \$fileId")
        && str_contains($assignSource, 'data-editor-url=')
        && str_contains($assignSource, 'Inspect / Edit theme PHP'),
        'per-slot registered-theme actions open the exact service-issued PHP source identity');
    $helperSource = (string)file_get_contents($root . '/cfg/helpers/theme_source.php');
    $check(str_contains($helperSource, "[PHP_BINARY, '-l', \$path]") && str_contains($helperSource, "do_action_isolated('theme_source_changed'")
        && str_contains($helperSource, "'theme_source_edit_policy'") && str_contains($helperSource, "'theme_source_editor_actions'\n") === false,
        'service uses array proc_open lint, isolated mutation events, and policy hooks');
    $stageSection = substr($helperSource, (int)strpos($helperSource, "\$handle = @fopen(\$temporary, 'xb');"));
    $check(str_contains($helperSource, '$oldUmask = umask(0077);')
        && strpos($stageSection, '$protectedStage = @fstat($handle);') < strpos($stageSection, '$this->writeAll($handle, $content)')
        && str_contains($helperSource, '$opened = @fstat($handle);'),
        'atomic stages and private records verify descriptor permissions before writing their first byte');
    $check(str_contains($routes['source.php'], "do_action_isolated_output('theme_source_editor_actions'")
        && str_contains($routes['source.php'], "do_action_isolated_output('theme_source_editor_context'")
        && str_contains($routes['source.php'], 'Form controls are not allowed in source context panels.')
        && str_contains($routes['source.php'], '<?=$editorContext?>'),
        'editor action and contextual panel hooks isolate listener output and reserve form controls for Core');
    $agents = (string)file_get_contents($root . '/AGENTS.md');
    $check(str_contains($agents, 'escape every label, URL, and contextual value they emit')
        && str_contains($agents, 'source save/revision controls remain Core-only'),
        'extension action contract assigns output escaping and reserves source mutations for Core');
    $configSource = (string)file_get_contents($root . '/cfg/config.php');
    $check(str_contains($configSource, "helpers/theme_source.php")
        && str_contains($helperSource, "add_action('theme_install_completed'")
        && str_contains($helperSource, "add_action('theme_update_completed'")
        && str_contains($helperSource, "add_filter('theme_update_preflight'"),
        'Core loads source ownership and wires install, update, and preflight lifecycle hooks');

    $translationSource = (string)file_get_contents($root . '/schema/translations.sql');
    $translationKeys = ['PHP modified (%d)', 'PHP unverified', 'Inspect / Edit Source'];
    foreach ($routes as $route) {
        preg_match_all('/(?:__|_e)\(\'([^\']+)\'\)/', $route, $matches);
        $translationKeys = array_merge($translationKeys, $matches[1]);
    }
    preg_match_all('/translate\(\'([^\']+)\'\)/', $helperSource, $matches);
    $translationKeys = array_values(array_unique(array_merge($translationKeys, $matches[1])));
    foreach ($translationKeys as $key) {
        $check(substr_count($translationSource, "'" . str_replace("'", "''", $key) . "'") >= 2,
            'translation seeds cover source editor string: ' . $key);
    }
} finally {
    $storage = $backend . '/var/theme-source';
    foreach ([900001, 900002, 900003, 900004, 900005, 900006] as $themeId) $removeTree($storage . '/revisions/' . $themeId);
    foreach (['local' => 900001, 'default' => 900002, 'storetheme' => 900003, 'badstore' => 900004, 'observed' => 900005, 'generation' => 900006] as $folder => $themeId) {
        $themeRoot = $views . '/' . $folder;
        $stat = @lstat($themeRoot);
        if (is_array($stat)) {
            $key = hash('sha256', implode("\0", [$folder, (string)$themeId, (string)$stat['dev'], (string)$stat['ino']]));
            @unlink($storage . '/baselines/' . $key . '.json');
        }
        @unlink($storage . '/locks/' . hash('sha256', $folder) . '.lock');
    }
    $removeTree($fixture);
}

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " theme source editor contract check(s) failed.\n");
    exit(1);
}
echo "Theme source editor contract passed ({$checks} checks).\n";
