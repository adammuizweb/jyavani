<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/cfg/helpers/extension_compatibility.php';

$failures = [];
$check = static function (bool $condition, string $label) use (&$failures): void {
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $label . PHP_EOL;
    if (!$condition) $failures[] = $label;
};

$plugin = ['technology' => [
    'schema' => 1,
    'distribution_id' => JYAVANI_DISTRIBUTION_ID,
    'implementation_id' => 'org.jyavani.php.extensions',
    'api' => '^1.0.0',
]];
$theme = ['technology' => [
    'schema' => 1,
    'distribution_id' => JYAVANI_DISTRIBUTION_ID,
    'implementation_id' => 'org.jyavani.php.themes',
    'api' => '>=1.0.0 <2.0.0',
]];

$check(extension_technology_errors([], 'plugin') === [] && extension_technology_errors([], 'theme') === [],
    'metadata-free legacy PHP packages remain compatible');
$check(extension_technology_errors($plugin, 'plugin') === [] && extension_technology_errors($theme, 'theme') === [],
    'aware plugin and theme profiles bind the installed distribution APIs');

$wrongDistribution = $plugin;
$wrongDistribution['technology']['distribution_id'] = 'org.jyavani.next';
$wrongApi = $theme;
$wrongApi['technology']['api'] = '^2.0.0';
$check(in_array('Extension targets another Jyavani distribution', extension_technology_errors($wrongDistribution, 'plugin'), true)
    && in_array('Theme API requirement is not satisfied', extension_technology_errors($wrongApi, 'theme'), true),
    'foreign distributions and unsupported APIs fail compatibility');

$malformed = $plugin;
$malformed['technology']['unexpected'] = true;
$check(in_array('Invalid technology compatibility declaration shape', extension_technology_errors($malformed, 'plugin'), true)
    && extension_technology_errors(['technology' => null], 'plugin') !== [],
    'present technology metadata is atomic and rejects unknown or null values');

$equivalent = $plugin;
$equivalent['technology']['api'] = '>=1.0.0 <2.0.0';
$check(extension_technology_parity_errors($plugin, $equivalent, 'plugin') !== []
    && extension_technology_parity_errors($plugin, $plugin, 'plugin') === []
    && extension_technology_parity_errors([], $plugin, 'plugin') !== [],
    'Store and package profiles require canonical declaration parity without mixing legacy metadata');

$themeHelper = (string)file_get_contents($root . '/cfg/helpers/theme_helper.php');
$plugins = (string)file_get_contents($root . '/plugins/index.php');
$pluginStore = (string)file_get_contents($root . '/app/controllers/PluginStoreController.php');
$themeStore = (string)file_get_contents($root . '/app/controllers/ThemeStoreClient.php');
$check(str_contains($plugins, "extension_technology_errors(\$manifest, 'plugin')")
    && str_contains($plugins, "extension_technology_parity_errors(\$catalog, \$package, 'plugin')")
    && str_contains($themeHelper, "extension_technology_errors(\$m, 'theme')")
    && str_contains($themeHelper, 'theme_assert_folder_compatible($folder)')
    && str_contains($themeHelper, '$compatible = theme_folder_compatibility_errors((string)$folder) === []'),
    'install, activation, assignment, and runtime use shared compatibility checks');
$check(str_contains($pluginStore, 'private static function technologyMetadata')
    && str_contains($themeStore, 'private static function technologyMetadata')
    && str_contains($pluginStore, "extension_technology_profile(\$metadata, 'plugin')")
    && str_contains($themeStore, "extension_technology_profile(\$metadata, 'theme')"),
    'both Store clients bound and normalize technology metadata');

if ($failures !== []) {
    fwrite(STDERR, 'RESULT: FAIL (' . count($failures) . ')' . PHP_EOL);
    exit(1);
}
echo 'RESULT: ALL PASS (7 checks)' . PHP_EOL;
