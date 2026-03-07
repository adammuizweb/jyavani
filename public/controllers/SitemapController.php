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

        echo '</sitemapindex>';
        exit;
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
        $dbType = $type === 'posts' ? 'article' : 'page';

        $stmt = $pdo->prepare("
            SELECT slug, COALESCE(updated_at, created_at) AS changed_at
            FROM posts
            WHERE type = :type
              AND is_deleted = 0
              AND status = 'published'
            ORDER BY created_at DESC
            LIMIT :limit OFFSET :offset
        ");
        $stmt->bindValue(':type', $dbType);
        $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL;
        echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . PHP_EOL;

        foreach ($rows as $r) {
            $slug = trim($r['slug'], '/');
            if ($slug === '') continue;
            $loc = $domain . '/' . rawurlencode($slug) . '/';
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
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM posts WHERE type = :type AND is_deleted = 0 AND status = 'published'");
        $stmt->execute([':type' => $type]);
        return (int)$stmt->fetchColumn();
    }
}
