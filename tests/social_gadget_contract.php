<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/cfg/helpers/hooks.php';
require_once $root . '/cfg/helpers/lang_helpers.php';
require_once $root . '/cfg/helpers/theme_zones.php';

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};

$networks = theme_zone_social_networks();
$expectedNetworks = ['facebook', 'x', 'instagram', 'youtube', 'github', 'tiktok', 'telegram', 'whatsapp'];
$check(array_keys($networks) === $expectedNetworks, 'social gadget exposes all supported networks in stable order');

$invalidNetworks = [];
$normalized = theme_zone_normalize_social_config([
    'enabled' => ['github', 'facebook', 'github', 'unknown'],
    'links' => [
        'facebook' => 'https://facebook.com/example',
        'github' => 'https://github.com/example?tab=repositories&sort=updated',
        'youtube' => 'https://youtube.com/@example',
        'x' => 'javascript:alert(1)',
        'unknown' => 'https://example.com/',
    ],
], $invalidNetworks);
$check($normalized['enabled'] === ['facebook', 'github'], 'enabled social networks are whitelisted, deduplicated, and normalized');
$check(array_keys($normalized['links']) === ['facebook', 'youtube', 'github'] && $invalidNetworks === ['x'],
    'valid links are retained for enabled or disabled known networks and invalid links are reported');

$invalidUrls = [
    'javascript:alert(1)',
    'data:text/html,unsafe',
    '//example.com/profile',
    'https://user:pass@example.com/profile',
    "https://example.com/line\nbreak",
    'https:\\example.com\\profile',
    'https://',
    str_repeat('a', 2049),
];
$check(array_reduce($invalidUrls, static fn(bool $valid, string $url): bool => $valid && theme_zone_social_url($url) === '', true),
    'social URL policy rejects unsafe and malformed addresses');
$check(theme_zone_social_url('https://example.com/profile?q=a%20b#links') === 'https://example.com/profile?q=a%20b#links',
    'social URL policy accepts complete HTTP(S) profile addresses');

$pdo = new PDO('sqlite::memory:');
$html = _theme_zone_built_in_widget_html($pdo, 'tz_social', [
    'title' => 'Follow us',
    'enabled' => ['facebook', 'x', 'youtube'],
    'links' => [
        'facebook' => 'https://facebook.com/example',
        'x' => '',
        'youtube' => 'https://youtube.com/@example',
    ],
]);
$check(str_contains($html, 'tz-social-facebook') && str_contains($html, 'tz-social-youtube') && !str_contains($html, 'tz-social-x'),
    'renderer shows only enabled networks with configured links');
$check(!str_contains($html, 'href="#"') && str_contains($html, 'role="listitem"'),
    'renderer emits no dead links and preserves list semantics');
$check(_theme_zone_built_in_widget_html($pdo, 'tz_social', [
    'title' => 'Follow us',
    'enabled' => ['x', 'github', 'instagram'],
    'links' => [],
]) === '', 'social gadget with no configured links renders no orphan title');

$sidebar = (string)file_get_contents($root . '/dashboard/admin/sidebar/index.php');
$customizer = (string)file_get_contents($root . '/dashboard/admin/themes/customize.php');
$translations = (string)file_get_contents($root . '/schema/translations.sql');
$check(str_contains($sidebar, "elseif (\$type === 'tz_social')")
    && str_contains($sidebar, '[config][enabled][]')
    && str_contains($sidebar, '[config][links][')
    && str_contains($sidebar, 'type="url"')
    && str_contains($sidebar, 'theme_zone_normalize_social_config'),
    'Sidebar Manager exposes and normalizes social network controls');
$check(str_contains($customizer, 'theme_zone_normalize_social_config')
    && str_contains($customizer, "'<input type=\"url\""),
    'Theme Customizer reuses the social URL policy');
$translationKeys = [
    'Select the social networks to show. Links left empty are hidden.',
    '%s URL',
    'Invalid URL for %s. Use a complete http:// or https:// address.',
];
$check(array_reduce($translationKeys, static fn(bool $valid, string $key): bool => $valid && substr_count($translations, "'" . $key . "'") >= 2, true),
    'social gadget interface strings have Indonesian and German translations');

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " social gadget contract check(s) failed.\n");
    exit(1);
}

echo "Social gadget contract passed.\n";
