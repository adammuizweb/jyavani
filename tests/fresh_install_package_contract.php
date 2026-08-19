<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$packagePath = $argv[1] ?? '';
$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};

$installer = (string)file_get_contents($root . '/public/pondasi/index.php');
$generator = (string)file_get_contents($root . '/tools/generate-manifest.php');
$builder = (string)file_get_contents($root . '/tools/build-package.php');
require_once $root . '/dashboard/admin/update/_update_helpers.php';

$check(
    str_contains($installer, 'elseif ($step === 2)'),
    'step 1 must not fall through into step 2 validation on the same request'
);
$check(
    str_contains($generator, "#^public/static/img/\\d{4}/#"),
    'dated uploaded images remain excluded from Core packages'
);
$check(
    str_contains($generator, '(?!default(?:/|$))'),
    'only the default system theme is included while Store themes remain preserved'
);
$check(
    str_contains($builder, '0100644')
        && str_contains($builder, 'setExternalAttributesName')
        && str_contains($builder, 'getExternalAttributesIndex'),
    'package builder normalizes and verifies distribution file permissions'
);

$preservePatterns = _get_preserve_patterns();
$check(!_cms_is_preserved('public/static/img/jyavani.svg', $preservePatterns), 'Core branding remains updateable');
$check(_cms_is_preserved('public/static/img/2026/08/upload.jpg', $preservePatterns), 'dated media uploads remain preserved');
$check(!_cms_is_preserved('public/views/themes/default/theme.json', $preservePatterns), 'default theme remains updateable');
$check(_cms_is_preserved('public/views/themes/adam/theme.json', $preservePatterns), 'adam Store theme remains preserved');
$check(_cms_is_preserved('public/views/themes/custom/theme.json', $preservePatterns), 'third-party themes remain preserved');

foreach ([
    'public/static/img/jyavani.svg',
    'public/static/img/favicon/jyavani.svg',
    'public/static/icons/lucide/shield-check.svg',
    'public/views/themes/default/theme.json',
    'public/views/themes/default/main/homepage.php',
] as $required) {
    $check(is_file($root . '/' . $required), 'fresh-install Core asset exists: ' . $required);
}

if ($packagePath !== '') {
    $zip = new ZipArchive();
    $opened = $zip->open($packagePath) === true;
    $check($opened, 'fresh-install package can be opened');
    if ($opened) {
        $check($zip->locateName('public/views/themes/default/theme.json') !== false, 'package contains the default system theme');
        $check($zip->locateName('public/views/themes/adam/theme.json') === false, 'package excludes the adam Store theme');
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $entry = $zip->getNameIndex($index);
            $opsys = 0;
            $attributes = 0;
            $hasAttributes = $zip->getExternalAttributesIndex($index, $opsys, $attributes);
            $unixMode = $attributes >> 16;
            $check(
                $entry !== false
                    && $hasAttributes
                    && $opsys === ZipArchive::OPSYS_UNIX
                    && ($unixMode & 0170000) === 0100000
                    && ($unixMode & 0777) === 0644,
                'package entry is a readable 0644 regular file: ' . ($entry === false ? "entry {$index}" : $entry)
            );
        }
        $zip->close();
    }
}

if ($failures !== []) {
    foreach ($failures as $failure) fwrite(STDERR, "FAIL: {$failure}\n");
    exit(1);
}

echo "Fresh-install package contract passed.\n";
