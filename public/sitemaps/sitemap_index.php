<?php
header("Content-Type: application/xml; charset=utf-8");

require_once __DIR__ . '/../adiwira/bootstrap_public.php';

$domain = (
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http'
) . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');


function countByType(PDO $pdo, $type) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM posts WHERE type = ? AND status='published' AND is_deleted = 0");
    $stmt->execute([$type]);
    return (int) $stmt->fetchColumn();
}

function sitemapCount($total, $limit) {
    return max(1, ceil($total / $limit));
}

$limit = 30;

$postCount = countByType($pdo, 'article');
$pageCount = countByType($pdo, 'page');

$postMaps = sitemapCount($postCount, $limit);
$pageMaps = sitemapCount($pageCount, $limit);

echo '<?xml version="1.0" encoding="UTF-8"?>';
echo '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

for ($i=1; $i <= $postMaps; $i++) {
    echo "
    <sitemap>
        <loc>$domain/sitemap_posts_$i.xml</loc>
    </sitemap>";
}

for ($i=1; $i <= $pageMaps; $i++) {
    echo "
    <sitemap>
        <loc>$domain/sitemap_pages_$i.xml</loc>
    </sitemap>";
}

echo '</sitemapindex>';
