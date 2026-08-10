<?php
declare(strict_types=1);

final class ContentRouteContractStatement extends PDOStatement
{
    private array $rows = [];

    public function __construct(private ContentRouteContractPdo $pdo, private string $sql)
    {
    }

    public function execute(?array $params = null): bool
    {
        $this->rows = $this->pdo->run($this->sql, $params ?? []);
        return true;
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        return array_shift($this->rows) ?: false;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        $rows = $this->rows;
        $this->rows = [];
        return $rows;
    }

    public function fetchColumn(int $column = 0): mixed
    {
        $row = array_shift($this->rows);
        if (!is_array($row)) return false;
        $values = array_values($row);
        return $values[$column] ?? false;
    }
}

final class ContentRouteContractPdo extends PDO
{
    public array $posts = [];
    public array $routes = [];
    public array $settings = [
        'admin_path' => 'control',
        'posts_list_path' => 'articles',
        'pages_list_path' => 'pages',
        'category_path' => 'topics',
    ];
    private int $nextRouteId = 1;
    private bool $transaction = false;
    private ?array $transactionSnapshot = null;
    private ?array $savepointSnapshot = null;

    public function __construct()
    {
    }

    public function getAttribute(int $attribute): mixed
    {
        return $attribute === PDO::ATTR_DRIVER_NAME ? 'contract' : null;
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new ContentRouteContractStatement($this, $query);
    }

    public function beginTransaction(): bool
    {
        if ($this->transaction) throw new PDOException('Transaction already active.');
        $this->transaction = true;
        $this->transactionSnapshot = $this->routes;
        return true;
    }

    public function inTransaction(): bool
    {
        return $this->transaction;
    }

    public function commit(): bool
    {
        if (!$this->transaction) return false;
        $this->transaction = false;
        $this->transactionSnapshot = null;
        $this->savepointSnapshot = null;
        return true;
    }

    public function rollBack(): bool
    {
        if (!$this->transaction) return false;
        $this->routes = $this->transactionSnapshot ?? $this->routes;
        $this->transaction = false;
        $this->transactionSnapshot = null;
        $this->savepointSnapshot = null;
        return true;
    }

    public function exec(string $statement): int|false
    {
        if (str_starts_with($statement, 'SAVEPOINT ')) {
            $this->savepointSnapshot = $this->routes;
        } elseif (str_starts_with($statement, 'ROLLBACK TO SAVEPOINT ')) {
            $this->routes = $this->savepointSnapshot ?? $this->routes;
        } elseif (str_starts_with($statement, 'RELEASE SAVEPOINT ')) {
            $this->savepointSnapshot = null;
        }
        return 0;
    }

    public function route(string $locale, string $path): ?array
    {
        foreach ($this->routes as $route) {
            if ($route['locale'] === $locale && $route['path'] === $path) return $route;
        }
        return null;
    }

    public function run(string $sql, array $params): array
    {
        $flat = preg_replace('/\s+/', ' ', trim($sql));

        if (str_contains($flat, 'SELECT `value` FROM settings')) {
            $key = (string)$params[':key'];
            return array_key_exists($key, $this->settings) ? [['value' => $this->settings[$key]]] : [];
        }

        if (str_contains($flat, 'SELECT id FROM posts WHERE id = :post_id')) {
            $post = $this->posts[(int)$params[':post_id']] ?? null;
            return $post && in_array($post['type'], ['article', 'page', 'theme'], true) ? [['id' => $post['id']]] : [];
        }

        if (str_contains($flat, 'SELECT id, slug, type FROM posts')) {
            foreach ($this->posts as $post) {
                if ($post['slug'] === $params[':slug'] && !$post['is_deleted'] && in_array($post['type'], ['article', 'page', 'theme'], true)) {
                    return [['id' => $post['id'], 'slug' => $post['slug'], 'type' => $post['type']]];
                }
            }
            return [];
        }

        if (str_contains($flat, 'FROM content_routes route JOIN posts p')) {
            $route = $this->route((string)$params[':locale'], (string)$params[':path']);
            if ($route === null) return [];
            $post = $this->posts[$route['post_id']] ?? null;
            if (!$post || $post['is_deleted'] || !in_array($post['type'], ['article', 'page', 'theme'], true)) return [];
            if (str_contains($flat, "p.status = 'published'") && $post['status'] !== 'published') return [];
            if (str_contains($flat, "p.status IN ('published','private')") && !in_array($post['status'], ['published', 'private'], true)) return [];
            $canonical = null;
            foreach ($this->routes as $candidate) {
                if ($candidate['post_id'] === $route['post_id'] && $candidate['locale'] === $route['locale'] && $candidate['canonical_slot'] === 1) {
                    $canonical = $candidate;
                    break;
                }
            }
            return [[
                ...$post,
                'content_route_id' => $route['id'],
                'route_locale' => $route['locale'],
                'route_path' => $route['path'],
                'route_is_canonical' => $route['is_canonical'],
                'canonical_path' => $canonical['path'] ?? null,
            ]];
        }

        if (str_contains($flat, 'FROM content_routes') && str_contains($flat, 'canonical_slot = 1')) {
            foreach ($this->routes as $route) {
                if ($route['post_id'] === (int)$params[':post_id'] && $route['locale'] === $params[':locale'] && $route['canonical_slot'] === 1) {
                    return [$route];
                }
            }
            return [];
        }

        if (str_contains($flat, 'FROM content_routes') && isset($params[':locale'], $params[':path'])) {
            $route = $this->route((string)$params[':locale'], (string)$params[':path']);
            return $route === null ? [] : [$route];
        }

        if (str_starts_with($flat, 'UPDATE content_routes SET is_canonical = 0')) {
            $id = (int)$params[':id'];
            $this->routes[$id]['is_canonical'] = 0;
            $this->routes[$id]['canonical_slot'] = null;
            return [];
        }

        if (str_starts_with($flat, 'UPDATE content_routes SET is_canonical = 1')) {
            $id = (int)$params[':id'];
            foreach ($this->routes as $route) {
                if ($route['id'] !== $id && $route['post_id'] === (int)$params[':post_id'] && $route['locale'] === $this->routes[$id]['locale'] && $route['canonical_slot'] === 1) {
                    throw new PDOException('Duplicate canonical route.');
                }
            }
            $this->routes[$id]['is_canonical'] = 1;
            $this->routes[$id]['canonical_slot'] = 1;
            return [];
        }

        if (str_starts_with($flat, 'INSERT INTO content_routes')) {
            $locale = (string)$params[':locale'];
            $path = (string)$params[':path'];
            if ($this->route($locale, $path) !== null) throw new PDOException('Duplicate locale/path route.');
            $canonical = str_contains($flat, 'VALUES (:post_id, :locale, :path, 1, 1)');
            if ($canonical) {
                foreach ($this->routes as $route) {
                    if ($route['post_id'] === (int)$params[':post_id'] && $route['locale'] === $locale && $route['canonical_slot'] === 1) {
                        throw new PDOException('Duplicate canonical route.');
                    }
                }
            }
            $id = $this->nextRouteId++;
            $this->routes[$id] = [
                'id' => $id,
                'post_id' => (int)$params[':post_id'],
                'locale' => $locale,
                'path' => $path,
                'is_canonical' => $canonical ? 1 : 0,
                'canonical_slot' => $canonical ? 1 : null,
                'created_at' => '2026-08-10 00:00:00',
                'updated_at' => '2026-08-10 00:00:00',
            ];
            return [];
        }

        throw new RuntimeException('Unhandled contract SQL: ' . $flat);
    }
}

$root = dirname(__DIR__);
define('PUBLIC_PATH', $root . '/public');
$GLOBALS['_content_route_contract_plugin_routes'] = ['shop' => static fn() => null];
function get_frontend_routes(): array
{
    return $GLOBALS['_content_route_contract_plugin_routes'];
}
require_once $root . '/cfg/helpers/content_route_helpers.php';
require $root . '/cfg/helpers/content_route_helpers.php';

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};
$throws = static function (callable $callback, string $class = Throwable::class): bool {
    try {
        $callback();
        return false;
    } catch (Throwable $error) {
        return $error instanceof $class;
    }
};

$pdo = new ContentRouteContractPdo();
$pdo->posts = [
    1 => ['id' => 1, 'title' => 'Published', 'slug' => 'published-direct', 'type' => 'article', 'status' => 'published', 'is_deleted' => 0],
    2 => ['id' => 2, 'title' => 'Draft', 'slug' => 'draft-direct', 'type' => 'page', 'status' => 'draft', 'is_deleted' => 0],
    3 => ['id' => 3, 'title' => 'Preset', 'slug' => 'preset', 'type' => 'sc_preset', 'status' => 'published', 'is_deleted' => 0],
];

$check(content_route_normalize_path('/guides/getting-started/') === 'guides/getting-started', 'nested paths normalize without boundary slashes');
$check(content_route_normalize_locale('EN_us') === 'en-us', 'locales normalize to lowercase BCP-style tags');
$check(!content_route_is_valid_path('../secret'), 'dot-dot traversal is rejected');
$check(!content_route_is_valid_path('guides/%2e%2e/secret'), 'encoded traversal input is rejected');
$check(!content_route_is_valid_path('guides//secret'), 'empty nested segments are rejected');
$check(!content_route_is_valid_path("guides/na\xC3\xAFve"), 'non-ASCII route text is rejected');
$check(!content_route_is_valid_path("guides/start\n"), 'ASCII control bytes are rejected before normalization');

$first = content_route_set_canonical($pdo, 1, 'guides/start');
$second = content_route_set_canonical($pdo, 1, 'guides/current');
$old = $pdo->route('', 'guides/start');
$check($first['path'] === 'guides/start' && $second['path'] === 'guides/current', 'canonical routes can be created and changed');
$check($old !== null && $old['is_canonical'] === 0 && $old['canonical_slot'] === null, 'the old canonical route is retained as a redirect alias');
$resolvedAlias = content_route_resolve($pdo, 'guides/start');
$check(($resolvedAlias['route_is_canonical'] ?? 1) === 0 && ($resolvedAlias['canonical_path'] ?? '') === 'guides/current', 'alias resolution exposes the current canonical target');

$promoted = content_route_set_canonical($pdo, 1, 'guides/start');
$check($promoted['path'] === 'guides/start' && $pdo->route('', 'guides/current')['is_canonical'] === 0, 'an existing alias can be promoted back to canonical');
$manualAlias = content_route_add_alias($pdo, 1, 'guides/manual-v1');
$check($manualAlias['is_canonical'] === 0 && content_route_resolve($pdo, 'guides/manual-v1')['canonical_path'] === 'guides/start', 'explicit aliases resolve to the canonical route');
$check($throws(fn() => content_route_set_canonical($pdo, 2, 'guides/start'), DomainException::class), 'locale/path uniqueness rejects assignment to another post');
$localized = content_route_set_canonical($pdo, 2, 'guides/start', 'en');
$check($localized['locale'] === 'en', 'the same path remains available in a different locale');
$check(content_route_find_canonical($pdo, 1)['path'] === 'guides/start', 'a rejected collision leaves the existing canonical unchanged');
$check($throws(fn() => content_route_set_canonical($pdo, 1, 'draft-direct'), DomainException::class), 'direct post slugs participate in collision checks');
$check($throws(fn() => content_route_set_canonical($pdo, 1, 'author/someone'), DomainException::class), 'Core route prefixes participate in collision checks');
$check($throws(fn() => content_route_set_canonical($pdo, 1, 'articles/archive'), DomainException::class), 'configured collection routes participate in collision checks');
$check($throws(fn() => content_route_set_canonical($pdo, 1, 'shop/item'), DomainException::class), 'registered plugin route prefixes participate in collision checks');
$check($throws(fn() => content_route_set_canonical($pdo, 1, 'views/example'), DomainException::class), 'physical public path prefixes participate in collision checks');
$check($throws(fn() => content_route_set_canonical($pdo, 3, 'preset-route'), DomainException::class), 'only article, page, and theme posts can receive routes');

content_route_set_canonical($pdo, 2, 'about/draft');
$check(content_route_resolve($pdo, 'about/draft', '', 'public') === null, 'public resolution hides unpublished content');
$check((content_route_resolve($pdo, 'about/draft', '', 'editorial')['id'] ?? 0) === 2, 'editorial visibility can resolve draft routable content');
$check((content_route_resolve($pdo, 'guides/start', '', 'public')['id'] ?? 0) === 1, 'public visibility resolves published content');
$check(content_route_public_path('Guides/A-B', 'id') === '/id/guides/a-b/', 'public URL generation normalizes and encodes every segment');
$check(content_route_canonical_url($pdo, 1) === '/guides/start/', 'canonical URL generation uses the stored canonical route');

$beforeOuterTransaction = $pdo->routes;
$pdo->beginTransaction();
content_route_set_canonical($pdo, 1, 'guides/in-transaction');
$check($pdo->inTransaction(), 'canonical changes preserve a caller-owned transaction');
$pdo->rollBack();
$check($pdo->routes === $beforeOuterTransaction, 'caller rollback still reverts a savepoint-aware canonical change');

$migration = file_get_contents($root . '/schema/migrations/012-content-routes.sql');
$check(is_string($migration) && str_contains($migration, 'UNIQUE KEY `uq_content_routes_locale_path` (`locale`,`path`)'), 'migration enforces locale/path uniqueness');
$check(is_string($migration) && str_contains($migration, 'UNIQUE KEY `uq_content_routes_post_locale_canonical` (`post_id`,`locale`,`canonical_slot`)'), 'nullable canonical slot enforces one canonical route per post and locale');
$check(is_string($migration) && str_contains($migration, 'ON DELETE CASCADE'), 'content route rows cascade when a post is deleted');

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " assertion(s) failed.\n");
    exit(1);
}
echo "RESULT: ALL PASS\n";
