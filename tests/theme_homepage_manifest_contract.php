<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$fixture = sys_get_temp_dir() . '/jy-theme-homepage-' . bin2hex(random_bytes(6));
$themes = $fixture . '/themes';
$folder = 'homepage.contract';
$themeRoot = $themes . '/' . $folder;
mkdir($themeRoot . '/main', 0770, true);
define('PUBLIC_PATH', $fixture);
define('VIEWS_BASE', $themes);
define('DEFAULT_THEME_FOLDER', $folder);
require_once $root . '/cfg/helpers/theme_helper.php';

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};
$remove = static function (string $path) use (&$remove): void {
    if (is_link($path) || is_file($path)) {
        @unlink($path);
        return;
    }
    if (!is_dir($path)) return;
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') $remove($path . '/' . $entry);
    }
    @rmdir($path);
};
$writeManifest = static function (mixed $homepage) use ($themeRoot): void {
    file_put_contents($themeRoot . '/theme.json', json_encode([
        'name' => 'Homepage Contract',
        'version' => '1.0.0',
        'homepage' => $homepage,
    ], JSON_THROW_ON_ERROR));
};
$valid = [
    'mode' => 'file',
    'template' => 'main/homepage.php',
    'source_slug' => 'homepage-source',
    'sections' => ['home.hero', 'home.news'],
    'live_sections' => ['home.news'],
];

try {
    file_put_contents($themeRoot . '/main/homepage.php', '<?php echo "homepage";');
    $writeManifest($valid);
    $expected = [
        'folder' => $folder,
        'template' => 'main/homepage.php',
        'source_slug' => 'homepage-source',
        'sections' => ['home.hero', 'home.news'],
        'live_sections' => ['home.news'],
    ];
    $check(theme_homepage_manifest() === $expected, 'the default theme file homepage contract is normalized');
    $check(theme_homepage_manifest($folder) === $expected, 'theme folders may use the supported dot grammar');

    foreach ([
        'wrong mode' => [...$valid, 'mode' => 'post'],
        'wrong template' => [...$valid, 'template' => 'main/other.php'],
        'invalid source slug' => [...$valid, 'source_slug' => '../source'],
        'mapped sections' => [...$valid, 'sections' => ['hero' => 'home.hero']],
        'duplicate sections' => [...$valid, 'sections' => ['home.hero', 'HOME.HERO']],
        'unknown live section' => [...$valid, 'live_sections' => ['home.other']],
        'duplicate live sections' => [...$valid, 'live_sections' => ['home.news', 'HOME.NEWS']],
        'too many sections' => [...$valid, 'sections' => array_fill(0, 129, 'home.hero')],
    ] as $label => $homepage) {
        $writeManifest($homepage);
        $check(theme_homepage_manifest($folder) === null, $label . ' fail closed');
    }

    $writeManifest([...$valid, 'live_sections' => []]);
    $withoutLive = $expected;
    $withoutLive['live_sections'] = [];
    $check(theme_homepage_manifest($folder) === $withoutLive, 'live sections are optional');

    file_put_contents($themeRoot . '/theme.json', '{');
    $check(theme_homepage_manifest($folder) === null, 'malformed JSON fails closed');

    $writeManifest($valid);
    unlink($themeRoot . '/main/homepage.php');
    $check(theme_homepage_manifest($folder) === null, 'a missing homepage template fails closed');

    $outside = $fixture . '/outside.php';
    file_put_contents($outside, '<?php echo "outside";');
    symlink($outside, $themeRoot . '/main/homepage.php');
    $check(theme_homepage_manifest($folder) === null, 'a symlinked homepage template fails closed');
    unlink($themeRoot . '/main/homepage.php');

    file_put_contents($themeRoot . '/main/homepage.php', '<?php echo "homepage";');
    file_put_contents($themeRoot . '/theme.json', str_repeat(' ', 512 * 1024 + 1));
    $check(theme_homepage_manifest($folder) === null, 'an oversized theme manifest fails closed');
} finally {
    $remove($fixture);
}

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " assertion(s) failed.\n");
    exit(1);
}
echo "RESULT: ALL PASS\n";
