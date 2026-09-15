<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$fixture = sys_get_temp_dir() . '/jy-site-health-' . bin2hex(random_bytes(6));
$removeTree = static function (string $path) use (&$removeTree): void {
    if (is_link($path) || is_file($path)) { @unlink($path); return; }
    if (!is_dir($path)) return;
    foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $entry) $removeTree($entry->getPathname());
    @rmdir($path);
};
foreach (['cfg/var', 'app', 'tools', 'public/views/themes/default', 'public/views/themes/store-theme', 'public/views/themes/wrong-theme', 'public/views/themes/missing-theme', 'public/static/img/2026', 'public/static/plugins/store-plugin', 'plugins/local-plugin', 'plugins/store-plugin/assets', 'plugins/.backup-local-plugin', 'private_files/media/2026'] as $directory) {
    if (!is_dir($fixture . '/' . $directory) && !mkdir($fixture . '/' . $directory, 0755, true)) throw new RuntimeException('Unable to create fixture.');
}
define('BACKEND_PATH', $fixture . '/cfg');
require_once $root . '/cfg/helpers/update_operation.php';
require_once $root . '/cfg/helpers/site_health.php';

$failures = [];
$checks = 0;
$check = static function (bool $condition, string $message) use (&$failures, &$checks): void {
    $checks++;
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};

try {
    file_put_contents($fixture . '/version.json', json_encode(['version' => '9.8.7'], JSON_THROW_ON_ERROR));
    file_put_contents($fixture . '/app/core.php', "<?php\nreturn true;\n");
    file_put_contents($fixture . '/public/index.php', "<?php\n");
    file_put_contents($fixture . '/public/views/themes/default/theme.json', '{}');
    $coreFiles = [];
    foreach (['version.json', 'app/core.php', 'public/index.php', 'public/views/themes/default/theme.json'] as $logical) {
        $coreFiles[$logical] = hash_file('sha256', $fixture . '/' . $logical);
    }
    $manifest = ['version' => '9.8.7', 'total_files' => count($coreFiles), 'files' => $coreFiles];
    file_put_contents($fixture . '/tools/cms-manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
    file_put_contents($fixture . '/plugins/local-plugin/plugin.json', json_encode([
        'name' => 'local-plugin', 'version' => '1.0.0',
        'plugin_uri' => 'https://jyavani.com/plugin/local-plugin/',
        'release_manifest_url' => 'https://localhost/plugin-manifest.json',
    ], JSON_THROW_ON_ERROR));
    file_put_contents($fixture . '/plugins/local-plugin/plugin.php', '<?php');
    $storePluginData = [
        'name' => 'Store Plugin',
        'version' => '1.2.3',
        'store' => ['url' => 'https://jyavani.com/plugin-store/', 'slug' => 'store-plugin'],
        'static' => ['copy' => [['from' => 'assets/app.js', 'to' => 'static/plugins/store-plugin/app.js']]],
    ];
    file_put_contents($fixture . '/plugins/store-plugin/plugin.json', json_encode($storePluginData, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    file_put_contents($fixture . '/plugins/store-plugin/plugin.php', '<?php return true;');
    file_put_contents($fixture . '/plugins/store-plugin/assets/app.js', 'window.storePlugin=true;');
    file_put_contents($fixture . '/public/static/plugins/store-plugin/app.js', 'window.storePlugin=true;');
    file_put_contents($fixture . '/plugins/.backup-local-plugin/plugin.json', json_encode(['name' => 'Local Plugin', 'version' => '0.9.0'], JSON_THROW_ON_ERROR));
    file_put_contents($fixture . '/plugins/.backup-local-plugin/plugin.php', '<?php');
    file_put_contents($fixture . '/public/views/themes/store-theme/theme.json', json_encode([
        'name' => 'Store Theme', 'version' => '2.0.0', 'store' => ['url' => 'https://example.test/themes/', 'slug' => 'store-theme'],
    ], JSON_THROW_ON_ERROR));
    file_put_contents($fixture . '/public/views/themes/store-theme/index.php', '<?php');
    foreach (['wrong-theme', 'missing-theme'] as $canonicalTheme) {
        file_put_contents($fixture . '/public/views/themes/' . $canonicalTheme . '/theme.json', json_encode([
            'name' => ucfirst(str_replace('-', ' ', $canonicalTheme)), 'version' => '3.0.0',
            'store' => ['url' => 'https://jyavani.com/theme-store', 'slug' => $canonicalTheme],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($fixture . '/public/views/themes/' . $canonicalTheme . '/index.php', '<?php');
    }
    file_put_contents($fixture . '/private_files/media/2026/photo.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='));

    $provider = static fn(string $url, string $version): array => $manifest;
    $releaseManifest = static function (string $type, string $slug, string $version, string $directory): array {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
        $prefixLength = strlen(rtrim($directory, '/')) + 1;
        foreach ($iterator as $entry) {
            if ($entry->isFile() && !$entry->isLink()) {
                $files[str_replace('\\', '/', substr($entry->getPathname(), $prefixLength))] = hash_file('sha256', $entry->getPathname());
            }
        }
        ksort($files, SORT_STRING);
        return [
            'schema_version' => 1, 'type' => $type, 'name' => $slug, 'version' => $version,
            'package_sha256' => str_repeat('a', 64), 'zip_size' => 1234,
            'total_files' => count($files), 'files' => $files,
        ];
    };
    $pluginRelease = $releaseManifest('plugin', 'store-plugin', '1.2.3', $fixture . '/plugins/store-plugin');
    $legacyPluginRelease = $releaseManifest('plugin', 'local-plugin', '1.0.0', $fixture . '/plugins/local-plugin');
    file_put_contents($fixture . '/plugins/store-plugin/.store.json', json_encode(['source' => 'preserved'], JSON_THROW_ON_ERROR));

    mkdir($fixture . '/plugins/store-plugin/empty-runtime-directory');
    $requestedManifestUrls = [];
    $extensionProvider = static function (string $url, string $type, string $slug, string $version, float $deadline) use (&$requestedManifestUrls, $pluginRelease, $legacyPluginRelease): ?array {
        $requestedManifestUrls[] = $url;
        if ($slug === 'store-plugin') return $pluginRelease;
        if ($slug === 'local-plugin') return $legacyPluginRelease;
        if ($slug === 'wrong-theme') {
            return [
                'schema_version' => 1, 'type' => $type, 'name' => $slug, 'version' => '3.0.1',
                'package_sha256' => str_repeat('b', 64), 'zip_size' => 100,
                'total_files' => 1, 'files' => ['theme.json' => str_repeat('c', 64)],
            ];
        }
        return null;
    };
    $report = site_health_run($fixture, $fixture . '/public', $provider, $extensionProvider);
    $check($report['components']['core']['status'] === 'clean', 'full scan retains independent clean Core integrity');
    $check($report['components']['extensions']['status'] === 'unverified'
        && $report['components']['extensions']['summary']['total'] === 5, 'plugins and themes retain an unverified component boundary when local or unresolved extensions exist');
    $extensionItemsByFolder = array_column($report['components']['extensions']['items'], null, 'folder');
    $check(($extensionItemsByFolder['store-plugin']['status'] ?? null) === 'clean'
        && ($extensionItemsByFolder['store-plugin']['baseline'] ?? null) === 'canonical_exact_https_store', 'an exact plugin file tree, valid preserved Store metadata, ignored empty directory, and matching static.copy destination are clean');
    $check(($extensionItemsByFolder['local-plugin']['status'] ?? null) === 'clean'
        && ($extensionItemsByFolder['local-plugin']['baseline'] ?? null) === 'canonical_exact_https_store'
        && ($extensionItemsByFolder['store-theme']['status'] ?? null) === 'unverified', 'an official legacy plugin URI can resolve an exact Store baseline while noncanonical theme metadata remains unverified');
    $check(($extensionItemsByFolder['wrong-theme']['reason'] ?? null) === 'store_release_manifest_invalid'
        && ($extensionItemsByFolder['missing-theme']['reason'] ?? null) === 'store_release_manifest_unavailable', 'wrong and missing exact-version release manifests remain unverified with distinct reasons');
    $check(in_array('https://jyavani.com/plugin-store/store-plugin/releases/1.2.3/manifest.json', $requestedManifestUrls, true)
        && in_array('https://jyavani.com/plugin-store/local-plugin/releases/1.0.0/manifest.json', $requestedManifestUrls, true)
        && in_array('https://jyavani.com/theme-store/wrong-theme/releases/3.0.0/manifest.json', $requestedManifestUrls, true), 'Site Health requests canonical exact-version plugin and theme manifest paths');
    $check(extension_release_manifest_official_plugin_uri('https://jyavani.com/plugin/example/')
        && !extension_release_manifest_official_plugin_uri('http://jyavani.com/plugin/example/')
        && !extension_release_manifest_official_plugin_uri('https://user@jyavani.com/plugin/example/')
        && !extension_release_manifest_official_plugin_uri('https://example.com/plugin/example/'), 'legacy plugin provenance accepts only a strict official HTTPS listing URL');
    $unsortedManifest = $pluginRelease;
    $unsortedManifest['files'] = array_reverse($unsortedManifest['files'], true);
    try {
        extension_release_manifest_validate($unsortedManifest, 'plugin', 'store-plugin', '1.2.3');
        $unsortedRejected = false;
    } catch (RuntimeException $error) {
        $unsortedRejected = true;
    }
    $check($unsortedRejected, 'release manifests require the exact field contract and a sorted safe extension-relative file map');
    $collisionManifest = $pluginRelease;
    $collisionManifest['files'] = ['Code.php' => str_repeat('d', 64), 'code.php' => str_repeat('e', 64)];
    $collisionManifest['total_files'] = 2;
    try {
        extension_release_manifest_validate($collisionManifest, 'plugin', 'store-plugin', '1.2.3');
        $collisionRejected = false;
    } catch (RuntimeException $error) {
        $collisionRejected = true;
    }
    $check($collisionRejected, 'release manifests reject case-insensitive path collisions');
    $gitManifest = $pluginRelease;
    $gitManifest['files'] = ['.git/config' => str_repeat('f', 64)];
    $gitManifest['total_files'] = 1;
    try {
        extension_release_manifest_validate($gitManifest, 'plugin', 'store-plugin', '1.2.3');
        $gitRejected = false;
    } catch (RuntimeException $error) {
        $gitRejected = true;
    }
    $check($gitRejected, 'release manifests cannot make .git content trusted');
    $check(!in_array('.backup-local-plugin', array_column($report['components']['extensions']['items'], 'folder'), true), 'hidden plugin backups are excluded from installed extension inventory');
    $check($report['components']['content']['status'] === 'scanned'
        && $report['components']['content']['files_scanned'] === 1, 'safe site-owned content receives a completed safety scan');
    $check($report['status'] === 'unverified', 'overall state preserves the unverified extension boundary');

    file_put_contents($fixture . '/plugins/store-plugin/.STORE.JSON', '{}');
    $caseVariantMetadata = site_health_run($fixture, $fixture . '/public', $provider, $extensionProvider);
    $caseVariantItems = array_column($caseVariantMetadata['components']['extensions']['items'], null, 'folder');
    $check(($caseVariantItems['store-plugin']['status'] ?? null) === 'contaminated', 'only the exact updater-preserved .store.json filename is exempt from release identity');
    unlink($fixture . '/plugins/store-plugin/.STORE.JSON');

    file_put_contents($fixture . '/plugins/store-plugin/.store.json', '{invalid');
    $invalidMetadata = site_health_run($fixture, $fixture . '/public', $provider, $extensionProvider);
    $invalidMetadataItems = array_column($invalidMetadata['components']['extensions']['items'], null, 'folder');
    $check(($invalidMetadataItems['store-plugin']['status'] ?? null) === 'unverified', 'invalid updater-preserved Store metadata prevents a clean result');
    file_put_contents($fixture . '/plugins/store-plugin/.store.json', str_repeat('x', SITE_HEALTH_STORE_METADATA_MAX_BYTES + 1));
    $oversizedMetadata = site_health_run($fixture, $fixture . '/public', $provider, $extensionProvider);
    $oversizedMetadataItems = array_column($oversizedMetadata['components']['extensions']['items'], null, 'folder');
    $check(($oversizedMetadataItems['store-plugin']['status'] ?? null) === 'unverified', 'unreadable or oversized updater-preserved Store metadata prevents a clean result');
    unlink($fixture . '/plugins/store-plugin/.store.json');
    symlink($fixture . '/app/core.php', $fixture . '/plugins/store-plugin/.store.json');
    $unsafeMetadata = site_health_run($fixture, $fixture . '/public', $provider, $extensionProvider);
    $unsafeMetadataItems = array_column($unsafeMetadata['components']['extensions']['items'], null, 'folder');
    $check(($unsafeMetadataItems['store-plugin']['status'] ?? null) === 'contaminated', 'symlinked updater-preserved Store metadata is contaminated');
    unlink($fixture . '/plugins/store-plugin/.store.json');
    file_put_contents($fixture . '/plugins/store-plugin/.store.json', json_encode(['source' => 'preserved'], JSON_THROW_ON_ERROR));

    mkdir($fixture . '/plugins/store-plugin/.git');
    $gitTree = site_health_run($fixture, $fixture . '/public', $provider, $extensionProvider);
    $gitTreeItems = array_column($gitTree['components']['extensions']['items'], null, 'folder');
    $check(($gitTreeItems['store-plugin']['status'] ?? null) === 'contaminated', 'an empty .git directory cannot be clean Store content');
    rmdir($fixture . '/plugins/store-plugin/.git');

    symlink($fixture . '/plugins/store-plugin', $fixture . '/unsafe-extension-root');
    $rootEntries = 100;
    $rootBytes = 1024 * 1024;
    $unsafeTrustedRoot = site_health_scan_extension_tree(
        $fixture . '/unsafe-extension-root', 'plugins/store-plugin', $pluginRelease,
        microtime(true) + 1.0, $rootEntries, $rootBytes
    );
    $check(($unsafeTrustedRoot['summary']['contaminated'] ?? 0) === 1, 'trusted extension scanning rejects an unsafe root before constructing its recursive iterator');
    unlink($fixture . '/unsafe-extension-root');

    file_put_contents($fixture . '/plugins/store-plugin/plugin.php', '<?php return false;');
    $modifiedExtension = site_health_run($fixture, $fixture . '/public', $provider, $extensionProvider);
    $modifiedItems = array_column($modifiedExtension['components']['extensions']['items'], null, 'folder');
    $check(($modifiedItems['store-plugin']['status'] ?? null) === 'modified', 'a changed file in a trusted extension tree is modified');
    file_put_contents($fixture . '/plugins/store-plugin/plugin.php', '<?php return true;');

    unlink($fixture . '/plugins/store-plugin/plugin.php');
    $missingExtensionFile = site_health_run($fixture, $fixture . '/public', $provider, $extensionProvider);
    $missingExtensionItems = array_column($missingExtensionFile['components']['extensions']['items'], null, 'folder');
    $check(($missingExtensionItems['store-plugin']['status'] ?? null) === 'modified', 'a missing file from a trusted extension tree is modified');
    file_put_contents($fixture . '/plugins/store-plugin/plugin.php', '<?php return true;');

    file_put_contents($fixture . '/plugins/store-plugin/unexpected.php', '<?php');
    $contaminatedExtension = site_health_run($fixture, $fixture . '/public', $provider, $extensionProvider);
    $contaminatedItems = array_column($contaminatedExtension['components']['extensions']['items'], null, 'folder');
    $check(($contaminatedItems['store-plugin']['status'] ?? null) === 'contaminated', 'an unexpected file in a trusted extension tree is contaminated');
    unlink($fixture . '/plugins/store-plugin/unexpected.php');

    file_put_contents($fixture . '/public/static/plugins/store-plugin/app.js', 'changed');
    $modifiedStatic = site_health_run($fixture, $fixture . '/public', $provider, $extensionProvider);
    $modifiedStaticItems = array_column($modifiedStatic['components']['extensions']['items'], null, 'folder');
    $check(($modifiedStaticItems['store-plugin']['status'] ?? null) === 'modified', 'a changed static.copy destination is modified using the trusted plugin.json mapping');
    file_put_contents($fixture . '/public/static/plugins/store-plugin/app.js', 'window.storePlugin=true;');

    file_put_contents($fixture . '/public/static/plugins/store-plugin/stale.js', 'stale');
    $unexpectedStatic = site_health_run($fixture, $fixture . '/public', $provider, $extensionProvider);
    $unexpectedStaticItems = array_column($unexpectedStatic['components']['extensions']['items'], null, 'folder');
    $check(($unexpectedStaticItems['store-plugin']['status'] ?? null) === 'contaminated', 'an undeclared file in the plugin-owned static namespace is contaminated');
    unlink($fixture . '/public/static/plugins/store-plugin/stale.js');

    file_put_contents($fixture . '/public/static/plugins/store-plugin/.store.json', '{}');
    $unexpectedStaticMetadata = site_health_run($fixture, $fixture . '/public', $provider, $extensionProvider);
    $unexpectedStaticMetadataItems = array_column($unexpectedStaticMetadata['components']['extensions']['items'], null, 'folder');
    $check(($unexpectedStaticMetadataItems['store-plugin']['status'] ?? null) === 'contaminated', '.store.json is not exempt inside a public static namespace');
    unlink($fixture . '/public/static/plugins/store-plugin/.store.json');

    file_put_contents($fixture . '/public/views/themes/backdoor.php', '<?php');
    $rootArtifact = site_health_run($fixture, $fixture . '/public', $provider, $extensionProvider);
    $check($rootArtifact['components']['extensions']['status'] === 'contaminated'
        && in_array('public/views/themes/backdoor.php', array_column($rootArtifact['components']['extensions']['findings'], 'path'), true), 'regular executable files directly under the theme root are contaminated');
    unlink($fixture . '/public/views/themes/backdoor.php');

    symlink($fixture . '/app/core.php', $fixture . '/plugins/local-plugin/unsafe-link');
    $unsafeExtension = site_health_run($fixture, $fixture . '/public', $provider, $extensionProvider);
    $check($unsafeExtension['components']['extensions']['status'] === 'contaminated'
        && $unsafeExtension['status'] === 'contaminated', 'an unsafe extension artifact raises component and overall contamination');
    unlink($fixture . '/plugins/local-plugin/unsafe-link');

    file_put_contents($fixture . '/private_files/media/2026/shell.php.jpg', '<?php echo 1;');
    $unsafeContent = site_health_run($fixture, $fixture . '/public', $provider, $extensionProvider);
    $contentReasons = array_column($unsafeContent['components']['content']['findings'], 'reason');
    $check($unsafeContent['components']['content']['status'] === 'contaminated'
        && in_array('executable_upload', $contentReasons, true), 'double-extension executable content is contaminated');
    unlink($fixture . '/private_files/media/2026/shell.php.jpg');

    file_put_contents($fixture . '/private_files/media/2026/fake.jpg', 'not an image');
    $mimeContent = site_health_scan_content($fixture, $fixture . '/public', microtime(true) + 5.0);
    $check(in_array('image_mime_mismatch', array_column($mimeContent['findings'], 'reason'), true), 'image extension and detected MIME mismatch is reported');
    unlink($fixture . '/private_files/media/2026/fake.jpg');
    $emptyBudget = 0;
    $limitedContent = site_health_scan_content($fixture, $fixture . '/public', microtime(true) + 5.0, $emptyBudget);
    $check($limitedContent['status'] === 'unverified'
        && in_array('scan_limit_reached', array_column($limitedContent['findings'], 'reason'), true), 'dated image discovery consumes the shared full-scan entry budget');

    $storedRun = site_health_run_and_store($fixture, $fixture . '/public', $provider, $extensionProvider);
    $check(site_health_report_valid($storedRun), 'combined Site Health report validates and writes atomically');
    $stored = site_health_read_report();
    $check(is_array($stored) && $stored['status'] === 'unverified'
        && isset($stored['components']['extensions'], $stored['components']['content']), 'combined report reload retains every ownership component');
    $fabricated = $report;
    $fabricated['components']['extensions']['summary']['unverified'] = 0;
    $fabricated['components']['extensions']['summary']['clean'] = 2;
    $fabricated['components']['extensions']['status'] = 'clean';
    $fabricated['status'] = 'clean';
    $check(!site_health_report_valid($fabricated), 'persisted extension counters must agree with item statuses');
    $fabricated = $report;
    foreach ($fabricated['components']['extensions']['items'] as &$fabricatedItem) {
        if ($fabricatedItem['folder'] === 'store-plugin') $fabricatedItem['baseline'] = 'none';
    }
    unset($fabricatedItem);
    $check(!site_health_report_valid($fabricated), 'persisted clean extension results require a canonical exact HTTPS Store baseline');
    $check(site_health_report_valid($modifiedExtension), 'a modified trusted extension report has valid structure');
    $fabricated = $modifiedExtension;
    foreach ($fabricated['components']['extensions']['items'] as &$fabricatedItem) {
        if ($fabricatedItem['folder'] === 'store-plugin') $fabricatedItem['baseline'] = 'none';
    }
    unset($fabricatedItem);
    $check(!site_health_report_valid($fabricated), 'persisted modified extension results require a canonical exact HTTPS Store baseline');
    $fabricated = $report;
    $fabricated['components']['content']['findings'][] = ['path' => 'private_files/fake.php', 'status' => 'contaminated', 'reason' => 'executable_upload'];
    $check(!site_health_report_valid($fabricated), 'persisted content counters must agree with untruncated findings');
    $fabricated = $report;
    $fabricated['components']['extensions']['items'] = [];
    $fabricated['components']['extensions']['summary'] = ['total' => 0, 'clean' => 0, 'unverified' => 0, 'modified' => 0, 'contaminated' => 0, 'infected' => 0];
    $fabricated['components']['extensions']['status'] = 'clean';
    $fabricated['components']['extensions']['findings'] = [['path' => 'public/views/themes/backdoor.php', 'status' => 'contaminated', 'reason' => 'unexpected_file']];
    $fabricated['status'] = 'clean';
    $check(!site_health_report_valid($fabricated), 'persisted extension findings cannot contradict a clean component status');
    $scanLock = update_operation_open_lock(dirname(site_health_report_path()) . '/site-health-scan.lock');
    flock($scanLock, LOCK_EX);
    try {
        site_health_run_and_store($fixture, $fixture . '/public', $provider, $extensionProvider);
        $contentionRejected = false;
    } catch (RuntimeException $error) {
        $contentionRejected = true;
    }
    flock($scanLock, LOCK_UN);
    fclose($scanLock);
    $check($contentionRejected, 'a dedicated nonblocking lock prevents concurrent full scans from overwriting newer reports');

    $page = (string)file_get_contents($root . '/dashboard/admin/settings/health.php');
    $config = (string)file_get_contents($root . '/cfg/config.php');
    $translations = (string)file_get_contents($root . '/schema/translations.sql');
    $dashboardHome = (string)file_get_contents($root . '/dashboard/theme/adiwira/part/views/home.php');
    $dashboardWidgets = (string)file_get_contents($root . '/dashboard/theme/adiwira/part/views/widgets.php');
    $dashboardStyles = (string)file_get_contents($root . '/public/static/dashboard/css/style.css');
    $chevronAsset = (string)file_get_contents($root . '/public/static/icons/lucide/chevron-down.svg');
    $check(str_contains($config, "helpers/site_health.php") && str_contains($page, 'site_health_run_and_store('), 'dashboard loads and executes the locked full Site Health orchestrator');
    $check(str_contains($page, 'name="site_health_action" value="run_site_health"')
        && !str_contains($page, 'name="action"'), 'full scan form cannot trigger the layout-bypassing dashboard action dispatcher');
    $check(str_contains($page, "'Plugin and Theme Inventory'")
        && str_contains($page, "'Media and File Safety'")
        && !str_contains($page, "_e('Not scanned')"), 'dashboard presents real extension and content results instead of placeholder coverage');
    $check(str_contains($page, 'site-health__status-chart')
        && str_contains($page, "distributionCounts['unverified'] += max")
        && !str_contains($page, 'site-health__panel-result'), 'dashboard presents a conservative multi-status Core distribution outside the panel header');
    $check(str_contains($page, "item.addEventListener('mouseenter'")
        && str_contains($page, "item.addEventListener('focus'")
        && str_contains($page, "chart.classList.add('is-ready')")
        && str_contains($page, 'prefers-reduced-motion:reduce'), 'Core distribution exposes hover and keyboard details with a reduced-motion-aware load animation');
    $check(substr_count($page, 'data-health-list data-page-size="15"') === 3
        && str_contains($page, "search.addEventListener('input'")
        && str_contains($page, "status.addEventListener('change'")
        && str_contains($page, "list.healthFilterStatus=function"), 'all report tables provide client-side search, status filters, and pagination');
    $check(str_contains($page, "item.dataset.chartStatus==='clean'")
        && str_contains($page, "document.getElementById('core-findings')")
        && str_contains($page, "list.healthFilterStatus(item.dataset.chartStatus)"), 'non-clean chart selections navigate to matching filtered Core findings');
    $check(str_contains($page, 'site-health__signals')
        && str_contains($page, 'site-health__hero-meta')
        && !str_contains($page, 'site-health__hero-state')
        && str_contains($page, 'site-health__panel--accent'), 'Site Health uses a status-aware visual hierarchy for its overview and component panels');
    $check(substr_count($page, 'site-health__disclosure" id=') === 3
        && substr_count($page, '<summary class="site-health__panel-head">') === 3
        && str_contains($chevronAsset, 'lucide-chevron-down')
        && str_contains($chevronAsset, 'm6 9 6 6 6-6')
        && str_contains($page, "findings.open=true")
        && str_contains($page, "target.open=true"), 'Core, extension, and content result panels collapse accessibly and reopen for direct navigation');
    foreach (['clean', 'unverified', 'modified', 'contaminated', 'infected'] as $colorStatus) {
        $check(str_contains($page, '.site-health__count--' . $colorStatus . '{--health-color:')
            && str_contains($page, 'html.theme-dark .site-health__count--' . $colorStatus)
            && str_contains($page, 'site-health__count--<?=h($countStatus)?>'), 'light and dark themes keep an explicit count-card color for ' . $colorStatus);
    }
    $check(str_contains($dashboardHome, "\$widgets['site_health']")
        && str_contains($dashboardHome, "'render' => 'dash_widget_site_health'")
        && str_contains($dashboardHome, "'site_health:r'")
        && str_contains($dashboardHome, "current_user_can(\$pdo, 'core.settings.manage')"), 'Site Health registers as a Site Owner settings widget in the Core dashboard layout');
    $saveDashboardLayout = (string)file_get_contents($root . '/dashboard/admin/save_dashboard_layout.php');
    $check(str_contains($dashboardHome, "dashboard_widget_layout_version")
        && str_contains($dashboardHome, "\$order[] = 'site_health:r'")
        && str_contains($saveDashboardLayout, "dashboard_widget_layout_version"), 'persisted pre-Site-Health layouts receive the widget once and retain later hide choices');
    $check(str_contains($dashboardWidgets, 'function dash_widget_site_health')
        && str_contains($dashboardWidgets, 'site_health_read_report()')
        && str_contains($dashboardWidgets, "ADMIN_BASE_PATH . '/?page=admin/settings/health'")
        && !str_contains($dashboardWidgets, 'site_health_run_and_store(')
        && !str_contains($dashboardWidgets, 'site_health_run(')
        && !str_contains($dashboardWidgets, 'core_integrity_run('), 'dashboard Site Health widget reads only the persisted report and links to the manual scan page');
    $check(str_contains($dashboardWidgets, '$cleanPercentage')
        && str_contains($dashboardWidgets, '$findingCount')
        && str_contains($dashboardWidgets, '$completedAt > 0 ? __(\'View Site Health\') : __(\'Run full scan\')')
        && str_contains($dashboardWidgets, '$scoreTotal > 0 ? min(100, max(0, ($distributionCount / $scoreTotal) * 100)) : 0.0')
        && str_contains($dashboardWidgets, 'dw-health-chart-segment')
        && str_contains($dashboardWidgets, 'dw-health-chart-legend')
        && str_contains($dashboardStyles, '.dw-health-chart-track,.dw-health-chart-segment')
        && str_contains($dashboardStyles, '.dw-health-chart-dot--clean')
        && !str_contains($dashboardStyles, '.dw-health-progress')
        && !str_contains($dashboardWidgets, 'fetch('), 'dashboard widget presents a complete persisted Core percentage ring including explicit zero values without automatic scan requests');
    foreach (['Run full scan', 'Scanned', 'Plugin and Theme Inventory', 'Media and File Safety', 'Canonical exact-version Store hashes and filesystem safety', 'Canonical exact HTTPS Store baseline', 'No trusted release baseline', 'Executable and MIME safety rules', 'Core file status', 'Search results…', 'Filter by status', 'All statuses', 'Showing %d-%d of %d results', 'No results match these filters.', 'Integrity center', 'Observed state', 'Overall health', 'View Site Health'] as $source) {
        $check(substr_count($translations, "'" . str_replace("'", "''", $source) . "'") >= 2, 'full Site Health translation coverage: ' . $source);
    }
} finally {
    $removeTree($fixture);
}

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " Site Health contract checks failed.\n");
    exit(1);
}
echo "Site Health contract passed ({$checks} checks).\n";
