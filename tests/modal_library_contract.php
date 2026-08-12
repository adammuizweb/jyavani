<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$files = [
    'image' => (string)file_get_contents($root . '/dashboard/admin/modal_img/list_modal.php'),
    'file' => (string)file_get_contents($root . '/dashboard/admin/modal_file/list_modal.php'),
    'file_index' => (string)file_get_contents($root . '/dashboard/admin/modal_file/index.php'),
    'css' => (string)file_get_contents($root . '/public/static/dashboard/css/style.css'),
];
$failures = [];
$check = static function (bool $passed, string $message) use (&$failures): void {
    echo ($passed ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$passed) $failures[] = $message;
};

foreach (['image', 'file'] as $type) {
    $check(str_contains($files[$type], '$perPageOptions = [20, 50, 100, 200]'), $type . ' modal exposes the supported page sizes');
    $check(str_contains($files[$type], ": 20;"), $type . ' modal defaults to 20 items');
    $check(str_contains($files[$type], 'id="mdlib-per-page"'), $type . ' modal renders a page-size selector');
    $check(str_contains($files[$type], 'mdlib-list-skeleton'), $type . ' modal uses skeleton loading');
}
$check(str_contains($files['image'], 'loading="lazy" decoding="async"'), 'image thumbnails use native lazy loading');
$check(str_contains($files['image'], 'AbortController'), 'image list cancels stale requests');
$check(str_contains($files['file_index'], 'fileListSkeleton()'), 'hosted file modal uses card skeletons');
$check(str_contains($files['file_index'], 'return loadList(true, url || lastListUrl);'), 'hosted file modal retains the current list URL');
$check(str_contains($files['file_index'], 'document.createDocumentFragment()')
    && str_contains($files['file_index'], 'host.replaceChildren(lastListFragment)'), 'failed file refreshes restore the live list DOM and its handlers');
$check(substr_count($files['file_index'], 'requestId !== listRequestSequence') >= 2, 'hosted file modal ignores stale successes and errors');
$viewPosition = strpos($files['file_index'], "host.setAttribute('data-view', 'list');");
$fetchPosition = strpos($files['file_index'], 'const replaced = await fetchIntoLibrary');
$check($viewPosition !== false && $fetchPosition !== false && $viewPosition > $fetchPosition, 'file modal marks list view only after a successful response');
$check(str_contains($files['css'], '.mdlib-list-skeleton--image') && str_contains($files['css'], '.mdlib-list-skeleton--file'), 'shared stylesheet defines both skeleton layouts');
$check(str_contains($files['css'], '.mdlib-pager button'), 'file pager buttons share pager styling');

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " modal library contract check(s) failed.\n");
    exit(1);
}
echo "RESULT: ALL PASS\n";
