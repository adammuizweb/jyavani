<?php
declare(strict_types=1);

$root = dirname(__DIR__);
define('PUBLIC_PATH', $root . '/public');
define('VIEWS_BASE', PUBLIC_PATH . '/views/themes');
require_once $root . '/cfg/helpers/hooks.php';
require_once $root . '/cfg/helpers/theme_helper.php';
require_once $root . '/cfg/helpers/theme_sections.php';

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};

$suffix = (string)getmypid();
$folder = 'entrypoint-contract-' . $suffix;
$failedFolder = 'entrypoint-failed-' . $suffix;
$fixtureRoot = VIEWS_BASE . '/' . $folder;
$failedRoot = VIEWS_BASE . '/' . $failedFolder;
$outsideRoot = sys_get_temp_dir() . '/jyavani-theme-entrypoint-' . $suffix;
$linkedFolder = 'entrypoint-linked-' . $suffix;
$linkedRoot = VIEWS_BASE . '/' . $linkedFolder;

$removeTree = static function (string $path) use (&$removeTree): void {
    if (is_link($path) || is_file($path)) {
        @unlink($path);
        return;
    }
    if (!is_dir($path)) return;
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $removeTree($path . DIRECTORY_SEPARATOR . $entry);
    }
    @rmdir($path);
};

$activeFolder = $folder;
$activeThemeFilter = static function (string $current) use (&$activeFolder): string {
    return $activeFolder;
};

try {
    mkdir($fixtureRoot, 0775, true);
    mkdir($failedRoot, 0775, true);
    mkdir($outsideRoot, 0775, true);
    file_put_contents($fixtureRoot . '/theme.php', <<<'PHP'
<?php
$GLOBALS['theme_entrypoint_contract_count'] = ($GLOBALS['theme_entrypoint_contract_count'] ?? 0) + 1;
$GLOBALS['theme_entrypoint_contract_context'] = [$theme_folder, $theme_root, $pdo];
register_theme_section('contract.hero', ['label' => 'Theme-owned Hero']);
add_filter('theme_zone_widget_types', static function (array $types): array {
    $types['tz_contract'] = ['label' => 'Contract widget', 'default_config' => []];
    return $types;
});
echo 'must not escape bootstrap';
PHP);
    file_put_contents($failedRoot . '/theme.php', <<<'PHP'
<?php
register_theme_section('contract.failed', ['label' => 'Must roll back']);
register_theme_slot('contract.failed', [
    'owner' => 'entrypoint-failed',
    'label' => 'Must roll back',
    'template' => 'main/failed.php',
]);
add_filter('theme_zone_widget_types', static function (array $types): array {
    $types['tz_failed'] = ['label' => 'Must roll back'];
    return $types;
});
throw new RuntimeException('expected contract failure');
PHP);
    file_put_contents($outsideRoot . '/theme.php', '<?php $GLOBALS["outside_theme_entrypoint_loaded"] = true;');
    if (function_exists('symlink')) @symlink($outsideRoot, $linkedRoot);

    add_filter('active_theme_folder', $activeThemeFilter);
    ob_start();
    $loaded = theme_load_active_entrypoint();
    $escapedOutput = (string)ob_get_clean();

    $check($loaded, 'active theme entrypoint loads successfully');
    $check($escapedOutput === '', 'theme entrypoint output is discarded before response rendering');
    $check(($GLOBALS['theme_entrypoint_contract_count'] ?? 0) === 1, 'theme entrypoint executes exactly once per request');
    $check((theme_section_definition('contract.hero')['label'] ?? '') === 'Theme-owned Hero', 'theme entrypoint registers Theme Sections for Core dashboards and rendering');
    $widgetTypes = apply_filters('theme_zone_widget_types', []);
    $check(isset($widgetTypes['tz_contract']), 'theme entrypoint can register Customize gadget filters');
    $context = $GLOBALS['theme_entrypoint_contract_context'] ?? [];
    $check(($context[0] ?? '') === $folder && ($context[1] ?? '') === realpath($fixtureRoot)
        && array_key_exists(2, $context) && $context[2] === null, 'entrypoint receives bounded theme context variables');

    theme_load_active_entrypoint();
    $check(($GLOBALS['theme_entrypoint_contract_count'] ?? 0) === 1, 'repeated loader calls do not execute theme.php again');

    $activeFolder = $failedFolder;
    $check(!theme_load_active_entrypoint(), 'throwing theme entrypoint fails without aborting the request');
    $check(theme_section_definition('contract.failed') === [], 'failed entrypoint Theme Section registrations are rolled back');
    $check(!isset(theme_slot_definitions()['contract.failed']), 'failed entrypoint slot registrations are rolled back');
    $failedWidgetTypes = apply_filters('theme_zone_widget_types', []);
    $check(!isset($failedWidgetTypes['tz_failed']), 'failed entrypoint hook registrations are rolled back');
    $check(($GLOBALS['__jy_theme_entrypoint_errors'][$failedFolder] ?? '') === 'expected contract failure', 'failed entrypoint exposes a request-local diagnostic');

    if (is_link($linkedRoot)) {
        $check(theme_entrypoint_path($linkedFolder) === null, 'theme entrypoint cannot escape the installed themes root through a symlink');
        $check(!isset($GLOBALS['outside_theme_entrypoint_loaded']), 'rejected external entrypoint is not executed');
    }

    $router = (string)file_get_contents($root . '/public/router.php');
    $index = (string)file_get_contents($root . '/public/index.php');
    $dashboard = (string)file_get_contents($root . '/dashboard/index.php');
    foreach (['router' => $router, 'index' => $index] as $name => $source) {
        $check(strpos($source, 'plugin_load_active();') < strpos($source, 'theme_load_active_entrypoint($pdo);')
            && strpos($source, 'theme_load_active_entrypoint($pdo);') < strpos($source, 'plugin_run_frontend_init();'), $name . ' loads active theme registrations after plugins and before frontend init');
    }
    $check(strpos($dashboard, "current_user_can(\$pdo, 'core.dashboard.access')") < strpos($dashboard, 'theme_load_active_entrypoint($pdo);')
        && strpos($dashboard, 'set_locale(admin_ui_locale())') < strpos($dashboard, 'theme_load_active_entrypoint($pdo);'), 'dashboard loads theme PHP only after authorization and admin locale setup');
} finally {
    remove_filter('active_theme_folder', $activeThemeFilter);
    $removeTree($fixtureRoot);
    $removeTree($failedRoot);
    $removeTree($linkedRoot);
    $removeTree($outsideRoot);
}

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " assertion(s) failed.\n");
    exit(1);
}
echo "RESULT: ALL PASS\n";
