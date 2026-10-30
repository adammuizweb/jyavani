<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/cfg/helpers/hooks.php';
require_once $root . '/cfg/helpers/resource_lifecycle.php';
require_once $root . '/cfg/helpers/content_publication.php';

$failures = [];
$checks = 0;
$check = static function (bool $condition, string $message) use (&$failures, &$checks): void {
    $checks++;
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$outsideRejected = false;
try {
    content_status_published_create($pdo, 12, 4, 'admin.posts.edit', 'draft', 3);
} catch (RuntimeException) {
    $outsideRejected = true;
}
$check($outsideRejected, 'publication events can only be created in the mutation transaction');

$pdo->beginTransaction();
$event = content_status_published_create($pdo, 12, 4, 'admin.posts.edit', 'draft', 3);
$pdo->commit();
$check(array_keys($event) === [
    'schema', 'event_id', 'occurred_at', 'content_type', 'content_id', 'actor_id', 'source', 'previous_status', 'status_revision',
] && strlen($event['event_id']) === 64 && $event['content_type'] === 'article',
    'publication events expose the exact schema-1 Article payload');

$invalidRejected = false;
try {
    content_status_published_event(array_replace($event, ['previous_status' => 'published']));
} catch (InvalidArgumentException) {
    $invalidRejected = true;
}
$check($invalidRejected, 'publication event validation rejects a non-edge source status');

$observed = [];
$leaking = static function (array $received, PDO $database) use (&$observed): void {
    $received['content_id'] = 999;
    $observed[] = ['listener' => 'leaking', 'id' => $received['content_id']];
    $database->beginTransaction();
};
$throwing = static function (): void {
    throw new RuntimeException('observer failed');
};
$final = static function (array $received, PDO $database) use (&$observed): void {
    $observed[] = ['listener' => 'final', 'id' => $received['content_id'], 'in_transaction' => $database->inTransaction()];
};
add_action('content_status_published', $leaking, 10);
add_action('content_status_published', $throwing, 20);
add_action('content_status_published', $final, 30);
$errors = content_status_published_notify($pdo, $event);
remove_action('content_status_published', $leaking, 10);
remove_action('content_status_published', $throwing, 20);
remove_action('content_status_published', $final, 30);
$check(count($errors) === 2
    && $observed === [
        ['listener' => 'leaking', 'id' => 999],
        ['listener' => 'final', 'id' => 12, 'in_transaction' => false],
    ]
    && !$pdo->inTransaction(),
    'observers are isolated, receive immutable values, and cannot leak transaction ownership');

$activeRejected = false;
$pdo->beginTransaction();
try {
    content_status_published_notify($pdo, $event);
} catch (RuntimeException) {
    $activeRejected = true;
}
$pdo->rollBack();
$check($activeRejected, 'post-commit publication dispatch rejects an active caller transaction');

$sources = [
    'add' => (string)file_get_contents($root . '/dashboard/admin/posts/add.php'),
    'edit' => (string)file_get_contents($root . '/dashboard/admin/posts/save.php'),
    'bulk' => (string)file_get_contents($root . '/dashboard/admin/posts/bulk_action.php'),
    'undo' => (string)file_get_contents($root . '/dashboard/admin/content/undo_bulk_status.php'),
    'scheduler' => (string)file_get_contents($root . '/cfg/helpers/content_scheduler.php'),
];
foreach ($sources as $path => $source) {
    $commit = strpos($source, '$pdo->commit();');
    $notify = strpos($source, 'content_status_published_notify_safely', $commit === false ? 0 : $commit);
    $check(str_contains($source, 'content_status_published_create(')
        && $commit !== false && $notify !== false && $commit < $notify,
        $path . ' creates a publication event from locked state and dispatches it after commit');
}
$check(str_contains($sources['edit'], "(string)\$lockedPost['status'] !== 'published' && \$status === 'published'")
    && str_contains($sources['bulk'], "\$new_status === 'published' && \$rawStatusesById[\$selectedId] !== 'published'")
    && str_contains($sources['undo'], "\$items[\$id]['previous_status'] === 'published'"),
    'editor, bulk, and Undo writers emit only real edges into Published');

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " content publication contract check(s) failed.\n");
    exit(1);
}
echo "Content publication contract passed ({$checks} checks).\n";
