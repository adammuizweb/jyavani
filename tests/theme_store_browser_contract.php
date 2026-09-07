<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$browser = (string)file_get_contents($root . '/dashboard/admin/themes/browse.php');
$catalog = (string)file_get_contents($root . '/dashboard/admin/themes/catalog.php');
$card = (string)file_get_contents($root . '/dashboard/admin/themes/_store_card.php');
$client = (string)file_get_contents($root . '/app/controllers/ThemeStoreClient.php');
$translations = (string)file_get_contents($root . '/schema/translations.sql');
$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};

$check(str_contains($client, 'fetchCatalogPage(') && str_contains($client, '?api_version=2&limit=')
    && str_contains($client, "count(\$data['themes']) > \$limit") && str_contains($client, '$nextCursor === $cursor'),
    'Core fetches bounded validated theme catalog pages and rejects repeated cursors');
$check(str_contains($catalog, 'core.themes.manage') && str_contains($catalog, 'adiwira_require_site_owner')
    && str_contains($catalog, 'session_write_close()') && str_contains($catalog, 'theme_store_card_html('),
    'same-origin theme catalog proxy is Site Owner-only and releases its session before remote I/O');
$check(!str_contains($browser, 'session_write_close()') && str_contains($browser, "preg_match('//u', \$_GET['q'])")
    && str_contains($browser, 'role="status"'),
    'layout-rendered catalog retains its session, invalid UTF-8 is normalized, and progress uses status semantics');
$check(str_contains($browser, 'new IntersectionObserver(') && str_contains($browser, 'new AbortController()')
    && str_contains($browser, 'requestSequence') && str_contains($browser, 'visitedCursors')
    && str_contains($browser, 'hasMore&&appended>0') && str_contains($browser, "input.addEventListener('input'"),
    'Theme Store incrementally loads, cancels stale searches, and bounds no-progress auto-loading');
$check(str_contains($browser, 'JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT')
    && str_contains($browser, 'id="themeStoreMore"') && str_contains($browser, 'href="<?= h($nextUrl) ?>"'),
    'inline data is script-safe and no-JavaScript cursor navigation remains available');
$check(str_contains($browser, '.theme-store-browser .btn{text-decoration:none}')
    && str_contains($browser, '.theme-card-actions form{display:flex;flex:1 1 0}')
    && str_contains($browser, '.theme-card-actions .btn{box-sizing:border-box;display:inline-flex'),
    'Theme Store links have no underline and card actions share consistent flex sizing');
$check(str_contains($browser, 'ThemeStoreClient::fetchOfficialTheme($themeName)')
    && str_contains($browser, "hash_file('sha256', \$tmpZip)")
    && str_contains($browser, "'JyavaniCMS-ThemeInstall', null, false")
    && str_contains($browser, 'install_theme_from_zip($pdo, $tmpZip, false, $uid, $themeName, $themeData)')
    && !str_contains($browser, 'foreach ($themes as $p)'),
    'Store install uses exact authoritative metadata, bounded streaming, and checksum verification');
$check(str_contains($card, 'data-theme="<?= h($name) ?>"') && str_contains($card, 'theme_store_installed_map')
    && str_contains($card, 'scandir(VIEWS_BASE)') && str_contains($card, 'theme_store_physical_manifest($path)')
    && str_contains($card, 'is_link($manifestPath)') && str_contains($card, "!is_string(\$manifest['version'])")
    && !str_contains($browser, 'SELECT manifest_json FROM themes'),
    'escaped reusable cards reconcile physical themes into an O(1) map without per-card queries');
foreach (['Search themes', 'Search themes…', 'No themes match your search.', 'All themes loaded.', 'Loading themes…',
    'Installing theme…', 'Theme already installed.', 'Theme not found in store.', 'Invalid theme package checksum.',
    'Unknown error.', 'Theme installed from store.', 'Theme package version does not match the store catalog.',
    'Theme package requirements do not match the store catalog.', 'This theme requires PHP %s or newer.'] as $key) {
    $needle = "('default', '" . str_replace("'", "''", $key) . "'";
    $check(substr_count($translations, $needle) === 2, $key . ' has Indonesian and German translation seeds');
}

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " Theme Store browser check(s) failed.\n");
    exit(1);
}
echo "Theme Store browser contract passed.\n";
