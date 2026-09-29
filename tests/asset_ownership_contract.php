<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};

$manifest = json_decode((string)file_get_contents($root . '/public/views/themes/default/theme.json'), true);
$check(is_array($manifest), 'default theme manifest is valid JSON');
$check(
    ($manifest['core_assets'] ?? null) === ['anime', 'quill', 'fonts', 'swiper'],
    'default theme declares its Core frontend dependencies explicitly'
);

foreach ([
    'public/static/assets/js/nav.js',
    'public/static/assets/js/script.js',
    'public/static/assets/js/translate.js',
    'public/static/assets/fonts/taviraj/taviraj-400.woff2',
    'public/static/img/foto/user-thumbnail.png',
] as $legacyAsset) {
    $check(!file_exists($root . '/' . $legacyAsset), $legacyAsset . ' remains removed');
}

$check(
    is_file($root . '/public/static/js/editor/core-api.js'),
    'Core editor JavaScript remains in its stable public namespace'
);

if ($failures !== []) {
    foreach ($failures as $failure) fwrite(STDERR, "FAIL: {$failure}\n");
    exit(1);
}

echo "PASS asset ownership contract\n";
