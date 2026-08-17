<?php
declare(strict_types=1);

$source = file_get_contents(__DIR__ . '/../app/controllers/SearchController.php');
if ($source === false) {
    fwrite(STDERR, "FAIL unable to read SearchController\n");
    exit(1);
}

$failures = [];
if (str_contains($source, '$page_title = \'Pencarian: \' . $qEsc;')) {
    $failures[] = 'search document titles do not reuse HTML-escaped query text';
}
if (!str_contains($source, "\$page_title = __('Search') . ': ' . \$q;")) {
    $failures[] = 'search document titles retain raw query text until layout escaping';
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL {$failure}\n");
    }
    exit(1);
}

echo "PASS search document titles do not reuse HTML-escaped query text\n";
echo "PASS search document titles retain raw query text until layout escaping\n";
