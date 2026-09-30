<?php
declare(strict_types=1);

final class ContractStatement extends PDOStatement
{
    public array $params = [];

    public function __construct(private array $rows = [])
    {
    }

    public function execute(?array $params = null): bool
    {
        $this->params = $params ?? [];
        return true;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
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

final class ContractPdo extends PDO
{
    public array $preparedSql = [];
    public array $statements = [];
    public int $matchingCount = 12;

    public function __construct()
    {
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        if (str_contains($query, 'FROM categories')) {
            return new ContractStatement([[
                'id' => 4,
                'name' => 'Guides',
                'slug' => 'guides',
                'parent_id' => null,
            ]]);
        }
        return new ContractStatement([]);
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->preparedSql[] = $query;
        $rows = str_contains($query, 'COUNT(DISTINCT p.id)')
            ? [['total' => $this->matchingCount]]
            : (str_contains($query, "type = 'sc_preset'")
            ? [[
                'id' => 7,
                'title' => 'Contract preset',
                'slug' => 'contract_preset',
                'meta' => '{"category":"guides","limit":4,"max_items":10,"pagination":"1"}',
            ]]
            : [[
                'id' => 11,
                'title' => 'Source title',
                'slug' => 'source-title',
                'content' => 'Source content',
                'type' => 'article',
                'meta' => '{}',
                'youtube' => '',
                'thumbnail' => '',
                'status' => 'published',
                'created_by' => 1,
                'created_at' => '2026-08-09 12:00:00',
                'updated_at' => '2026-08-09 12:00:00',
            ]]);
        $statement = new ContractStatement($rows);
        $this->statements[] = $statement;
        return $statement;
    }
}

$root = dirname(__DIR__);
define('PUBLIC_PATH', $root . '/public');
define('VIEWS_BASE', PUBLIC_PATH . '/views/themes');
require_once $root . '/cfg/helpers/hooks.php';
require_once $root . '/cfg/helpers/collection_helpers.php';
require_once $root . '/cfg/helpers/cms_content.php';
require_once $root . '/cfg/helpers/widget_helper.php';
require_once $root . '/cfg/helpers/shortcode_builder.php';

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};

add_filter('collection_query_clauses', static function (array $clauses, array $context): array {
    if (($context['scope'] ?? '') !== 'post_category_shortcode') return $clauses;
    $clauses['where'][] = 'p.id <> :contract_hidden';
    $clauses['params'][':contract_hidden'] = 99;
    return $clauses;
}, 10, 2);
add_filter('collection_rows', static function (array $rows, array $context): array {
    if (($context['scope'] ?? '') === 'post_category_shortcode') {
        $rows[0]['title'] = 'Localized title';
    }
    return $rows;
}, 10, 2);
add_filter('collection_url', static function (string $url, string $type, array $context): string {
    return ($context['scope'] ?? '') === 'post_category_shortcode'
        ? '/localized/' . (string)($context['item']['slug'] ?? '') . '/'
        : $url;
}, 10, 3);

$pdo = new ContractPdo();
$rows = cms_posts_by_category($pdo, 'guides', [
    'status' => 'published',
    'limit' => 999,
    'collection_context' => ['scope' => 'post_category_shortcode'],
]);
$sql = $pdo->preparedSql[0] ?? '';
$params = $pdo->statements[0]->params ?? [];
$check(str_contains($sql, 'p.id <> :contract_hidden'), 'collection SQL filter runs before the query');
$check(str_contains($sql, 'LIMIT 200 OFFSET 0'), 'collection limit is capped at 200');
$check(isset($params[':cms_category_0']) && $params[':cms_category_0'] === 4, 'category IDs use named parameters');
$check(($params[':cms_status'] ?? null) === 'published', 'published status is bound explicitly');
$check(($rows[0]['title'] ?? '') === 'Localized title', 'collection row filter runs after the query');

$preparedCount = count($pdo->preparedSql);
$check(cms_posts_by_category($pdo, 'missing-category') === [], 'invalid categories return no rows');
$check(count($pdo->preparedSql) === $preparedCount, 'invalid categories do not execute a post query');

$_SERVER['REQUEST_URI'] = '/sample/?view=compact';
$_GET = ['view' => 'compact'];
$directPreset = render_widget('contract_preset', [], $pdo, ['surface' => 'contract.direct_widget']);
$presetKeyMatch = [];
$hasPresetKey = preg_match('/data-pcat-pagination-root="(pcat_page_contract_preset-[a-f0-9]{16})"/', $directPreset, $presetKeyMatch) === 1;
$presetKey = $hasPresetKey ? (string)$presetKeyMatch[1] : '';
$check(isset($GLOBALS['_widget_shortcode_handlers']['contract_preset']), 'direct widget rendering lazily registers published presets');
$check(str_contains($directPreset, 'Localized title'), 'a directly rendered preset uses its filtered collection');
$check($hasPresetKey && str_contains($directPreset, 'class="pcat-pagination"')
    && str_contains($directPreset, $presetKey . '=2')
    && str_contains($directPreset, 'view=compact'), 'preset pagination uses an isolated query key while preserving existing query parameters');
$check(str_contains($directPreset, '.pcat-pagination .is-current{')
    && str_contains($directPreset, 'background:var(--accent,#00a89e)')
    && str_contains($directPreset, 'box-shadow:0 2px 6px rgba(15,23,42,.16)')
    && !str_contains($directPreset, 'scale(1.06)'), 'current page uses a simple solid accent with a restrained shadow');
$check(str_contains(implode("\n", $pdo->preparedSql), 'COUNT(DISTINCT p.id)'), 'preset pagination counts the filtered Core collection');
$_SERVER['REQUEST_URI'] = '/sample/?view=compact&' . $presetKey . '=2';
$_GET[$presetKey] = '2';
$secondPagePreset = render_widget('contract_preset', [], $pdo, ['surface' => 'contract.direct_widget']);
$check(str_contains($secondPagePreset, 'aria-current="page">2</span>')
    && str_contains(implode("\n", $pdo->preparedSql), 'LIMIT 4 OFFSET 4'), 'page two advances by one Limit while retaining the configured base Offset');
$_SERVER['REQUEST_URI'] = '/sample/?view=compact&' . $presetKey . '=3';
$_GET[$presetKey] = '3';
$thirdPagePreset = render_widget('contract_preset', [], $pdo, ['surface' => 'contract.direct_widget']);
$check(str_contains($thirdPagePreset, 'aria-current="page">3</span>')
    && str_contains(implode("\n", $pdo->preparedSql), 'LIMIT 2 OFFSET 8'), 'Max Items caps the last page so Limit 4 and Max Items 10 render 4, 4, then 2 items');
$nonPaginatedSqlOffset = count($pdo->preparedSql);
post_cat_shortcode_render($pdo, [
    'category' => 'guides',
    'layout' => 'list',
    'limit' => 8,
    'max_items' => 2,
    'pagination' => '0',
]);
$nonPaginatedSql = implode("\n", array_slice($pdo->preparedSql, $nonPaginatedSqlOffset));
$check(str_contains($nonPaginatedSql, 'LIMIT 8 OFFSET 0'), 'Max Items does not truncate Limit when pagination is disabled');
$directQueryHtml = ShortcodeQuery::posts()
    ->category('guides')
    ->limit(4)
    ->maxItems(10)
    ->paginate()
    ->render($pdo);
$directQueryKey = [];
$hasDirectQueryKey = preg_match('/data-pcat-pagination-root="(pcat_page_query-[a-f0-9]{16})"/', $directQueryHtml, $directQueryKey) === 1;
$check($hasDirectQueryKey
    && str_contains($directQueryHtml, ($directQueryKey[1] ?? '') . '=2'), 'direct ShortcodeQuery pagination receives a deterministic isolated query key');
$aliasFirst = post_cat_shortcode_render($pdo, [
    'category' => 'guides',
    'layout' => 'list',
    'visible' => 2,
    'max_items' => 10,
    'pagination' => '1',
], ['preset_slug' => 'alias_contract']);
$aliasSecond = post_cat_shortcode_render($pdo, [
    'category' => 'guides',
    'layout' => 'list',
    'visible' => 4,
    'max_items' => 10,
    'pagination' => '1',
], ['preset_slug' => 'alias_contract']);
$aliasFirstKey = [];
$aliasSecondKey = [];
$check(preg_match('/data-pcat-pagination-root="([^"]+)"/', $aliasFirst, $aliasFirstKey) === 1
    && preg_match('/data-pcat-pagination-root="([^"]+)"/', $aliasSecond, $aliasSecondKey) === 1
    && ($aliasFirstKey[1] ?? '') !== ($aliasSecondKey[1] ?? ''), 'legacy limit aliases contribute their normalized value to pagination identity');
$previewPagination = post_cat__pagination_html(
    'pcat_page_preview',
    1,
    3,
    static fn(int $page): string => '#preview-page-' . $page
);
$check(str_contains($previewPagination, 'href="#preview-page-2"')
    && str_contains($previewPagination, 'Page 1 of 3'), 'preview pagination reuses runtime markup with inert document-local links');
$_SERVER['REQUEST_URI'] = '/sample/?view=compact';
$_GET = ['view' => 'compact'];
$sliderPreset = post_cat_shortcode_render($pdo, [
    'category' => 'guides',
    'layout' => 'sliderpage',
    'limit' => 4,
    'max_items' => 10,
    'pagination' => '1',
], ['preset_slug' => 'slider_contract']);
$check(str_contains($sliderPreset, 'data-pcat-pagination-mode="slider"')
    && str_contains($sliderPreset, 'data-pcat-pagination-slider="self"')
    && str_contains($sliderPreset, 'rel="next"'), 'slider layouts expose next-page fallback links through their arrow-navigation contract');
$firstOverridePreset = render_shortcode_preset($pdo, 'contract_preset', [
    'limit' => 2,
    'max_items' => 6,
    'pagination' => '1',
]);
$secondOverridePreset = render_shortcode_preset($pdo, 'contract_preset', [
    'limit' => 3,
    'max_items' => 9,
    'pagination' => '1',
]);
$firstOverrideKey = [];
$secondOverrideKey = [];
$check(preg_match('/data-pcat-pagination-root="([^"]+)"/', $firstOverridePreset, $firstOverrideKey) === 1
    && preg_match('/data-pcat-pagination-root="([^"]+)"/', $secondOverridePreset, $secondOverrideKey) === 1
    && ($firstOverrideKey[1] ?? '') !== ($secondOverrideKey[1] ?? ''), 'the same preset with different effective overrides receives distinct pagination state');
$pdo->matchingCount = 22;
$_SERVER['REQUEST_URI'] = '/sample/?view=compact';
$_GET = ['view' => 'compact'];
$randomFirstPage = post_cat_shortcode_render($pdo, [
    'category' => 'guides',
    'layout' => 'grid',
    'limit' => 4,
    'max_items' => 20,
    'pagination' => '1',
    'order_by' => 'RAND()',
], ['preset_slug' => 'random_contract']);
$randomKeyMatch = [];
$hasRandomKey = preg_match('/data-pcat-pagination-root="(pcat_page_random_contract-[a-f0-9]{16})"/', $randomFirstPage, $randomKeyMatch) === 1;
$randomKey = $hasRandomKey ? (string)$randomKeyMatch[1] : '';
$randomSeedKey = post_cat__pagination_seed_key($randomKey);
$seedMatch = [];
$hasSeed = $randomSeedKey !== '' && preg_match('/' . preg_quote($randomSeedKey, '/') . '=([1-9][0-9]*)/', $randomFirstPage, $seedMatch) === 1;
$randomSeed = $hasSeed ? (int)$seedMatch[1] : 0;
$check($hasRandomKey && $hasSeed
    && str_contains($randomFirstPage, 'Page 1 of 5')
    && str_contains($randomFirstPage, $randomKey . '=2')
    && str_contains(implode("\n", $pdo->preparedSql), 'RAND(' . $randomSeed . ')'), 'random pagination creates one seed and exposes five stable pages for Limit 4 and Max Items 20');
$_SERVER['REQUEST_URI'] = '/sample/?view=compact&' . $randomKey . '=2&' . $randomSeedKey . '=' . $randomSeed;
$_GET = [
    'view' => 'compact',
    $randomKey => '2',
    $randomSeedKey => (string)$randomSeed,
];
$randomSecondPage = post_cat_shortcode_render($pdo, [
    'category' => 'guides',
    'layout' => 'grid',
    'limit' => 4,
    'max_items' => 20,
    'pagination' => '1',
    'order_by' => 'RAND()',
], ['preset_slug' => 'random_contract']);
$lastRandomSql = (string)($pdo->preparedSql[array_key_last($pdo->preparedSql)] ?? '');
$check(str_contains($randomSecondPage, 'aria-current="page">2</span>')
    && str_contains($randomSecondPage, $randomSeedKey . '=' . $randomSeed)
    && str_contains($lastRandomSql, 'RAND(' . $randomSeed . ')')
    && str_contains($lastRandomSql, 'LIMIT 4 OFFSET 4'), 'random page two reuses the exact seed and advances without reshuffling the collection');
$pdo->matchingCount = 12;
$expanded = widget_expand_shortcodes('[[widget:contract_preset]]', $pdo);
$check(str_contains($expanded, 'Localized title'), 'a lazily registered preset renders its filtered collection');
$check(str_contains($expanded, '/localized/source-title/'), 'a rendered preset filters collection URLs');
$composed = render_shortcode_preset($pdo, 'contract_preset', ['limit' => 2], ['surface' => 'contract.theme_section']);
$check(str_contains($composed, 'Localized title'), 'the public preset composition API renders through the collection pipeline');
$resolverSql = implode("\n", $pdo->preparedSql);
$check(str_contains($resolverSql, "status = 'published'") && str_contains($resolverSql, 'is_deleted = 0'), 'preset composition resolves only published non-deleted presets');
$check(render_shortcode_preset($pdo, '../invalid') === '', 'preset composition rejects invalid public identifiers');
$check(
    post_cat__excerpt('<p>Roles &amp; Permissions</p>', 80) === 'Roles & Permissions',
    'post category excerpts decode HTML entities before template escaping'
);

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " assertion(s) failed.\n");
    exit(1);
}
echo "RESULT: ALL PASS\n";
