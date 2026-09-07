<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$browser = (string)file_get_contents($root . '/dashboard/admin/plugins/browse.php');
$catalog = (string)file_get_contents($root . '/dashboard/admin/plugins/catalog.php');
$card = (string)file_get_contents($root . '/dashboard/admin/plugins/_store_card.php');
$client = (string)file_get_contents($root . '/app/controllers/PluginStoreController.php');
$translations = (string)file_get_contents($root . '/schema/translations.sql');
$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};

$check(str_contains($client, 'fetchCatalogPage(')
    && str_contains($client, '?api_version=2&limit=')
    && str_contains($client, "count(\$data['plugins']) > \$limit")
    && str_contains($client, 'validatedStoreUrl('),
    'Core fetches bounded validated v2 catalog pages from the official Store');
$check(str_contains($catalog, 'core.plugins.manage')
    && str_contains($catalog, 'adiwira_require_site_owner')
    && str_contains($catalog, 'plugin_store_card_html('),
    'same-origin catalog proxy is Site Owner-only and renders escaped Core cards');
$check(str_contains($browser, 'new IntersectionObserver(')
    && str_contains($browser, 'new AbortController()')
    && str_contains($browser, 'requestSequence')
    && str_contains($browser, 'visitedCursors')
    && str_contains($browser, 'seen = new Set(')
    && str_contains($browser, "input.addEventListener('input'")
    && str_contains($browser, "grid.addEventListener('click'"),
    'Plugin Store browser incrementally loads, cancels stale searches, deduplicates, and delegates dynamic actions');
$check(str_contains($browser, '.plugin-store-browser .btn{text-decoration:none}')
    && str_contains($browser, 'grid-template-columns:repeat(3,minmax(0,1fr))')
    && str_contains($browser, '.plugin-card-actions .plugin-install-form{display:contents}')
    && str_contains($browser, '.plugin-card-actions .btn{box-sizing:border-box;display:inline-flex'),
    'Plugin Store links have no underline and card actions share consistent flex sizing');
$check(str_contains($client, '$nextCursor === $cursor')
    && str_contains($browser, '!visitedCursors.has(nextCursor)')
    && str_contains($browser, 'hasMore && appended > 0')
    && str_contains($browser, 'window.clearTimeout(debounce); load(true);'),
    'catalog loading rejects repeated/no-progress auto-loads and submit cancels pending live-search debounce');
$check(str_contains($browser, 'id="pluginStoreMore"')
    && str_contains($browser, 'href="<?= h($nextUrl) ?>"')
    && str_contains($browser, 'aria-live="polite"'),
    'incremental loading retains accessible no-JavaScript page navigation');
$check(str_contains($browser, 'PluginStoreController::fetchOfficialPlugin($pluginName)')
    && str_contains($browser, "hash_file('sha256', \$tmpZip)")
    && str_contains($browser, "'JyavaniCMS-PluginInstall', null, false")
    && str_contains($browser, 'plugin_prepare_package_stage($tmpZip, $pluginName, $activatePlugin, $pluginData)')
    && !str_contains($browser, 'foreach ($plugins as $p)'),
    'Store installation resolves exact authoritative metadata and verifies checksum independently of visible pages');
$check(str_contains($card, 'data-plugin="<?= h($name) ?>"')
    && str_contains($card, 'data-install-action="install"')
    && str_contains($card, 'loading="lazy"'),
    'server-rendered cards expose safe incremental identities and install actions');
foreach (['Search plugins', 'Load more', 'Loading plugins…', 'No plugins match your search.', 'All plugins loaded.',
    'Next page', 'Installing plugin…', 'Plugin package integrity verification failed.',
    'Plugin package version does not match the store catalog.', 'Invalid catalog request.'] as $key) {
    $needle = "('default', '" . str_replace("'", "''", $key) . "'";
    $check(substr_count($translations, $needle) === 2, $key . ' has Indonesian and German translation seeds');
}

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " plugin Store browser check(s) failed.\n");
    exit(1);
}
echo "Plugin Store browser contract passed.\n";
