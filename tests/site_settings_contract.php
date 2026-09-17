<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$public = $root . '/public';
require_once $root . '/cfg/helpers/settings_helpers.php';

$failures = [];
$checks = 0;
$check = static function (bool $condition, string $message) use (&$failures, &$checks): void {
    $checks++;
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};

$invalidUrl = 'Favicon must use a root-relative path or an HTTPS URL to a PNG, ICO, or SVG file.';
$invalidFile = 'Local favicon file is missing or outside the public directory.';
$invalidDimensions = 'Favicon must be square (1:1) and at least 48×48 pixels.';

$check(settings_favicon_url_validation_error('', $public) === null
    && settings_favicon_url_validation_error('https://cdn.example.test/brand/favicon.png?v=2', $public) === null,
    'favicon validation accepts empty and external HTTPS image URLs without fetching them');
$check(settings_favicon_url_validation_error('http://example.test/favicon.png', $public) === $invalidUrl
    && settings_favicon_url_validation_error('//example.test/favicon.png', $public) === $invalidUrl
    && settings_favicon_url_validation_error('https://user@example.test/favicon.png', $public) === $invalidUrl
    && settings_favicon_url_validation_error('https://example.test/favicon.jpg', $public) === $invalidUrl
    && settings_favicon_url_validation_error('https://example.test/favicon%0A.png', $public) === $invalidUrl,
    'favicon validation rejects unsafe schemes, credentials, unsupported formats, and encoded controls');
$check(settings_favicon_url_validation_error('/static/img/favicon/apple-touch-icon.png', $public) === null,
    'favicon validation accepts a square local image at least 48 pixels wide');
$check(settings_favicon_url_validation_error('/static/img/favicon/favicon-32x32.png', $public) === $invalidDimensions
    && settings_favicon_url_validation_error('/static/img/favicon/jyavani.svg', $public) === $invalidDimensions,
    'favicon validation rejects undersized and non-square local images');
$check(settings_favicon_url_validation_error('/static/img/favicon/missing.png', $public) === $invalidFile,
    'favicon validation rejects missing local files');

$layout = (string)file_get_contents($root . '/app/layout.php');
$dashboardLayout = (string)file_get_contents($root . '/dashboard/theme/adiwira/layout.php');
$settingsPage = (string)file_get_contents($root . '/dashboard/admin/settings/site.php');
$check(!is_file($public . '/static/img/favicon-16x16.png')
    && !is_file($public . '/static/img/favicon-32x32.png'),
    'obsolete root favicon assets cannot be mistaken for canonical Jyavani fallbacks');
$check(str_contains($settingsPage, 'id="site-settings-form" data-unsaved-guard')
    && str_contains($settingsPage, "data-unsaved-guard-initial-dirty' : ''"),
    'Site Settings warns before leaving changed or server-rejected values unsaved');
$collectionHook = strpos($settingsPage, "do_action('site_settings_after_collection_paths', \$pdo, \$_POST)");
$postsPath = strpos($settingsPage, 'id="posts_list_path"');
$pagesPath = strpos($settingsPage, 'id="pages_list_path"');
$categoriesSection = strpos($settingsPage, 'settings-section--categories');
$check($collectionHook !== false && $postsPath !== false && $pagesPath !== false && $categoriesSection !== false
    && $postsPath < $pagesPath && $pagesPath < $collectionHook && $collectionHook < $categoriesSection,
    'Site Settings exposes collection extensions directly below the Core Post and Page list paths');
$translations = (string)file_get_contents($root . '/schema/translations.sql');
$check(str_contains($layout, '$defaultAppleTouchIconUrl = $faviconUrl !== \'\' ? $faviconUrl')
    && str_contains($layout, "apply_filters('apple_touch_icon_url', \$defaultAppleTouchIconUrl, \$pdo)"),
    'frontend custom favicon is the default Apple touch icon while preserving plugin override');
$check(str_contains($dashboardLayout, "settings_get(\$pdo, 'favicon_url', '')")
    && str_contains($dashboardLayout, '<link rel="icon" href="<?= htmlspecialchars($faviconUrl')
    && str_contains($dashboardLayout, '/static/img/favicon/jyavani.svg')
    && str_contains($dashboardLayout, '/static/img/favicon/favicon-32x32.png')
    && !str_contains($dashboardLayout, 'href="/static/img/favicon-32x32.png"'),
    'dashboard uses the custom favicon setting with canonical Jyavani fallbacks');
$check(str_contains($settingsPage, 'settings_favicon_url_validation_error($favicon_url)')
    && str_contains($settingsPage, 'Use a square (1:1) PNG, ICO, or SVG at least 48×48 pixels.'),
    'Site Settings validates favicon input and documents search-compatible dimensions');
$check(str_contains($settingsPage, 'settings-section--timezone')
    && str_contains($settingsPage, 'name="site_timezone"')
    && str_contains($settingsPage, 'app_timezone_identifiers()')
    && str_contains($settingsPage, 'app_timezone_is_valid($current_site_timezone)')
    && str_contains($settingsPage, "settings_set(\$pdo, 'site_timezone'"),
    'Site Settings exposes, validates, and persists the IANA site timezone');
$check(str_contains($settingsPage, 'name="date_format_choice"')
    && str_contains($settingsPage, 'name="date_format_custom"')
    && str_contains($settingsPage, 'name="time_format_choice"')
    && str_contains($settingsPage, 'name="time_format_custom"')
    && str_contains($settingsPage, 'id="date-format-preview"')
    && str_contains($settingsPage, 'id="time-format-preview"')
    && str_contains($settingsPage, "bindFormatPreview('date'")
    && str_contains($settingsPage, "bindFormatPreview('time'")
    && str_contains($settingsPage, "app_display_format_validation_error(\$current_date_format, 'date')")
    && str_contains($settingsPage, "app_display_format_validation_error(\$current_time_format, 'time')")
    && str_contains($settingsPage, "settings_set(\$pdo, 'date_format'")
    && str_contains($settingsPage, "settings_set(\$pdo, 'time_format'"),
    'Site Settings provides preset and custom validated date/time display formats');
$check(str_contains($settingsPage, '<select name="date_format_choice"')
    && str_contains($settingsPage, '<select name="time_format_choice"')
    && !str_contains($settingsPage, 'type="radio" name="date_format_choice"')
    && !str_contains($settingsPage, 'type="radio" name="time_format_choice"')
    && str_contains($settingsPage, 'customWrap.hidden = !isCustom')
    && str_contains($settingsPage, 'custom.disabled = !isCustom'),
    'Date and Time Format use compact selects and reveal custom inputs only when selected');

foreach ([$invalidUrl, $invalidFile, $invalidDimensions,
    'Use a square (1:1) PNG, ICO, or SVG at least 48×48 pixels. Use a stable URL for search engines, or leave empty for the default favicon.'] as $source) {
    $escaped = str_replace("'", "''", $source);
    $check(substr_count($translations, "'{$escaped}'") >= 2, "favicon UI translation coverage: {$source}");
}
foreach (['Timezone', 'Site Timezone', 'Invalid site timezone.',
    'Controls how local dates and times are entered, displayed, and written to legacy wall-clock fields.',
    'Current site time:', 'Existing timestamps are not shifted when this setting changes.',
    'Leave empty to use the current site time.'] as $source) {
    $escaped = str_replace("'", "''", $source);
    $check(substr_count($translations, "'{$escaped}'") >= 2, "timezone UI translation coverage: {$source}");
}
foreach (['Date Format', 'Time Format', 'Custom:', 'Enter a custom date format below.',
    'Enter a custom time format below.', 'Preview:',
    'Supported date tokens: d, j, m, n, F, M, Y, y, l, D.',
    'Supported time tokens: H, G, h, g, i, s, a, A, T, P.',
    'Use spaces or - . , / : ( ) as separators.',
    'Invalid date format.', 'Invalid time format.', 'Invalid format.'] as $source) {
    $escaped = str_replace("'", "''", $source);
    $check(substr_count($translations, "'{$escaped}'") >= 2, "date/time format translation coverage: {$source}");
}

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " site settings contract check(s) failed.\n");
    exit(1);
}
echo "Site settings contract passed ({$checks} checks).\n";
