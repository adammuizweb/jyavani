<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$limit = 100;
foreach (array_slice($argv, 1) as $argument) {
    if (!preg_match('/^--limit=([1-9][0-9]{0,2})$/', $argument, $match)) {
        fwrite(STDERR, "Usage: php tools/publish-scheduled.php [--limit=1..500]\n");
        exit(2);
    }
    $limit = (int)$match[1];
    if ($limit > 500) {
        fwrite(STDERR, "--limit must not exceed 500.\n");
        exit(2);
    }
}

$root = dirname(__DIR__);
require $root . '/app/bootstrap_core.php';
theme_lifecycle_reader_start();
require $root . '/plugins/index.php';
plugin_load_active();

try {
    $published = content_scheduler_publish_due($pdo, $limit);
    fwrite(STDOUT, 'Published ' . count($published) . " scheduled content item(s).\n");
    $errors = content_scheduler_last_errors();
    if ($errors !== []) {
        fwrite(STDERR, 'Failed to publish ' . count($errors) . " scheduled content item(s).\n");
        exit(1);
    }
} catch (Throwable $error) {
    error_log('[content-scheduler] ' . $error->getMessage());
    fwrite(STDERR, "Scheduled publication failed.\n");
    exit(1);
}
