<?php
declare(strict_types=1);

final class CoreHardResetStatement extends PDOStatement
{
    public function __construct(private CoreHardResetPdo $pdo, private string $sql, private bool $query = false)
    {
    }

    public function execute(?array $params = null): bool
    {
        $this->pdo->record('prepare:' . $this->sql);
        return true;
    }

    public function fetchColumn(int $column = 0): mixed
    {
        return $this->query ? 1 : false;
    }
}

final class CoreHardResetPdo extends PDO
{
    private bool $transaction = false;

    public function __construct(public string $failOn = '')
    {
    }

    public function record(string $event): void
    {
        $GLOBALS['core_hard_reset_events'][] = $event;
        if ($this->failOn !== '' && str_contains($event, $this->failOn)) {
            throw new RuntimeException('expected database failure');
        }
    }

    public function exec(string $statement): int|false
    {
        $this->record('exec:' . $statement);
        return 1;
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $this->record('query:' . $query);
        return new CoreHardResetStatement($this, $query, true);
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new CoreHardResetStatement($this, $query);
    }

    public function beginTransaction(): bool
    {
        $this->record('begin');
        $this->transaction = true;
        return true;
    }

    public function commit(): bool
    {
        $this->record('commit');
        $this->transaction = false;
        return true;
    }

    public function rollBack(): bool
    {
        $this->record('rollback');
        $this->transaction = false;
        return true;
    }

    public function inTransaction(): bool
    {
        return $this->transaction;
    }

    public function lastInsertId(?string $name = null): string|false
    {
        $this->record('last-insert-id');
        return '15';
    }
}

function __(string $message): string
{
    return $message;
}

function plugins_all(): array
{
    return ['zeta' => [], 'alpha' => []];
}

function _plugin_write_disabled_names_already_locked(array $disabled): bool
{
    $GLOBALS['core_hard_reset_events'][] = 'plugin-state:' . implode(',', $disabled);
    return ($GLOBALS['core_hard_reset_state_write'] ?? true) === true;
}

function plugin_reset_runtime_cache(): void
{
    $GLOBALS['core_hard_reset_events'][] = 'plugin-cache-reset';
}

define('DASH_PATH', sys_get_temp_dir() . '/jyavani-core-hard-reset/dashboard');
$root = dirname(__DIR__);
require_once $root . '/dashboard/admin/update/_reset_service.php';

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};

$GLOBALS['core_hard_reset_events'] = [];
$GLOBALS['core_hard_reset_state_write'] = false;
$writeFailurePdo = new CoreHardResetPdo();
try {
    cms_reinstall_hard_reset($writeFailurePdo);
    $writeFailedClosed = false;
} catch (RuntimeException $error) {
    $writeFailedClosed = str_contains($error->getMessage(), 'disabled plugin state');
}
$check($writeFailedClosed && $GLOBALS['core_hard_reset_events'] === ['plugin-state:alpha,zeta']
    && !$writeFailurePdo->inTransaction(),
    'hard reset aborts before database mutation when disabled plugin state cannot be persisted');

$GLOBALS['core_hard_reset_events'] = [];
$GLOBALS['core_hard_reset_state_write'] = true;
$databaseFailurePdo = new CoreHardResetPdo('DELETE FROM assignments');
try {
    cms_reinstall_hard_reset($databaseFailurePdo);
    $databaseRolledBack = false;
} catch (RuntimeException $error) {
    $databaseRolledBack = $error->getMessage() === 'expected database failure';
}
$events = $GLOBALS['core_hard_reset_events'];
$check($databaseRolledBack && $events[0] === 'plugin-state:alpha,zeta'
    && in_array('rollback', $events, true) && !in_array('commit', $events, true)
    && !$databaseFailurePdo->inTransaction(),
    'database failure rolls back reset SQL after plugins are safely disabled');

$GLOBALS['core_hard_reset_events'] = [];
$successPdo = new CoreHardResetPdo();
$messages = cms_reinstall_hard_reset($successPdo);
$events = $GLOBALS['core_hard_reset_events'];
$check($events[0] === 'plugin-state:alpha,zeta' && $events[1] === 'plugin-cache-reset'
    && in_array('begin', $events, true) && end($events) === 'commit'
    && count(array_filter($events, static fn(string $event): bool => str_contains($event, 'is_primary = 1'))) === 1
    && count(array_filter($events, static fn(string $event): bool => str_contains($event, 'is_default = 1'))) === 1
    && str_contains(implode(' ', $messages), '2 plugins disabled'),
    'successful hard reset disables every installed plugin before committing reset SQL');
$schema = (string)file_get_contents($root . '/schema/default.sql');
$resetSource = (string)file_get_contents($root . '/dashboard/admin/update/_reset_service.php');
$check(str_contains($schema, "('id', 'name', 'slug', 'is_default')") || str_contains($schema, '`is_default` tinyint(1)')
    && str_contains($schema, '`is_primary` tinyint(1)')
    && str_contains($resetSource, 'WHERE is_default = 1')
    && str_contains($resetSource, 'WHERE is_primary = 1')
    && !str_contains($resetSource, "sidebar_zones WHERE slug = 'main'")
    && !str_contains($resetSource, "menus WHERE slug = 'primary'"),
    'hard reset resolves the canonical default menu and primary sidebar flags from the actual schema');

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " assertion(s) failed.\n");
    exit(1);
}
echo "RESULT: ALL PASS\n";
