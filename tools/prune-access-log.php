<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
require_once $root . '/app/bootstrap_core.php';

$settings = access_log_settings($pdo);
$deleted = 0;
$lastCount = 0;
for ($batch = 0; $batch < 100; $batch++) {
    $count = access_log_prune($pdo, $settings['retention_days'], 5000);
    $lastCount = $count;
    $deleted += $count;
    if ($count < 5000) break;
}
if ($lastCount === 5000) {
    access_log_mark_prune_due($pdo);
} elseif (!settings_set(
        $pdo,
        'audit_access_last_pruned_utc',
        (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
        1
    )) {
        fwrite(STDERR, "Unable to persist the access retention checkpoint.\n");
        exit(1);
}
fwrite(STDOUT, 'Pruned ' . $deleted . " expired access event(s).\n");
