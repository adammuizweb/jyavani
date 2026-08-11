<?php
declare(strict_types=1);

final class PresetContractStatement extends PDOStatement
{
    public function __construct(private array $rows = []) {}
    public function execute(?array $params = null): bool { return true; }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        if ($mode === PDO::FETCH_COLUMN) {
            $column = (int)($args[0] ?? 0);
            return array_map(static fn(array $row): mixed => array_values($row)[$column] ?? null, $this->rows);
        }
        return $this->rows;
    }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        return array_shift($this->rows) ?: false;
    }
    public function fetchColumn(int $column = 0): mixed
    {
        $row = array_shift($this->rows);
        return is_array($row) ? (array_values($row)[$column] ?? false) : false;
    }
}

final class PresetContractPdo extends PDO
{
    public function __construct() {}
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if (str_contains($query, "status = 'published'")) {
            return new PresetContractStatement([
                ['id' => 71, 'slug' => 'static_collision', 'meta' => '{}'],
                ['id' => 72, 'slug' => 'runtime_preset', 'meta' => '{"plugin_key":{"kept":true}}'],
                ['id' => 73, 'slug' => 'invalid.dot', 'meta' => '{}'],
            ]);
        }
        return new PresetContractStatement([]);
    }
}

final class PresetTransactionContractPdo extends PDO
{
    public function __construct(public bool $active) {}
    public function inTransaction(): bool { return $this->active; }
}

$root = dirname(__DIR__);
define('PUBLIC_PATH', $root . '/public');
define('VIEWS_BASE', PUBLIC_PATH . '/views/themes');
require_once $root . '/cfg/helpers/hooks.php';
require_once $root . '/cfg/helpers/widget_helper.php';

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};

$invalidFilters = shortcode_preset_list_filters(['p' => ['2'], 'q' => ['bad'], 'status' => ['deleted'], 'owner' => ['9']], false);
$check($invalidFilters === ['p' => 1, 'q' => '', 'status' => '', 'owner' => 0], 'list filters reject malformed pages, statuses, and non-admin owner filters');
$filters = shortcode_preset_list_filters(['p' => '3', 'q' => '  hero_100%  ', 'status' => 'private', 'owner' => '9'], true);
$check($filters['p'] === 3 && $filters['owner'] === 9 && $filters['status'] === 'private', 'admin list filters retain validated pagination, owner, and status');
$adminSpec = shortcode_preset_list_spec($filters, 4, 'admin');
$check(str_contains($adminSpec['where'], 'p.created_by = :owner') && ($adminSpec['params'][':owner'] ?? 0) === 9, 'admin owner filter is parameterized');
$check(($adminSpec['params'][':search'] ?? '') === '%hero\\_100\\%%', 'title and slug search escapes LIKE wildcards');
$authorSpec = shortcode_preset_list_spec($filters, 4, 'author');
$check(str_contains($authorSpec['where'], 'p.created_by = :uid') && !str_contains($authorSpec['where'], ':owner'), 'non-admin list visibility is always ownership scoped');

$check(shortcode_preset_slug_is_valid('hero_cards-2'), 'preset slug grammar accepts lowercase widget identifiers');
$check(!shortcode_preset_slug_is_valid('hero.cards') && !shortcode_preset_slug_is_valid('Hero'), 'preset slug grammar rejects names the shortcode parser cannot consume');
$check(shortcode_preset_slugify('Hero Cards!') === 'hero-cards', 'preset slug generation produces parser-safe identifiers');

add_filter('shortcode_preset_defaults', static function (array $defaults): array {
    $defaults['plugin_default'] = 'default-value';
    return $defaults;
});
add_filter('shortcode_preset_config_loaded', static function (array $config): array {
    $config['loaded_hook'] = true;
    return $config;
});
$loaded = shortcode_preset_config_loaded('{"plugin_key":{"kept":true}}');
$check(($loaded['plugin_default'] ?? '') === 'default-value' && ($loaded['plugin_key']['kept'] ?? false), 'defaults and stored unknown plugin keys survive config loading');
$check(($loaded['loaded_hook'] ?? false) === true, 'config-loaded filter runs');

add_filter('shortcode_preset_sources', static function (array $sources): array {
    $sources[] = 'plugin_feed';
    $sources[] = 'Invalid Source';
    return $sources;
});
$sources = shortcode_preset_sources(['scope' => 'contract']);
$check(in_array('posts', $sources, true) && in_array('plugin_feed', $sources, true), 'source filter adds plugin sources without removing Core sources');
$check(!in_array('invalid source', $sources, true), 'source filter rejects identifiers outside the source grammar');
$pluginSourceAdmin = shortcode_preset_validate_config(array_merge($loaded, ['source' => 'plugin_feed']), true, null, ['scope' => 'contract']);
$check(!in_array('Invalid preset source.', $pluginSourceAdmin['errors'], true), 'administrators can save a source registered by a plugin');
$pluginSourceAuthor = shortcode_preset_validate_config(array_merge($loaded, ['source' => 'plugin_feed']), false, null, ['scope' => 'contract']);
$check(in_array('Only administrators can use non-Core preset sources.', $pluginSourceAuthor['errors'], true), 'plugin sources remain restricted for non-admin users');
$check(post_cat__safe_source('plugin_feed') === 'plugin_feed', 'runtime source parsing honors registered plugin sources');

$validation = shortcode_preset_validate_config(array_merge($loaded, [
    'author' => 8,
    'date_from' => '2026-12-31',
    'date_to' => '2026-01-01',
]), false);
$check(count($validation['errors']) >= 2, 'server validation enforces non-admin author and date-range restrictions');
$check(($validation['config']['plugin_key']['kept'] ?? false) === true, 'validation preserves unknown plugin config keys');

$previewHookCalls = 0;
add_filter('shortcode_preset_preview_config', static function (array $config) use (&$previewHookCalls): array {
    $previewHookCalls++;
    return $config;
});
$invalidAuthorPreview = shortcode_preset_prepare_preview_config(['source' => 'plugin_feed', 'layout' => 'list'], false);
$check($invalidAuthorPreview['errors'] !== [] && $previewHookCalls === 0, 'invalid non-admin inline preview fails before preview source hooks');
$validAdminPreview = shortcode_preset_prepare_preview_config(['source' => 'plugin_feed', 'layout' => 'list'], true);
$check($validAdminPreview['errors'] === [] && $previewHookCalls === 1, 'admin plugin-source inline preview remains available after validation');
$check(post_cat__resolve_kicker([], '') === '', 'missing kicker with an empty category remains empty');
$check(post_cat__resolve_kicker([], 'world-news') === 'WORLD NEWS', 'missing kicker resolves from a nonempty category');
$check(post_cat__resolve_kicker(['kicker' => ''], 'world-news') === '', 'explicit empty kicker suppresses automatic category resolution');

$pdo = new PresetContractPdo();
register_widget_shortcode_handler('static_collision', static fn(): string => 'static');
$runtimeLocales = [];
add_filter('shortcode_preset_runtime_config', static function (array $config) use (&$runtimeLocales): array {
    $runtimeLocales[] = $GLOBALS['preset_contract_locale'] ?? 'unset';
    $config['runtime_locale'] = $GLOBALS['preset_contract_locale'] ?? 'unset';
    return $config;
});
load_preset_widgets($pdo);
$check(($GLOBALS['_widget_shortcode_handlers']['static_collision']['origin'] ?? '') === 'static', 'runtime presets do not replace statically registered widgets');
$check(($GLOBALS['_widget_shortcode_handlers']['runtime_preset']['origin'] ?? '') === 'preset', 'valid published presets register at runtime');
$check(!isset($GLOBALS['_widget_shortcode_handlers']['invalid.dot']), 'runtime rejects preset slugs outside parser grammar');
$check($runtimeLocales === [], 'runtime config filter is not captured during early preset registration');
$runtimeHandler = $GLOBALS['_widget_shortcode_handlers']['runtime_preset']['fn'];
$GLOBALS['preset_contract_locale'] = 'en';
$runtimeHandler($pdo, [], []);
$GLOBALS['preset_contract_locale'] = 'id';
$runtimeHandler($pdo, [], []);
$check($runtimeLocales === ['en', 'id'], 'runtime config filter evaluates at each render after locale routing');

$previewEventConfig = null;
add_filter('shortcode_preset_preview_config', static function (array $config, array $context): array {
    $config['preview_locale'] = $context['locale'] ?? '';
    return $config;
}, 10, 2);
add_action('shortcode_preset_preview_configured', static function (array $config) use (&$previewEventConfig): void {
    $previewEventConfig = $config;
});
$previewConfig = shortcode_preset_preview_config(['layout' => 'list'], ['locale' => 'de']);
$check(($previewConfig['preview_locale'] ?? '') === 'de' && $previewEventConfig === $previewConfig, 'preview config filter and event expose the final plugin-extended config');
add_filter('shortcode_preset_preview_result', static function (?array $result, array $config): ?array {
    return ($config['source'] ?? '') === 'plugin_feed'
        ? ['html' => '<p>plugin preview</p>', 'mode' => 'plugin_feed']
        : $result;
}, 10, 2);
$pluginPreview = shortcode_preset_preview_result(null, ['source' => 'plugin_feed'], ['mode' => 'inline']);
$check(($pluginPreview['html'] ?? '') === '<p>plugin preview</p>' && ($pluginPreview['mode'] ?? '') === 'plugin_feed', 'plugin-defined preset sources can return source-aware preview HTML without Core post queries');

$preDeleteSeen = false;
add_action('admin_shortcode_preset_before_delete', static function (int $presetId, PDO $transaction) use (&$preDeleteSeen): void {
    $preDeleteSeen = $presetId === 88 && $transaction->inTransaction();
    throw new RuntimeException('dependent cleanup failed');
});
$blocked = null;
try {
    shortcode_preset_before_delete(new PresetTransactionContractPdo(true), 88);
} catch (Throwable $error) {
    $blocked = $error;
}
$check($preDeleteSeen && $blocked instanceof ShortcodePresetDeletionBlockedException && $blocked->getPrevious() instanceof RuntimeException, 'pre-delete hook runs in the active transaction and propagates dependent cleanup failure');
$check($blocked instanceof ShortcodePresetDeletionBlockedException && !str_contains($blocked->getMessage(), 'dependent cleanup failed'), 'pre-delete listener internals are retained as the previous exception but redacted from the public message');
$requiresTransaction = false;
try {
    shortcode_preset_before_delete(new PresetTransactionContractPdo(false), 88);
} catch (LogicException $error) {
    $requiresTransaction = true;
}
$check($requiresTransaction, 'pre-delete contract rejects invocation outside a database transaction');

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " assertion(s) failed.\n");
    exit(1);
}
echo "RESULT: ALL PASS\n";
