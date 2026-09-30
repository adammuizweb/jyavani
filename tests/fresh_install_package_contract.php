<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$packagePath = $argv[1] ?? '';
$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};

$installer = (string)file_get_contents($root . '/public/pondasi/index.php');
$demoInstallerHelper = (string)file_get_contents($root . '/cfg/installer_demo.php');
$generator = (string)file_get_contents($root . '/tools/generate-manifest.php');
$manifestPolicy = (string)file_get_contents($root . '/cfg/helpers/cms_manifest.php');
$builder = (string)file_get_contents($root . '/tools/build-package.php');
$schema = (string)file_get_contents($root . '/schema/default.sql');
$pluginMigration = (string)file_get_contents($root . '/schema/migrations/015-plugin-migrations.sql');
$timezoneMigration = (string)file_get_contents($root . '/schema/migrations/026-site-timezone.sql');
$dateTimeFormatMigration = (string)file_get_contents($root . '/schema/migrations/027-date-time-formats.sql');
$schedulingSettingMigration = (string)file_get_contents($root . '/schema/migrations/029-content-scheduling-setting.sql');
require_once $root . '/dashboard/admin/update/_update_helpers.php';
require_once $root . '/cfg/installer_demo.php';

$check(
    str_contains($installer, 'elseif ($step === 2)'),
    'step 1 must not fall through into step 2 validation on the same request'
);
$check(str_contains($schema, "('site_timezone',    'Asia/Jakarta', 1)")
    && str_contains($timezoneMigration, "VALUES ('site_timezone', 'Asia/Jakarta', 1)")
    && substr_count($installer, 'app_db_set_session_timezone($pdo, app_timezone_default_id())') >= 2
    && str_contains($installer, "['site_timezone', app_timezone_default_id()]"),
    'fresh and upgraded installations establish the Jakarta-compatible site timezone contract');
$check(str_contains($schema, "('date_format',      'F j, Y', 1)")
    && str_contains($schema, "('time_format',      'H:i', 1)")
    && str_contains($dateTimeFormatMigration, "('date_format', 'F j, Y', 1)")
    && str_contains($dateTimeFormatMigration, "('time_format', 'H:i', 1)")
    && str_contains($installer, "['date_format', app_date_format_default()]")
    && str_contains($installer, "['time_format', app_time_format_default()]"),
    'fresh and upgraded installations establish date and time display format defaults');
$check(str_contains($schema, "('content_scheduling_enabled', '0', 1)")
    && str_contains($schedulingSettingMigration, "('content_scheduling_enabled', '0', 1)")
    && str_contains($installer, "['content_scheduling_enabled', '0']"),
    'fresh and upgraded installations keep scheduled publishing disabled until server setup is complete');
$check(str_contains($schema, "'sistem',       'Artikel tentang administrasi sistem, maintenance, update, dan manajemen user', NULL)")
    && str_contains($installer, 'UPDATE categories SET created_by = :owner')
    && str_contains($installer, "[1 => 'panduan', 2 => 'keamanan', 3 => 'pengembangan', 4 => 'sistem']"), 'fresh default categories avoid a fixed user ID, validate canonical identity, and bind to the actual initial Site Owner');
$check(str_contains($schema, 'CREATE TABLE IF NOT EXISTS `plugin_migrations`')
    && str_contains($pluginMigration, 'CREATE TABLE IF NOT EXISTS `plugin_migrations`')
    && str_contains($pluginMigration, '`checksum` char(64)'),
    'fresh and upgraded installations define the immutable plugin migration ledger');
$check(str_contains($installer, "require_once \$cfgDir . '/installer_demo.php'")
    && str_contains($installer, 'SET @jyavani_demo_owner_id = ')
    && str_contains($installer, 'pondasi_demo_assets_publish($demoAssetsDir, $publicDir)')
    && str_contains($installer, 'pondasi_demo_assets_rollback($demoAssetOperation)'), 'demo installation binds the actual Site Owner and coordinates database and asset rollback');
$check(str_contains($installer, 'dua Preset published')
    && str_contains($installer, 'Theme Section preset-backed')
    && str_contains($installer, 'langsung tampil di homepage'), 'installer clearly describes the complete optional demo experience');
$check(str_contains($demoInstallerHelper, 'Aset demo akan menimpa file situs yang berbeda')
    && str_contains($demoInstallerHelper, 'hash_file(\'sha256\'')
    && str_contains($demoInstallerHelper, 'link($temporary, $destination)')
    && str_contains($demoInstallerHelper, "(int)(\$stat['ino'] ?? -1)")
    && str_contains($demoInstallerHelper, 'static/img/\\d{4}')
    && str_contains($demoInstallerHelper, 'getimagesize($sourceReal)')
    && str_contains($demoInstallerHelper, '$mimeByExtension')
    && str_contains($demoInstallerHelper, 'extension executable')
    && str_contains($demoInstallerHelper, 'isLink()'), 'demo asset publisher rejects conflicting, executable, and unsafe filesystem identities');
$check(
    str_contains($generator, 'cms_manifest_is_preserved(')
        && str_contains($manifestPolicy, "#^public/static/img/\\d{4}/#"),
    'dated uploaded images remain excluded from Core packages'
);
$check(
    str_contains($generator, 'cms_manifest_allowed_directories(')
        && str_contains($manifestPolicy, '(?!default(?:/|$))'),
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
    'public/views/themes/default/partials/shortcodes/section/home.preset-posts.php',
    'cfg/installer_demo.php',
    'schema/demo.sql',
] as $required) {
    $check(is_file($root . '/' . $required), 'fresh-install Core asset exists: ' . $required);
}

$fixture = sys_get_temp_dir() . '/jyavani-pondasi-demo-' . bin2hex(random_bytes(6));
$sourceDirectory = $fixture . '/source';
$targetDirectory = $fixture . '/target';
mkdir($sourceDirectory . '/static/img/2026/demo', 0770, true);
mkdir($targetDirectory, 0770, true);
$demoPng = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
file_put_contents($sourceDirectory . '/static/img/2026/demo/example.png', $demoPng);
$operation = pondasi_demo_assets_publish($sourceDirectory, $targetDirectory);
$publishedAsset = $targetDirectory . '/static/img/2026/demo/example.png';
$check(($operation['count'] ?? 0) === 1 && file_get_contents($publishedAsset) === $demoPng, 'demo asset publisher installs a verified contained image');
pondasi_demo_assets_rollback($operation);
$check(!file_exists($publishedAsset) && !is_dir($targetDirectory . '/static'), 'demo asset rollback removes files and directories created before database commit');

$changedOperation = pondasi_demo_assets_publish($sourceDirectory, $targetDirectory);
unlink($publishedAsset);
file_put_contents($publishedAsset, 'concurrent-site-file');
pondasi_demo_assets_rollback($changedOperation);
$check(file_get_contents($publishedAsset) === 'concurrent-site-file', 'demo asset rollback preserves a destination whose published filesystem identity changed');
unlink($publishedAsset);

file_put_contents($targetDirectory . '/static/img/2026/demo/example.png', 'site-owned-different-file');
$collisionRejected = false;
try {
    pondasi_demo_assets_publish($sourceDirectory, $targetDirectory);
} catch (RuntimeException $error) {
    $collisionRejected = str_contains($error->getMessage(), 'menimpa file situs');
}
$check($collisionRejected && file_get_contents($targetDirectory . '/static/img/2026/demo/example.png') === 'site-owned-different-file', 'demo asset publisher fails closed without overwriting an existing different site file');

$unsafeSource = $fixture . '/unsafe-source';
mkdir($unsafeSource . '/static/img/2026/demo', 0770, true);
file_put_contents($unsafeSource . '/static/img/2026/demo/hostile.php', '<?php echo "unsafe";');
$unsafeRejected = false;
try {
    pondasi_demo_assets_publish($unsafeSource, $targetDirectory);
} catch (RuntimeException $error) {
    $unsafeRejected = str_contains($error->getMessage(), 'hanya boleh berupa gambar');
}
$check($unsafeRejected && !file_exists($targetDirectory . '/static/img/2026/demo/hostile.php'), 'demo asset publisher never publishes executable files into public storage');

$multiExtensionSource = $fixture . '/multi-extension-source';
mkdir($multiExtensionSource . '/static/img/2026/demo', 0770, true);
file_put_contents($multiExtensionSource . '/static/img/2026/demo/hostile.php.png', $demoPng);
$multiExtensionRejected = false;
try {
    pondasi_demo_assets_publish($multiExtensionSource, $targetDirectory);
} catch (RuntimeException $error) {
    $multiExtensionRejected = str_contains($error->getMessage(), 'extension executable');
}
$check($multiExtensionRejected && !file_exists($targetDirectory . '/static/img/2026/demo/hostile.php.png'), 'demo asset publisher rejects executable multi-extension image names');

$mismatchSource = $fixture . '/mismatch-source';
mkdir($mismatchSource . '/static/img/2026/demo', 0770, true);
file_put_contents($mismatchSource . '/static/img/2026/demo/mismatch.jpg', $demoPng);
$mismatchRejected = false;
try {
    pondasi_demo_assets_publish($mismatchSource, $targetDirectory);
} catch (RuntimeException $error) {
    $mismatchRejected = str_contains($error->getMessage(), 'MIME aset demo tidak valid');
}
$check($mismatchRejected && !file_exists($targetDirectory . '/static/img/2026/demo/mismatch.jpg'), 'demo asset publisher requires the image MIME to match its final extension');

$removeFixture = static function (string $path) use (&$removeFixture): void {
    if (!is_dir($path) || is_link($path)) {
        if (is_file($path) || is_link($path)) @unlink($path);
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $removeFixture($path . DIRECTORY_SEPARATOR . $entry);
    }
    @rmdir($path);
};
$removeFixture($fixture);

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
