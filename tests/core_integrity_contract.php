<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$fixture = sys_get_temp_dir() . '/jy-core-integrity-' . bin2hex(random_bytes(6));
if (!mkdir($fixture . '/cfg/var', 0750, true) || !mkdir($fixture . '/app', 0755, true)
    || !mkdir($fixture . '/public', 0755, true) || !mkdir($fixture . '/tools', 0755, true)) {
    throw new RuntimeException('Unable to create Core integrity fixture.');
}
define('BACKEND_PATH', $fixture . '/cfg');
define('THEME_LIFECYCLE_LOCK_KEY', '0-theme-lifecycle');
$GLOBALS['core_integrity_test_lock_acquired'] = 0;
$GLOBALS['core_integrity_test_lock_released'] = 0;
function theme_operation_holds_lock(string $key, ?int $mode = null): bool { return false; }
function theme_operation_acquire(array $keys, int $mode, ?float $deadline = null): array
{
    $GLOBALS['core_integrity_test_lock_acquired']++;
    return [fopen('php://temp', 'r+')];
}
function theme_operation_release(array $locks): void
{
    $GLOBALS['core_integrity_test_lock_released']++;
    foreach ($locks as $lock) if (is_resource($lock)) fclose($lock);
}

require_once $root . '/cfg/helpers/update_operation.php';
require_once $root . '/cfg/helpers/core_integrity.php';

$failures = [];
$checks = 0;
$check = static function (bool $condition, string $message) use (&$failures, &$checks): void {
    $checks++;
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};
$removeTree = static function (string $directory) use (&$removeTree): void {
    if (!is_dir($directory) || is_link($directory)) {
        if (file_exists($directory) || is_link($directory)) @unlink($directory);
        return;
    }
    foreach (new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS) as $entry) {
        $path = $entry->getPathname();
        if ($entry->isDir() && !$entry->isLink()) $removeTree($path);
        else @unlink($path);
    }
    @rmdir($directory);
};

try {
    file_put_contents($fixture . '/version.json', json_encode(['version' => '9.8.7'], JSON_THROW_ON_ERROR));
    file_put_contents($fixture . '/app/core.php', "<?php\nreturn true;\n");
    file_put_contents($fixture . '/public/index.php', "<?php\n");
    $files = [
        'app/core.php' => hash_file('sha256', $fixture . '/app/core.php'),
        'public/index.php' => hash_file('sha256', $fixture . '/public/index.php'),
        'version.json' => hash_file('sha256', $fixture . '/version.json'),
    ];
    $manifest = ['version' => '9.8.7', 'total_files' => count($files), 'files' => $files];
    file_put_contents($fixture . '/tools/cms-manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));

    $clean = core_integrity_scan(['manifest' => $manifest, 'trusted' => true, 'source' => 'test', 'reason' => null], $fixture, $fixture . '/public');
    $check($clean['status'] === 'clean' && $clean['summary']['clean'] === 3 && $clean['findings'] === [], 'matching trusted files produce a clean report');

    mkdir($fixture . '/plugins/example', 0755, true);
    mkdir($fixture . '/public/views/themes/custom', 0755, true);
    mkdir($fixture . '/public/static/img/2026', 0755, true);
    file_put_contents($fixture . '/plugins/example/plugin.php', '<?php');
    file_put_contents($fixture . '/public/views/themes/custom/index.php', '<?php');
    file_put_contents($fixture . '/public/static/img/2026/upload.jpg', 'image');
    $preserved = core_integrity_scan(['manifest' => $manifest, 'trusted' => true, 'source' => 'test', 'reason' => null], $fixture, $fixture . '/public');
    $check($preserved['status'] === 'clean' && $preserved['findings'] === [], 'plugin, Store theme, and dated upload ownership boundaries are preserved');

    file_put_contents($fixture . '/public/site-owned.js', 'expected site asset');
    file_put_contents($fixture . '/cfg/site-files.json', json_encode([
        'schema' => 1,
        'files' => ['public/site-owned.js' => hash_file('sha256', $fixture . '/public/site-owned.js')],
    ], JSON_THROW_ON_ERROR));
    $siteOwned = core_integrity_scan(['manifest' => $manifest, 'trusted' => true, 'source' => 'test', 'reason' => null], $fixture, $fixture . '/public');
    $check($siteOwned['status'] === 'clean' && $siteOwned['findings'] === [], 'an exact hash-bound site file is outside Core ownership');
    file_put_contents($fixture . '/public/site-owned.js', 'changed site asset');
    $siteChanged = core_integrity_scan(['manifest' => $manifest, 'trusted' => true, 'source' => 'test', 'reason' => null], $fixture, $fixture . '/public');
    $check($siteChanged['status'] === 'contaminated'
        && in_array('site_file_hash_mismatch', array_column($siteChanged['findings'], 'reason'), true), 'a changed site-owned file remains visible as contamination');
    unlink($fixture . '/public/site-owned.js');
    unlink($fixture . '/cfg/site-files.json');

    file_put_contents($fixture . '/app/core.php', "<?php\nreturn false;\n");
    $modified = core_integrity_scan(['manifest' => $manifest, 'trusted' => true, 'source' => 'test', 'reason' => null], $fixture, $fixture . '/public');
    $check($modified['status'] === 'modified'
        && $modified['summary']['modified'] === 1
        && ($modified['findings'][0]['reason'] ?? '') === 'hash_mismatch', 'a trusted hash mismatch is modified');

    $untrusted = core_integrity_scan(['manifest' => $manifest, 'trusted' => false, 'source' => 'installed_local', 'reason' => 'canonical_unavailable'], $fixture, $fixture . '/public');
    $check($untrusted['status'] === 'unverified' && $untrusted['summary']['modified'] === 1, 'a local-only baseline reports drift without claiming trusted modification status');

    file_put_contents($fixture . '/app/foreign.php', '<?php');
    $contaminated = core_integrity_scan(['manifest' => $manifest, 'trusted' => true, 'source' => 'test', 'reason' => null], $fixture, $fixture . '/public');
    $reasons = array_column($contaminated['findings'], 'reason');
    $check($contaminated['status'] === 'contaminated' && in_array('unexpected_file', $reasons, true), 'an unexpected file in a Core-managed location is contaminated');
    $untrustedContaminated = core_integrity_scan(['manifest' => $manifest, 'trusted' => false, 'source' => 'installed_local', 'reason' => 'canonical_unavailable'], $fixture, $fixture . '/public');
    $check($untrustedContaminated['status'] === 'unverified', 'manifest-dependent contamination cannot override an untrusted baseline');
    unlink($fixture . '/app/foreign.php');

    unlink($fixture . '/public/index.php');
    symlink($fixture . '/app/core.php', $fixture . '/public/index.php');
    $unsafe = core_integrity_scan(['manifest' => $manifest, 'trusted' => true, 'source' => 'test', 'reason' => null], $fixture, $fixture . '/public');
    $check($unsafe['status'] === 'contaminated'
        && in_array('unsafe_path', array_column($unsafe['findings'], 'reason'), true), 'the scanner rejects a symlink at an expected Core path');
    unlink($fixture . '/public/index.php');
    file_put_contents($fixture . '/public/index.php', "<?php\n");
    file_put_contents($fixture . '/app/core.php', "<?php\nreturn true;\n");

    $requestedUrl = '';
    $resolved = core_integrity_resolve_baseline($fixture, static function (string $url, string $version) use ($manifest, &$requestedUrl): array {
        $requestedUrl = $url;
        return $manifest;
    });
    $check($resolved['trusted'] === true && $resolved['source'] === 'canonical_https'
        && str_contains($requestedUrl, 'version=9.8.7'), 'an exact version-qualified canonical provider establishes the trusted baseline');
    $fallback = core_integrity_resolve_baseline($fixture, static fn(string $url, string $version): array => array_replace($manifest, ['version' => '10.0.0']));
    $check($fallback['trusted'] === false && $fallback['source'] === 'installed_local'
        && $fallback['reason'] === 'canonical_version_mismatch', 'a different canonical version falls back without trusting the local manifest');

    foreach ([
        ['version' => '1', 'total_files' => 1, 'files' => ['../bad.php' => str_repeat('a', 64)]],
        ['version' => '1', 'total_files' => 2, 'files' => ['app/a.php' => str_repeat('a', 64)]],
        ['version' => '1', 'total_files' => 1, 'files' => ['app/a.php' => 'bad']],
    ] as $invalid) {
        try {
            cms_manifest_validate($invalid, 'test manifest');
            $rejected = false;
        } catch (RuntimeException $error) {
            $rejected = true;
        }
        $check($rejected, 'malformed manifest paths, counts, and hashes fail closed');
    }
    $legacyPreserved = ['version' => '1', 'total_files' => 1, 'files' => ['private_files/legacy.txt' => str_repeat('a', 64)]];
    $check(cms_manifest_validate($legacyPreserved, 'legacy local manifest', true)['total_files'] === 1, 'recognized preserved paths remain readable from legacy local manifests');
    try {
        cms_manifest_validate($legacyPreserved, 'remote manifest');
        $remotePreservedRejected = false;
    } catch (RuntimeException $error) {
        $remotePreservedRejected = true;
    }
    $check($remotePreservedRejected, 'new remote manifests cannot claim preserved site-owned paths');
    file_put_contents($fixture . '/cfg/site-files.json', '{bad json');
    $invalidSiteManifest = core_integrity_scan(['manifest' => $manifest, 'trusted' => true, 'source' => 'test', 'reason' => null], $fixture, $fixture . '/public');
    $check($invalidSiteManifest['status'] === 'unverified'
        && in_array('site_manifest_invalid', array_column($invalidSiteManifest['findings'], 'reason'), true), 'an invalid site ownership manifest fails closed');
    unlink($fixture . '/cfg/site-files.json');
    $check(!cms_manifest_site_file_allowed('app/backdoor.php')
        && !cms_manifest_site_file_allowed('public/backdoor.php')
        && cms_manifest_site_file_allowed('public/site.css')
        && cms_manifest_site_file_allowed('tools/site-import.php'), 'site ownership cannot exempt Core runtime or public executable files');
    file_put_contents($fixture . '/app/large.php', str_repeat('x', 32));
    $check(core_integrity_hash_regular_file($fixture . '/app/large.php', 8)['status'] === 'too_large', 'descriptor size limits stop oversized Core files before hashing');
    $check(core_integrity_hash_regular_file($fixture . '/app/large.php', 64, microtime(true) - 1)['status'] === 'limit', 'chunked hashing enforces the wall-clock deadline');
    unlink($fixture . '/app/large.php');

    mkdir($fixture . '/empty', 0755, true);
    file_put_contents($fixture . '/empty/version.json', json_encode(['version' => '9.8.7'], JSON_THROW_ON_ERROR));
    $noBaseline = core_integrity_run($fixture . '/empty', $fixture . '/public', static fn(string $url, string $version): ?array => null);
    $check($noBaseline['status'] === 'unverified' && core_integrity_report_valid($noBaseline), 'a missing canonical and local baseline produces a valid unverified report');

    $fresh = core_integrity_run($fixture, $fixture . '/public', static fn(string $url, string $version): array => $manifest);
    $check($fresh['status'] === 'clean' && core_integrity_write_report($fresh), 'a completed report is written atomically');
    $check($GLOBALS['core_integrity_test_lock_acquired'] === 1 && $GLOBALS['core_integrity_test_lock_released'] === 1, 'standalone scans acquire and release the shared lifecycle lock');
    $stored = core_integrity_read_report();
    $check(is_array($stored) && $stored['status'] === 'clean' && ($stored['baseline']['version'] ?? '') === '9.8.7', 'the persisted report is schema-validated when read');
    $reportStat = lstat(core_integrity_report_path());
    $check(is_array($reportStat) && (($reportStat['mode'] ?? 0) & 0777) === 0640, 'persisted reports use a private file mode');
    $fabricated = $fresh;
    $fabricated['status'] = 'clean';
    $fabricated['baseline']['trusted'] = false;
    $check(!core_integrity_report_valid($fabricated), 'a local baseline cannot fabricate a persisted clean report');
    $contradictory = $fresh;
    $contradictory['baseline']['source'] = 'installed_local';
    $check(!core_integrity_report_valid($contradictory), 'persisted report trust must agree with its baseline source');
    $contradictory = $fresh;
    $contradictory['summary']['modified'] = 1;
    $contradictory['status'] = 'modified';
    $check(!core_integrity_report_valid($contradictory), 'persisted report counters must agree with untruncated findings');
    $contradictory = $fresh;
    $contradictory['baseline']['version'] = '';
    $contradictory['baseline']['total_files'] = 0;
    $contradictory['summary']['expected'] = 0;
    $contradictory['summary']['clean'] = 0;
    $check(!core_integrity_report_valid($contradictory), 'a canonical clean report requires a nonempty release baseline');
    $inventoryProbe = $fresh;
    $inventoryProbe['findings'] = [];
    $inventoryProbe['summary']['unverified'] = 0;
    core_integrity_inventory_root($inventoryProbe, $fixture . '/app/core.php', 'app', []);
    $check($inventoryProbe['complete'] === false
        && ($inventoryProbe['findings'][0]['reason'] ?? '') === 'inventory_unreadable', 'an unreadable or invalid inventory root makes the scan incomplete');
    $preservedProbe = $fresh;
    $preservedProbe['findings'] = [];
    $preservedProbe['summary']['unverified'] = 0;
    core_integrity_inventory_root($preservedProbe, $fixture . '/plugins', 'plugins', [], microtime(true) - 1);
    $check($preservedProbe['complete'] === true && $preservedProbe['findings'] === [], 'preserved extension trees are pruned before inventory limits apply');

    $helper = (string)file_get_contents($root . '/cfg/helpers/core_integrity.php');
    $page = (string)file_get_contents($root . '/dashboard/admin/settings/health.php');
    $aside = (string)file_get_contents($root . '/dashboard/theme/adiwira/part/aside.php');
    $hub = (string)file_get_contents($root . '/dashboard/admin/settings/index.php');
    $translations = (string)file_get_contents($root . '/schema/translations.sql');
    $check(str_contains($page, "adiwira_require_permission(\$pdo, 'core.settings.manage', false)")
        && str_contains($page, 'adiwira_require_site_owner($pdo, false)')
        && !str_contains($page, 'adiwira_require_admin'), 'Site Health requires settings permission and Site Owner authority');
    $check(strpos($page, 'adiwira_csrf_validate(') < strpos($page, 'site_health_run_and_store(')
        && str_contains($page, 'name="site_health_action" value="run_site_health"')
        && !str_contains($page, 'name="action"'), 'manual scans require an allowlisted non-dispatch action and CSRF before execution');
    $check(str_contains($page, "sprintf(__('Last scan: %s'), \$scanTime)")
        && str_contains($page, "sprintf(__('%d expected files'),")
        && !str_contains($page, "__('Last scan: %s',"), 'translated format strings use sprintf instead of passing values as the translation scope');
    $check(str_contains($helper, 'update_metadata_fetch_json(')
        && str_contains($helper, 'hash_update(')
        && !str_contains($helper, 'FOLLOW_SYMLINKS'), 'Core integrity uses bounded canonical metadata and descriptor hashing without following links');
    $check(str_contains($aside, 'admin/settings/health') && str_contains($aside, "adam_icon('server'")
        && str_contains($hub, "'href'  => \$base . '/?page=admin/settings/health'"), 'Site Health is linked from the Site Owner settings surfaces');
    $check(str_contains($page, 'It does not prove that the website')
        && str_contains($page, "'Plugins and themes'")
        && str_contains($page, "'Canonical exact HTTPS Store baseline'")
        && str_contains($page, "'No trusted release baseline'"), 'the UI carries the mandatory disclaimer and explicit extension trust boundary');
    foreach (['Site Health', 'Core Integrity', 'Clean', 'Unverified', 'Modified', 'Contaminated', 'Infected', 'Run full scan', 'Important limitation'] as $source) {
        $check(substr_count($translations, "'" . str_replace("'", "''", $source) . "'") >= 2, 'Site Health translation coverage: ' . $source);
    }
} finally {
    $removeTree($fixture);
}

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " Core integrity contract checks failed.\n");
    exit(1);
}
echo "Core integrity contract passed ({$checks} checks).\n";
