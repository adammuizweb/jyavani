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

require_once dirname(__DIR__) . '/app/bootstrap_core.php';

try {
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
