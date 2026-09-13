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
foreach (['cfg/var', 'app', 'tools', 'public/views/themes/default', 'public/views/themes/store-theme', 'public/static/img/2026', 'plugins/local-plugin', 'plugins/.backup-local-plugin', 'private_files/media/2026'] as $directory) {
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
    file_put_contents($fixture . '/plugins/local-plugin/plugin.json', json_encode(['name' => 'Local Plugin', 'version' => '1.0.0'], JSON_THROW_ON_ERROR));
    file_put_contents($fixture . '/plugins/local-plugin/plugin.php', '<?php');
    file_put_contents($fixture . '/plugins/.backup-local-plugin/plugin.json', json_encode(['name' => 'Local Plugin', 'version' => '0.9.0'], JSON_THROW_ON_ERROR));
    file_put_contents($fixture . '/plugins/.backup-local-plugin/plugin.php', '<?php');
    file_put_contents($fixture . '/public/views/themes/store-theme/theme.json', json_encode([
        'name' => 'Store Theme', 'version' => '2.0.0', 'store' => ['url' => 'https://example.test/themes/', 'slug' => 'store-theme'],
    ], JSON_THROW_ON_ERROR));
    file_put_contents($fixture . '/public/views/themes/store-theme/index.php', '<?php');
    file_put_contents($fixture . '/private_files/media/2026/photo.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='));

    $provider = static fn(string $url, string $version): array => $manifest;
    $report = site_health_run($fixture, $fixture . '/public', $provider);
    $check($report['components']['core']['status'] === 'clean', 'full scan retains independent clean Core integrity');
    $check($report['components']['extensions']['status'] === 'unverified'
        && $report['components']['extensions']['summary']['total'] === 2, 'plugins and themes are inventoried but remain unverified without trusted file manifests');
    $check(!in_array('.backup-local-plugin', array_column($report['components']['extensions']['items'], 'folder'), true), 'hidden plugin backups are excluded from installed extension inventory');
    $check($report['components']['content']['status'] === 'scanned'
        && $report['components']['content']['files_scanned'] === 1, 'safe site-owned content receives a completed safety scan');
    $check($report['status'] === 'unverified', 'overall state preserves the unverified extension boundary');

    file_put_contents($fixture . '/public/views/themes/backdoor.php', '<?php');
    $rootArtifact = site_health_run($fixture, $fixture . '/public', $provider);
    $check($rootArtifact['components']['extensions']['status'] === 'contaminated'
        && in_array('public/views/themes/backdoor.php', array_column($rootArtifact['components']['extensions']['findings'], 'path'), true), 'regular executable files directly under the theme root are contaminated');
    unlink($fixture . '/public/views/themes/backdoor.php');

    symlink($fixture . '/app/core.php', $fixture . '/plugins/local-plugin/unsafe-link');
    $unsafeExtension = site_health_run($fixture, $fixture . '/public', $provider);
    $check($unsafeExtension['components']['extensions']['status'] === 'contaminated'
        && $unsafeExtension['status'] === 'contaminated', 'an unsafe extension artifact raises component and overall contamination');
    unlink($fixture . '/plugins/local-plugin/unsafe-link');

    file_put_contents($fixture . '/private_files/media/2026/shell.php.jpg', '<?php echo 1;');
    $unsafeContent = site_health_run($fixture, $fixture . '/public', $provider);
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

    $storedRun = site_health_run_and_store($fixture, $fixture . '/public', $provider);
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
        site_health_run_and_store($fixture, $fixture . '/public', $provider);
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
        && str_contains($page, 'site-health__hero-state')
        && str_contains($page, 'site-health__panel--accent'), 'Site Health uses a status-aware visual hierarchy for its overview and component panels');
    $check(substr_count($page, 'site-health__disclosure" id=') === 3
        && substr_count($page, '<summary class="site-health__panel-head">') === 3
        && str_contains($page, "findings.open=true")
        && str_contains($page, "target.open=true"), 'Core, extension, and content result panels collapse accessibly and reopen for direct navigation');
    foreach (['clean', 'unverified', 'modified', 'contaminated', 'infected'] as $colorStatus) {
        $check(str_contains($page, '.site-health__count--' . $colorStatus . '{--health-color:')
            && str_contains($page, 'site-health__count--<?=h($countStatus)?>'), 'light theme keeps an explicit count-card color for ' . $colorStatus);
    }
    $check(str_contains($dashboardHome, "\$widgets['site_health']")
        && str_contains($dashboardHome, "'render' => 'dash_widget_site_health'")
        && str_contains($dashboardHome, "'site_health:r'")
        && str_contains($dashboardHome, "current_user_can(\$pdo, 'core.settings.manage')"), 'Site Health registers as a Site Owner settings widget in the Core dashboard layout');
    $check(str_contains($dashboardWidgets, 'function dash_widget_site_health')
        && str_contains($dashboardWidgets, 'site_health_read_report()')
        && str_contains($dashboardWidgets, "ADMIN_BASE_PATH . '/?page=admin/settings/health'")
        && !str_contains($dashboardWidgets, 'site_health_run_and_store(')
        && !str_contains($dashboardWidgets, 'site_health_run(')
        && !str_contains($dashboardWidgets, 'core_integrity_run('), 'dashboard Site Health widget reads only the persisted report and links to the manual scan page');
    foreach (['Run full scan', 'Scanned', 'Plugin and Theme Inventory', 'Media and File Safety', 'Inventory and filesystem safety', 'Executable and MIME safety rules', 'Core file status', 'Search results…', 'Filter by status', 'All statuses', 'Showing %d-%d of %d results', 'No results match these filters.', 'Integrity center', 'Observed state', 'Overall health', 'View Site Health'] as $source) {
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
