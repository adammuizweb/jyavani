<?php
declare(strict_types=1);

$root = dirname(__DIR__);
define('ADMIN_BASE_PATH', '/hidden-admin');
require_once $root . '/cfg/helpers/hooks.php';
require_once $root . '/cfg/helpers/content_row_actions.php';
require_once $root . '/cfg/helpers/asset_detail_actions.php';

$failures = [];
$check = static function (bool $passed, string $message) use (&$failures): void {
    echo ($passed ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$passed) $failures[] = $message;
};

$public = asset_detail_action_context('media', 'admin.media.detail', [
    'id' => 7, 'title' => 'Public image', 'filename' => 'image.jpg',
    'visibility' => 'public', 'storage_disk' => 'public', 'access_scope' => 'public',
], '/static/img/image.jpg', 11);
$protected = asset_detail_action_context('file', 'admin.file.modal.detail', [
    'id' => 8, 'title' => 'Protected file', 'filename' => 'file.pdf',
    'visibility' => 'public', 'storage_disk' => 'public', 'access_scope' => 'editorial',
], '/private/pdf/view/?id=8', 11);
$check($public['schema'] === 1 && $public['is_public'] === true
    && $public['public_url'] === '/static/img/image.jpg' && $public['actor_id'] === 11,
    'public asset context exposes one bounded public URL');
$check($protected['is_public'] === false && $protected['public_url'] === null,
    'protected asset context never exposes its controller URL');

$invalidRejected = false;
try {
    asset_detail_action_context('media', 'admin.file.detail', [], '/asset', 1);
} catch (InvalidArgumentException) {
    $invalidRejected = true;
}
$check($invalidRejected, 'resource and surface combinations are validated');

add_filter('admin_asset_detail_actions', static fn(array $items): array => [
    ['key' => 'example.valid', 'label' => 'QR', 'url' => '/hidden-admin/?page=admin%2Ftools%2Fexample#payload', 'title' => 'Create QR'],
    ['key' => 'example.external', 'label' => 'Bad', 'url' => 'https://evil.test/'],
    ['key' => 'example.valid', 'label' => 'Duplicate', 'url' => '/hidden-admin/?page=admin%2Ftools%2Fexample'],
]);
$html = asset_detail_actions_render(new PDO('sqlite::memory:'), $public);
$check(substr_count($html, '<a') === 1 && str_contains($html, 'asset-detail-extension-action')
    && str_contains($html, '>QR</a>') && !str_contains($html, 'evil.test') && !str_contains($html, 'Duplicate'),
    'renderer accepts unique internal dashboard actions and rejects unsafe items');

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " asset detail action contract check(s) failed.\n");
    exit(1);
}
echo "RESULT: ALL PASS\n";
