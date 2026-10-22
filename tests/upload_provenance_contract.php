<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};

$endpoints = [
    'file library' => $root . '/dashboard/admin/upload_file.php',
    'plugin package' => $root . '/dashboard/admin/plugins/upload.php',
    'theme package' => $root . '/dashboard/admin/themes/upload.php',
];

foreach ($endpoints as $label => $path) {
    $source = (string)file_get_contents($path);
    $uploadCheck = strpos($source, 'is_uploaded_file(');
    $moveCall = strpos($source, 'move_uploaded_file(');
    $check($uploadCheck !== false, $label . ' requires PHP upload provenance validation');
    $check($moveCall === false || $uploadCheck !== false && $uploadCheck < $moveCall,
        $label . ' validates upload provenance before moving the file');
}

if ($failures !== []) exit(1);
echo "RESULT: ALL PASS\n";
