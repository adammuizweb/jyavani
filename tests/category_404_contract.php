<?php
declare(strict_types=1);

$source = file_get_contents(__DIR__ . '/../app/controllers/CategoryController.php');
if ($source === false) {
    fwrite(STDERR, "FAIL unable to read CategoryController\n");
    exit(1);
}

$failures = [];
if (substr_count($source, "require __DIR__ . '/../frontend_404.php';") < 2) {
    $failures[] = 'category failures use the shared frontend 404 renderer';
}
if (str_contains($source, 'Kategori tidak ditemukan')) {
    $failures[] = 'CategoryController does not contain an inline category 404';
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL {$failure}\n");
    }
    exit(1);
}

echo "PASS category failures use the shared frontend 404 renderer\n";
echo "PASS CategoryController does not contain an inline category 404\n";
