<?php
declare(strict_types=1);

$root = dirname(__DIR__);
define('PUBLIC_PATH', $root . '/public');
define('VIEWS_BASE', PUBLIC_PATH . '/views/themes');
require_once $root . '/cfg/helpers/theme_helper.php';

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};

$allCore = ['anime', 'quill', 'fonts', 'swiper'];
$check(theme_manifest_core_assets([]) === $allCore, 'legacy manifests retain all Core assets');
$check(theme_manifest_core_assets(['core_assets' => []]) === [], 'themes can disable all Core assets');
$check(
    theme_manifest_core_assets([
        'core_assets' => [
            'default' => $allCore,
            'contexts' => ['main.homepage' => []],
        ],
    ], 'main.homepage') === [],
    'Core assets can be disabled for one context'
);
$check(
    theme_manifest_core_assets([
        'core_assets' => [
            'default' => ['fonts'],
            'contexts' => ['single.*' => ['fonts', 'quill']],
        ],
    ], 'single.post') === ['quill', 'fonts'],
    'Core asset context wildcards are supported'
);

$assetManifest = [
    'styles' => [
        'assets/css/style.css',
        ['src' => 'assets/css/blocks.css', 'exclude_contexts' => ['main.homepage']],
    ],
    'scripts' => [
        'assets/js/site.js',
        ['src' => 'assets/js/code.js', 'contexts' => ['single.*']],
    ],
];
$check(
    theme_manifest_asset_sources($assetManifest, 'styles', 'main.homepage') === ['assets/css/style.css'],
    'homepage excludes context-specific styles'
);
$check(
    theme_manifest_asset_sources($assetManifest, 'scripts', 'single.post') === ['assets/js/site.js', 'assets/js/code.js'],
    'single context includes matching scripts'
);
$check(
    !theme_manifest_has_asset($assetManifest, 'scripts', 'code.js', 'main.homepage'),
    'asset detection respects the current context'
);

$fixtureFolder = '.asset-contract-' . getmypid();
$fixtureRoot = VIEWS_BASE . '/' . $fixtureFolder;
$removeFixture = static function (string $path) use (&$removeFixture): void {
    if (!is_dir($path)) return;
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $target = $path . '/' . $entry;
        if (is_dir($target)) $removeFixture($target);
        else @unlink($target);
    }
    @rmdir($path);
};

try {
    mkdir($fixtureRoot . '/assets/css', 0775, true);
    mkdir($fixtureRoot . '/assets/js', 0775, true);
    mkdir($fixtureRoot . '/assets/img', 0775, true);
    file_put_contents($fixtureRoot . '/assets/css/style.css', 'body{}');
    file_put_contents($fixtureRoot . '/assets/css/blocks.css', '.block{}');
    file_put_contents($fixtureRoot . '/assets/js/site.js', 'void 0;');
    file_put_contents($fixtureRoot . '/assets/js/code.js', 'void 0;');
    file_put_contents($fixtureRoot . '/assets/img/hero-480.webp', '480');
    file_put_contents($fixtureRoot . '/assets/img/hero-960.webp', '960');
    file_put_contents($fixtureRoot . '/theme.json', json_encode([
        'name' => 'Asset Contract Fixture',
        'version' => '1.0.0',
        'styles' => $assetManifest['styles'],
        'scripts' => $assetManifest['scripts'],
        'core_assets' => ['default' => $allCore, 'contexts' => ['main.homepage' => []]],
        'preloads' => [[
            'href' => 'assets/img/hero-960.webp',
            'as' => 'image',
            'contexts' => ['main.homepage'],
            'fetchpriority' => 'high',
            'imagesizes' => '100vw',
            'imagesrcset' => [
                ['href' => 'assets/img/hero-480.webp', 'descriptor' => '480w'],
                ['href' => 'assets/img/hero-960.webp', 'descriptor' => '960w'],
            ],
        ]],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    $homepageAssets = collect_theme_asset_urls(null, [$fixtureFolder], 'main.homepage');
    $singleAssets = collect_theme_asset_urls(null, [$fixtureFolder], 'single.post');
    $check(resolve_theme_core_assets(null, [$fixtureFolder], 'main.homepage') === [], 'a standalone contracted theme can omit all Core assets');
    $check(resolve_theme_core_assets(null, ['default', $fixtureFolder], 'main.homepage') === $allCore, 'a relevant legacy theme retains its Core dependencies');
    $check(count($homepageAssets['styles']) === 1 && str_contains($homepageAssets['styles'][0], '?v='), 'theme CSS receives a file version');
    $check(count($homepageAssets['scripts']) === 1 && str_contains($homepageAssets['scripts'][0], '?v='), 'theme JavaScript receives a file version');
    $check(count($singleAssets['styles']) === 2 && count($singleAssets['scripts']) === 2, 'single context receives its additional assets');

    $preloads = collect_theme_preloads(null, [$fixtureFolder], 'main.homepage');
    $check(count($preloads) === 1 && ($preloads[0]['fetchpriority'] ?? '') === 'high', 'context preload is collected');
    $check(substr_count((string)($preloads[0]['imagesrcset'] ?? ''), '.webp') === 2, 'responsive preload source set is resolved');
    $check(collect_theme_preloads(null, [$fixtureFolder], 'single.post') === [], 'preload is omitted outside its context');
} finally {
    $removeFixture($fixtureRoot);
}

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " assertion(s) failed.\n");
    exit(1);
}
echo "RESULT: ALL PASS\n";
