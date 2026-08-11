<?php
declare(strict_types=1);

$fixtureRoot = sys_get_temp_dir() . '/shortcode-layout-dashboard-' . getmypid() . '-' . bin2hex(random_bytes(4));
define('PUBLIC_PATH', $fixtureRoot . '/public');
define('VIEWS_BASE', PUBLIC_PATH . '/views/themes');
define('ADMIN_BASE_PATH', '/secure-admin');
define('SHORTCODE_LAYOUT_PROJECT_PATH', $fixtureRoot);
define('SHORTCODE_LAYOUT_QUARANTINE_PATH', $fixtureRoot . '/cfg/var/layout-quarantine');
$collectionDirectory = PUBLIC_PATH . '/views/partials/shortcodes/post_cat';
$sectionDirectory = VIEWS_BASE . '/theme-a/partials/shortcodes/section';
$GLOBALS['layout_contract_section_directory'] = $sectionDirectory;
mkdir($collectionDirectory, 0775, true);
mkdir($sectionDirectory, 0775, true);
mkdir($fixtureRoot . '/cfg/var', 0775, true);

if (!function_exists('theme_section_name_is_valid')) {
    function theme_section_name_is_valid(string $name): bool
    {
        return strlen($name) <= 120
            && preg_match('/\A[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*\z/', $name) === 1;
    }
}
if (!function_exists('theme_section_theme_directory')) {
    function theme_section_theme_directory(?PDO $pdo = null, bool $create = false, ?string $folder = null): ?string
    {
        return realpath((string)$GLOBALS['layout_contract_section_directory']) ?: null;
    }
}
if (!function_exists('theme_section_path_is_within')) {
    function theme_section_path_is_within(string $path, string $root): bool
    {
        $root = rtrim($root, DIRECTORY_SEPARATOR);
        return $path === $root || str_starts_with($path, $root . DIRECTORY_SEPARATOR);
    }
}
if (!function_exists('theme_section_definitions')) {
    function theme_section_definitions(): array
    {
        return ['registered.hero' => ['label' => 'Registered Hero']];
    }
}

final class ShortcodeLayoutDashboardContractStatement extends PDOStatement
{
    public function __construct(private array $rows = []) {}
    public function execute(?array $params = null): bool { return true; }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return $this->rows; }
    public function fetchColumn(int $column = 0): mixed { return $this->rows[0][$column] ?? false; }
}

final class ShortcodeLayoutDashboardContractPdo extends PDO
{
    public array $presetRows = [];
    public function __construct()
    {
    }
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new ShortcodeLayoutDashboardContractStatement($this->presetRows);
    }
}

final class ShortcodeLayoutLockContractPdo extends PDO
{
    public array $events = [];
    public function __construct() {}
    public function getAttribute(int $attribute): mixed { return $attribute === PDO::ATTR_DRIVER_NAME ? 'mysql' : null; }
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->events[] = str_contains($query, 'RELEASE_LOCK') ? 'release' : 'acquire';
        return new ShortcodeLayoutDashboardContractStatement([[1]]);
    }
}

final class ShortcodeContentMutationContractPdo extends PDO
{
    public array $events = [];
    private bool $transaction = false;
    public function __construct(bool $transaction = false) { $this->transaction = $transaction; }
    public function getAttribute(int $attribute): mixed { return $attribute === PDO::ATTR_DRIVER_NAME ? 'mysql' : null; }
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->events[] = str_contains($query, 'RELEASE_LOCK') ? 'release' : 'acquire';
        return new ShortcodeLayoutDashboardContractStatement([[1]]);
    }
    public function inTransaction(): bool { return $this->transaction; }
    public function beginTransaction(): bool { $this->events[] = 'begin'; return $this->transaction = true; }
    public function commit(): bool { $this->events[] = 'commit'; $this->transaction = false; return true; }
}

$root = dirname(__DIR__);
$files = [
    'index' => $root . '/dashboard/admin/shortcodes/index.php',
    'editor' => $root . '/dashboard/admin/shortcodes/layout.php',
    'delete' => $root . '/dashboard/admin/shortcodes/delete_layout.php',
    'bulk' => $root . '/dashboard/admin/shortcodes/bulk_layout_action.php',
    'manager' => $root . '/dashboard/admin/shortcodes/_layout_manager.php',
    'translations' => $root . '/schema/translations.sql',
    'post_add' => $root . '/dashboard/admin/posts/add.php',
    'post_save' => $root . '/dashboard/admin/posts/save.php',
    'page_add' => $root . '/dashboard/admin/pages/add.php',
    'page_save' => $root . '/dashboard/admin/pages/save.php',
    'theme_add' => $root . '/dashboard/admin/themes/add.php',
    'theme_save' => $root . '/dashboard/admin/themes/save.php',
];
$source = array_map(static fn(string $file): string => (string)file_get_contents($file), $files);
require_once $root . '/cfg/helpers/hooks.php';
require_once $files['manager'];
require_once $root . '/cfg/helpers/widget_shortcodes_p.php';
require_once $root . '/dashboard/admin/_notify.php';

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};

$collectionFilters = shortcode_layout_list_filters([
    'p' => ['-4'],
    'q' => ['card'],
    'filter' => ['registered'],
], 'collection');
$sectionFilters = shortcode_layout_list_filters([
    'p' => '27',
    'q' => str_repeat('x', 150),
    'filter' => 'registered',
], 'section');
$check($collectionFilters === ['p' => 1, 'q' => '', 'filter' => ''], 'malformed collection filter arrays are rejected without coercion');
$check($sectionFilters['p'] === 27 && strlen($sectionFilters['q']) === 120 && $sectionFilters['filter'] === 'registered', 'section search and registration filters are bounded and validated');
$check(shortcode_layout_file_is_valid('custom_grid.php', 'collection'), 'collection layout filenames accept runtime-compatible slugs');
$check(!shortcode_layout_file_is_valid('../custom_grid.php', 'collection') && !shortcode_layout_file_is_valid('custom.grid.php', 'collection'), 'collection layout filenames reject traversal and unsupported names');
$check(shortcode_layout_file_is_valid('page.hero.php', 'section') && !shortcode_layout_file_is_valid('../page.hero.php', 'section'), 'section filenames use the section identifier contract and reject traversal');
$check(shortcode_layout_builtin_names() === ['cards', 'list', 'card2', 'sliderpage', 'grid', 'mini'], 'all Core collection layouts share one exhaustive protected-name list');
$check(!shortcode_collection_layout_name_is_valid('gríd') && !shortcode_collection_layout_name_is_valid(str_repeat('x', 41)), 'collection naming contract rejects unsupported Unicode and overlong values');
$check(shortcode_collection_layout_filename('roundtrip_name') === 'roundtrip_name.php' && shortcode_collection_layout_name_from_filename('roundtrip_name.php') === 'roundtrip_name', 'collection name and filename conversion round-trips unchanged');

$pdo = new ShortcodeLayoutDashboardContractPdo();
$lockPdo = new ShortcodeLayoutLockContractPdo();
try {
    shortcode_collection_layout_with_lock($lockPdo, static function (): void { throw new RuntimeException('contract failure'); });
} catch (RuntimeException $error) {
}
$check($lockPdo->events === ['acquire', 'release'], 'MySQL advisory lock is released in finally when a lifecycle operation throws');
$localLockRejected = false;
try {
    shortcode_collection_layout_with_lock($pdo, static function () use ($pdo): void {
        shortcode_collection_layout_with_lock($pdo, static function (): void {});
    });
} catch (ShortcodeCollectionLayoutLockException $error) {
    $localLockRejected = true;
}
$check($localLockRejected, 'fake PDO lock support deterministically rejects re-entrant lifecycle operations');
$mutationPdo = new ShortcodeContentMutationContractPdo();
shortcode_collection_layout_content_mutation($mutationPdo, static function () use ($mutationPdo): void {
    $mutationPdo->beginTransaction();
    $mutationPdo->commit();
});
$check($mutationPdo->events === ['acquire', 'begin', 'commit', 'release'], 'content mutation ordering is collection lock, database transaction, commit, then lock release');
$nestedTransactionRejected = false;
try {
    shortcode_collection_layout_content_mutation(new ShortcodeContentMutationContractPdo(true), static function (): void {});
} catch (LogicException $error) {
    $nestedTransactionRejected = true;
}
$check($nestedTransactionRejected, 'content mutation boundary rejects transaction-first nesting conflicts');

file_put_contents($collectionDirectory . '/custom-one.php', '<?php // one');
file_put_contents($collectionDirectory . '/custom-two.php', '<?php // two');
foreach (shortcode_layout_builtin_names() as $builtin) {
    file_put_contents($collectionDirectory . '/' . $builtin . '.php', '<?php // protected');
}
$deleted = shortcode_layout_delete_files($pdo, 'collection', ['custom-one.php', 'custom-two.php']);
$check($deleted === 2 && !file_exists($collectionDirectory . '/custom-one.php') && !file_exists($collectionDirectory . '/custom-two.php'), 'collection bulk deletion removes the complete validated selection');

file_put_contents($collectionDirectory . '/custom-safe.php', '<?php // safe');
try {
    shortcode_layout_delete_files($pdo, 'collection', ['custom-safe.php', '../invalid.php']);
} catch (Throwable $error) {
}
$check(file_exists($collectionDirectory . '/custom-safe.php'), 'bulk deletion performs complete prevalidation before moving any valid file');
foreach (shortcode_layout_builtin_names() as $builtin) {
    try {
        shortcode_layout_delete_files($pdo, 'collection', [$builtin . '.php']);
    } catch (Throwable $error) {
    }
    $check(file_exists($collectionDirectory . '/' . $builtin . '.php'), 'behavioral deletion preserves built-in ' . $builtin);
}

file_put_contents($collectionDirectory . '/roundtrip_name.php', '<?php // round trip');
$listedNames = array_column(shortcode_layout_list($pdo, 'collection'), 'name');
$runtimePath = post_cat__find_layout_template($pdo, 'roundtrip_name');
$check(in_array('roundtrip_name', $listedNames, true) && $runtimePath === realpath($collectionDirectory . '/roundtrip_name.php'), 'a contract-valid saved filename is listed and runtime-resolved unchanged');

$pdo->presetRows = [[
    'id' => 42,
    'title' => 'Uses dependency',
    'slug' => 'uses-dependency',
    'meta' => '{"layout":"dependency_layout"}',
]];
file_put_contents($collectionDirectory . '/dependency_layout.php', '<?php // dependency');
$dependencyMessage = '';
try {
    shortcode_layout_delete_files($pdo, 'collection', ['dependency_layout.php']);
} catch (Throwable $error) {
    $dependencyMessage = $error->getMessage();
}
$check(file_exists($collectionDirectory . '/dependency_layout.php') && str_contains($dependencyMessage, 'active preset'), 'active preset dependency preflight blocks deletion before filesystem changes');
$pdo->presetRows = [];

$pdo->presetRows = [[
    'id' => 43,
    'title' => 'Direct shortcode dependency',
    'type' => 'article',
    'meta' => '{}',
    'content' => '[post_cat_shortcode category="news" layout="direct_layout"]',
]];
file_put_contents($collectionDirectory . '/direct_layout.php', '<?php // direct');
try {
    shortcode_layout_delete_files($pdo, 'collection', ['direct_layout.php']);
} catch (Throwable $error) {
}
$check(file_exists($collectionDirectory . '/direct_layout.php'), 'supported direct post_cat_shortcode layout references block deletion');
$pdo->presetRows = [];

$referenceForms = [
    '&#91;post_cat_shortcode category="news" layout="entity_layout"&#93;' => 'entity_layout',
    '[[widget:post_cat_shortcode layout="widget_direct_layout"]]' => 'widget_direct_layout',
    '[[widget:post_list layout="widget_list_layout"]]' => 'widget_list_layout',
    '[[widget:post_cards layout="widget_cards_layout"]]' => 'widget_cards_layout',
    '[[widget:post_slider layout="widget_slider_layout"]]' => 'widget_slider_layout',
];
foreach ($referenceForms as $content => $layoutName) {
    $references = post_cat_shortcode_references($content);
    $check(($references[0]['attrs']['layout'] ?? '') === $layoutName, 'shared parser detects explicit layout in ' . $layoutName . ' form');
    $pdo->presetRows = [[
        'id' => 100,
        'title' => 'Reference form',
        'type' => 'article',
        'meta' => '{}',
        'content' => $content,
    ]];
    file_put_contents($collectionDirectory . '/' . $layoutName . '.php', '<?php // referenced');
    try {
        shortcode_layout_delete_files($pdo, 'collection', [$layoutName . '.php']);
    } catch (Throwable $error) {
    }
    $check(file_exists($collectionDirectory . '/' . $layoutName . '.php'), 'dependency preflight blocks ' . $layoutName . ' deletion');
}
$pdo->presetRows = [];
add_filter('shortcode_collection_layout_dependency_names', static fn(array $names): array => array_merge($names, ['declared_layout']));
file_put_contents($collectionDirectory . '/declared_layout.php', '<?php // declared');
try {
    shortcode_layout_delete_files($pdo, 'collection', ['declared_layout.php']);
} catch (Throwable $error) {
}
$check(file_exists($collectionDirectory . '/declared_layout.php'), 'dynamic plugins can declare collection layout dependency names');
$check(str_contains($source['manager'], "'sc_preset', 'article', 'page', 'theme'"), 'dependency scan includes every Core post type that can render layout shortcodes');

$atomicPath = $collectionDirectory . '/atomic_layout.php';
file_put_contents($atomicPath, '<?php // original');
$saved = shortcode_layout_atomic_save($pdo, 'collection', 'atomic_layout.php', '', '<?php // replacement');
$saveTemps = glob($collectionDirectory . '/.layout-save-*.tmp') ?: [];
$check($saved['file'] === 'atomic_layout.php' && file_get_contents($atomicPath) === '<?php // replacement' && $saveTemps === [], 'layout save atomically replaces the validated file and leaves no temporary file');
$outsideLayout = $fixtureRoot . '/outside-layout.php';
file_put_contents($outsideLayout, '<?php // outside');
symlink($outsideLayout, $collectionDirectory . '/symlink_layout.php');
try {
    shortcode_layout_atomic_save($pdo, 'collection', 'symlink_layout.php', '', '<?php // hostile replacement');
} catch (Throwable $error) {
}
$check(file_get_contents($outsideLayout) === '<?php // outside', 'atomic layout save rejects a symlink target without modifying its destination');
unlink($collectionDirectory . '/symlink_layout.php');

$recoverSource = $collectionDirectory . '/recover_layout.php';
file_put_contents($recoverSource, '<?php // recover');
$recoverOperation = str_repeat('a', 32);
$recoverStage = SHORTCODE_LAYOUT_QUARANTINE_PATH . '/' . $recoverOperation;
mkdir($recoverStage, 0700, true);
$recoverRetained = hash('sha256', 'recover_layout.php') . '.layout';
rename($recoverSource, $recoverStage . '/' . $recoverRetained);
file_put_contents($recoverStage . '/manifest.json', json_encode([
    'operation' => $recoverOperation,
    'scope' => 'collection',
    'state' => 'staging',
    'files' => [['source' => $recoverSource, 'quarantined' => $recoverRetained]],
]));
shortcode_layout_list($pdo, 'collection');
$check(is_file($recoverSource) && !is_dir($recoverStage), 'next manager list operation deterministically restores an interrupted staging manifest');

$themeSwitchSource = $sectionDirectory . '/switched.hero.php';
file_put_contents($themeSwitchSource, '<?php // switched theme recovery');
$themeSwitchOperation = str_repeat('b', 32);
$themeSwitchStage = SHORTCODE_LAYOUT_QUARANTINE_PATH . '/' . $themeSwitchOperation;
mkdir($themeSwitchStage, 0700, true);
$themeSwitchRetained = hash('sha256', 'switched.hero.php') . '.layout';
rename($themeSwitchSource, $themeSwitchStage . '/' . $themeSwitchRetained);
file_put_contents($themeSwitchStage . '/manifest.json', json_encode([
    'operation' => $themeSwitchOperation,
    'scope' => 'section',
    'state' => 'staging',
    'owner' => shortcode_layout_section_identity($sectionDirectory),
    'files' => [['file' => 'switched.hero.php', 'source' => $fixtureRoot . '/untrusted-manifest-target.php', 'quarantined' => $themeSwitchRetained]],
]));
$themeBDirectory = VIEWS_BASE . '/theme-b/partials/shortcodes/section';
mkdir($themeBDirectory, 0775, true);
$GLOBALS['layout_contract_section_directory'] = $themeBDirectory;
shortcode_layout_list($pdo, 'section');
$check(is_file($themeSwitchSource) && !is_dir($themeSwitchStage), 'section recovery restores the recorded owning theme after an active-theme switch');
$GLOBALS['layout_contract_section_directory'] = $sectionDirectory;

file_put_contents($sectionDirectory . '/registered.hero.php', '<?php // registered');
file_put_contents($sectionDirectory . '/loose.hero.php', '<?php // unregistered');
$sectionLayouts = shortcode_layout_list($pdo, 'section');
$sectionTypes = array_column($sectionLayouts, 'registered', 'name');
$check(($sectionTypes['registered.hero'] ?? false) === true && ($sectionTypes['loose.hero'] ?? true) === false, 'section listing behavior distinguishes registered and unregistered active-theme renderers');
$deleted = shortcode_layout_delete_files($pdo, 'section', ['loose.hero.php']);
$check($deleted === 1 && !file_exists($sectionDirectory . '/loose.hero.php'), 'section bulk deletion operates only on a validated active-theme renderer');

$publicEntries = scandir($collectionDirectory) ?: [];
$quarantineOperations = glob(SHORTCODE_LAYOUT_QUARANTINE_PATH . '/*', GLOB_ONLYDIR) ?: [];
$quarantinedPhp = glob(SHORTCODE_LAYOUT_QUARANTINE_PATH . '/*/*.php') ?: [];
$check($quarantineOperations !== [] && $quarantinedPhp === [] && !array_filter($publicEntries, static fn(string $entry): bool => str_starts_with($entry, '.layout-delete-')), 'successful deletion retains staged content only in non-public, non-PHP quarantine files');

$safeFallback = ADMIN_BASE_PATH . '/?page=admin/shortcodes/index';
$safeCandidate = ADMIN_BASE_PATH . '/?page=admin/shortcodes/index&tab=layouts';
$check(adiwira_safe_return_to($safeCandidate, $safeFallback) === $safeCandidate, 'redirect sanitizer preserves a contained admin return URL');
foreach (['/outside', '//evil.test/x', '/%2f%2fevil.test', '/%252f%252fevil.test', '/secure-admin\\evil', "/secure-admin/ok\0bad"] as $unsafeReturn) {
    $check(adiwira_safe_return_to($unsafeReturn, $safeFallback) === $safeFallback, 'redirect sanitizer rejects unsafe return URL: ' . addcslashes($unsafeReturn, "\0"));
}
$check(adiwira_safe_return_to(['bad'], $safeFallback) === $safeFallback, 'redirect sanitizer rejects malformed array input');
$normalizedReturn = adiwira_safe_return_to('/secure-admin//tools/?page=plugins', $safeFallback);
$check($normalizedReturn === '/secure-admin/tools/?page=plugins', 'redirect sanitizer returns a normalized contained admin path');
foreach (['/secure-admin/../outside', '/secure-admin/%2e%2e/outside', '/secure-admin/%252e%252e/outside', '/secure-admin/./tools'] as $dotReturn) {
    $check(adiwira_safe_return_to($dotReturn, $safeFallback) === $safeFallback, 'redirect sanitizer rejects raw and encoded dot segment: ' . $dotReturn);
}

$realCollectionDirectory = $collectionDirectory . '-real';
rename($collectionDirectory, $realCollectionDirectory);
symlink($realCollectionDirectory, $collectionDirectory);
$check(shortcode_layout_directory($pdo, 'collection') === null, 'collection root final-directory symlink is rejected');
unlink($collectionDirectory);
rename($realCollectionDirectory, $collectionDirectory);

$check(str_contains($source['index'], "if (\$tab === 'layouts' && !\$isAdmin)") && str_contains($source['editor'], 'adiwira_require_admin($pdo, false)'), 'layouts listing and editor are administrator-only');
$check(str_contains($source['delete'], 'adiwira_require_admin($pdo, true)') && str_contains($source['bulk'], 'adiwira_require_admin($pdo, true)'), 'single and bulk layout deletion endpoints require administrators');
$check(str_contains($source['index'], '$layoutPerPage = 15') && str_contains($source['index'], '$layoutPagingItems'), 'both layout scopes paginate at 15 rows with numbered pagination');
$check(str_contains($source['index'], '$pageQuery = $layoutQuery') && str_contains($source['index'], "\$pageQuery['p'] = \$pageNumber"), 'layout pagination preserves validated scope, search, and filter query values');
$check(str_contains($source['index'], "['page' => 'admin/shortcodes/index', 'tab' => 'layouts', 'scope' => \$layoutScope]") && str_contains($source['index'], "\$layoutQuery['q']") && str_contains($source['index'], "\$layoutQuery['filter']"), 'layout return URLs preserve scope, search, and filter state');
$check(substr_count($source['index'], "'return_to' => \$layoutReturnTo") >= 2 && str_contains($source['index'], 'name="return_to" value="<?= h($layoutReturnTo)'), 'add, edit, single delete, and bulk delete carry the filtered return URL');
$check(str_contains($source['index'], "['registered', 'unregistered']") === false && str_contains($source['manager'], "['registered', 'unregistered']") && str_contains($source['manager'], "['builtin', 'custom']"), 'scope-specific filters distinguish registered sections and built-in collection layouts');
$check(str_contains($source['manager'], 'theme_section_definitions()') && str_contains($source['manager'], "array_key_exists(\$name, \$definitions)"), 'section registration filtering uses the validated runtime registry');
$check(str_contains($source['index'], 'id="layout-select-all"') && str_contains($source['index'], 'class="layout-row-check"') && str_contains($source['index'], 'name="files[]"'), 'layout rows expose checkboxes and select-all behavior');

$check(str_contains($source['bulk'], "REQUEST_METHOD") && str_contains($source['bulk'], 'adiwira_csrf_validate($csrf)'), 'bulk layout deletion requires POST and CSRF');
$check(strpos($source['bulk'], 'adiwira_safe_return_to') < strpos($source['bulk'], 'adiwira_csrf_validate'), 'bulk deletion sanitizes return_to before CSRF failures');
$check(str_contains($source['bulk'], "\$_POST['action'] !== 'delete'") && str_contains($source['bulk'], "is_array(\$files)"), 'bulk deletion accepts only the delete action and an array selection');
$check(str_contains($source['manager'], "in_array(\$name, shortcode_layout_builtin_names(), true)") && str_contains($source['manager'], "\$scope === 'collection'"), 'bulk validation protects built-in collection layouts');
$check(str_contains($source['manager'], 'theme_section_theme_directory($pdo)') && str_contains($source['manager'], 'shortcode_layout_path_is_within($realPath, $directory)') && str_contains($source['manager'], 'is_link($candidate)'), 'bulk validation confines active-theme sections and rejects symlink targets');
$check(strpos($source['manager'], '$targets = []') < strpos($source['manager'], '$operationId =') && strpos($source['manager'], '$targets[$file] = $realPath') < strpos($source['manager'], '$operationId ='), 'all selected paths and names are prevalidated before filesystem changes');
$check(str_contains($source['manager'], 'layout-quarantine') && str_contains($source['manager'], 'shortcode_layout_atomic_json_write') && str_contains($source['manager'], 'array_reverse($moved, true)') && str_contains($source['manager'], 'rename($staged, $source)'), 'bulk deletion uses atomic manifests in non-public quarantine and rolls back the complete visible set on staging failure');
$check(str_contains($source['manager'], 'shortcode_layout_section_identity') && str_contains($source['manager'], "'theme_folder'") && str_contains($source['manager'], 'shortcode_layout_path_chain_has_symlink'), 'section quarantine manifests bind recovery to a canonical non-symlinked installed-theme identity');
$check(str_contains($source['manager'], 'shortcode_layout_sync_directory'), 'atomic layout and quarantine renames attempt directory fsync where supported');
$check(str_contains($source['delete'], 'shortcode_layout_delete_files($pdo, $layoutScope, [$fileName])'), 'single delete remains available through the shared hardened deletion path');
$check(!str_contains($source['index'], 'window.alert(') && str_contains($source['index'], 'NewNotifToast') && str_contains($source['index'], 'aria-live="polite"'), 'layout bulk validation uses NewNotif toast with an accessible inline fallback');

foreach (['Search layout file or name…', 'All layout types', 'All registration statuses', 'Built-in', 'Unregistered', 'Delete selected layouts', 'No layouts selected.'] as $translation) {
    $check(substr_count($source['translations'], "'" . $translation . "'") >= 2, 'translation seeds include ' . $translation);
}

foreach (['post_add', 'post_save', 'page_add', 'page_save', 'theme_add', 'theme_save'] as $mutationEndpoint) {
    $mutationSource = $source[$mutationEndpoint];
    $check(str_contains($mutationSource, 'shortcode_collection_layout_content_mutation($pdo'), $mutationEndpoint . ' uses the shared layout-aware mutation boundary');
    $check(strpos($mutationSource, 'shortcode_collection_layout_content_mutation($pdo') < strpos($mutationSource, "do_action('admin_"), $mutationEndpoint . ' releases the layout mutation boundary before lifecycle hooks and redirects');
}

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
$removeTree($fixtureRoot);

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " assertion(s) failed.\n");
    exit(1);
}
echo "RESULT: ALL PASS\n";
