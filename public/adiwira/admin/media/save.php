<?php
declare(strict_types=1);

// /adiwira/admin/media/save.php
ob_start();
require_once __DIR__ . '/../_guard.php';

adiwira_cosmetic_404_on_direct_open();

[$uid, $role] = adiwira_require_editorial($pdo, true);
$isAdmin = ($role === 'admin');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    adiwira_json(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$csrf = (string)($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
if (!adiwira_csrf_validate($csrf)) {
    adiwira_json(['ok' => false, 'error' => 'CSRF invalid'], 419);
}

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    adiwira_json(['ok' => false, 'error' => 'Missing id'], 400);
}

$title   = trim((string)($_POST['title'] ?? ''));
$alt     = trim((string)($_POST['alt'] ?? ''));
$caption = trim((string)($_POST['caption'] ?? ''));
$credit  = trim((string)($_POST['credit'] ?? ''));

$target_url_raw       = trim((string)($_POST['target_url'] ?? ''));
$target_attribute_raw = trim((string)($_POST['target_attribute'] ?? ''));

$errors = [];

$link_url = null;
if ($target_url_raw !== '') {
    if (!filter_var($target_url_raw, FILTER_VALIDATE_URL)) {
        $errors[] = 'Invalid target URL. Use full URL starting with http:// or https://';
    } else {
        $parts = parse_url($target_url_raw);
        $scheme = isset($parts['scheme']) ? strtolower((string)$parts['scheme']) : '';
        if (!in_array($scheme, ['http', 'https'], true)) {
            $errors[] = 'Target URL must use http or https scheme';
        } else {
            $link_url = $target_url_raw;
        }
    }
}

$allowed = ['', '_self', '_blank', '_parent', '_top'];
$link_target = null;
if ($target_attribute_raw !== '') {
    if (!in_array($target_attribute_raw, $allowed, true)) {
        $errors[] = 'Invalid target attribute';
    } else {
        $link_target = $target_attribute_raw ?: null;
    }
}

if (!empty($errors)) {
    adiwira_json([
        'ok'     => false,
        'error'  => 'Validation failed',
        'errors' => $errors
    ], 400);
}

if (!function_exists('media_detect_link_columns')) {
    function media_detect_link_columns(PDO $pdo): array
    {
        $candidates = [
            'link_url',
            'link_target',
            'target_url',
            'target_attribute',
        ];

        $exists = [];
        foreach ($candidates as $col) {
            try {
                $st = $pdo->prepare("SHOW COLUMNS FROM media LIKE :col");
                $st->execute([':col' => $col]);
                $exists[$col] = (bool)$st->fetch(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {
                $exists[$col] = false;
            }
        }

        $urlColumn = null;
        $targetColumn = null;

        if ($exists['link_url']) {
            $urlColumn = 'link_url';
        } elseif ($exists['target_url']) {
            $urlColumn = 'target_url';
        }

        if ($exists['link_target']) {
            $targetColumn = 'link_target';
        } elseif ($exists['target_attribute']) {
            $targetColumn = 'target_attribute';
        }

        return [$urlColumn, $targetColumn];
    }
}

try {
    $sql = "SELECT id FROM media WHERE id = :id";
    $params = [':id' => $id];

    if (!$isAdmin) {
        $sql .= " AND user_id = :uid";
        $params[':uid'] = $uid;
    }

    $sql .= " LIMIT 1";

    $check = $pdo->prepare($sql);
    $check->execute($params);
    if (!$check->fetchColumn()) {
        adiwira_json(['ok' => false, 'error' => 'Media not found'], 404);
    }

    [$urlColumn, $targetColumn] = media_detect_link_columns($pdo);

    $setParts = [
        'title = :title',
        'alt = :alt',
        'caption = :caption',
        'credit = :credit',
        'updated_at = NOW()',
    ];

    $exec = [
        ':title'   => $title,
        ':alt'     => $alt,
        ':caption' => $caption,
        ':credit'  => $credit,
        ':id'      => $id,
    ];

    if ($urlColumn !== null) {
        $setParts[] = $urlColumn . ' = :link_url';
        $exec[':link_url'] = $link_url;
    }

    if ($targetColumn !== null) {
        $setParts[] = $targetColumn . ' = :link_target';
        $exec[':link_target'] = $link_target;
    }

    $stmt = $pdo->prepare("
        UPDATE media
           SET " . implode(",\n               ", $setParts) . "
         WHERE id = :id
         LIMIT 1
    ");

    $stmt->execute($exec);

    adiwira_json([
        'ok' => true,
        'id' => $id,
        'updated' => [
            'title'            => $title,
            'alt'              => $alt,
            'caption'          => $caption,
            'credit'           => $credit,
            'link_url'         => $link_url,
            'link_target'      => $link_target,
            'target_url'       => $link_url,
            'target_attribute' => $link_target,
        ]
    ], 200);

} catch (Throwable $e) {
    error_log('media/save.php error: ' . $e->getMessage());
    adiwira_json([
        'ok'    => false,
        'error' => 'DB error',
    ], 500);
}