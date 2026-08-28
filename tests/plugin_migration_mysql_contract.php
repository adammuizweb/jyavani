<?php
declare(strict_types=1);

$dsn = getenv('JY_TEST_MYSQL_DSN');
if (!is_string($dsn) || trim($dsn) === '') {
    echo "SKIP plugin migration MySQL contract requires JY_TEST_MYSQL_DSN\n";
    exit(0);
}
if (!extension_loaded('pdo_mysql')) {
    fwrite(STDERR, "FAIL JY_TEST_MYSQL_DSN is set but pdo_mysql is unavailable\n");
    exit(1);
}

$root = dirname(__DIR__);
$suffix = bin2hex(random_bytes(6));
$fixture = sys_get_temp_dir() . '/jy-plugin-migrations-mysql-' . $suffix;
mkdir($fixture . '/cfg/var', 0750, true);
mkdir($fixture . '/plugins', 0775, true);
define('BACKEND_PATH', $fixture . '/cfg');
define('PLUGIN_PATH', $fixture . '/plugins');

require_once $root . '/cfg/helpers/theme_helper.php';
require_once $root . '/cfg/helpers/migration_helper.php';
require_once $root . '/cfg/helpers/hooks.php';
require_once $root . '/plugins/index.php';

$user = getenv('JY_TEST_MYSQL_USER');
$password = getenv('JY_TEST_MYSQL_PASSWORD');
$pdo = new PDO(
    $dsn,
    is_string($user) ? $user : '',
    is_string($password) ? $password : '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};
$remove = static function (string $path) use (&$remove): void {
    if (is_file($path) || is_link($path)) { @unlink($path); return; }
    if (!is_dir($path)) return;
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') $remove($path . '/' . $entry);
    }
    @rmdir($path);
};
$table = 'jy_migration_' . $suffix;
$plugin = 'mysql-migration-' . $suffix;
$leakPlugin = 'mysql-autocommit-' . $suffix;

try {
    mkdir(PLUGIN_PATH . '/' . $plugin . '/migrations', 0775, true);
    file_put_contents(
        PLUGIN_PATH . '/' . $plugin . '/migrations/0001-create.sql',
        "CREATE TABLE `{$table}` (`id` int NOT NULL PRIMARY KEY, `value` varchar(64) NOT NULL);\n"
        . "INSERT INTO `{$table}` (`id`, `value`) VALUES (1, 'mysql;statement');\n"
    );
    $locks = theme_operation_acquire(theme_lifecycle_lock_keys([$plugin]));
    $result = plugin_migrations_run_pending_already_locked($pdo, $plugin, '1.0.0', PLUGIN_PATH . '/' . $plugin);
    theme_operation_release($locks);
    $tracked = $pdo->prepare('SELECT COUNT(*) FROM plugin_migrations WHERE plugin_name = ?');
    $tracked->execute([$plugin]);
    $check($result['applied'] === ['0001-create.sql'] && (int)$tracked->fetchColumn() === 1
        && (int)$pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn() === 1,
        'MySQL executes and tracks a conventional SQL migration');

    mkdir(PLUGIN_PATH . '/' . $leakPlugin . '/migrations', 0775, true);
    file_put_contents(PLUGIN_PATH . '/' . $leakPlugin . '/migrations/0001-leak.php', <<<'PHP'
<?php
return static function (PDO $pdo): void {
    $pdo->setAttribute(PDO::ATTR_AUTOCOMMIT, false);
};
PHP);
    $locks = theme_operation_acquire(theme_lifecycle_lock_keys([$leakPlugin]));
    $leakRejected = false;
    try {
        plugin_migrations_run_pending_already_locked($pdo, $leakPlugin, '1.0.0', PLUGIN_PATH . '/' . $leakPlugin);
    } catch (Throwable) {
        $leakRejected = true;
    }
    theme_operation_release($locks);
    $tracked->execute([$leakPlugin]);
    $check($leakRejected && (bool)$pdo->getAttribute(PDO::ATTR_AUTOCOMMIT)
        && (int)$pdo->query('SELECT @@SESSION.autocommit')->fetchColumn() === 1
        && !$pdo->inTransaction() && (int)$tracked->fetchColumn() === 0,
        'MySQL autocommit leakage is restored and rejected before ledger insertion');

    $cleanupLeak = plugin_uninstall_run_cleanup_listener(
        $pdo,
        static function () use ($pdo): void { $pdo->setAttribute(PDO::ATTR_AUTOCOMMIT, false); },
        [],
        ['hook' => 'plugin_uninstall', 'priority' => 10, 'listener' => 0]
    );
    $check(is_array($cleanupLeak) && (bool)$pdo->getAttribute(PDO::ATTR_AUTOCOMMIT)
        && (int)$pdo->query('SELECT @@SESSION.autocommit')->fetchColumn() === 1
        && !$pdo->inTransaction(),
        'MySQL cleanup-listener autocommit leakage is restored and reported as failure');
} finally {
    try { $pdo->exec("DROP TABLE IF EXISTS `{$table}`"); } catch (Throwable) {}
    try {
        $delete = $pdo->prepare('DELETE FROM plugin_migrations WHERE plugin_name IN (?, ?)');
        $delete->execute([$plugin, $leakPlugin]);
    } catch (Throwable) {}
    $remove($fixture);
}

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " MySQL plugin migration checks failed.\n");
    exit(1);
}
echo "RESULT: ALL PASS\n";
