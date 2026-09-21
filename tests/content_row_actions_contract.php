<?php
declare(strict_types=1);

define('ADMIN_BASE_PATH', '/hidden-admin');
$GLOBALS['_content_row_actions_filter'] = static fn(array $items): array => $items;

function apply_filters(string $name, mixed $value, mixed ...$args): mixed
{
    if ($name !== 'admin_content_row_actions') return $value;
    return ($GLOBALS['_content_row_actions_filter'])($value, ...$args);
}

require_once dirname(__DIR__) . '/cfg/helpers/content_row_actions.php';

$failures = [];
$check = static function (bool $passed, string $message) use (&$failures): void {
    echo ($passed ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$passed) $failures[] = $message;
};

$pdo = new PDO('sqlite::memory:');
$row = ['id' => 7, 'title' => 'Example'];
$context = ['schema' => 1, 'content_type' => 'article', 'actor_id' => 3, 'is_public' => true];

$GLOBALS['_content_row_actions_filter'] = static fn(array $items): array => [
    ['key' => 'example.qr', 'label' => 'QR & share', 'url' => '/hidden-admin/?page=admin/tools/example&value=%2Fpost%2F', 'title' => 'Create "QR"'],
    ['key' => 'example.second', 'label' => 'Second', 'url' => '/hidden-admin/?page=admin/tools/example'],
];
$html = content_row_actions_render($pdo, $row, $context);
$check(str_contains($html, 'QR &amp; share') && str_contains($html, 'Create &quot;QR&quot;'), 'labels and titles are escaped');
$check(substr_count($html, 'class="adam-ubah"') === 2 && substr_count($html, 'muted-divider') === 1, 'valid actions render in a separated non-destructive row');

$GLOBALS['_content_row_actions_filter'] = static fn(array $items): array => [
    ['key' => 'duplicate', 'label' => 'First', 'url' => '/hidden-admin/?page=admin/tools/example'],
    ['key' => 'duplicate', 'label' => 'Duplicate', 'url' => '/hidden-admin/?page=admin/tools/example'],
    ['key' => 'external', 'label' => 'External', 'url' => 'https://example.test/'],
    ['key' => 'external-admin', 'label' => 'External admin', 'url' => 'https://example.test/hidden-admin/?page=admin/tools/example'],
    ['key' => 'wrong-path', 'label' => 'Wrong', 'url' => '/dashboard/?page=admin/tools/example'],
    ['key' => 'missing-page', 'label' => 'Missing', 'url' => '/hidden-admin/?value=1'],
    ['key' => 'bad label', 'label' => "Bad\nLabel", 'url' => '/hidden-admin/?page=admin/tools/example'],
];
$html = content_row_actions_render($pdo, $row, $context);
$check(substr_count($html, 'class="adam-ubah"') === 1 && str_contains($html, 'First'), 'duplicate and unsafe descriptors are rejected');

$malformed = array_fill(0, 65, ['key' => '', 'label' => '', 'url' => '']);
$GLOBALS['_content_row_actions_filter'] = static fn(array $items): array => [...$malformed, [
    'key' => 'valid.after-invalid', 'label' => 'Valid', 'url' => '/hidden-admin/?page=admin/tools/example',
]];
$check(str_contains(content_row_actions_render($pdo, $row, $context), '>Valid</a>'), 'invalid descriptors do not consume the valid action limit');

$check(content_row_actions_public_url('/article/?page=2') === '/article/?page=2'
    && content_row_actions_public_url('//evil.test/') === null
    && content_row_actions_public_url('https://evil.test/') === null
    && content_row_actions_public_url('/safe/../admin/') === null
    && content_row_actions_public_url('/safe/%2e%2e/admin/') === null
    && content_row_actions_public_url('/safe/%252e%252e/admin/') === null
    && content_row_actions_public_url('/%252f%252fevil.test/') === null
    && content_row_actions_public_url('/safe/%0dheader/') === null
    && content_row_actions_public_url('/safe/%255cevil/') === null, 'public URLs are normalized to safe root-relative same-site paths');

$GLOBALS['_content_row_actions_filter'] = static fn(array $items): string => 'invalid';
$check(content_row_actions_render($pdo, $row, $context) === '', 'non-list filter output fails closed');

$root = dirname(__DIR__);
$article = (string)file_get_contents($root . '/dashboard/admin/posts/index.php');
$page = (string)file_get_contents($root . '/dashboard/admin/pages/index.php');
$theme = (string)file_get_contents($root . '/dashboard/admin/themes/index.php');
foreach (['article' => $article, 'page' => $page, 'theme' => $theme] as $type => $source) {
    $check(str_contains($source, "'content_type' => '" . $type . "'")
        && str_contains($source, "'is_public' => \$status === 'published'")
        && str_contains($source, 'content_row_actions_render($pdo'), $type . ' list provides the schema-1 action context');
    $extension = strpos($source, '$contentRowActions !==');
    $delete = strpos($source, 'js-' . ($type === 'article' ? 'post' : $type) . '-delete');
    $check($extension !== false && $delete !== false && $extension < $delete, $type . ' action renders before Delete');
}

$config = (string)file_get_contents($root . '/cfg/config.php');
$docs = (string)file_get_contents($root . '/cms.md');
$check(str_contains($config, "helpers/content_row_actions.php"), 'Core loads the content row-action helper');
$check(str_contains($docs, "'admin_content_row_actions'"), 'extension contract is documented');

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " check(s) failed.\n");
    exit(1);
}
echo "RESULT: ALL PASS\n";
