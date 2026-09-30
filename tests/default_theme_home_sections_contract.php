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
$GLOBALS['home_contract_preset_calls'] = [];

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

function cms_posts_count_by_category(PDO $pdo, mixed $category, array $options = []): int
{
    return 22;
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
    private array $rows = [];

    public function __construct(private array $rowsByPreset = [])
    {
    }

    public function execute(?array $params = null): bool
    {
        $preset = $params[':preset'] ?? null;
        $this->rows = isset($this->rowsByPreset[$preset]) ? [$this->rowsByPreset[$preset]] : [];
        return true;
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        return array_shift($this->rows) ?: false;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        return $this->rows;
    }
}

final class DefaultThemeHomeContractPdo extends PDO
{
    public bool $presetAvailable = true;

    public function __construct()
    {
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $rows = $this->presetAvailable && str_contains($query, "type = 'sc_preset'")
            ? [
                'demo_home_posts' => [
                'id' => 301,
                'title' => 'Demo Homepage Posts Preset',
                'slug' => 'demo_home_posts',
                'meta' => '{"source":"posts","type":"article","category":"pengembangan","order_by":"created_at","order_dir":"DESC","limit":"4","layout":"cards","kicker":"Preset Demo","excerpt_len":"120","wrap":"1"}',
                ],
                'demo_random_posts' => [
                    'id' => 300,
                    'title' => 'Demo Random Posts Preset',
                    'slug' => 'demo_random_posts',
                    'meta' => '{"source":"posts","type":"article","category":"","order_by":"RAND()","order_dir":"DESC","limit":"4","layout":"mini","kicker":"Pilihan","excerpt_len":"90","wrap":"1"}',
                ],
            ]
            : [];
        return new DefaultThemeHomeContractStatement($rows);
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

add_filter('shortcode_preset_runtime_config', static function (array $config, array $preset, PDO $pdo, array $context): array {
    $GLOBALS['home_contract_preset_calls'][] = ['preset' => $preset['slug'] ?? '', 'context' => $context];
    return $config;
});

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
    'home.preset-posts',
    'home.categories',
    'home.random-posts',
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
$presetDefaults = $definitions['home.preset-posts']['defaults'] ?? [];
$check(($presetDefaults['preset'] ?? '') === 'demo_home_posts', 'preset-backed homepage section defaults to the canonical demo preset');
foreach (['title', 'summary'] as $defaultKey) {
    $defaultText = (string)($presetDefaults[$defaultKey] ?? '');
    $check($defaultText !== '' && substr_count($translations, "'" . str_replace("'", "''", $defaultText) . "'") >= 2, 'preset section ' . $defaultKey . ' has Indonesian and German translation seeds');
}
$randomDefaults = $definitions['home.random-posts']['defaults'] ?? [];
$check(($randomDefaults['preset'] ?? '') === 'demo_random_posts', 'random Card Grid section reuses the canonical random demo preset');
foreach (['title', 'summary', 'refresh_label'] as $defaultKey) {
    $defaultText = (string)($randomDefaults[$defaultKey] ?? '');
    $check($defaultText !== '' && substr_count($translations, "'" . str_replace("'", "''", $defaultText) . "'") >= 2, 'random section ' . $defaultKey . ' has Indonesian and German translation seeds');
}

$homepageSource = (string)file_get_contents($homepagePath);
$check(substr_count($homepageSource, 'render_theme_section(') === 9, 'homepage orchestrator delegates all nine semantic sections');
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
    strpos($richHtml, 'data-theme-section="home.preset-posts"'),
    strpos($richHtml, 'hp-categories'),
    strpos($richHtml, 'data-theme-section="home.random-posts"'),
    strpos($richHtml, 'hp-cta-block'),
    strpos($richHtml, 'hp-multicol'),
    strpos($richHtml, 'data-contract-zone="after"'),
];
$sortedRichOrder = $richOrder;
sort($sortedRichOrder);
$check(!in_array(false, $richOrder, true) && $richOrder === $sortedRichOrder, 'rich homepage preserves section and Theme Zone order');
$check(str_contains($richHtml, 'new Swiper') && str_contains($richHtml, '/id/artikel/')
    && str_contains($richHtml, '/media/contract.jpg'), 'rich sections preserve carousel JS, localized URLs, and media display URLs');
$check(str_contains($richHtml, 'data-pcat-layout="cards"') && str_contains($richHtml, 'Pengembangan Article'), 'preset-backed section visibly renders its saved Collection Layout and query result');
$randomPageKeyMatch = [];
$hasRandomPageKey = preg_match('/data-pcat-pagination-root="(pcat_page_demo_random_posts-[a-f0-9]{16})"/', $richHtml, $randomPageKeyMatch) === 1;
$randomPageKey = $hasRandomPageKey ? (string)$randomPageKeyMatch[1] : '';
$randomSeedKey = post_cat__pagination_seed_key($randomPageKey);
$check(str_contains($richHtml, 'data-pcat-layout="grid"')
    && str_contains($richHtml, 'action="/#random-posts"')
    && str_contains($richHtml, 'name="random_posts" value="1"')
    && str_contains($richHtml, 'href="/id/latest-article/"')
    && str_contains($richHtml, 'Page 1 of 5')
    && $hasRandomPageKey
    && preg_match('/' . preg_quote($randomSeedKey, '/') . '=[1-9][0-9]*/', $richHtml) === 1, 'random section renders Card Grid with shuffle, card links, and five request-stable pages');
$check(($GLOBALS['home_contract_preset_calls'][0]['preset'] ?? '') === 'demo_home_posts'
    && ($GLOBALS['home_contract_preset_calls'][0]['context']['surface'] ?? '') === 'theme_section.home.preset-posts', 'homepage section delegates query and Collection Layout ownership to the published preset API');
$check(($GLOBALS['home_contract_preset_calls'][1]['preset'] ?? '') === 'demo_random_posts'
    && ($GLOBALS['home_contract_preset_calls'][1]['context']['surface'] ?? '') === 'theme_section.home.random-posts', 'random homepage section delegates data ownership to the published random preset');
$check(strpos($richHtml, 'data-contract-zone="after"') < strpos($richHtml, 'new Swiper'), 'homepage keeps the carousel initializer after the trailing Theme Zone');
$check($GLOBALS['home_contract_category_calls'] === 1, 'category data is queried once for the request');
$check($GLOBALS['home_contract_post_calls'] === ['panduan' => 1, '' => 2, 'keamanan' => 1, 'pengembangan' => 2, 'sistem' => 1], 'shared homepage data is cached while both preset sections independently own their collection queries');

$shortcodeHtml = widget_expand_shortcodes('[[widget:theme_section name="home.guide-bento"]]', $pdo);
$check(str_contains($shortcodeHtml, 'hp-bento') && str_contains($shortcodeHtml, 'Panduan Article'), 'registered homepage renderer is reusable through the Theme Section shortcode');
$check($GLOBALS['home_contract_category_calls'] === 1
    && $GLOBALS['home_contract_post_calls'] === ['panduan' => 1, '' => 2, 'keamanan' => 1, 'pengembangan' => 2, 'sistem' => 1], 'non-preset shortcode reuse consumes the request-cached homepage data');
$presetShortcodeHtml = widget_expand_shortcodes('[[widget:theme_section name="home.preset-posts"]]', $pdo);
$check(str_contains($presetShortcodeHtml, 'data-theme-section="home.preset-posts"')
    && str_contains($presetShortcodeHtml, 'data-pcat-layout="cards"'), 'preset-backed homepage renderer is reusable through the Theme Section shortcode');
$randomShortcodeHtml = widget_expand_shortcodes('[[widget:theme_section name="home.random-posts"]]', $pdo);
$check(str_contains($randomShortcodeHtml, 'data-theme-section="home.random-posts"')
    && str_contains($randomShortcodeHtml, 'data-pcat-layout="grid"'), 'random Card Grid renderer is reusable through the Theme Section shortcode');
$_SERVER['REQUEST_URI'] = '/?view=compact&' . $randomPageKey . '=3&' . $randomSeedKey . '=123&pcat_page_other=2';
$_GET = [
    'view' => 'compact',
    $randomPageKey => '3',
    $randomSeedKey => '123',
    'pcat_page_other' => '2',
];
$shuffleHtml = render_theme_section('home.random-posts', [], $pdo);
$check(str_contains($shuffleHtml, '<input type="hidden" name="view" value="compact">')
    && str_contains($shuffleHtml, '<input type="hidden" name="pcat_page_other" value="2">')
    && !str_contains($shuffleHtml, '<input type="hidden" name="' . $randomPageKey . '"')
    && !str_contains($shuffleHtml, '<input type="hidden" name="' . $randomSeedKey . '"'), 'Shuffle resets only its own page and seed while preserving unrelated query state');
$_SERVER['REQUEST_URI'] = '/';
$_GET = [];

$pdo->presetAvailable = false;
$missingPresetHtml = render_theme_section('home.preset-posts', [], $pdo);
$check($missingPresetHtml === '', 'preset-backed section emits no wrapper when demo content is not installed or the preset is unavailable');
$pdo->presetAvailable = true;

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
$check(($manifest['version'] ?? '') === '1.7.0' && ($manifest['jyavani_required'] ?? '') === '2.3.161', 'default theme declares its random Card Grid section version and coordinated Core requirement');

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " assertion(s) failed.\n");
    exit(1);
}
echo "RESULT: ALL PASS\n";
