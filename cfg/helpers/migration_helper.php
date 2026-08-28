<?php
declare(strict_types=1);

function migration_get_pending(PDO $pdo): array
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS `schema_migrations` (
        `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
        `migration` varchar(255) NOT NULL,
        `batch` int(10) unsigned NOT NULL DEFAULT 1,
        `executed_at` datetime NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_migration` (`migration`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $executed = $pdo->query("SELECT migration FROM schema_migrations")->fetchAll(PDO::FETCH_COLUMN);

    $migrationsDir = dirname(__DIR__, 2) . '/schema/migrations';
    if (!is_dir($migrationsDir)) {
        return [];
    }

    $files = glob($migrationsDir . '/*.sql');
    sort($files);

    $pending = [];
    foreach ($files as $file) {
        $name = basename($file);
        if (!in_array($name, $executed, true)) {
            $pending[] = $name;
        }
    }
    return $pending;
}

function migration_run_pending(PDO $pdo): array
{
    $pending = migration_get_pending($pdo);
    $results = [];

    if (empty($pending)) {
        return $results;
    }

    $stmt = $pdo->query("SELECT COALESCE(MAX(batch), 0) FROM schema_migrations");
    $batch = (int)$stmt->fetchColumn() + 1;

    $migrationsDir = dirname(__DIR__, 2) . '/schema/migrations';
    $ins = $pdo->prepare("INSERT INTO schema_migrations (migration, batch) VALUES (?, ?)");

    foreach ($pending as $name) {
        $file = $migrationsDir . '/' . $name;
        $sql = file_get_contents($file);

        if ($sql === false) {
            $results[$name] = 'error: cannot read file';
            continue;
        }

        // Strip comment lines before splitting
        $lines = explode("\n", $sql);
        $cleanLines = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '--') || str_starts_with($trimmed, '#')) {
                continue;
            }
            $cleanLines[] = $line;
        }
        $cleanSql = implode("\n", $cleanLines);
        $statements = preg_split('/;\s*$/m', $cleanSql);

        // DDL (ALTER TABLE, CREATE TABLE) auto-commits in MySQL,
        // so wrap each statement individually instead of one big transaction.
        $allOk = true;
        foreach ($statements as $stmt) {
            $stmt = trim($stmt);
            if ($stmt === '') continue;

            try {
                $pdo->exec($stmt);
            } catch (Throwable $e) {
                $results[$name] = 'error: ' . $e->getMessage();
                $allOk = false;
                break;
            }
        }

        if ($allOk) {
            try {
                $ins->execute([$name, $batch]);
                $results[$name] = 'ok';
            } catch (Throwable $e) {
                $results[$name] = 'error (tracking): ' . $e->getMessage();
            }
        }
    }

    return $results;
}

/** Discover the fixed, append-only migration files in one plugin package. */
function plugin_migrations_discover(string $pluginDir): array
{
    $root = defined('PLUGIN_PATH') ? realpath(PLUGIN_PATH) : false;
    $directory = realpath($pluginDir);
    if ($root === false || $directory === false || is_link($pluginDir)
        || dirname($directory) !== $root) {
        throw new RuntimeException('Plugin migration directory is outside the plugin root.');
    }

    $migrationDir = $directory . '/migrations';
    if (!file_exists($migrationDir)) return [];
    if (!is_dir($migrationDir) || is_link($migrationDir)) {
        throw new RuntimeException('Plugin migrations must be a safe directory.');
    }

    $files = [];
    $sequences = [];
    foreach (new DirectoryIterator($migrationDir) as $entry) {
        if ($entry->isDot()) continue;
        $name = $entry->getFilename();
        if (!$entry->isFile() || $entry->isLink()
            || preg_match('/\A([0-9]{4})-[a-z0-9][a-z0-9_-]*\.(sql|php)\z/', $name, $match) !== 1
            || $match[1] === '0000'
            || strlen($name) > 191 || isset($sequences[$match[1]])) {
            throw new RuntimeException('Invalid or duplicate plugin migration: ' . $name);
        }
        $size = $entry->getSize();
        $path = $entry->getPathname();
        $hash = $size > 0 && $size <= 1048576 ? hash_file('sha256', $path) : false;
        if (!is_string($hash)) throw new RuntimeException('Plugin migration is empty, unreadable, or too large: ' . $name);
        $sequences[$match[1]] = true;
        $files[$name] = [
            'path' => $path,
            'type' => $match[2],
            'checksum' => $hash,
            'sequence' => (int)$match[1],
        ];
    }
    uasort($files, static fn(array $left, array $right): int => $left['sequence'] <=> $right['sequence']);
    return $files;
}

function plugin_migrations_assert_locks(string $pluginName): void
{
    $global = defined('THEME_LIFECYCLE_LOCK_KEY') ? (string)THEME_LIFECYCLE_LOCK_KEY : '0-theme-lifecycle';
    if (!function_exists('theme_operation_holds_lock')
        || !theme_operation_holds_lock($global, LOCK_EX)
        || !theme_operation_holds_lock($pluginName, LOCK_EX)) {
        throw new RuntimeException('Plugin migrations require the global and plugin lifecycle locks.');
    }
}

function plugin_migrations_ensure_table(PDO $pdo): void
{
    $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        $pdo->exec("CREATE TABLE IF NOT EXISTS plugin_migrations (
            plugin_name TEXT NOT NULL,
            migration TEXT NOT NULL,
            checksum TEXT NOT NULL,
            applied_version TEXT NOT NULL,
            executed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (plugin_name, migration)
        )");
        return;
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS `plugin_migrations` (
        `plugin_name` varchar(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        `migration` varchar(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        `checksum` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        `applied_version` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        `executed_at` datetime NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`plugin_name`, `migration`),
        KEY `idx_plugin_migrations_executed` (`executed_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function plugin_migrations_mysql_autocommit(PDO $pdo): ?int
{
    if ((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') return null;
    $attribute = $pdo->getAttribute(PDO::ATTR_AUTOCOMMIT);
    if (($attribute !== 0 && $attribute !== 1 && $attribute !== '0' && $attribute !== '1'
        && $attribute !== false && $attribute !== true)) {
        throw new RuntimeException('Unable to verify the MySQL autocommit state.');
    }
    if (!(bool)$attribute) return 0;
    $session = $pdo->query('SELECT @@SESSION.autocommit')->fetchColumn();
    if ($session !== 0 && $session !== 1 && $session !== '0' && $session !== '1') {
        throw new RuntimeException('Unable to verify the MySQL autocommit state.');
    }
    return (int)((bool)$attribute && (bool)$session);
}

function plugin_migrations_assert_clean_connection(PDO $pdo): void
{
    if ($pdo->inTransaction()) {
        throw new RuntimeException('Plugin migrations require a standalone database connection.');
    }
    if (plugin_migrations_mysql_autocommit($pdo) === 0) {
        throw new RuntimeException('Plugin migrations require MySQL autocommit to be enabled.');
    }
}

function plugin_migrations_recover_connection(PDO $pdo): void
{
    if ($pdo->inTransaction() && !$pdo->rollBack()) {
        throw new RuntimeException('Unable to roll back the leaked plugin migration transaction.');
    }
    if (plugin_migrations_mysql_autocommit($pdo) === 0) {
        $pdo->setAttribute(PDO::ATTR_AUTOCOMMIT, true);
        $pdo->exec('SET SESSION autocommit = 1');
    }
    plugin_migrations_assert_clean_connection($pdo);
}

/** Validate migration history and return the exact pending files. */
function plugin_migrations_plan_already_locked(
    PDO $pdo,
    string $pluginName,
    string $pluginVersion,
    string $pluginDir
): array {
    plugin_migrations_assert_locks($pluginName);
    plugin_migrations_assert_clean_connection($pdo);
    if (preg_match('/\A[a-zA-Z0-9_-]+\z/', $pluginName) !== 1 || strlen($pluginName) > 255
        || $pluginVersion === '' || strlen($pluginVersion) > 64
        || preg_match('/[\x00-\x1F\x7F]/', $pluginVersion) === 1) {
        throw new RuntimeException('Invalid plugin migration identity.');
    }

    $files = plugin_migrations_discover($pluginDir);
    plugin_migrations_ensure_table($pdo);
    $select = $pdo->prepare('SELECT migration, checksum FROM plugin_migrations WHERE plugin_name = ?');
    $select->execute([$pluginName]);
    $applied = [];
    foreach ($select->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $name = (string)($row['migration'] ?? '');
        $checksum = (string)($row['checksum'] ?? '');
        if (!isset($files[$name])) {
            throw new RuntimeException('Applied plugin migration is missing from the package: ' . $name);
        }
        if (!hash_equals($checksum, $files[$name]['checksum'])) {
            throw new RuntimeException('Applied plugin migration was modified: ' . $name);
        }
        $applied[$name] = true;
    }

    $pendingSeen = false;
    foreach (array_keys($files) as $name) {
        if (isset($applied[$name])) {
            if ($pendingSeen) {
                throw new RuntimeException('Plugin migrations must be appended after all applied migrations: ' . $name);
            }
        } else {
            $pendingSeen = true;
        }
    }

    return [
        'plugin' => $pluginName,
        'version' => $pluginVersion,
        'files' => $files,
        'pending' => array_values(array_diff(array_keys($files), array_keys($applied))),
    ];
}

/** Split ordinary SQL without enabling PDO multi-statements or client DELIMITER syntax. */
function plugin_migrations_split_sql(string $sql): array
{
    if (str_contains($sql, "\0") || str_contains($sql, '\\')
        || preg_match('/^\s*DELIMITER\b/im', $sql) === 1
        || preg_match('#/\*(?:!|M!|\+)#i', $sql) === 1) {
        throw new RuntimeException('Unsupported SQL migration syntax.');
    }
    $statements = [];
    $current = '';
    $quote = null;
    $lineComment = false;
    $blockComment = false;
    $length = strlen($sql);
    for ($index = 0; $index < $length; $index++) {
        $char = $sql[$index];
        $next = $index + 1 < $length ? $sql[$index + 1] : '';
        if ($lineComment) {
            if ($char === "\n") { $lineComment = false; $current .= $char; }
            continue;
        }
        if ($blockComment) {
            if ($char === '*' && $next === '/') { $blockComment = false; $index++; }
            continue;
        }
        if ($quote !== null) {
            $current .= $char;
            if ($char === $quote) {
                if ($next === $quote) $current .= $sql[++$index];
                else $quote = null;
            }
            continue;
        }
        if ($char === "'" || $char === '"' || $char === '`') { $quote = $char; $current .= $char; continue; }
        if ($char === '#') { $lineComment = true; continue; }
        if ($char === '-' && $next === '-' && ($index + 2 >= $length || ctype_space($sql[$index + 2]))) {
            $lineComment = true; $index++; continue;
        }
        if ($char === '/' && $next === '*') { $blockComment = true; $current .= ' '; $index++; continue; }
        if ($char === ';') {
            $statement = trim($current);
            if ($statement !== '') $statements[] = $statement;
            $current = '';
            continue;
        }
        $current .= $char;
    }
    if ($quote !== null || $blockComment) throw new RuntimeException('Unterminated SQL migration syntax.');
    $statement = trim($current);
    if ($statement !== '') $statements[] = $statement;
    if ($statements === []) throw new RuntimeException('SQL migration contains no statements.');
    foreach ($statements as $statement) {
        if (preg_match('/\A(?:BEGIN\b|START\s+TRANSACTION\b|COMMIT\b|ROLLBACK\b|SAVEPOINT\b|RELEASE\s+SAVEPOINT\b|XA\s+(?:START|BEGIN|END|PREPARE|COMMIT|ROLLBACK)\b|SET\b|LOCK\s+TABLES\b|UNLOCK\s+TABLES\b|USE\b)/i', $statement) === 1) {
            throw new RuntimeException('SQL migration transaction commands are not supported.');
        }
    }
    return $statements;
}

/** Execute pending migrations in order and track only complete files. */
function plugin_migrations_run_pending_already_locked(
    PDO $pdo,
    string $pluginName,
    string $pluginVersion,
    string $pluginDir
): array {
    $plan = plugin_migrations_plan_already_locked($pdo, $pluginName, $pluginVersion, $pluginDir);
    $insert = $pdo->prepare(
        'INSERT INTO plugin_migrations (plugin_name, migration, checksum, applied_version) VALUES (?, ?, ?, ?)'
    );
    $applied = [];
    foreach ($plan['pending'] as $name) {
        $file = $plan['files'][$name];
        $currentHash = hash_file('sha256', $file['path']);
        if (!is_string($currentHash) || !hash_equals($file['checksum'], $currentHash)) {
            throw new RuntimeException('Plugin migration changed during execution: ' . $name);
        }
        try {
            if ($file['type'] === 'sql') {
                $sql = file_get_contents($file['path']);
                if (!is_string($sql)) throw new RuntimeException('Unable to read SQL migration.');
                foreach (plugin_migrations_split_sql($sql) as $statement) $pdo->exec($statement);
            } else {
                $load = static function (string $path): mixed { return require $path; };
                $migration = $load($file['path']);
                if (!$migration instanceof Closure) throw new RuntimeException('PHP migration must return a Closure.');
                $migration($pdo);
            }
            plugin_migrations_assert_clean_connection($pdo);
            $currentFiles = plugin_migrations_discover($pluginDir);
            $expectedIdentity = array_map(
                static fn(array $migrationFile): array => [
                    'type' => $migrationFile['type'],
                    'checksum' => $migrationFile['checksum'],
                    'sequence' => $migrationFile['sequence'],
                ],
                $plan['files']
            );
            $currentIdentity = array_map(
                static fn(array $migrationFile): array => [
                    'type' => $migrationFile['type'],
                    'checksum' => $migrationFile['checksum'],
                    'sequence' => $migrationFile['sequence'],
                ],
                $currentFiles
            );
            if ($currentIdentity !== $expectedIdentity) {
                throw new RuntimeException('Plugin migration files changed during execution.');
            }
            $insert->execute([$pluginName, $name, $file['checksum'], $pluginVersion]);
            $applied[] = $name;
        } catch (Throwable $error) {
            try {
                plugin_migrations_recover_connection($pdo);
            } catch (Throwable $recoveryError) {
                throw new RuntimeException(
                    'Plugin migration connection recovery failed at ' . $name . ': ' . $recoveryError->getMessage(),
                    0,
                    $error
                );
            }
            throw new RuntimeException('Plugin migration failed at ' . $name . ': ' . $error->getMessage(), 0, $error);
        }
    }
    return ['applied' => $applied];
}

function plugin_migrations_assert_complete_already_locked(
    PDO $pdo,
    string $pluginName,
    string $pluginVersion,
    string $pluginDir
): void {
    $plan = plugin_migrations_plan_already_locked($pdo, $pluginName, $pluginVersion, $pluginDir);
    if ($plan['pending'] !== []) {
        throw new RuntimeException('Plugin migration files changed after migration execution.');
    }
}

function plugin_migrations_forget_already_locked(PDO $pdo, string $pluginName): void
{
    plugin_migrations_assert_locks($pluginName);
    plugin_migrations_assert_clean_connection($pdo);
    if (preg_match('/\A[a-zA-Z0-9_-]+\z/', $pluginName) !== 1 || strlen($pluginName) > 255) {
        throw new RuntimeException('Invalid plugin migration identity.');
    }
    plugin_migrations_ensure_table($pdo);
    $delete = $pdo->prepare('DELETE FROM plugin_migrations WHERE plugin_name = ?');
    $delete->execute([$pluginName]);
    plugin_migrations_assert_clean_connection($pdo);
}
