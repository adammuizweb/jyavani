<?php
declare(strict_types=1);

if (!function_exists('content_route_normalize_path')) {
    /**
     * Normalize a decoded, site-relative content path for storage.
     * Stored paths are ASCII and do not include leading/trailing slashes.
     */
    function content_route_normalize_path(string $path): string
    {
        if ($path === '' || str_contains($path, "\0") || str_contains($path, '\\')) {
            throw new InvalidArgumentException('Content route path is empty or malformed.');
        }
        if (preg_match('/[^\x20-\x7E]/', $path)) {
            throw new InvalidArgumentException('Content route paths must contain ASCII characters only.');
        }
        $path = trim($path);
        if (strpbrk($path, '?#%') !== false) {
            throw new InvalidArgumentException('Content route paths must be decoded and cannot contain a query, fragment, or percent escape.');
        }

        $path = strtolower(trim($path, '/'));
        if ($path === '' || strlen($path) > 512 || str_contains($path, '//')) {
            throw new InvalidArgumentException('Content route path must be between 1 and 512 characters with no empty segments.');
        }
        if (!preg_match('/\A[a-z0-9][a-z0-9_-]*(?:\/[a-z0-9][a-z0-9_-]*)*\z/', $path)) {
            throw new InvalidArgumentException('Content route path contains an invalid segment.');
        }

        return $path;
    }

    function content_route_is_valid_path(string $path): bool
    {
        try {
            content_route_normalize_path($path);
            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    function content_route_normalize_locale(string $locale): string
    {
        $locale = strtolower(str_replace('_', '-', trim($locale)));
        if ($locale === '') return '';
        if (strlen($locale) > 16 || !preg_match('/^[a-z]{2,3}(?:-[a-z0-9]{2,8})*$/', $locale)) {
            throw new InvalidArgumentException('Content route locale is invalid.');
        }
        return $locale;
    }

    function content_route_driver(PDO $pdo): string
    {
        try {
            return strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        } catch (Throwable) {
            return '';
        }
    }

    function content_route_lock_suffix(PDO $pdo): string
    {
        return in_array(content_route_driver($pdo), ['mysql', 'pgsql'], true) ? ' FOR UPDATE' : '';
    }

    function content_route_find_canonical(PDO $pdo, int $postId, string $locale = ''): ?array
    {
        if ($postId <= 0) throw new InvalidArgumentException('Post ID must be positive.');
        $locale = content_route_normalize_locale($locale);
        $stmt = $pdo->prepare(
            'SELECT id, post_id, locale, path, is_canonical, created_at, updated_at
             FROM content_routes
             WHERE post_id = :post_id AND locale = :locale AND canonical_slot = 1
             LIMIT 1'
        );
        $stmt->execute([':post_id' => $postId, ':locale' => $locale]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    function content_route_setting(PDO $pdo, string $key, string $default): string
    {
        try {
            $stmt = $pdo->prepare('SELECT `value` FROM settings WHERE `key` = :key LIMIT 1');
            $stmt->execute([':key' => $key]);
            $value = $stmt->fetchColumn();
            return $value === false || $value === null ? $default : (string)$value;
        } catch (Throwable) {
            return $default;
        }
    }

    function content_route_path_has_base(string $path, string $base): bool
    {
        $base = trim($base, '/');
        return $base !== '' && ($path === $base || str_starts_with($path, $base . '/'));
    }

    /** @return array{type:string,value:string}|null */
    function content_route_reserved_conflict(PDO $pdo, string $path): ?array
    {
        $path = content_route_normalize_path($path);
        $prefixes = ['author', 'private', 'plugins', 'static'];
        foreach ($prefixes as $prefix) {
            if (content_route_path_has_base($path, $prefix)) {
                return ['type' => 'core_prefix', 'value' => $prefix];
            }
        }
        if ($path === 'sw.js' || preg_match('/^sitemap(?:_[a-z0-9-]+)?(?:_(?:posts|pages)_\d+)?\.xml$/', $path)) {
            return ['type' => 'core_route', 'value' => $path];
        }
        if (preg_match('/^\d{4}(?:\/|$)/', $path)) {
            return ['type' => 'core_prefix', 'value' => substr($path, 0, 4)];
        }

        $settingRoutes = [
            ['key' => 'admin_path', 'default' => 'dashboard', 'prefix' => true],
            ['key' => 'login_path', 'default' => 'login', 'prefix' => false],
            ['key' => 'register_path', 'default' => 'register', 'prefix' => false],
            ['key' => 'posts_list_path', 'default' => 'artikel', 'prefix' => true],
            ['key' => 'pages_list_path', 'default' => 'halaman', 'prefix' => true],
            ['key' => 'category_path', 'default' => 'category', 'prefix' => true],
        ];
        foreach ($settingRoutes as $route) {
            $value = trim(content_route_setting($pdo, $route['key'], $route['default']), '/');
            if ($value === '') continue;
            $matches = $route['prefix'] ? content_route_path_has_base($path, $value) : $path === $value;
            if ($matches) return ['type' => 'setting_route', 'value' => $route['key'] . ':' . $value];
        }
        if (content_route_path_has_base($path, 'posts')) {
            return ['type' => 'core_prefix', 'value' => 'posts'];
        }

        if (function_exists('get_frontend_route_definitions')) {
            $pluginRoutes = get_frontend_route_definitions();
            if (is_array($pluginRoutes)) {
                foreach ($pluginRoutes as $route) {
                    if (!is_array($route)) continue;
                    $routePath = trim((string)($route['path'] ?? ''), '/');
                    $match = (string)($route['match'] ?? 'prefix');
                    $matches = $match === 'exact'
                        ? $path === $routePath
                        : ($routePath !== '' && content_route_path_has_base($path, $routePath));
                    if ($matches) return ['type' => 'plugin_route', 'value' => $routePath];
                }
            }
        } elseif (function_exists('get_frontend_routes')) {
            $pluginRoutes = get_frontend_routes();
            if (is_array($pluginRoutes)) {
                foreach (array_keys($pluginRoutes) as $prefix) {
                    $prefix = trim((string)$prefix, '/');
                    if ($prefix !== '' && content_route_path_has_base($path, $prefix)) {
                        return ['type' => 'plugin_route', 'value' => $prefix];
                    }
                }
            }
        }

        $publicRoot = defined('PUBLIC_PATH') ? (string)PUBLIC_PATH : dirname(__DIR__, 2) . '/public';
        $firstSegment = explode('/', $path, 2)[0];
        $candidate = rtrim($publicRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
        $firstCandidate = rtrim($publicRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $firstSegment;
        if (file_exists($candidate) || is_link($candidate) || is_dir($firstCandidate) || is_link($firstCandidate)) {
            return ['type' => 'physical_path', 'value' => $firstSegment];
        }

        return null;
    }

    /** @return array<string,mixed>|null */
    function content_route_path_conflict(PDO $pdo, string $path, string $locale = '', ?int $exceptPostId = null, bool $allowPhysicalShadow = false): ?array
    {
        $path = content_route_normalize_path($path);
        $locale = content_route_normalize_locale($locale);

        $stmt = $pdo->prepare(
            'SELECT id, post_id, locale, path, is_canonical
             FROM content_routes
             WHERE locale = :locale AND path = :path
             LIMIT 1'
        );
        $stmt->execute([':locale' => $locale, ':path' => $path]);
        $route = $stmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($route) && ($exceptPostId === null || (int)$route['post_id'] !== $exceptPostId)) {
            return ['type' => 'content_route'] + $route;
        }

        $stmt = $pdo->prepare(
            "SELECT id, slug, type FROM posts
             WHERE slug = :slug AND is_deleted = 0 AND type IN ('article','page','theme')
             LIMIT 1"
        );
        $stmt->execute([':slug' => $path]);
        $post = $stmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($post) && ($exceptPostId === null || (int)$post['id'] !== $exceptPostId)) {
            return ['type' => 'post_slug', 'post_id' => (int)$post['id'], 'value' => (string)$post['slug']];
        }

        if (function_exists('permalink_resolve')) {
            $resolved = permalink_resolve($pdo, $path);
            if (is_array($resolved) && ($exceptPostId === null || (int)($resolved['id'] ?? 0) !== $exceptPostId)) {
                return ['type' => 'post_permalink', 'post_id' => (int)($resolved['id'] ?? 0), 'value' => $path];
            }
        }
        if (function_exists('is_category_enabled') && !is_category_enabled($pdo) && function_exists('resolve_category_from_path')) {
            $category = resolve_category_from_path($pdo, $path);
            if ($category !== null) return ['type' => 'root_category', 'value' => (string)$category];
        }

        $reserved = content_route_reserved_conflict($pdo, $path);
        return $allowPhysicalShadow && ($reserved['type'] ?? '') === 'physical_path' ? null : $reserved;
    }

    function content_route_path_is_available(PDO $pdo, string $path, string $locale = '', ?int $exceptPostId = null, bool $allowPhysicalShadow = false): bool
    {
        return content_route_path_conflict($pdo, $path, $locale, $exceptPostId, $allowPhysicalShadow) === null;
    }

    function content_route_assert_post(PDO $pdo, int $postId, bool $lock = false): void
    {
        if ($postId <= 0) throw new InvalidArgumentException('Post ID must be positive.');
        $sql = "SELECT id FROM posts WHERE id = :post_id AND type IN ('article','page','theme') LIMIT 1";
        if ($lock) $sql .= content_route_lock_suffix($pdo);
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':post_id' => $postId]);
        if (!$stmt->fetchColumn()) throw new DomainException('Routable post not found.');
    }

    /** @return array{owned:bool,savepoint:?string} */
    function content_route_transaction_begin(PDO $pdo): array
    {
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            return ['owned' => true, 'savepoint' => null];
        }
        static $savepointSequence = 0;
        $savepoint = 'content_route_change_' . ++$savepointSequence;
        $pdo->exec('SAVEPOINT ' . $savepoint);
        return ['owned' => false, 'savepoint' => $savepoint];
    }

    function content_route_transaction_finish(PDO $pdo, array $transaction): void
    {
        if ($transaction['owned']) {
            $pdo->commit();
        } elseif ($transaction['savepoint'] !== null) {
            $pdo->exec('RELEASE SAVEPOINT ' . $transaction['savepoint']);
        }
    }

    function content_route_transaction_cancel(PDO $pdo, array $transaction): void
    {
        if ($transaction['owned']) {
            if ($pdo->inTransaction()) $pdo->rollBack();
        } elseif ($transaction['savepoint'] !== null) {
            $pdo->exec('ROLLBACK TO SAVEPOINT ' . $transaction['savepoint']);
            $pdo->exec('RELEASE SAVEPOINT ' . $transaction['savepoint']);
        }
    }

    /**
     * Set a canonical route atomically. A replaced canonical remains as a
     * noncanonical route so callers can issue a permanent redirect.
     */
    function content_route_set_canonical(PDO $pdo, int $postId, string $path, string $locale = '', bool $allowPhysicalShadow = false): array
    {
        $path = content_route_normalize_path($path);
        $locale = content_route_normalize_locale($locale);
        $transaction = content_route_transaction_begin($pdo);

        try {
            content_route_assert_post($pdo, $postId, true);
            $conflict = content_route_path_conflict($pdo, $path, $locale, $postId, $allowPhysicalShadow);
            if ($conflict !== null) {
                throw new DomainException('Content route path conflicts with ' . $conflict['type'] . '.');
            }

            $suffix = content_route_lock_suffix($pdo);
            $stmt = $pdo->prepare(
                'SELECT id, path FROM content_routes
                 WHERE post_id = :post_id AND locale = :locale AND canonical_slot = 1
                 LIMIT 1' . $suffix
            );
            $stmt->execute([':post_id' => $postId, ':locale' => $locale]);
            $current = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($current) && $current['path'] === $path) {
                $route = content_route_find_canonical($pdo, $postId, $locale);
                if ($route === null) throw new RuntimeException('Canonical route disappeared after update.');
                content_route_transaction_finish($pdo, $transaction);
                return $route;
            }

            $stmt = $pdo->prepare(
                'SELECT id, post_id FROM content_routes
                 WHERE locale = :locale AND path = :path
                 LIMIT 1' . $suffix
            );
            $stmt->execute([':locale' => $locale, ':path' => $path]);
            $target = $stmt->fetch(PDO::FETCH_ASSOC);

            if (is_array($current)) {
                $stmt = $pdo->prepare(
                    'UPDATE content_routes
                     SET is_canonical = 0, canonical_slot = NULL, updated_at = CURRENT_TIMESTAMP
                     WHERE id = :id'
                );
                $stmt->execute([':id' => (int)$current['id']]);
            }

            if (is_array($target)) {
                if ((int)$target['post_id'] !== $postId) {
                    throw new DomainException('Content route path is already assigned to another post.');
                }
                $stmt = $pdo->prepare(
                    'UPDATE content_routes
                     SET is_canonical = 1, canonical_slot = 1, updated_at = CURRENT_TIMESTAMP
                     WHERE id = :id AND post_id = :post_id'
                );
                $stmt->execute([':id' => (int)$target['id'], ':post_id' => $postId]);
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO content_routes (post_id, locale, path, is_canonical, canonical_slot)
                     VALUES (:post_id, :locale, :path, 1, 1)'
                );
                $stmt->execute([':post_id' => $postId, ':locale' => $locale, ':path' => $path]);
            }

            $route = content_route_find_canonical($pdo, $postId, $locale);
            if ($route === null) throw new RuntimeException('Canonical route was not persisted.');
            content_route_transaction_finish($pdo, $transaction);
            return $route;
        } catch (Throwable $error) {
            content_route_transaction_cancel($pdo, $transaction);
            throw $error;
        }
    }

    function content_route_add_alias(PDO $pdo, int $postId, string $path, string $locale = '', bool $allowPhysicalShadow = false): array
    {
        $path = content_route_normalize_path($path);
        $locale = content_route_normalize_locale($locale);
        $transaction = content_route_transaction_begin($pdo);
        try {
            content_route_assert_post($pdo, $postId, true);
            $conflict = content_route_path_conflict($pdo, $path, $locale, $postId, $allowPhysicalShadow);
            if ($conflict !== null) throw new DomainException('Content route path conflicts with ' . $conflict['type'] . '.');

            $stmt = $pdo->prepare('SELECT * FROM content_routes WHERE locale = :locale AND path = :path LIMIT 1');
            $stmt->execute([':locale' => $locale, ':path' => $path]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($existing)) {
                content_route_transaction_finish($pdo, $transaction);
                return $existing;
            }

            $stmt = $pdo->prepare(
                'INSERT INTO content_routes (post_id, locale, path, is_canonical, canonical_slot)
                 VALUES (:post_id, :locale, :path, 0, NULL)'
            );
            $stmt->execute([':post_id' => $postId, ':locale' => $locale, ':path' => $path]);
            $stmt = $pdo->prepare('SELECT * FROM content_routes WHERE locale = :locale AND path = :path LIMIT 1');
            $stmt->execute([':locale' => $locale, ':path' => $path]);
            $route = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($route)) throw new RuntimeException('Content route alias was not persisted.');
            content_route_transaction_finish($pdo, $transaction);
            return $route;
        } catch (Throwable $error) {
            content_route_transaction_cancel($pdo, $transaction);
            throw $error;
        }
    }

    /**
     * Resolve a route and its canonical target. Visibility may be public
     * (published), authenticated (published/private), or editorial (all).
     */
    function content_route_resolve(PDO $pdo, string $path, string $locale = '', string $visibility = 'public'): ?array
    {
        $path = content_route_normalize_path($path);
        $locale = content_route_normalize_locale($locale);
        $visibility = strtolower(trim($visibility));
        $statusSql = match ($visibility) {
            'public', 'published' => "p.status = 'published'",
            'authenticated' => "p.status IN ('published','private')",
            'editorial' => '1 = 1',
            default => throw new InvalidArgumentException('Unknown content route visibility.'),
        };

        $stmt = $pdo->prepare(
            "SELECT p.*,
                    route.id AS content_route_id,
                    route.locale AS route_locale,
                    route.path AS route_path,
                    route.is_canonical AS route_is_canonical,
                    canonical.path AS canonical_path
             FROM content_routes route
             JOIN posts p ON p.id = route.post_id
             LEFT JOIN content_routes canonical
               ON canonical.post_id = route.post_id
              AND canonical.locale = route.locale
              AND canonical.canonical_slot = 1
             WHERE route.locale = :locale
               AND route.path = :path
               AND p.type IN ('article','page','theme')
               AND p.is_deleted = 0
               AND {$statusSql}
             LIMIT 1"
        );
        $stmt->execute([':locale' => $locale, ':path' => $path]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    function content_route_public_path(string $path, string $locale = ''): string
    {
        $path = content_route_normalize_path($path);
        $locale = content_route_normalize_locale($locale);
        $segments = array_map('rawurlencode', explode('/', $path));
        if ($locale !== '') array_unshift($segments, rawurlencode($locale));
        return '/' . implode('/', $segments) . '/';
    }

    function content_route_canonical_url(PDO $pdo, int $postId, string $locale = ''): ?string
    {
        $route = content_route_find_canonical($pdo, $postId, $locale);
        return $route === null ? null : content_route_public_path((string)$route['path'], (string)$route['locale']);
    }

    function content_route_delete_for_post(PDO $pdo, int $postId, string $locale = ''): int
    {
        if ($postId <= 0) throw new InvalidArgumentException('Post ID must be positive.');
        $locale = content_route_normalize_locale($locale);
        $stmt = $pdo->prepare('DELETE FROM content_routes WHERE post_id = :post_id AND locale = :locale');
        $stmt->execute([':post_id' => $postId, ':locale' => $locale]);
        return $stmt->rowCount();
    }

    function content_route_conflicts_with_setting_path(PDO $pdo, string $path, bool $prefix = true): bool
    {
        try {
            $path = content_route_normalize_path($path);
        } catch (InvalidArgumentException) {
            return false;
        }
        $sql = "SELECT 1 FROM content_routes WHERE locale = '' AND (path = :path";
        if ($prefix) $sql .= " OR path LIKE :descendant";
        $sql .= ') LIMIT 1';
        $stmt = $pdo->prepare($sql);
        $params = [':path' => $path];
        if ($prefix) $params[':descendant'] = $path . '/%';
        $stmt->execute($params);
        return (bool)$stmt->fetchColumn();
    }
}
