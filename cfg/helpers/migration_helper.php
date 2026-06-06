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

        $statements = preg_split('/;\s*$/m', $sql);

        try {
            $pdo->beginTransaction();

            foreach ($statements as $stmt) {
                $stmt = trim($stmt);
                if ($stmt === '') continue;
                if (str_starts_with($stmt, '--') || str_starts_with($stmt, '#')) continue;

                $pdo->exec($stmt);
            }

            $ins->execute([$name, $batch]);
            $pdo->commit();
            $results[$name] = 'ok';
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $results[$name] = 'error: ' . $e->getMessage();
        }
    }

    return $results;
}
