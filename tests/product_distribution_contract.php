<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/cfg/helpers/cms_manifest.php';
require_once $root . '/cfg/helpers/product_descriptor.php';

$failures = [];
$check = static function (bool $condition, string $label) use (&$failures): void {
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $label . PHP_EOL;
    if (!$condition) $failures[] = $label;
};

$metadata = jyavani_product_metadata($root);
$distribution = $metadata['distribution'];
$check(($metadata['product']['id'] ?? null) === JYAVANI_PRODUCT_ID
    && ($distribution['distribution_id'] ?? null) === JYAVANI_DISTRIBUTION_ID
    && ($distribution['implementation_id'] ?? null) === JYAVANI_IMPLEMENTATION_ID,
    'release metadata separates stable Jyavani product identity from the PHP distribution');
$check(($metadata['version'] ?? null) === trim((string)file_get_contents($root . '/VERSION'))
    && ($distribution['version'] ?? null) === $metadata['version'],
    'flat release and distribution versions remain exact');
$check(($distribution['extension_system']['api_versions'] ?? null) === [JYAVANI_EXTENSION_API_VERSION]
    && ($distribution['theme_system']['api_versions'] ?? null) === [JYAVANI_THEME_API_VERSION],
    'distribution advertises explicit extension and theme implementation contracts');
$capabilities = array_column($distribution['capabilities'], 'status', 'id');
$check(($capabilities['content.lifecycle'] ?? null) === 'native'
    && ($capabilities['asset.blob-sha256'] ?? null) === 'partial'
    && ($capabilities['archive.create-only'] ?? null) === 'unsupported',
    'capabilities report current limits instead of claiming unimplemented portability');

$invalid = $metadata;
$invalid['distribution']['distribution_id'] = 'org.jyavani.next';
try {
    jyavani_product_metadata_validate($invalid, (string)$metadata['version']);
    $wrongDistributionRejected = false;
} catch (RuntimeException $error) {
    $wrongDistributionRejected = str_contains($error->getMessage(), 'another Jyavani distribution');
}
$check($wrongDistributionRejected, 'another distribution fails closed');

$invalidImplementation = $metadata;
$invalidImplementation['distribution']['extension_system']['implementation_id'] = 'org.jyavani.next.extensions';
try {
    jyavani_product_metadata_validate($invalidImplementation, (string)$metadata['version']);
    $wrongImplementationRejected = false;
} catch (RuntimeException $error) {
    $wrongImplementationRejected = str_contains($error->getMessage(), 'another implementation API');
}
$check($wrongImplementationRejected, 'foreign extension and theme implementation IDs fail closed');

$invalid = $metadata;
$invalid['distribution']['capabilities'][] = $invalid['distribution']['capabilities'][0];
try {
    jyavani_product_metadata_validate($invalid, (string)$metadata['version']);
    $duplicateRejected = false;
} catch (RuntimeException $error) {
    $duplicateRejected = str_contains($error->getMessage(), 'Duplicate distribution capability');
}
$check($duplicateRejected, 'duplicate capability identity fails closed');

$legacy = $metadata;
unset($legacy['product'], $legacy['distribution'], $legacy['contracts']);
$legacy['version'] = '2.3.194';
$partial = $legacy;
$partial['product'] = $metadata['product'];
$check(jyavani_core_package_distribution_errors($legacy) === []
    && jyavani_core_package_distribution_errors($partial) === ['Incomplete Jyavani product or distribution metadata']
    && jyavani_core_package_distribution_errors($invalid) !== [],
    'legacy Core packages remain accepted while partial or incompatible aware packages fail closed');
$futureLegacy = $legacy;
$futureLegacy['version'] = JYAVANI_AWARE_CORE_VERSION_FLOOR;
$check(jyavani_core_package_distribution_errors($futureLegacy) !== [],
    'Core releases from the aware metadata floor cannot downgrade to legacy identity');
$malformedLegacy = $legacy;
$malformedLegacy['version'] = 'v2.3.999';
$check(jyavani_core_package_distribution_errors($malformedLegacy) !== [],
    'malformed Core versions cannot bypass the aware metadata floor');
$futureLegacy['version'] = JYAVANI_AWARE_CORE_VERSION_FLOOR . '+build.1';
$check(jyavani_core_package_distribution_errors($futureLegacy) !== [],
    'Core build metadata cannot lower a release below the aware metadata floor');

$config = (string)file_get_contents($root . '/cfg/config.php');
$generator = (string)file_get_contents($root . '/tools/generate-manifest.php');
$systemPage = (string)file_get_contents($root . '/dashboard/admin/settings/system.php');
$widgets = (string)file_get_contents($root . '/dashboard/theme/adiwira/part/views/widgets.php');
$aside = (string)file_get_contents($root . '/dashboard/theme/adiwira/part/aside.php');
$settings = (string)file_get_contents($root . '/dashboard/admin/settings/index.php');
$translations = (string)file_get_contents($root . '/schema/translations.sql');
$updateHelpers = (string)file_get_contents($root . '/dashboard/admin/update/_update_helpers.php');
$updateActions = (string)file_get_contents($root . '/dashboard/admin/update/_update_actions.php');
$check(strpos($config, "helpers/product_descriptor.php") < strpos($config, "helpers/debug_helpers.php"),
    'product descriptor loads before database-independent runtime consumers');
$check(str_contains($generator, "'distribution' => \$version['distribution']")
    && str_contains($generator, 'jyavani_product_metadata_validate'),
    'Core manifest generator validates and carries platform metadata');
$check(str_contains($systemPage, "'core.settings.manage'") && str_contains($systemPage, 'adiwira_require_site_owner')
    && str_contains($systemPage, 'PDO::ATTR_SERVER_VERSION')
    && !str_contains($systemPage, 'phpinfo(') && !str_contains($systemPage, 'getenv('),
    'System Information is Site Owner-only and exposes bounded facts without environment secrets');
$check(str_contains($widgets, 'function dash_widget_system_info(PDO $pdo)')
    && str_contains($widgets, "current_user_can(\$pdo, 'core.settings.manage')")
    && !str_contains($widgets, "if (\$statsScope === 'any')"),
    'System Info widget no longer follows the ordinary dashboard statistics permission');
$check(substr_count($aside, 'admin/settings/system') >= 2 && str_contains($settings, 'admin/settings/system'),
    'owner navigation exposes the dedicated System Information page');
$check(substr_count($translations, "'System Information'") === 2
    && substr_count($translations, "'View System Information'") === 2,
    'System Information labels have Indonesian and German seeds');
$check(str_contains($updateHelpers, 'function _cms_validate_core_package_metadata(')
    && str_contains($updateHelpers, 'jyavani_core_package_distribution_errors($packageVersion)')
    && str_contains($updateActions, '_cms_validate_core_package_metadata($zip, $remoteManifest)')
    && str_contains($updateHelpers, 'Core package distribution metadata does not match its manifest.'),
    'manual and managed Core update paths verify distribution identity before mutation');

if ($failures !== []) {
    fwrite(STDERR, 'RESULT: FAIL (' . count($failures) . ')' . PHP_EOL);
    exit(1);
}
echo 'RESULT: ALL PASS (' . 18 . ' checks)' . PHP_EOL;
