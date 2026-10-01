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
require_once $root . '/cfg/helpers/editor_references.php';
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
    $descriptor = theme_section_source_descriptor($name, $pdo);
    $firstFingerprint = theme_section_source_fingerprint($name, $pdo);
    $check(($descriptor['identity'] ?? '') === 'theme_section:' . $name, 'source descriptor exposes stable section identity');
    $check(($descriptor['definition']['label'] ?? '') === 'Contract Hero', 'source descriptor exposes the registered definition');
    $check(count($descriptor['composition_order'] ?? []) >= 2, 'source descriptor exposes renderer composition order and Core fallback');
    $check(strlen((string)($descriptor['renderer']['revision'] ?? '')) === 64, 'file renderer revision is a SHA-256 content identity');
    $editorReferences = editor_reference_configuration($pdo, [
        'surface' => 'theme_content',
        'content' => '[[widget:theme_section name="  ' . strtoupper($name) . '  "]] [[widget:theme_section name="../outside"]]',
        'admin_base_path' => '/dashboard',
        'return_to' => '/dashboard/?page=admin%2Fthemes%2Fedit&id=7',
        'can_edit_executable' => true,
    ]);
    $sectionProvider = $editorReferences['providers'][0] ?? [];
    $activeReference = $sectionProvider['entries'][$name] ?? [];
    $encodedReferences = json_encode($editorReferences, JSON_UNESCAPED_SLASHES);
    $check(($sectionProvider['shortcode'] ?? '') === 'theme_section'
        && ($sectionProvider['attribute'] ?? '') === 'name'
        && ($sectionProvider['normalize'] ?? '') === 'lowercase'
        && ($sectionProvider['trim'] ?? false) === true
        && ($sectionProvider['max_bytes'] ?? 0) === 120, 'Theme Section publishes a generic provider with runtime-equivalent normalization bounds');
    $check(str_contains((string)($activeReference['url'] ?? ''), 'page=admin%2Fshortcodes%2Flayout')
        && str_contains((string)($activeReference['url'] ?? ''), 'file=' . $name . '.php')
        && ($activeReference['action_label'] ?? '') === 'Open editor', 'active-theme references target the exact Theme Section editor');
    $check(is_string($encodedReferences) && !str_contains($encodedReferences, $fixtureRoot)
        && !isset($sectionProvider['entries']['../outside']), 'editor references expose no filesystem paths and reject malformed names');
    $check(editor_reference_configuration($pdo, [
        'surface' => 'theme_content',
        'content' => '[[widget:theme_section name="' . $name . '"]] ',
        'admin_base_path' => '/dashboard',
        'return_to' => '/dashboard/',
        'can_edit_executable' => false,
    ]) === [], 'executable editor references are withheld without explicit authority');
    $check(editor_reference_admin_url('/dashboard/?page=allowed', '/dashboard') !== ''
        && editor_reference_admin_url('/dashboard-escape/?page=denied', '/dashboard') === ''
        && editor_reference_admin_url('/dashboard/%252e%252e/escape', '/dashboard') === ''
        && editor_reference_admin_url('https://example.test/dashboard/', '/dashboard') === '', 'editor reference URLs remain confined to the local dashboard path');
    $edgeNames = theme_section_editor_reference_names(
        '[[widget:theme_section name="' . "\0" . 'edge.nul' . "\0" . '"]]'
        . '[[widget:theme_section name="' . "\u{00A0}" . 'edge.nbsp' . "\u{00A0}" . '"]] '
        . '[[widget:theme_section name="' . "\f" . 'edge.formfeed' . "\f" . '"]] ',
        $pdo
    );
    $check(in_array('edge.nul', $edgeNames, true)
        && !in_array('edge.nbsp', $edgeNames, true)
        && !in_array('edge.formfeed', $edgeNames, true),
        'reference-name normalization follows PHP trim semantics for NUL, NBSP, and form feed');
    $longProviderKey = 'a' . str_repeat('b', 199);
    $longProvider = editor_reference_provider_config('long-reference', [
        'syntax' => 'widget',
        'shortcode' => 'example',
        'attribute' => 'name',
        'label' => 'Example',
        'value_pattern' => '^[a-z]+$',
        'max_bytes' => 240,
        'entries' => [
            $longProviderKey => [
                'title' => 'Long reference',
                'source' => 'Contract',
                'action_label' => 'Open',
                'url' => '/dashboard/?page=example',
            ],
        ],
    ], '/dashboard');
    $check(isset($longProvider['entries'][$longProviderKey]), 'generic provider entry bounds follow the declared maximum byte length');
    file_put_contents($activeFile, '<article data-source="active-v2"><?= $esc($attrs[\'title\'] ?? \'\') ?></article>');
    $check(theme_section_source_fingerprint($name, $pdo) !== $firstFingerprint, 'source fingerprint changes when renderer content changes');
    file_put_contents($activeFile, '<article data-source="active" data-context="<?= $esc($context[\'page\'][\'slug\'] ?? \'\') ?>" data-attr-page="<?= isset($attrs[\'page\']) ? \'yes\' : \'no\' ?>"><?= $esc($attrs[\'title\'] ?? \'\') ?></article>');

    $shortcodeHtml = widget_expand_shortcodes('[[widget:theme_section name="' . $name . '"]]', $pdo, [
        'page' => ['slug' => 'context-page'],
    ]);
    $check(str_contains($shortcodeHtml, 'Default title'), 'widget shortcode invokes the section renderer and merges defaults');
    $check(str_contains($shortcodeHtml, 'data-context="context-page"'), 'widget shortcode passes page context separately');
    $check(str_contains($shortcodeHtml, 'data-attr-page="no"'), 'page context does not leak into shortcode attributes');

    unlink($activeFile);
    $check(str_contains(render_theme_section($name, [], $pdo), 'data-source="default"'), 'default theme renderer is the second choice');
    $fallbackReferences = editor_reference_configuration($pdo, [
        'surface' => 'theme_content',
        'content' => '[[widget:theme_section name="' . $name . '"]]',
        'admin_base_path' => '/dashboard',
        'return_to' => '/dashboard/?page=admin%2Fthemes%2Fedit&id=7',
        'can_edit_executable' => true,
    ]);
    $fallbackReference = $fallbackReferences['providers'][0]['entries'][$name] ?? [];
    $check(($fallbackReference['source'] ?? '') === 'Default theme'
        && ($fallbackReference['action_label'] ?? '') === 'Create active-theme override'
        && str_contains((string)($fallbackReference['url'] ?? ''), 'name=' . $name)
        && !str_contains((string)($fallbackReference['url'] ?? ''), 'file='), 'fallback renderers offer an explicit active-theme override instead of editing another owner');

    unlink($defaultFile);
    $check(str_contains(render_theme_section($name, [], $pdo), 'data-source="global"'), 'global renderer is the final file fallback');

    unlink($globalFile);
    $fallbackHtml = render_theme_section($name, ['summary' => '<Summary>'], $pdo);
    $check(str_contains($fallbackHtml, 'theme-section--fallback'), 'missing renderers produce semantic Core markup');
    $check(str_contains($fallbackHtml, '&lt;Summary&gt;'), 'semantic fallback escapes user attributes');
    $unsafeHtml = render_theme_section($name, ['url' => 'javascript:alert(1)', 'link_label' => 'Unsafe'], $pdo);
    $check(!str_contains($unsafeHtml, 'javascript:'), 'semantic fallback rejects unsafe URL schemes');
    $check(theme_section_safe_url('/safe/path') === '/safe/path', 'relative section URLs remain supported');
    $previewHtml = theme_section_preview_sanitize_html('<section>Safe<script>alert(1)</script></section>');
    $check($previewHtml === '<section>Safe</section>', 'preview documents remove renderer scripts before entering the sandbox');
    $collectionPreviewContext = null;
    add_filter('shortcode_collection_preview_styles', static function (array $styles, string $previewFolder, mixed $previewPdo, array $context) use (&$collectionPreviewContext): array {
        $collectionPreviewContext = [$previewFolder, $context['layout'] ?? null];
        $styles[] = '/collection-preview.css';
        return $styles;
    });
    $collectionDocument = shortcode_collection_preview_document(
        '<section>Collection<script>alert(1)</script></section>',
        $pdo,
        ['layout' => 'contract-list', 'theme_folder' => $folder]
    );
    $check(str_contains($collectionDocument, 'data-shortcode-collection-preview="' . $folder . '"')
        && str_contains($collectionDocument, 'href="/collection-preview.css"')
        && !str_contains($collectionDocument, '<script'), 'Collection Layout preview uses a script-free theme-styled document shell');
    $check($collectionPreviewContext === [$folder, 'contract-list'], 'Collection Layout preview style hooks receive theme and layout context');
    $dottedCollectionShell = shortcode_collection_preview_document_shell($pdo, ['theme_folder' => 'theme.contract']);
    $check(str_contains($dottedCollectionShell['before'], 'data-shortcode-collection-preview="theme.contract"'), 'Collection Layout preview accepts the canonical dotted theme-folder identity');

    $slotContext = null;
    add_filter('resolve_template', static function (mixed $resolved, string $slotKey): mixed {
        if ($slotKey !== 'main.contract') return $resolved;
        return ['type' => 'custom_post', 'post' => ['type' => 'theme', 'content' => 'unfiltered']];
    });
    add_filter('theme_slot_post_data', static function (array $post, string $slotKey, mixed $slotPdo, array $context) use (&$slotContext): array {
        if ($slotKey !== 'main.contract') return $post;
        $slotContext = $context;
        $post['content'] = 'filtered {{page.slug}}';
        return $post;
    });
    $slotHtml = render_slot($pdo, 'main.contract', ['page' => ['slug' => 'slot-context']]);
    $check($slotHtml === 'filtered slot-context', 'custom Theme post adapters run before rendering');
    $check(($slotContext['page']['slug'] ?? '') === 'slot-context', 'custom Theme post adapters receive render context');

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
