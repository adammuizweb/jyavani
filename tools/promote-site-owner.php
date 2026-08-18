<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$options = getopt('', ['user:', 'confirm']);
$identifier = trim((string)($options['user'] ?? ''));
if ($identifier === '' || !array_key_exists('confirm', $options)) {
    fwrite(STDERR, "Usage: php tools/promote-site-owner.php --user=email-or-username --confirm\n");
    exit(2);
}

$root = dirname(__DIR__);
require_once $root . '/cfg/env.php';
load_env($root . '/cfg/.env');

$connect = static function (string $host): PDO {
    $dsn = 'mysql:host=' . $host
        . ';dbname=' . env('DB_NAME')
        . ';port=' . env('DB_PORT', 3306)
        . ';charset=utf8mb4';
    return new PDO($dsn, env('DB_USER'), env('DB_PASS'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
};

try {
    $dbHost = (string)env('DB_HOST', 'localhost');
    try {
        $pdo = $connect($dbHost);
    } catch (PDOException $e) {
        if ($dbHost !== 'localhost') {
            throw $e;
        }
        $pdo = $connect('127.0.0.1');
    }

    require_once $root . '/cfg/helpers/migration_helper.php';
    foreach (migration_run_pending($pdo) as $status) {
        if (str_starts_with((string)$status, 'error')) {
            throw new RuntimeException('Pending schema migration failed.');
        }
    }
    require_once $root . '/cfg/helpers/authorization.php';

    $stmt = $pdo->prepare(
        'SELECT id, email, username, is_site_owner
         FROM users
         WHERE (email = :identifier OR username = :identifier)
           AND is_deleted = 0
           AND is_locked = 0'
    );
    $stmt->execute([':identifier' => $identifier]);
    $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($matches) !== 1) {
        throw new RuntimeException('Active account not found.');
    }
    $user = $matches[0];
    $result = authorization_recover_site_owner($pdo, (int)$user['id']);
    if (!in_array($result, ['ok', 'unchanged'], true)) {
        throw new RuntimeException('Site Owner recovery policy failed.');
    }
} catch (Throwable $e) {
    fwrite(STDERR, "Site Owner recovery failed.\n");
    exit(1);
}

fwrite(STDOUT, "Site Owner and Administrator access ensured for user ID " . (int)$user['id'] . ".\n");
