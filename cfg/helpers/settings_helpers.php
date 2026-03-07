<?php
declare(strict_types=1);

if (!function_exists('settings_get')) {
    function settings_get(PDO $pdo, string $key, ?string $default = null): ?string {
        static $cache = null;

        // lazy load autoload settings sekali per request
        if ($cache === null) {
            $cache = [];
            try {
                $st = $pdo->query("SELECT `key`, `value` FROM settings WHERE autoload = 1");
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $cache[(string)$row['key']] = $row['value'] !== null ? (string)$row['value'] : null;
                }
            } catch (Throwable $e) {
                // kalau tabel belum ada / error, fallback ke default
                return $default;
            }
        }

        return array_key_exists($key, $cache) ? $cache[$key] : $default;
    }
}

if (!function_exists('settings_set')) {
    function settings_set(PDO $pdo, string $key, ?string $value, int $autoload = 1): bool {
        $sql = "INSERT INTO settings (`key`,`value`,`autoload`)
                VALUES (:k,:v,:a)
                ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), `autoload` = VALUES(`autoload`)";
        $st = $pdo->prepare($sql);
        return $st->execute([
            ':k' => $key,
            ':v' => $value,
            ':a' => $autoload
        ]);
    }
}
