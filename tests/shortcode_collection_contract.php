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
}

final class ContractPdo extends PDO
{
    public array $preparedSql = [];
    public array $statements = [];

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
        $rows = str_contains($query, "type = 'sc_preset'")
            ? [['slug' => 'contract_preset', 'meta' => '{"category":"guides"}']]
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
            ]];
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

$expanded = widget_expand_shortcodes('[[widget:contract_preset]]', $pdo);
$check(isset($GLOBALS['_widget_shortcode_handlers']['contract_preset']), 'widget expansion lazily registers published presets');
$check(str_contains($expanded, 'Localized title'), 'a lazily registered preset renders its filtered collection');
$check(str_contains($expanded, '/localized/source-title/'), 'a rendered preset filters collection URLs');

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " assertion(s) failed.\n");
    exit(1);
}
echo "RESULT: ALL PASS\n";
