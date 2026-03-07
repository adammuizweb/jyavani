<?php
declare(strict_types=1);
// lokasi file /adiwira/admin/media/save.php
ob_start();
require_once __DIR__ . '/../_guard.php'; // /adiwira/admin/_guard.php

// 1) Kalau orang buka URL ini langsung di browser: samarkan total sebagai 404 HTML
if (adiwira_is_navigate_request()) {
  http_response_code(404);
  require __DIR__ . '/../../../frontend_404.php';
  exit;
}

// 2) Untuk request programmatic (AJAX/fetch): pakai JSON
adiwira_require_admin(true);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    adiwira_json(['ok'=>false,'error'=>'Method not allowed'], 405);
}

$csrf = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (!adiwira_csrf_validate(is_string($csrf) ? $csrf : '')) {
    adiwira_json(['ok'=>false,'error'=>'CSRF invalid'], 419);
}

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    adiwira_json(['ok'=>false,'error'=>'Missing id'], 400);
}

$title   = trim((string)($_POST['title'] ?? ''));
$alt     = trim((string)($_POST['alt'] ?? ''));
$caption = trim((string)($_POST['caption'] ?? ''));
$credit  = trim((string)($_POST['credit'] ?? ''));

$target_url_raw       = trim((string)($_POST['target_url'] ?? ''));
$target_attribute_raw = trim((string)($_POST['target_attribute'] ?? ''));

// validation
$errors = [];

$target_url = null;
if ($target_url_raw !== '') {
    if (!filter_var($target_url_raw, FILTER_VALIDATE_URL)) {
        $errors[] = 'Invalid target URL. Use full URL starting with http:// or https://';
    } else {
        $parts = parse_url($target_url_raw);
        $scheme = isset($parts['scheme']) ? strtolower((string)$parts['scheme']) : '';
        if (!in_array($scheme, ['http','https'], true)) {
            $errors[] = 'Target URL must use http or https scheme';
        } else {
            $target_url = $target_url_raw;
        }
    }
}

$allowed = ['', '_self', '_blank', '_parent', '_top'];
$target_attribute = null;
if ($target_attribute_raw !== '') {
    if (!in_array($target_attribute_raw, $allowed, true)) {
        $errors[] = 'Invalid target attribute';
    } else {
        $target_attribute = $target_attribute_raw ?: null;
    }
}

if (!empty($errors)) {
    adiwira_json(['ok'=>false,'error'=>'Validation failed','errors'=>$errors], 400);
}

try {
    $stmt = $pdo->prepare(
        "UPDATE media
           SET title=:title,
               alt=:alt,
               caption=:caption,
               credit=:credit,
               target_url=:target_url,
               target_attribute=:target_attribute,
               updated_at=NOW()
         WHERE id=:id"
    );

    $stmt->execute([
        ':title' => $title,
        ':alt' => $alt,
        ':caption' => $caption,
        ':credit' => $credit,
        ':target_url' => $target_url,
        ':target_attribute' => $target_attribute,
        ':id' => $id,
    ]);

    adiwira_json([
        'ok' => true,
        'id' => $id,
        'updated' => [
            'title' => $title,
            'alt' => $alt,
            'caption' => $caption,
            'credit' => $credit,
            'target_url' => $target_url,
            'target_attribute' => $target_attribute
        ]
    ], 200);

} catch (Throwable $e) {
    adiwira_json(['ok'=>false,'error'=>'DB error','detail'=>$e->getMessage()], 500);
}