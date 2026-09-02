<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/cfg/helpers/migration_helper.php';

$fixture = tempnam(sys_get_temp_dir(), 'jy-migration-checksum-');
if (!is_string($fixture)) throw new RuntimeException('Unable to create checksum fixture.');

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};
$file = static fn(string $path): array => [
    'path' => $path,
    'checksum' => (string)hash_file('sha256', $path),
];

try {
    $lf = "<?php\nreturn static function (): void {};\n";
    $crlf = str_replace("\n", "\r\n", $lf);
    file_put_contents($fixture, $lf);
    $lfFile = $file($fixture);
    $check(plugin_migration_checksum_matches_file(hash('sha256', $lf), $lfFile),
        'migration history accepts an exact checksum');
    $check(plugin_migration_checksum_matches_file(hash('sha256', $crlf), $lfFile),
        'migration history accepts an otherwise identical historical CRLF checkout');

    file_put_contents($fixture, $crlf);
    $crlfFile = $file($fixture);
    $check(plugin_migration_checksum_matches_file(hash('sha256', $lf), $crlfFile),
        'migration history accepts an otherwise identical historical LF checkout');

    file_put_contents($fixture, "<?php\r\nreturn 1;\n");
    $check(!plugin_migration_checksum_matches_file(hash('sha256', "<?php\nreturn 1;\n"), $file($fixture)),
        'mixed line endings cannot bypass immutable migration history');
    file_put_contents($fixture, "<?php\rreturn 1;\r");
    $check(!plugin_migration_checksum_matches_file(hash('sha256', "<?php\nreturn 1;\n"), $file($fixture)),
        'lone carriage returns cannot bypass immutable migration history');
    file_put_contents($fixture, "<?php\nreturn 2;\n");
    $check(!plugin_migration_checksum_matches_file(hash('sha256', "<?php\nreturn 1;\n"), $file($fixture)),
        'content changes remain rejected when line endings are canonical');
    $check(!plugin_migration_checksum_matches_file(str_repeat('A', 64), $file($fixture)),
        'malformed ledger checksums fail closed');
} finally {
    @unlink($fixture);
}

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " plugin migration checksum checks failed.\n");
    exit(1);
}

echo "Plugin migration checksum contract passed.\n";
