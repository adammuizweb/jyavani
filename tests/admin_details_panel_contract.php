<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/cfg/helpers/hooks.php';
if (!defined('ADMIN_BASE_PATH')) define('ADMIN_BASE_PATH', '/secure-admin');
if (!defined('ADAM_THEME')) define('ADAM_THEME', true);

function __(string $source, string $scope = 'default'): string { return $source; }
function _e(string $source, string $scope = 'default'): void { echo __($source, $scope); }
function h(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }

function plugin_resolve_route(string $route): ?array
{
    return $route === 'plugin/example/report'
        ? ['route' => $route, 'plugin' => 'example', 'title' => 'Example Report', 'file' => '/private/plugin.php']
        : null;
}

require_once $root . '/cfg/helpers/admin_details.php';

$read = static fn(string $path): string => (string)file_get_contents($root . '/' . $path);
$failures = [];
$checks = 0;
$check = static function (bool $condition, string $message) use (&$failures, &$checks): void {
    $checks++;
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};

$pdo = new PDO('sqlite::memory:');
$_GET['id'] = '42';
$_SERVER['REQUEST_METHOD'] = 'GET';

add_filter('admin_details_context', static function (array $context): array {
    foreach (['schema', 'page', 'page_valid', 'mode', 'entity_id', 'user_id', 'request_method', 'admin_base_path', 'is_plugin_page', 'plugin', 'plugin_title'] as $key) {
        $context[$key] = 'spoofed';
    }
    $context['extension_example'] = 'available';
    return $context;
});
add_filter('admin_details_context', static fn(): string => 'invalid', 15);
add_filter('admin_details_context', static function (): array {
    throw new RuntimeException('context listener failure');
}, 20);
add_filter('admin_details_context', static function (array $context): array {
    $context['extension_after_failure'] = true;
    return $context;
}, 30);
$context = admin_details_context($pdo, 'plugin/example/report', 7);
$check($context['schema'] === 1
    && $context['page'] === 'plugin/example/report'
    && $context['page_valid'] === true
    && $context['mode'] === 'default'
    && $context['user_id'] === 7
    && $context['entity_id'] === 42
    && $context['request_method'] === 'GET'
    && $context['admin_base_path'] === '/secure-admin'
    && $context['is_plugin_page'] === true
    && $context['plugin'] === 'example'
    && $context['plugin_title'] === 'Example Report'
    && $context['extension_example'] === 'available'
    && $context['extension_after_failure'] === true
    && !isset($context['file']),
    'schema-1 context preserves protected Core metadata while allowing extension keys');

add_filter('admin_details_visible', static fn(bool $visible, array $ctx): bool => $ctx['plugin'] !== 'example');
add_filter('admin_details_visible', static fn(): array => [], 20);
add_filter('admin_details_visible', static fn(bool $visible): bool => $visible, 30);
$check(admin_details_visible($pdo, $context) === false,
    'visibility filter can remove one route and ignores malformed listener returns');

add_filter('admin_details_core_content', static fn(string $html): string => $html . '<p>extended</p>');
add_filter('admin_details_core_content', static fn(): array => [], 15);
add_filter('admin_details_core_content', static function (): string {
    throw new RuntimeException('content listener failure');
}, 20);
add_filter('admin_details_core_content', static fn(string $html): string => $html . '<p>continued</p>', 30);
$filtered = admin_details_filter_core_content($pdo, '<p>core</p>', $context);
$check($filtered === '<p>core</p><p>extended</p><p>continued</p>',
    'isolated content filters retain successful output and continue after failures');

$actionCalls = [];
add_action('admin_details', static function () use (&$actionCalls): void {
    $actionCalls[] = 'legacy';
    echo '<p>legacy</p>';
});
add_action('admin_details', static function () use (&$actionCalls): void {
    $actionCalls[] = 'failed';
    echo '<p>partial</p>';
    ob_start();
    echo '<p>nested-partial</p>';
    throw new RuntimeException('action listener failure');
}, 20);
add_action('admin_details', static function (array $ctx, PDO $db) use (&$actionCalls, $pdo): void {
    if ($ctx['page'] === 'plugin/example/report' && $db === $pdo) {
        $actionCalls[] = 'context';
        echo '<p>context</p>';
        ob_start();
        echo '<p>nested-context</p>';
    }
}, 30);
ob_start();
admin_details_run_action('admin_details', $pdo, $context);
$actionOutput = (string)ob_get_clean();
$check($actionCalls === ['legacy', 'failed', 'context']
    && $actionOutput === '<p>legacy</p><p>context</p><p>nested-context</p>',
    'legacy and contextual actions continue while failed listener output is discarded');

add_action('admin_details_before', static function (): void { echo '<i>before</i>'; });
add_action('admin_details_after', static function (): void { echo '<i>after</i>'; });

$details = $read('dashboard/theme/adiwira/part/details.php');
$layout = $read('dashboard/theme/adiwira/layout.php');
$header = $read('dashboard/theme/adiwira/part/header.php');
$script = $read('public/static/dashboard/js/panel.js');
$style = $read('public/static/dashboard/css/style.css');
$docs = $read('AGENTS.md');
$translations = $read('schema/translations.sql');

$renderDetails = static function (array $renderContext, bool $visible) use ($root, $pdo): string {
    $adminDetailsContext = $renderContext;
    $adminDetailsVisible = $visible;
    $user = ['id' => $renderContext['user_id']];
    ob_start();
    include $root . '/dashboard/theme/adiwira/part/details.php';
    return (string)ob_get_clean();
};
$themeContext = admin_details_context($pdo, 'admin/themes/edit', 7);
$check($themeContext['mode'] === 'theme_preview' && $themeContext['entity_id'] === 42,
    'theme-preview mode is derived from the protected route and entity context');
$themeOutput = $renderDetails($themeContext, true);
$check(str_contains($themeOutput, 'Live Theme Preview (ID: 42)')
    && str_contains($themeOutput, '/secure-admin/live.php?id=42')
    && str_contains($themeOutput, 'title="Live Theme Preview"'),
    'theme-preview branch renders translated formatted text without a type error');
$homeContext = $context;
$homeContext['page'] = 'home';
$homeContext['mode'] = 'default';
$homeContext['is_plugin_page'] = false;
$homeContext['plugin'] = '';
$homeContext['plugin_title'] = '';
$homeOutput = $renderDetails($homeContext, true);
$check(strpos($homeOutput, '<i>before</i>') < strpos($homeOutput, '<h3>Information</h3>')
    && strpos($homeOutput, '<h3>Information</h3>') < strpos($homeOutput, '<p>legacy</p>')
    && strpos($homeOutput, '<p>legacy</p>') < strpos($homeOutput, '<i>after</i>'),
    'rendered output preserves before, Core, primary, and after ordering');
$check($renderDetails($context, false) === '',
    'invisible details panel renders no markup or listener output');

$beforePosition = strpos($details, "admin_details_run_action('admin_details_before'");
$corePosition = strpos($details, '$coreDetailsContent ?>');
$primaryPosition = strpos($details, "admin_details_run_action('admin_details'");
$afterPosition = strpos($details, "admin_details_run_action('admin_details_after'");
$check($beforePosition !== false && $corePosition !== false && $primaryPosition !== false && $afterPosition !== false
    && $beforePosition < $corePosition && $corePosition < $primaryPosition && $primaryPosition < $afterPosition,
    'details template exposes ordered before, Core, primary, and after render slots');
$check(str_contains($layout, 'admin_details_context($pdo')
    && str_contains($layout, 'admin_details_visible($pdo')
    && str_contains($layout, 'details-panel-disabled')
    && str_contains($header, '($adminDetailsVisible ?? true) === true')
    && str_contains($style, 'body.details-panel-disabled'),
    'visibility is resolved before layout rendering and removes all panel chrome');
$check(str_contains($details, 'role="separator"')
    && str_contains($details, 'aria-valuenow="360"')
    && str_contains($script, "['ArrowLeft', 'ArrowRight', 'Home', 'End']")
    && str_contains($script, "event.key === 'Escape'")
    && str_contains($script, 'function maxPanelWidth()')
    && str_contains($script, 'function closeMobilePanel(')
    && str_contains($script, 'mobile === previousMobile')
    && str_contains($script, 'new MutationObserver(')
    && str_contains($script, "setAttribute('aria-expanded'")
    && str_contains($script, "setAttribute('aria-hidden'"),
    'toggle and resizer expose synchronized keyboard-accessible state');
$check(str_contains($docs, '### Details panel extension contract')
    && str_contains($docs, 'admin_details_core_content')
    && str_contains($docs, 'do not grant access'),
    'details panel extension API and authorization boundary are documented');

foreach (['Details panel', 'Resize details panel', 'Close details panel', 'Live Theme Preview', 'Live Theme Preview (ID: %d)'] as $key) {
    $check(substr_count($translations, "'" . $key . "'") >= 2,
        $key . ' has Indonesian and German translation seeds');
}

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " admin details panel contract check(s) failed.\n");
    exit(1);
}
echo "Admin details panel contract passed ({$checks} checks).\n";
