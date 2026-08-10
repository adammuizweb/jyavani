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

$invalidFilters = shortcode_preset_list_filters(['p' => '2oops', 'status' => 'deleted', 'owner' => '9'], false);
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

$pdo = new PresetContractPdo();
register_widget_shortcode_handler('static_collision', static fn(): string => 'static');
$runtimeSeen = false;
add_filter('shortcode_preset_runtime_config', static function (array $config) use (&$runtimeSeen): array {
    $runtimeSeen = true;
    $config['runtime_hook'] = true;
    return $config;
});
load_preset_widgets($pdo);
$check(($GLOBALS['_widget_shortcode_handlers']['static_collision']['origin'] ?? '') === 'static', 'runtime presets do not replace statically registered widgets');
$check(($GLOBALS['_widget_shortcode_handlers']['runtime_preset']['origin'] ?? '') === 'preset', 'valid published presets register at runtime');
$check(!isset($GLOBALS['_widget_shortcode_handlers']['invalid.dot']), 'runtime rejects preset slugs outside parser grammar');
$check($runtimeSeen && (($GLOBALS['_widget_shortcode_handlers']['runtime_preset']['defaults']['runtime_hook'] ?? false) === true), 'runtime config filter runs before registration');

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " assertion(s) failed.\n");
    exit(1);
}
echo "RESULT: ALL PASS\n";
