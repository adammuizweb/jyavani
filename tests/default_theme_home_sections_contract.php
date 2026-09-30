<?php
declare(strict_types=1);

function __(string $text): string
{
    return $text;
}

function settings_get(PDO $pdo, string $key, ?string $default = null): ?string
{
    return match ($key) {
        'site_title' => 'Contract Site',
        'site_description' => 'Contract Description',
        default => $default,
    };
}

function get_admin_path(PDO $pdo): string
{
    return 'private-admin';
}

function localized_path_url(string $path): string
{
    return '/id' . $path;
}

function get_post_permalink(array $post): string
{
    return '/id/' . rawurlencode((string)($post['slug'] ?? '')) . '/';
}

function media_post_display_url(array $post): ?string
{
    return isset($post['display_image']) ? (string)$post['display_image'] : null;
}

function widget_first_image_from_content(string $content): string
{
    return '/content-fallback.jpg';
}

function widget_format_date_id(?string $date): string
{
    return $date === null ? '' : 'formatted-' . $date;
}

$GLOBALS['home_contract_has_posts'] = true;
$GLOBALS['home_contract_category_calls'] = 0;
$GLOBALS['home_contract_post_calls'] = [];

function widget_fetch_categories(PDO $pdo, int $limit = 50, bool $onlyParents = true): array
{
    $GLOBALS['home_contract_category_calls']++;
    return $GLOBALS['home_contract_has_posts']
        ? [['name' => 'Panduan', 'slug' => 'panduan']]
        : [];
}

function cms_posts_by_category(PDO $pdo, mixed $category, array $options = []): array
{
    $key = (string)$category;
    $GLOBALS['home_contract_post_calls'][$key] = ($GLOBALS['home_contract_post_calls'][$key] ?? 0) + 1;
    if (!$GLOBALS['home_contract_has_posts']) return [];

    return [[
        'title' => $key === '' ? 'Latest Article' : ucfirst($key) . ' Article',
        'slug' => $key === '' ? 'latest-article' : $key . '-article',
        'content' => '<p>Contract article body</p>',
        'created_at' => '2026-09-30 10:00:00',
        'display_image' => '/media/contract.jpg',
    ]];
}

function theme_zone_has_position(PDO $pdo, string $zone, string $position): bool
{
    return $zone === 'main.homepage' && in_array($position, ['before', 'after'], true);
}

function theme_zone_render_position(PDO $pdo, string $zone, string $position): string
{
    return '<div data-contract-zone="' . htmlspecialchars($position, ENT_QUOTES, 'UTF-8') . '"></div>';
}

final class DefaultThemeHomeContractStatement extends PDOStatement
{
    public function execute(?array $params = null): bool
    {
        return true;
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        return false;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        return [];
    }
}

final class DefaultThemeHomeContractPdo extends PDO
{
    public function __construct()
    {
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new DefaultThemeHomeContractStatement();
    }
}

$root = dirname(__DIR__);
define('PUBLIC_PATH', $root . '/public');
define('VIEWS_BASE', PUBLIC_PATH . '/views/themes');
require_once $root . '/cfg/helpers/hooks.php';
require_once $root . '/cfg/helpers/widget_helper.php';
require_once $root . '/cfg/helpers/theme_helper.php';
require_once $root . '/cfg/helpers/theme_sections.php';
require $root . '/public/views/themes/default/theme.php';

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};

$themeRoot = $root . '/public/views/themes/default';
$sectionRoot = $themeRoot . '/partials/shortcodes/section';
$homepagePath = $themeRoot . '/main/homepage.php';
$names = [
    'home.hero',
    'home.guide-bento',
    'home.latest-carousel',
    'home.categories',
    'home.cta',
    'home.topic-columns',
    'home.empty-state',
];

$definitions = theme_section_definitions();
$translations = (string)file_get_contents($root . '/schema/translations.sql');
foreach ($names as $name) {
    $check(isset($definitions[$name]), 'default theme registers ' . $name);
    $check(($definitions[$name]['repeatable'] ?? null) === false, $name . ' is non-repeatable');
    $check(is_file($sectionRoot . '/' . $name . '.php'), $name . ' has a renderer file');
    $label = (string)($definitions[$name]['label'] ?? '');
    $check(substr_count($translations, "'" . str_replace("'", "''", $label) . "'") >= 2, $name . ' label has Indonesian and German translation seeds');
}
$check(is_file($sectionRoot . '/_home.php'), 'default homepage has a shared underscore-prefixed data helper');

$homepageSource = (string)file_get_contents($homepagePath);
$check(substr_count($homepageSource, 'render_theme_section(') === 7, 'homepage orchestrator delegates all seven semantic sections');
$check(!str_contains($homepageSource, 'cms_posts_by_category') && !str_contains($homepageSource, 'widget_fetch_categories')
    && !str_contains($homepageSource, '<section'), 'homepage orchestrator contains no queries or visual section markup');
$check(strpos($homepageSource, "render_theme_section('home.hero'") < strpos($homepageSource, "'main.homepage', 'before'")
    && strpos($homepageSource, "'main.homepage', 'before'") < strpos($homepageSource, "render_theme_section('home.guide-bento'")
    && strpos($homepageSource, "render_theme_section('home.topic-columns'") < strpos($homepageSource, "'main.homepage', 'after'"), 'homepage keeps Theme Zone positions around the content sections');

$pdo = new DefaultThemeHomeContractPdo();
ob_start();
include $homepagePath;
$richHtml = (string)ob_get_clean();

$richOrder = [
    strpos($richHtml, 'hp-hero--filled'),
    strpos($richHtml, 'data-contract-zone="before"'),
    strpos($richHtml, 'hp-bento'),
    strpos($richHtml, 'hp-carousel'),
    strpos($richHtml, 'hp-categories'),
    strpos($richHtml, 'hp-cta-block'),
    strpos($richHtml, 'hp-multicol'),
    strpos($richHtml, 'data-contract-zone="after"'),
];
$sortedRichOrder = $richOrder;
sort($sortedRichOrder);
$check(!in_array(false, $richOrder, true) && $richOrder === $sortedRichOrder, 'rich homepage preserves section and Theme Zone order');
$check(str_contains($richHtml, 'new Swiper') && str_contains($richHtml, '/id/artikel/')
    && str_contains($richHtml, '/media/contract.jpg'), 'rich sections preserve carousel JS, localized URLs, and media display URLs');
$check(strpos($richHtml, 'data-contract-zone="after"') < strpos($richHtml, 'new Swiper'), 'homepage keeps the carousel initializer after the trailing Theme Zone');
$check($GLOBALS['home_contract_category_calls'] === 1, 'category data is queried once for the request');
$check($GLOBALS['home_contract_post_calls'] === ['panduan' => 1, '' => 1, 'keamanan' => 1, 'pengembangan' => 1, 'sistem' => 1], 'each homepage post collection is queried once for the request');

$shortcodeHtml = widget_expand_shortcodes('[[widget:theme_section name="home.guide-bento"]]', $pdo);
$check(str_contains($shortcodeHtml, 'hp-bento') && str_contains($shortcodeHtml, 'Panduan Article'), 'registered homepage renderer is reusable through the Theme Section shortcode');
$check($GLOBALS['home_contract_category_calls'] === 1
    && max($GLOBALS['home_contract_post_calls']) === 1, 'shortcode reuse consumes the request-cached homepage data');

unset($GLOBALS['__jy_default_theme_home_data']);
$GLOBALS['__jy_default_theme_home_data_has_pdo'] = false;
$GLOBALS['home_contract_has_posts'] = false;
ob_start();
include $homepagePath;
$emptyHtml = (string)ob_get_clean();
$check(str_contains($emptyHtml, 'hp-hero--center') && str_contains($emptyHtml, 'hp-empty__box')
    && !str_contains($emptyHtml, 'hp-hero--filled') && !str_contains($emptyHtml, 'data-contract-zone='), 'empty homepage renders only the original empty state without Theme Zones');
$check(str_contains($emptyHtml, '/private-admin/'), 'empty homepage retains the configured admin URL fallback');

unset($GLOBALS['__jy_default_theme_home_data']);
$GLOBALS['__jy_default_theme_home_data_has_pdo'] = false;
$defaultHtml = render_theme_section('home.empty-state');
$check(str_contains($defaultHtml, 'Jyavani CMS') && str_contains($defaultHtml, '/dashboard/'), 'empty section renders safely without a database connection');

$GLOBALS['home_contract_has_posts'] = true;
$refreshedHtml = render_theme_section('home.hero', [], $pdo);
$check(str_contains($refreshedHtml, 'Contract Site') && $GLOBALS['home_contract_category_calls'] === 3,
    'a later PDO render refreshes data previously cached without a database connection');

$manifest = json_decode((string)file_get_contents($themeRoot . '/theme.json'), true);
$check(($manifest['version'] ?? '') === '1.5.0' && ($manifest['jyavani_required'] ?? '') === '2.3.157', 'default theme manifest declares its internal version and Jyavani requirement');

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " assertion(s) failed.\n");
    exit(1);
}
echo "RESULT: ALL PASS\n";
