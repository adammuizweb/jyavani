<?php
// controllers/SitemapController.php
class SitemapController
{
    // how many urls per sitemap file
    private const LIMIT = 30;

    // sitemap index: lists all sitemap_posts_X and sitemap_pages_X
    public static function index(PDO $pdo)
    {
        header('Content-Type: application/xml; charset=utf-8');

        $domain = self::domain();
        $limit = self::LIMIT;

        // counts
        $postCount = (int) self::countByType($pdo, 'article');
        $pageCount = (int) self::countByType($pdo, 'page');
        $themeCount = (int)self::countRoutedThemes($pdo);

        $postMaps = max(1, (int)ceil($postCount / $limit));
        $pageMaps = max(1, (int)ceil($pageCount / $limit));

        // lightweight cache header
        header('Cache-Control: public, max-age=3600');

        echo '<?xml version="1.0" encoding="UTF-8"?>';
        echo '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . PHP_EOL;

        for ($i = 1; $i <= $postMaps; $i++) {
            $loc = $domain . '/sitemap_posts_' . $i . '.xml';
            echo "  <sitemap>\n    <loc>" . htmlspecialchars($loc, ENT_XML1) . "</loc>\n  </sitemap>\n";
        }
        for ($i = 1; $i <= $pageMaps; $i++) {
            $loc = $domain . '/sitemap_pages_' . $i . '.xml';
            echo "  <sitemap>\n    <loc>" . htmlspecialchars($loc, ENT_XML1) . "</loc>\n  </sitemap>\n";
        }
        for ($i = 1; $i <= (int)ceil($themeCount / $limit); $i++) {
            $loc = $domain . '/sitemap_themes_' . $i . '.xml';
            echo "  <sitemap>\n    <loc>" . htmlspecialchars($loc, ENT_XML1) . "</loc>\n  </sitemap>\n";
        }
        foreach (apply_filters('sitemap_index_entries', [], $pdo, $domain, $limit) as $entry) {
            if (!is_array($entry) || empty($entry['loc'])) continue;
            echo "  <sitemap>\n    <loc>" . htmlspecialchars((string)$entry['loc'], ENT_XML1) . "</loc>\n  </sitemap>\n";
        }

        echo '</sitemapindex>';
        exit;
    }

    public static function renderLocale(PDO $pdo, string $locale, string $type, int $pageNum): bool
    {
        return apply_filters('sitemap_locale_rendered', false, $locale, $type, $pageNum, $pdo) === true;
    }

    // list type = 'posts' or 'pages', pageNum starting from 1
    public static function list(PDO $pdo, string $type, int $pageNum = 1)
    {
        header('Content-Type: application/xml; charset=utf-8');
        header('Cache-Control: public, max-age=3600');

        $limit = self::LIMIT;
        $offset = ($pageNum - 1) * $limit;
        $domain = self::domain();

        // map type -> DB type
        $dbType = match ($type) {
            'posts' => 'article',
            'themes' => 'theme',
            default => 'page',
        };
        $queryClauses = self::queryClauses($pdo, $dbType);
        $extensionWhere = $queryClauses['where'] === []
            ? ''
            : "\n              AND (" . implode(")\n              AND (", $queryClauses['where']) . ')';

        $stmt = $pdo->prepare("
            SELECT p.id, p.slug, p.created_at, COALESCE(p.updated_at, p.created_at) AS changed_at
            FROM posts p
            WHERE p.type = :type
              AND p.is_deleted = 0
              AND p.status = 'published'
               AND (:type_filter <> 'theme' OR EXISTS (
                  SELECT 1 FROM content_routes cr
                  WHERE cr.post_id = p.id AND cr.locale = '' AND cr.canonical_slot = 1
              )){$extensionWhere}
            ORDER BY p.created_at DESC
            LIMIT :limit OFFSET :offset
        ");
        $stmt->bindValue(':type', $dbType);
        $stmt->bindValue(':type_filter', $dbType);
        $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
        self::bindQueryParams($stmt, $queryClauses['params']);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL;
        echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . PHP_EOL;

        foreach ($rows as $r) {
            $slug = trim($r['slug'], '/');
            if ($slug === '') continue;
            if (function_exists('get_post_permalink') && in_array($dbType, ['article', 'theme'], true)) {
                $loc = $domain . get_post_permalink($r);
            } elseif (function_exists('get_page_permalink') && $dbType === 'page') {
                $loc = $domain . get_page_permalink($r);
            } else {
                $loc = $domain . '/' . rawurlencode($slug) . '/';
            }
            // lastmod: use ISO 8601 (W3C Datetime)
            $lastmod = !empty($r['changed_at']) ? date('c', strtotime($r['changed_at'])) : date('c');
            echo "  <url>\n";
            echo "    <loc>" . htmlspecialchars($loc, ENT_XML1) . "</loc>\n";
            echo "    <lastmod>" . htmlspecialchars($lastmod, ENT_XML1) . "</lastmod>\n";
            echo "    <changefreq>weekly</changefreq>\n";
            echo "    <priority>0.8</priority>\n";
            echo "  </url>\n";
        }

        echo '</urlset>';
        exit;
    }

    private static function domain(): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
        return rtrim($scheme . '://' . $host, '/');
    }

    private static function countByType(PDO $pdo, string $type): int
    {
        $queryClauses = self::queryClauses($pdo, $type);
        $extensionWhere = $queryClauses['where'] === [] ? '' : ' AND (' . implode(') AND (', $queryClauses['where']) . ')';
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM posts p WHERE p.type = :type AND p.is_deleted = 0 AND p.status = 'published'{$extensionWhere}");
        $stmt->bindValue(':type', $type);
        self::bindQueryParams($stmt, $queryClauses['params']);
        $stmt->execute();
        return (int)$stmt->fetchColumn();
    }

    private static function countRoutedThemes(PDO $pdo): int
    {
        $stmt = $pdo->query("SELECT COUNT(*) FROM posts p WHERE p.type = 'theme' AND p.is_deleted = 0 AND p.status = 'published' AND EXISTS (SELECT 1 FROM content_routes cr WHERE cr.post_id = p.id AND cr.locale = '' AND cr.canonical_slot = 1)");
        return (int)$stmt->fetchColumn();
    }

    /** @return array{where:list<string>,params:array<string,int|string>} */
    private static function queryClauses(PDO $pdo, string $type): array
    {
        $default = ['where' => [], 'params' => []];
        $filtered = apply_filters('sitemap_query_clauses', $default, $pdo, [
            'type' => $type,
            'table_alias' => 'p',
        ]);
        if (!is_array($filtered) || !is_array($filtered['where'] ?? null) || !is_array($filtered['params'] ?? null)) {
            error_log('[sitemap] Ignored malformed query clauses.');
            return $default;
        }

        $where = [];
        foreach ($filtered['where'] as $clause) {
            if (!is_string($clause) || trim($clause) === '' || str_contains($clause, ';')) {
                error_log('[sitemap] Ignored malformed query clauses.');
                return $default;
            }
            $where[] = $clause;
        }

        $params = [];
        foreach ($filtered['params'] as $key => $value) {
            if (!is_string($key) || preg_match('/\A:[A-Za-z][A-Za-z0-9_]*\z/D', $key) !== 1
                || in_array($key, [':type', ':type_filter', ':limit', ':offset'], true)
                || (!is_int($value) && !is_string($value))) {
                error_log('[sitemap] Ignored malformed query clauses.');
                return $default;
            }
            $params[$key] = $value;
        }
        return ['where' => $where, 'params' => $params];
    }

    /** @param array<string,int|string> $params */
    private static function bindQueryParams(PDOStatement $stmt, array $params): void
    {
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
    }
}
