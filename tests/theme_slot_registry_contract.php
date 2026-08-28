<?php
declare(strict_types=1);

$root = dirname(__DIR__);
define('PUBLIC_PATH', $root . '/public');
define('VIEWS_BASE', PUBLIC_PATH . '/views/themes');
require_once $root . '/cfg/helpers/hooks.php';
require_once $root . '/cfg/helpers/theme_helper.php';

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};

$core = theme_slot_definitions();
$check(isset($core['header'], $core['main.homepage'], $core['footer']), 'Core slot definitions are available');
$check(slot_to_file('single.post') === 'main/single/post.php', 'Core runtime template resolution uses canonical definitions');
$check(theme_slot_key_is_valid('catalog.single') && !theme_slot_key_is_valid('Catalog/single'), 'slot keys use the persisted lowercase grammar');
$check(theme_slot_key_is_valid(str_repeat('a', 100)) && !theme_slot_key_is_valid(str_repeat('a', 101)), 'slot keys respect the database 100-byte limit');
$check(theme_slot_template_is_valid('main/catalog/single.php'), 'nested relative PHP templates are valid');
$check(!theme_slot_template_is_valid('../outside.php') && !theme_slot_template_is_valid('/absolute.php'), 'unsafe template paths are rejected');
$check(theme_slot_template_is_valid(str_repeat('a', 251) . '.php') && !theme_slot_template_is_valid(str_repeat('a', 252) . '.php'), 'slot templates respect the database 255-byte limit');

$check(register_theme_slot('catalog.single', [
    'owner' => 'catalog-plugin',
    'label' => 'Catalog item',
    'template' => 'main/catalog/single.php',
    'bulk_assign' => false,
]), 'extensions can register a valid slot');
$check(!register_theme_slot('header', ['owner' => 'other-plugin', 'label' => 'Override', 'template' => 'override.php']), 'Core slots cannot be replaced');
$check(!register_theme_slot('catalog.single', ['owner' => 'other-plugin', 'label' => 'Collision', 'template' => 'other.php']), 'first extension declaration wins collisions');
$check(!register_theme_slot('../outside', ['owner' => 'other-plugin', 'label' => 'Unsafe', 'template' => '../outside.php']), 'invalid declarations fail closed');
$check(!register_theme_slot('owner.missing', ['label' => 'Missing owner', 'template' => 'missing.php']), 'extension slots require a stable owner identity');

add_filter('theme_slot_definitions', static function (array $candidates): array {
    $candidates[] = ['key' => 'events.index', 'owner' => 'events-plugin', 'label' => 'Events', 'template' => 'main/events/index.php'];
    $candidates[] = ['key' => 'header', 'owner' => 'other-plugin', 'label' => 'Override', 'template' => 'override.php'];
    return $candidates;
});
$definitions = theme_slot_definitions();
$check(($definitions['catalog.single']['template'] ?? '') === 'main/catalog/single.php', 'registered slot template is retained');
$check(($definitions['events.index']['template'] ?? '') === 'main/events/index.php', 'definition filter appends valid slots');
$check(($definitions['header']['template'] ?? '') === 'header.php', 'definition filter cannot replace Core slots');
$check(slot_to_file('catalog.single') === 'main/catalog/single.php', 'runtime resolves extension slot templates');
$check(slot_to_file('missing.extension') === '', 'unavailable slots do not derive executable fallback templates');
$check(theme_assignment_matches_definition(['slot_owner' => null], $definitions['header']), 'legacy Core assignments remain valid without an owner');
$check(theme_assignment_matches_definition(['slot_owner' => 'catalog-plugin'], $definitions['catalog.single']), 'extension assignments match their persisted owner');
$check(!theme_assignment_matches_definition(['slot_owner' => null], $definitions['catalog.single'])
    && !theme_assignment_matches_definition(['slot_owner' => 'replacement-plugin'], $definitions['catalog.single'])
    && !theme_assignment_matches_definition(['slot_owner' => 'catalog-plugin'], null), 'missing or changed extension owners fail closed');
$check(theme_bulk_assignment_can_update(null, $definitions['catalog.single'])
    && theme_bulk_assignment_can_update(['slot_owner' => 'catalog-plugin'], $definitions['catalog.single']), 'bulk assignment can initialize or update an owner-matched extension slot');
$check(!theme_bulk_assignment_can_update(['slot_owner' => null], $definitions['catalog.single'])
    && !theme_bulk_assignment_can_update(['slot_owner' => 'replacement-plugin'], $definitions['catalog.single'])
    && theme_bulk_assignment_can_update(['slot_owner' => null], $definitions['header']), 'bulk assignment preserves incompatible extension assignments while retaining legacy Core compatibility');

$themeHelper = (string)file_get_contents($root . '/cfg/helpers/theme_helper.php');
$dashboard = (string)file_get_contents($root . '/dashboard/admin/themes/assign.php');
$pluginLoader = (string)file_get_contents($root . '/plugins/index.php');
$schema = (string)file_get_contents($root . '/schema/default.sql');
$migration = (string)file_get_contents($root . '/schema/migrations/016-extension-registry-identities.sql');
$translations = (string)file_get_contents($root . '/schema/translations.sql');
$check(str_contains($themeHelper, '$definitions = theme_slot_definitions($pdo)') && str_contains($themeHelper, 'foreach ($definitions as $slot => $definition)'), 'bulk assignment consumes canonical definitions');
$check(str_contains($dashboard, '$slotDefinitions = theme_slot_definitions($pdo)'), 'assignment dashboard consumes canonical definitions');
$check(str_contains($dashboard, '$unavailableAssignments = array_diff_key($assign_rows, $slotDefinitions)'), 'dashboard preserves and exposes unavailable assignments');
$check(strpos($themeHelper, "if (\$definition === null) return ['type' => 'unavailable'];") < strpos($themeHelper, 'get_assignment($pdo, $slot_key)'), 'unavailable slots fail before persisted templates are resolved');
$check(str_contains($themeHelper, "if (!theme_assignment_matches_definition(\$assign, \$definition)) return ['type' => 'unavailable'];"), 'owner-mismatched assignments cannot execute persisted templates');
$check(str_contains($themeHelper, '$theme_file = (string)$definition[\'template\'];'), 'runtime uses the registered canonical template instead of a persisted same-owner path');
$check(str_contains($themeHelper, "sanitize_theme_file_for_db(\$theme_file) !== \$file"), 'assignment rejects a template that differs from the registered definition');
$bulkStart = strpos($themeHelper, 'function bulk_assign_theme(');
$bulkGuard = strpos($themeHelper, 'theme_bulk_assignment_can_update(', $bulkStart);
$bulkWrite = strpos($themeHelper, 'assign_theme_to_slot(', $bulkStart);
$check($bulkStart !== false && $bulkGuard !== false && $bulkWrite !== false && $bulkGuard < $bulkWrite, 'bulk assignment checks persisted owner compatibility before writing');
$check(str_contains($dashboard, '!theme_assignment_matches_definition($assignment, $slotDefinitions[$slotKey])'), 'owner-mismatched assignments stay read-only and unavailable in the dashboard');
$check(strpos($dashboard, '!theme_assignment_matches_definition($assignment, $slotDefinitions[$slotKey])') < strpos($dashboard, "if ((\$_SERVER['REQUEST_METHOD'] ?? '') === 'POST')"), 'owner-mismatched assignments are removed from mutation semantics before POST handling');
$check(str_contains($schema, '`slot_owner` varchar(100) DEFAULT NULL') && str_contains($migration, 'ADD COLUMN `slot_owner` varchar(100)'), 'fresh and existing databases persist assignment owner identity in migration 016');
$check(str_contains($dashboard, "__('Site default')") && str_contains($dashboard, "__('Custom: %s')")
    && str_contains($dashboard, "__('Theme: %s')") && str_contains($dashboard, "__('legacy file')"), 'all assignment labels exposed by unavailable rows are translatable');
$slotLabelsSeeded = true;
foreach ($core as $definition) {
    if (!str_contains($translations, "'" . str_replace("'", "''", (string)$definition['label']) . "'")) $slotLabelsSeeded = false;
}
$check($slotLabelsSeeded, 'all canonical assignment labels have translation seeds');
$check(str_contains($pluginLoader, '$themeSlotsBeforeLoad') && str_contains($pluginLoader, '$shortcodeSourcesBeforeLoad'), 'failed plugin loading rolls back both extension registries');

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " assertion(s) failed.\n");
    exit(1);
}
echo "RESULT: ALL PASS\n";
