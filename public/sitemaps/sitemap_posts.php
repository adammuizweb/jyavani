<?php
header("Content-Type: application/xml; charset=utf-8");

require_once __DIR__ . '/../bootstrap_core.php';

$domain = (
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http'
) . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');

$limit = 30;

// Ambil nomor pagination dari URL: sitemap_posts_2.xml
preg_match('/sitemap_posts_(\d+)\.xml/', $_SERVER['REQUEST_URI'], $m);
$page = isset($m[1]) ? max(1, (int)$m[1]) : 1;
$offset = ($page - 1) * $limit;

$stmt = $pdo->prepare("
    SELECT slug, updated_at
    FROM posts
    WHERE type='article'
      AND status='published'
      AND is_deleted=0
    ORDER BY created_at DESC
    LIMIT :limit OFFSET :offset
");

$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();

$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo '<?xml version="1.0" encoding="UTF-8"?>';
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

foreach ($rows as $row) {
    $loc = htmlspecialchars($domain . '/' . $row['slug'] . '/', ENT_XML1);
    $lastmod = date('c', strtotime($row['updated_at'] ?? 'now'));

    echo "
    <url>
        <loc>$loc</loc>
        <lastmod>$lastmod</lastmod>
        <changefreq>weekly</changefreq>
        <priority>0.8</priority>
    </url>";
}

echo '</urlset>';
