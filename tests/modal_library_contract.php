<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$files = [
    'image' => (string)file_get_contents($root . '/dashboard/admin/modal_img/list_modal.php'),
    'file' => (string)file_get_contents($root . '/dashboard/admin/modal_file/list_modal.php'),
    'file_index' => (string)file_get_contents($root . '/dashboard/admin/modal_file/index.php'),
    'media_list' => (string)file_get_contents($root . '/dashboard/admin/media/list.php'),
    'file_list' => (string)file_get_contents($root . '/dashboard/admin/file/list.php'),
    'media_index' => (string)file_get_contents($root . '/dashboard/admin/media/index.php'),
    'manager_file_index' => (string)file_get_contents($root . '/dashboard/admin/file/index.php'),
    'media_single' => (string)file_get_contents($root . '/dashboard/admin/media/single.php'),
    'file_single' => (string)file_get_contents($root . '/dashboard/admin/file/single.php'),
    'modal_media_single' => (string)file_get_contents($root . '/dashboard/admin/modal_img/single_modal.php'),
    'modal_file_single' => (string)file_get_contents($root . '/dashboard/admin/modal_file/single_modal.php'),
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

foreach (['media_list', 'file_list'] as $type) {
    $check(str_contains($files[$type], '$perPageOptions = [20, 50, 100, 200]'), $type . ' manager exposes supported page sizes');
    $check(str_contains($files[$type], "?? 20"), $type . ' manager defaults to 20 items');
    $check(str_contains($files[$type], 'id="media-per-page"'), $type . ' manager renders a page-size selector');
    $check(str_contains($files[$type], "_e('Previous')") && str_contains($files[$type], "_e('Next')"), $type . ' manager pager includes previous and next controls');
    $check(str_contains($files[$type], 'media-list-range'), $type . ' manager reports the visible result range');
}
$check(str_contains($files['media_index'], "'&per_page=' + encodeURIComponent(perPage)"), 'media manager preserves page size during AJAX refresh');
$check(str_contains($files['manager_file_index'], "'&per_page=' + encodeURIComponent(perPage)"), 'file manager preserves page size during AJAX refresh');
$check(str_contains($files['media_index'], 'AbortController') && str_contains($files['manager_file_index'], 'AbortController'), 'manager lists cancel stale AJAX refreshes');
$check(str_contains($files['media_list'], 'window.mediaUi.refreshListPanel({ silent: silent, q: q, page: p, perPage: perPage })'), 'media list delegates hosted refreshes to one request coordinator');
foreach (['media_single', 'file_single', 'modal_media_single', 'modal_file_single'] as $type) {
    $check(str_contains($files[$type], 'asset-detail'), $type . ' uses the enhanced detail workspace');
    $check(str_contains($files[$type], 'asset-detail-open'), $type . ' exposes a prominent open action');
}
$fileFormStart = strpos($files['file_single'], '<form id="file-edit-form"');
$fileFormEnd = strpos($files['file_single'], '</form>', $fileFormStart ?: 0);
$fileSaveButton = strpos($files['file_single'], 'id="file-save-btn"');
$check($fileFormStart !== false && $fileFormEnd !== false && $fileSaveButton !== false && $fileSaveButton < $fileFormEnd, 'file manager actions remain inside the metadata form');
$check(str_contains($files['file_single'], 'id="file-url-path"'), 'file manager copy action has an addressable URL field');
$check(str_contains($files['modal_file_single'], 'querySelector(\'select[name="access_scope"]\')')
    && str_contains($files['modal_file_single'], 'querySelector(\'input[name="is_downloadable"]\')'), 'file modal insert reads current editable access values');
$check(str_contains($files['modal_file_single'], "__('Insert this file without saving its metadata changes?')")
    && str_contains($files['modal_file_single'], "__('Insert without saving')"), 'file modal explains that Insert does not persist dirty metadata');
$check(str_contains($files['css'], '.asset-detail-card') && str_contains($files['css'], '.media-list-footer'), 'shared stylesheet defines detail and list pagination enhancements');

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " modal library contract check(s) failed.\n");
    exit(1);
}
echo "RESULT: ALL PASS\n";
