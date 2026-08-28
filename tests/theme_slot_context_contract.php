<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$fixture = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'jy-theme-slot-context-' . bin2hex(random_bytes(8));
$themeRoot = $fixture . DIRECTORY_SEPARATOR . 'default';
mkdir($themeRoot . DIRECTORY_SEPARATOR . 'main', 0777, true);
$template = <<<'PHP'
<?php echo implode('|', [
    (string)($extension_value ?? 'missing'),
    (string)($__jy_slot_key ?? 'missing'),
    (string)($__jy_theme_folder ?? 'missing'),
    (string)($__jy_theme_source_folder ?? 'missing'),
    $pdo === null ? 'pdo:null' : ($pdo instanceof PDO ? 'pdo:core' : 'pdo:spoof'),
]);
PHP;
file_put_contents($themeRoot . DIRECTORY_SEPARATOR . 'header.php', $template);
file_put_contents($themeRoot . DIRECTORY_SEPARATOR . 'main' . DIRECTORY_SEPARATOR . 'homepage.php', $template);
file_put_contents($themeRoot . DIRECTORY_SEPARATOR . 'fallback.php', $template);

define('PUBLIC_PATH', $root . '/public');
define('VIEWS_BASE', $fixture);
require_once $root . '/cfg/helpers/hooks.php';
require_once $root . '/cfg/helpers/theme_helper.php';

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};
$remove = static function (string $path) use (&$remove): void {
    if (!is_dir($path)) return;
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $child = $path . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($child)) $remove($child);
        else @unlink($child);
    }
    @rmdir($path);
};

try {
    $resolver = static function (mixed $resolved, string $slotKey): mixed {
        if ($slotKey === 'contract.fallback') {
            return ['type' => 'theme_file', 'theme_folder' => 'incomplete', 'theme_file' => 'fallback.php'];
        }
        if ($slotKey === 'contract.pdo') {
            return ['type' => 'theme_file', 'theme_folder' => 'default', 'theme_file' => 'header.php'];
        }
        if ($slotKey === 'contract.custom') {
            return [
                'type' => 'custom_post',
                'post' => [
                    'type' => 'theme',
                    'content' => '{{extension_value}}|{{__jy_slot_key}}|{{__jy_theme_folder}}|{{__jy_theme_source_folder}}|{{pdo}}',
                ],
            ];
        }
        return $resolved;
    };
    add_filter('resolve_template', $resolver);

    $calls = [];
    $augment = static function (array $context, string $slotKey, ?string $folder, ?PDO $pdo) use (&$calls): array {
        $calls[] = [$slotKey, $folder, $pdo];
        $context['extension_value'] = 'plugin:' . $slotKey;
        $context['pdo'] = 'spoofed-pdo';
        $context['__jy_theme_folder'] = 'spoofed-folder';
        $context['__jy_theme_source_folder'] = 'spoofed-source';
        $context['__jy_slot_key'] = 'spoofed-slot';
        return $context;
    };
    $customPostContexts = [];
    $observeCustomPost = static function (array $post, string $slotKey, ?PDO $pdo, array $context) use (&$customPostContexts): array {
        $customPostContexts[] = [$slotKey, $pdo, $context];
        return $post;
    };
    add_filter('theme_slot_context', $augment);
    add_filter('theme_slot_post_data', $observeCustomPost);

    $spoofed = [
        'extension_value' => 'caller',
        'pdo' => 'caller-pdo',
        '__jy_theme_folder' => 'caller-folder',
        '__jy_theme_source_folder' => 'caller-source',
        '__jy_slot_key' => 'caller-slot',
    ];
    $contractPdo = new PDO('sqlite::memory:');
    $header = render_slot(null, 'header', $spoofed);
    $homepage = render_slot(null, 'main.homepage', $spoofed);
    $fallback = render_slot(null, 'contract.fallback', $spoofed);
    $custom = render_slot($contractPdo, 'contract.custom', $spoofed);
    $pdoFile = render_slot($contractPdo, 'contract.pdo', $spoofed);
    $check($header === 'plugin:header|header|default|default|pdo:null'
        && $homepage === 'plugin:main.homepage|main.homepage|default|default|pdo:null',
        'plugins can augment header and main.homepage context at the centralized render boundary');
    $check($fallback === 'plugin:contract.fallback|contract.fallback|incomplete|default|pdo:null'
        && $calls[2] === ['contract.fallback', 'incomplete', null],
        'fallback files retain the selected folder argument and expose the actual source folder as protected metadata');
    $check($custom === 'plugin:contract.custom|contract.custom|||'
        && $calls[3] === ['contract.custom', null, $contractPdo],
        'custom theme posts receive a null folder and cannot render spoofed reserved metadata');
    $customContext = $customPostContexts[0][2] ?? [];
    $check(count($customPostContexts) === 1
        && ($customPostContexts[0][1] ?? null) === $contractPdo
        && array_key_exists('pdo', $customContext) && $customContext['pdo'] === $contractPdo
        && array_key_exists('__jy_theme_folder', $customContext) && $customContext['__jy_theme_folder'] === null
        && array_key_exists('__jy_theme_source_folder', $customContext) && $customContext['__jy_theme_source_folder'] === null
        && ($customContext['__jy_slot_key'] ?? null) === 'contract.custom',
        'custom-post adapters receive canonical protected metadata before custom rendering');
    $check($pdoFile === 'plugin:contract.pdo|contract.pdo|default|default|pdo:core'
        && ($calls[4][2] ?? null) === $contractPdo,
        'theme files receive the render-boundary PDO instead of caller, filter, or global spoof values');
    $check(count($calls) === 5
        && $calls[0] === ['header', 'default', null]
        && $calls[1] === ['main.homepage', 'default', null],
        'the context filter runs exactly once per available slot with slot key, selected folder, and PDO');
    $check(render_slot(null, 'contract.unavailable', $spoofed) === '' && count($calls) === 5,
        'unavailable slots render empty without invoking the context filter');
    remove_filter('theme_slot_context', $augment);
    remove_filter('theme_slot_post_data', $observeCustomPost);

    $validBeforeMalformed = static function (array $context): array {
        $context['extension_value'] = 'untrusted-change';
        return $context;
    };
    $sharedObject = (object)['value' => 'before'];
    $malformedCalls = 0;
    $malformed = static function (array $context) use (&$malformedCalls): string {
        $malformedCalls++;
        $context['shared_object']->value = 'mutated';
        return 'not-an-array';
    };
    add_filter('theme_slot_context', $validBeforeMalformed);
    add_filter('theme_slot_context', $malformed, 20);
    $malformedResult = render_slot(null, 'header', [
        'extension_value' => 'caller',
        'shared_object' => $sharedObject,
        '__jy_slot_key' => 'spoofed-slot',
    ]);
    $check($malformedResult === 'caller|header|default|default|pdo:null' && $malformedCalls === 1,
        'a malformed return discards the filtered array value and still applies protected metadata exactly once');
    $check($sharedObject->value === 'mutated',
        'array-value fallback does not claim to roll back mutations to nested object references');
    remove_filter('theme_slot_context', $validBeforeMalformed);
    remove_filter('theme_slot_context', $malformed, 20);

    $validBeforeThrow = static function (array $context): array {
        $context['extension_value'] = 'untrusted-change';
        return $context;
    };
    $throwingCalls = 0;
    $throwing = static function (array $context) use (&$throwingCalls): array {
        $throwingCalls++;
        throw new RuntimeException('contract listener failure');
    };
    add_filter('theme_slot_context', $validBeforeThrow);
    add_filter('theme_slot_context', $throwing, 20);
    $throwingResult = render_slot(null, 'main.homepage', ['extension_value' => 'caller', 'pdo' => 'spoofed-pdo']);
    $check($throwingResult === 'caller|main.homepage|default|default|pdo:null' && $throwingCalls === 1,
        'a throwing filter restores the pre-filter array value without suppressing rendering or duplicating invocation');
    remove_filter('theme_slot_context', $validBeforeThrow);
    remove_filter('theme_slot_context', $throwing, 20);

    $helper = (string)file_get_contents($root . '/cfg/helpers/theme_helper.php');
    $renderStart = strpos($helper, 'function render_slot(');
    $renderEnd = strpos($helper, '/**', $renderStart);
    $renderSource = $renderStart !== false && $renderEnd !== false ? substr($helper, $renderStart, $renderEnd - $renderStart) : '';
    $check(substr_count($renderSource, "apply_filters('theme_slot_context'") === 1,
        'render_slot contains one context-filter invocation');
    $check(!str_contains($renderSource, 'theme_operation_acquire')
        && !str_contains($renderSource, 'theme_operation_release')
        && !str_contains($renderSource, 'theme_lifecycle_reader_'),
        'slot context filtering preserves request-lifetime lifecycle locking semantics');

    $docs = (string)file_get_contents($root . '/cms.md');
    $check(str_contains($docs, "'theme_slot_context'")
        && str_contains($docs, '?string $folder')
        && str_contains($docs, '?PDO $pdo')
        && str_contains($docs, 'passed to each filter callback by value')
        && str_contains($docs, 'not a deep transactional rollback')
        && str_contains($docs, '`__jy_theme_source_folder`'),
        'the exact API, value-fallback limit, and protected metadata contract are documented');
    remove_filter('resolve_template', $resolver);
} finally {
    $remove($fixture);
}

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " assertion(s) failed.\n");
    exit(1);
}
echo "RESULT: ALL PASS\n";
