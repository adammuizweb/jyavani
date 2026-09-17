<?php
declare(strict_types=1);

$source = (string)file_get_contents(dirname(__DIR__) . '/dashboard/admin/themes/customize.php');
$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};

$signature = 'string $activePartial, int $uid, array $sidebarZones = [], array $pagesList = []';
$check(str_contains($source, $signature), 'zone editor receives the authorized user ID explicitly');
$check(substr_count($source, '$activePartial, $uid, $zones, $pagesList)') === 3, 'header, partial, and footer editors pass the authorized user ID');
$check(str_contains($source, "'user_id' => \$uid"), 'extension editor actions receive the explicit user ID');

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " Theme Customize context contract check(s) failed.\n");
    exit(1);
}

echo "Theme Customize context contract passed.\n";
