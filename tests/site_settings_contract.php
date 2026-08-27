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
$dashboardLayout = (string)file_get_contents($root . '/dashboard/theme/adam/layout.php');
$settingsPage = (string)file_get_contents($root . '/dashboard/admin/settings/site.php');
$translations = (string)file_get_contents($root . '/schema/translations.sql');
$check(str_contains($layout, '$defaultAppleTouchIconUrl = $faviconUrl !== \'\' ? $faviconUrl')
    && str_contains($layout, "apply_filters('apple_touch_icon_url', \$defaultAppleTouchIconUrl, \$pdo)"),
    'frontend custom favicon is the default Apple touch icon while preserving plugin override');
$check(str_contains($dashboardLayout, "settings_get(\$pdo, 'favicon_url', '')")
    && str_contains($dashboardLayout, '<link rel="icon" href="<?= htmlspecialchars($faviconUrl'),
    'dashboard uses the same custom favicon setting');
$check(str_contains($settingsPage, 'settings_favicon_url_validation_error($favicon_url)')
    && str_contains($settingsPage, 'Use a square (1:1) PNG, ICO, or SVG at least 48×48 pixels.'),
    'Site Settings validates favicon input and documents search-compatible dimensions');

foreach ([$invalidUrl, $invalidFile, $invalidDimensions,
    'Use a square (1:1) PNG, ICO, or SVG at least 48×48 pixels. Use a stable URL for search engines, or leave empty for the default favicon.'] as $source) {
    $escaped = str_replace("'", "''", $source);
    $check(substr_count($translations, "'{$escaped}'") >= 2, "favicon UI translation coverage: {$source}");
}

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " site settings contract check(s) failed.\n");
    exit(1);
}
echo "Site settings contract passed ({$checks} checks).\n";
