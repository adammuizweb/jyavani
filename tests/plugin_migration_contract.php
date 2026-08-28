<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$fixture = sys_get_temp_dir() . '/jy-plugin-migrations-' . bin2hex(random_bytes(6));
mkdir($fixture . '/cfg/var', 0750, true);
mkdir($fixture . '/plugins', 0775, true);
define('BACKEND_PATH', $fixture . '/cfg');
define('PLUGIN_PATH', $fixture . '/plugins');

require_once $root . '/cfg/helpers/theme_helper.php';
require_once $root . '/cfg/helpers/migration_helper.php';

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};
$throws = static function (callable $operation): bool {
    try { $operation(); } catch (Throwable) { return true; }
    return false;
};
$remove = static function (string $path) use (&$remove): void {
    if (is_link($path) || is_file($path)) { @unlink($path); return; }
    if (!is_dir($path)) return;
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') $remove($path . '/' . $entry);
    }
    @rmdir($path);
};
$makePlugin = static function (string $name): string {
    $directory = PLUGIN_PATH . '/' . $name;
    mkdir($directory . '/migrations', 0775, true);
    return $directory;
};

try {
    $plugin = $makePlugin('migration-contract');
    $firstSql = "CREATE/* preserve token boundary */ TABLE migration_contract_rows (id INTEGER PRIMARY KEY, value TEXT);\n"
        . "INSERT INTO migration_contract_rows (id, value) VALUES (1, 'value;with-semicolon');\n";
    file_put_contents($plugin . '/migrations/0001-create.sql', $firstSql);
    file_put_contents($plugin . '/migrations/0002-seed.php', <<<'PHP'
<?php
return static function (PDO $pdo): void {
    $pdo->exec("INSERT INTO migration_contract_rows (id, value) VALUES (2, 'php')");
};
PHP);

    $check($throws(static fn() => plugin_migrations_plan_already_locked($pdo, 'migration-contract', '1.2.0', $plugin)),
        'migration planning requires the global and exact plugin lifecycle locks');
    $locks = theme_operation_acquire(theme_lifecycle_lock_keys(['migration-contract']));
    $plan = plugin_migrations_plan_already_locked($pdo, 'migration-contract', '1.2.0', $plugin);
    $result = plugin_migrations_run_pending_already_locked($pdo, 'migration-contract', '1.2.0', $plugin);
    $again = plugin_migrations_run_pending_already_locked($pdo, 'migration-contract', '1.2.0', $plugin);
    theme_operation_release($locks);
    $rows = $pdo->query('SELECT value FROM migration_contract_rows ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    $ledger = $pdo->query("SELECT migration, checksum, applied_version FROM plugin_migrations WHERE plugin_name = 'migration-contract' ORDER BY migration")->fetchAll(PDO::FETCH_ASSOC);
    $check($plan['pending'] === ['0001-create.sql', '0002-seed.php']
        && $result['applied'] === $plan['pending'] && $again['applied'] === [] && $rows === ['value;with-semicolon', 'php'],
        'SQL and PHP closure migrations run once in deterministic filename order');
    $check(count($ledger) === 2 && $ledger[0]['applied_version'] === '1.2.0'
        && preg_match('/^[a-f0-9]{64}$/', (string)$ledger[0]['checksum']) === 1,
        'the ledger scopes exact migration names, immutable checksums, and applying plugin version');

    file_put_contents($plugin . '/migrations/0001-create.sql', $firstSql . "SELECT 1;\n");
    $locks = theme_operation_acquire(theme_lifecycle_lock_keys(['migration-contract']));
    $changedRejected = $throws(static fn() => plugin_migrations_plan_already_locked($pdo, 'migration-contract', '1.2.1', $plugin));
    theme_operation_release($locks);
    file_put_contents($plugin . '/migrations/0001-create.sql', $firstSql);
    unlink($plugin . '/migrations/0002-seed.php');
    $locks = theme_operation_acquire(theme_lifecycle_lock_keys(['migration-contract']));
    $missingRejected = $throws(static fn() => plugin_migrations_plan_already_locked($pdo, 'migration-contract', '1.2.1', $plugin));
    theme_operation_release($locks);
    $check($changedRejected && $missingRejected, 'applied migrations cannot be modified, renamed, or removed');

    $appendPlugin = $makePlugin('migration-append-only');
    file_put_contents($appendPlugin . '/migrations/0002-first.sql', 'CREATE TABLE migration_append_only (id INTEGER PRIMARY KEY);');
    $locks = theme_operation_acquire(theme_lifecycle_lock_keys(['migration-append-only']));
    plugin_migrations_run_pending_already_locked($pdo, 'migration-append-only', '1.0.0', $appendPlugin);
    file_put_contents($appendPlugin . '/migrations/0001-backfill.sql', 'SELECT 1;');
    $backfillRejected = $throws(static fn() => plugin_migrations_plan_already_locked(
        $pdo,
        'migration-append-only',
        '1.1.0',
        $appendPlugin
    ));
    theme_operation_release($locks);
    $check($backfillRejected, 'new migrations must append after the complete applied numeric prefix');

    $failurePlugin = $makePlugin('migration-failure');
    file_put_contents($failurePlugin . '/migrations/0001-first.sql', 'CREATE TABLE migration_first (id INTEGER PRIMARY KEY);');
    file_put_contents($failurePlugin . '/migrations/0002-fail.sql', 'CREATE TABLE migration_partial (id INTEGER PRIMARY KEY); INVALID SQL;');
    file_put_contents($failurePlugin . '/migrations/0003-never.sql', 'CREATE TABLE migration_never (id INTEGER PRIMARY KEY);');
    $locks = theme_operation_acquire(theme_lifecycle_lock_keys(['migration-failure']));
    $stopped = $throws(static fn() => plugin_migrations_run_pending_already_locked($pdo, 'migration-failure', '2.0.0', $failurePlugin));
    theme_operation_release($locks);
    $trackedFailure = (int)$pdo->query("SELECT COUNT(*) FROM plugin_migrations WHERE plugin_name = 'migration-failure'")->fetchColumn();
    $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);
    $check($stopped && $trackedFailure === 1 && in_array('migration_partial', $tables, true)
        && !in_array('migration_never', $tables, true),
        'execution stops at the first failure, tracks only complete files, and exposes the DDL partial-commit caveat');

    $transactionPlugin = $makePlugin('migration-transaction');
    file_put_contents($transactionPlugin . '/migrations/0001-create.sql', 'CREATE TABLE migration_transaction (id INTEGER PRIMARY KEY);');
    $locks = theme_operation_acquire(theme_lifecycle_lock_keys(['migration-transaction']));
    $pdo->beginTransaction();
    $outerRejected = $throws(static fn() => plugin_migrations_run_pending_already_locked($pdo, 'migration-transaction', '1.0.0', $transactionPlugin));
    $pdo->rollBack();
    theme_operation_release($locks);
    $check($outerRejected, 'plugin migrations reject caller-owned database transactions');

    $openTransactionPlugin = $makePlugin('migration-open-transaction');
    file_put_contents($openTransactionPlugin . '/migrations/0001-leak.php', <<<'PHP'
<?php
return static function (PDO $pdo): void {
    $pdo->beginTransaction();
    $pdo->exec('CREATE TABLE migration_leaked_transaction (id INTEGER PRIMARY KEY)');
};
PHP);
    $locks = theme_operation_acquire(theme_lifecycle_lock_keys(['migration-open-transaction']));
    $leakRejected = $throws(static fn() => plugin_migrations_run_pending_already_locked(
        $pdo,
        'migration-open-transaction',
        '1.0.0',
        $openTransactionPlugin
    ));
    theme_operation_release($locks);
    $leakTracked = (int)$pdo->query("SELECT COUNT(*) FROM plugin_migrations WHERE plugin_name = 'migration-open-transaction'")->fetchColumn();
    $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);
    $check($leakRejected && !$pdo->inTransaction() && $leakTracked === 0
        && !in_array('migration_leaked_transaction', $tables, true),
        'a PHP migration that leaks a transaction is rolled back and remains untracked');

    $selfModifyingPlugin = $makePlugin('migration-self-modifying');
    file_put_contents($selfModifyingPlugin . '/migrations/0001-change.php', <<<'PHP'
<?php
return static function (PDO $pdo): void {
    file_put_contents(__FILE__, "\n// modified during execution\n", FILE_APPEND);
};
PHP);
    $locks = theme_operation_acquire(theme_lifecycle_lock_keys(['migration-self-modifying']));
    $selfModificationRejected = $throws(static fn() => plugin_migrations_run_pending_already_locked(
        $pdo,
        'migration-self-modifying',
        '1.0.0',
        $selfModifyingPlugin
    ));
    theme_operation_release($locks);
    $selfModificationTracked = (int)$pdo->query("SELECT COUNT(*) FROM plugin_migrations WHERE plugin_name = 'migration-self-modifying'")->fetchColumn();
    $check($selfModificationRejected && $selfModificationTracked === 0,
        'migration tree identity is rehashed after execution and before ledger insertion');

    $transactionStatements = [
        'BEGIN WORK;',
        'START TRANSACTION WITH CONSISTENT SNAPSHOT;',
        'COMMIT AND CHAIN;',
        'ROLLBACK TO SAVEPOINT migration_point;',
        'SAVEPOINT migration_point;',
        'RELEASE SAVEPOINT migration_point;',
        'SET autocommit = 0;',
        'SET SESSION autocommit := 0;',
        'SET @@SESSION.autocommit = OFF;',
        'SET @@autocommit = 1;',
        'SET SESSION TRANSACTION ISOLATION LEVEL SERIALIZABLE;',
        'XA START \'migration\';',
        'LOCK TABLES example WRITE;',
        'UNLOCK TABLES;',
        'USE another_database;',
    ];
    $transactionSyntaxRejected = true;
    foreach ($transactionStatements as $statement) {
        if (!$throws(static fn() => plugin_migrations_split_sql($statement))) $transactionSyntaxRejected = false;
    }
    $check($transactionSyntaxRejected, 'SQL migrations reject transaction, autocommit, session SET, XA, lock, and database-state commands');
    $check($throws(static fn() => plugin_migrations_split_sql("SELECT 'backslash\\escape';"))
        && $throws(static fn() => plugin_migrations_split_sql('/*!40101 SET autocommit = 0 */ SELECT 1;'))
        && $throws(static fn() => plugin_migrations_split_sql('/*M!100100 SET autocommit = 0 */ SELECT 1;'))
        && $throws(static fn() => plugin_migrations_split_sql('/*+ MAX_EXECUTION_TIME(1000) */ SELECT 1;')),
        'SQL parsing rejects sql_mode-sensitive backslashes and executable or behavior-changing MySQL comments');

    $invalidPlugin = $makePlugin('migration-invalid');
    file_put_contents($invalidPlugin . '/migrations/custom.php', '<?php return static function (PDO $pdo): void {};');
    $check($throws(static fn() => plugin_migrations_discover($invalidPlugin)), 'migration discovery fails closed on non-conventional executable names');

    $zeroPlugin = $makePlugin('migration-zero-sequence');
    file_put_contents($zeroPlugin . '/migrations/0000-invalid.sql', 'SELECT 1;');
    $widePlugin = $makePlugin('migration-wide-sequence');
    file_put_contents($widePlugin . '/migrations/10000-invalid.sql', 'SELECT 1;');
    $check($throws(static fn() => plugin_migrations_discover($zeroPlugin))
        && $throws(static fn() => plugin_migrations_discover($widePlugin)),
        'migration sequences use one canonical positive four-digit format');

    $forgetPlugin = $makePlugin('migration-forget');
    file_put_contents($forgetPlugin . '/migrations/0001-create.sql', 'CREATE TABLE migration_forget_marker (id INTEGER PRIMARY KEY);');
    $locks = theme_operation_acquire(theme_lifecycle_lock_keys(['migration-forget']));
    plugin_migrations_run_pending_already_locked($pdo, 'migration-forget', '1.0.0', $forgetPlugin);
    plugin_migrations_forget_already_locked($pdo, 'migration-forget');
    theme_operation_release($locks);
    $forgotten = (int)$pdo->query("SELECT COUNT(*) FROM plugin_migrations WHERE plugin_name = 'migration-forget'")->fetchColumn();
    $check($forgotten === 0, 'complete uninstall can clear exact-plugin migration history while holding lifecycle locks');

    $badPhpPlugin = $makePlugin('migration-bad-php');
    file_put_contents($badPhpPlugin . '/migrations/0001-invalid.php', '<?php return "callable_from_manifest";');
    $locks = theme_operation_acquire(theme_lifecycle_lock_keys(['migration-bad-php']));
    $badPhpRejected = $throws(static fn() => plugin_migrations_run_pending_already_locked($pdo, 'migration-bad-php', '1.0.0', $badPhpPlugin));
    theme_operation_release($locks);
    $registry = (string)file_get_contents($root . '/plugins/index.php');
    $helper = (string)file_get_contents($root . '/cfg/helpers/migration_helper.php');
    $check($badPhpRejected && !str_contains($registry, "['migrations']")
        && !str_contains($helper, "['migrations']"),
        'PHP migrations must return a closure and no manifest command or migration path is interpreted');
} finally {
    $remove($fixture);
}

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " plugin migration checks failed.\n");
    exit(1);
}
echo "RESULT: ALL PASS\n";
