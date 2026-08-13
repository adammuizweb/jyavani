<?php
declare(strict_types=1);

final class PrivateFileShortcodeContractStatement extends PDOStatement
{
    public function __construct(private array $row) {}
    public function execute(?array $params = null): bool { return true; }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        return $this->row;
    }
}

final class PrivateFileShortcodeContractPdo extends PDO
{
    public function __construct() {}
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new PrivateFileShortcodeContractStatement([
            'id' => 123,
            'url' => '/static/files/example.pdf',
            'filename' => 'example.pdf',
            'mime' => 'application/pdf',
            'ext' => 'pdf',
            'title' => 'Example PDF',
            'size' => 1024,
            'visibility' => 'public',
            'storage_disk' => 'public',
            'access_scope' => 'public',
        ]);
    }
}

require_once dirname(__DIR__) . '/cfg/helpers/private_file_shortcodes.php';
require_once dirname(__DIR__) . '/cfg/helpers/role_helpers.php';

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};

$pdo = new PrivateFileShortcodeContractPdo();
$sample = '<pre><code>[private_pdf id="123"]</code></pre>';
$check(private_file_shortcode_expand($sample, $pdo) === $sample, 'shortcodes remain literal inside pre and code examples');
$nestedSample = '<pre><code>example</code>[private_pdf id="123"]</pre>';
$check(private_file_shortcode_expand($nestedSample, $pdo) === $nestedSample, 'shortcodes remain literal throughout an enclosing pre block');
foreach (['code', 'script', 'style', 'textarea'] as $protectedTag) {
    $protectedSample = '<' . $protectedTag . '>[private_pdf id="123"]</' . $protectedTag . '>';
    $check(private_file_shortcode_expand($protectedSample, $pdo) === $protectedSample, 'shortcodes remain literal inside ' . $protectedTag . ' elements');
}

$rendered = private_file_shortcode_expand('<p>[private_pdf id="123" mode="link"]</p>', $pdo);
$check(str_contains($rendered, '/private/pdf/view/?id=123'), 'shortcodes still expand in normal article content');
$check(!str_contains($rendered, '[private_pdf'), 'expanded shortcode is removed from normal article content');
$check(content_access_scope_allows_role('author', 'editorial'), 'authors belong to the content team scope');
$check(content_access_scope_allows_role('editor', 'editorial'), 'editors belong to the content team scope');
$check(content_access_scope_allows_role('admin', 'editorial'), 'administrators belong to the content team scope');
$check(content_access_scope_allows_role('author', 'employee'), 'legacy employee scope maps to the content team');
$check(content_access_scope_allows_role('editor', 'both'), 'legacy both scope maps to the content team');
$check(!content_access_scope_allows_role('author', 'admin'), 'admin scope rejects authors');
$check(!content_access_scope_allows_role('editor', 'admin'), 'admin scope rejects editors');
$check(content_access_scope_allows_role('admin', 'admin'), 'admin scope allows administrators');
$check(!content_access_scope_allows_role('admin', 'unknown'), 'unknown scopes fail closed');
$check(content_access_scope_label('editorial') === 'Content Team', 'editorial scope has an unambiguous user-facing label');
$check(content_access_scope_label('admin') === 'Administrator', 'admin scope has an explicit user-facing label');
$translationSeed = file_get_contents(dirname(__DIR__) . '/schema/translations.sql');
$check($translationSeed !== false && str_contains($translationSeed, "('default', 'Content Team', 'Tim Konten', 'id')"), 'Indonesian content team translation is spelled correctly');

exit($failures === [] ? 0 : 1);
