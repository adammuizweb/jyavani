<?php
declare(strict_types=1);

$dsn = getenv('JY_TEST_MYSQL_DSN');
if (!is_string($dsn) || trim($dsn) === '') {
    echo "SKIP time system MySQL contract requires JY_TEST_MYSQL_DSN\n";
    exit(0);
}
if (!extension_loaded('pdo_mysql')) {
    fwrite(STDERR, "FAIL JY_TEST_MYSQL_DSN is set but pdo_mysql is unavailable\n");
    exit(1);
}

require_once dirname(__DIR__) . '/cfg/helpers/time_helpers.php';
$user = getenv('JY_TEST_MYSQL_USER');
$password = getenv('JY_TEST_MYSQL_PASSWORD');
$pdo = new PDO($dsn, is_string($user) ? $user : '', is_string($password) ? $password : '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$original = (string)$pdo->query('SELECT @@SESSION.time_zone')->fetchColumn();
$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};

try {
    $utcApplied = app_db_set_session_timezone($pdo, 'UTC');
    $utcDelta = abs((int)$pdo->query('SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW())')->fetchColumn());
    $check(in_array($utcApplied, ['UTC', '+00:00'], true) && $utcDelta <= 1,
        'UTC site timezone aligns MySQL NOW with UTC_TIMESTAMP');

    $zone = 'America/New_York';
    $applied = app_db_set_session_timezone($pdo, $zone);
    $expectedOffset = (new DateTimeImmutable('now', new DateTimeZone($zone)))->getOffset();
    $actualOffset = (int)$pdo->query('SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW())')->fetchColumn();
    $check($applied === $zone || preg_match('/^[+-]\d{2}:\d{2}$/', $applied) === 1,
        'MySQL uses the IANA zone when available or a validated numeric offset fallback');
    $check(abs($expectedOffset - $actualOffset) <= 1,
        'MySQL local wall clock matches the configured timezone current offset');
} finally {
    $pdo->exec('SET SESSION time_zone = ' . $pdo->quote($original));
}

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " time system MySQL contract check(s) failed.\n");
    exit(1);
}
echo "Time system MySQL contract passed.\n";
