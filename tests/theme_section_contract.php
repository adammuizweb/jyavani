<?php
declare(strict_types=1);

final class ThemeSectionContractStatement extends PDOStatement
{
    public function execute(?array $params = null): bool
    {
        return true;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        return [];
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        return false;
    }
}

final class ThemeSectionContractPdo extends PDO
{
    public function __construct()
    {
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new ThemeSectionContractStatement();
    }
}

$root = dirname(__DIR__);
define('PUBLIC_PATH', $root . '/public');
define('VIEWS_BASE', PUBLIC_PATH . '/views/themes');
require_once $root . '/cfg/helpers/hooks.php';
require_once $root . '/cfg/helpers/widget_helper.php';
require_once $root . '/cfg/helpers/theme_helper.php';
require_once $root . '/cfg/helpers/theme_sections.php';

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};

$pdo = new ThemeSectionContractPdo();
$suffix = (string)getmypid();
$folder = 'section-contract-' . $suffix;
$name = 'contract.hero-' . $suffix;
$fixtureRoot = VIEWS_BASE . '/' . $folder;
$activeDirectory = $fixtureRoot . '/partials/shortcodes/section';
$defaultDirectory = VIEWS_BASE . '/' . DEFAULT_THEME_FOLDER . '/partials/shortcodes/section';
$globalDirectory = PUBLIC_PATH . '/views/partials/shortcodes/section';
$defaultDirectoryExisted = is_dir($defaultDirectory);
$globalDirectoryExisted = is_dir($globalDirectory);
$defaultFile = $defaultDirectory . '/' . $name . '.php';
$globalFile = $globalDirectory . '/' . $name . '.php';
$activeFile = $activeDirectory . '/' . $name . '.php';
$outsideFile = sys_get_temp_dir() . '/theme-section-outside-' . $suffix . '.php';

$removeTree = static function (string $path) use (&$removeTree): void {
    if (!is_dir($path)) return;
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $target = $path . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($target)) $removeTree($target);
        else @unlink($target);
    }
    @rmdir($path);
};

try {
    mkdir($activeDirectory, 0775, true);
    if (!is_dir($defaultDirectory)) mkdir($defaultDirectory, 0775, true);
    if (!is_dir($globalDirectory)) mkdir($globalDirectory, 0775, true);

    file_put_contents($activeFile, '<article data-source="active" data-context="<?= $esc($context[\'page\'][\'slug\'] ?? \'\') ?>" data-attr-page="<?= isset($attrs[\'page\']) ? \'yes\' : \'no\' ?>"><?= $esc($attrs[\'title\'] ?? \'\') ?></article>');
    file_put_contents($defaultFile, '<article data-source="default"><?= $esc($section) ?></article>');
    file_put_contents($globalFile, '<article data-source="global"><?= $esc($section) ?></article>');
    file_put_contents($outsideFile, '<strong>outside</strong>');

    add_filter('active_theme_folder', static fn(string $current): string => $folder);
    register_theme_section($name, [
        'label' => 'Contract Hero',
        'defaults' => ['title' => 'Default title'],
    ]);

    $check(theme_section_name_is_valid($name), 'dotted and dashed section identifiers are valid');
    $check(!theme_section_name_is_valid('../outside'), 'path traversal is rejected as a section identifier');
    $check(theme_section_theme_directory($pdo) === realpath($activeDirectory), 'admin directory resolves inside the active theme');

    $activeHtml = render_theme_section($name, ['title' => '<Active>'], $pdo);
    $check(str_contains($activeHtml, 'data-source="active"'), 'active theme renderer has first priority');
    $check(str_contains($activeHtml, '&lt;Active&gt;'), 'theme renderer receives the escaping helper');

    $shortcodeHtml = widget_expand_shortcodes('[[widget:theme_section name="' . $name . '"]]', $pdo, [
        'page' => ['slug' => 'context-page'],
    ]);
    $check(str_contains($shortcodeHtml, 'Default title'), 'widget shortcode invokes the section renderer and merges defaults');
    $check(str_contains($shortcodeHtml, 'data-context="context-page"'), 'widget shortcode passes page context separately');
    $check(str_contains($shortcodeHtml, 'data-attr-page="no"'), 'page context does not leak into shortcode attributes');

    unlink($activeFile);
    $check(str_contains(render_theme_section($name, [], $pdo), 'data-source="default"'), 'default theme renderer is the second choice');

    unlink($defaultFile);
    $check(str_contains(render_theme_section($name, [], $pdo), 'data-source="global"'), 'global renderer is the final file fallback');

    unlink($globalFile);
    $fallbackHtml = render_theme_section($name, ['summary' => '<Summary>'], $pdo);
    $check(str_contains($fallbackHtml, 'theme-section--fallback'), 'missing renderers produce semantic Core markup');
    $check(str_contains($fallbackHtml, '&lt;Summary&gt;'), 'semantic fallback escapes user attributes');
    $unsafeHtml = render_theme_section($name, ['url' => 'javascript:alert(1)', 'link_label' => 'Unsafe'], $pdo);
    $check(!str_contains($unsafeHtml, 'javascript:'), 'semantic fallback rejects unsafe URL schemes');
    $check(theme_section_safe_url('/safe/path') === '/safe/path', 'relative section URLs remain supported');

    add_filter('theme_section_layout_candidates', static function (array $candidates) use ($outsideFile): array {
        array_unshift($candidates, $outsideFile);
        return $candidates;
    });
    $check(theme_section_resolve_layout($name, $pdo) === null, 'candidate filters cannot escape validated section directories');
    $check(render_theme_section('../outside', [], $pdo) === '', 'invalid section names render nothing');
} finally {
    @unlink($activeFile);
    @unlink($defaultFile);
    @unlink($globalFile);
    @unlink($outsideFile);
    $removeTree($fixtureRoot);
    if (!$defaultDirectoryExisted) {
        @rmdir($defaultDirectory);
        @rmdir(dirname($defaultDirectory));
        @rmdir(dirname(dirname($defaultDirectory)));
    }
    if (!$globalDirectoryExisted) {
        @rmdir($globalDirectory);
        @rmdir(dirname($globalDirectory));
    }
}

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " assertion(s) failed.\n");
    exit(1);
}
echo "RESULT: ALL PASS\n";
