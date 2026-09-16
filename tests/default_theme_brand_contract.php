<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$header = (string)file_get_contents($root . '/public/views/themes/default/header.php');
$themeJson = (string)file_get_contents($root . '/public/views/themes/default/theme.json');
$theme = json_decode($themeJson, true, 512, JSON_THROW_ON_ERROR);
$styles = (string)file_get_contents($root . '/public/views/themes/default/assets/css/style.css');
$defaultSchema = (string)file_get_contents($root . '/schema/default.sql');
$migration = (string)file_get_contents($root . '/schema/migrations/025-correct-default-theme-wordmark.sql');
$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};

$sequence = [
    ['Just', 'J'],
    ['Your', 'y'],
    ['Available', 'a'],
    ['Visiting', 'v'],
    ['Always', 'a'],
    ['Nice', 'n'],
    ['Inspire', 'i'],
];
$brandPattern = '';
foreach ($sequence as [$word, $letter]) {
    $brandPattern .= '.*?data-word=["\\\\]' . preg_quote($word, '/') . '["\\\\][^>]*>' . $letter . '<\/span>';
}

$check(preg_match('/' . $brandPattern . '/s', $header) === 1,
    'default header spells Jyavani with Available as the first a');
$brandPresets = [];
$collectBrandPresets = static function (array $items) use (&$collectBrandPresets, &$brandPresets): void {
    foreach ($items as $key => $value) {
        if ($key === 'html' && is_string($value) && str_contains($value, 'class="jyavani-logo"')) {
            $brandPresets[] = $value;
        } elseif (is_array($value)) {
            $collectBrandPresets($value);
        }
    }
};
$collectBrandPresets($theme);
$validBrandPresets = array_filter(
    $brandPresets,
    static fn(string $html): bool => preg_match('/' . $brandPattern . '/s', $html) === 1
);
$check(count($brandPresets) === 2 && count($validBrandPresets) === 2,
    'default Theme Zone brand presets retain the complete Jyavani wordmark');
$legacyPreset = str_replace(
    "    <span class=\"letter base\" data-word=\"Available\">a</span>\n",
    '',
    $brandPresets[0] ?? ''
);
$legacyHashes = [hash('sha256', $legacyPreset), hash('sha256', $legacyPreset . "\n")];
$check(
    str_contains($defaultSchema, 'data-word="Available">a</span>\\n    <span class="letter accent" data-word="Visiting"')
        && str_contains($defaultSchema, "(`zone_slug` = 'header' AND `position` = 'logo')")
        && str_contains($defaultSchema, "`title` = 'Site Logo'"),
    'fresh-install Theme Zone seeds are corrected to the complete Jyavani wordmark'
);
$check(
    str_contains($migration, "`zone_slug` = 'header' AND `position` = 'logo'")
        && str_contains($migration, "`zone_slug` = 'footer' AND `position` = 'about'")
        && substr_count($migration, 'SHA2(JSON_UNQUOTE(JSON_EXTRACT') === 2
        && str_contains($migration, $legacyHashes[0])
        && str_contains($migration, $legacyHashes[1])
        && !str_contains($migration, hash('sha256', $legacyPreset . "\n<!-- customized -->")),
    'legacy migration accepts both exact footer defaults without matching customized HTML'
);
$check(str_contains($styles, '.jyavani-logo .letter:nth-child(7) { transition-delay: 480ms; }'),
    'wordmark wave animation includes the seventh letter');

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " default theme brand contract check(s) failed.\n");
    exit(1);
}

echo "Default theme brand contract passed.\n";
