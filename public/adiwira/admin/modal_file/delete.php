<?php
declare(strict_types=1);

// /adiwira/admin/modal_file/delete.php
ob_start();

require_once __DIR__ . '/../_guard.php';

if (adiwira_is_navigate_request()) {
    http_response_code(404);
    require __DIR__ . '/../../../frontend_404.php';
    exit;
}

[$uid, $role] = adiwira_require_editorial($pdo, true);
$isAdmin = ($role === 'admin');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    adiwira_json(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$csrf = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (!adiwira_csrf_validate(is_string($csrf) ? $csrf : '')) {
    adiwira_json(['ok' => false, 'error' => 'CSRF invalid'], 419);
}

$id  = (int)($_POST['id'] ?? 0);
$url = trim((string)($_POST['url'] ?? ''));

if ($id <= 0 && $url === '') {
    adiwira_json(['ok' => false, 'error' => 'Missing id or url'], 400);
}

if (!function_exists('modalfilez_starts_with')) {
    function modalfilez_starts_with(string $haystack, string $needle): bool
    {
        if ($needle === '') return true;
        return strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}

if (!function_exists('modalfilez_local_path_from_url')) {
    function modalfilez_local_path_from_url(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH) ?: '';
        if ($path === '' || !modalfilez_starts_with($path, '/static/files/')) {
            return null;
        }

        $staticRoot = realpath(rtrim((string)PUBLIC_PATH, '/\\') . '/static');
        if (!$staticRoot || !is_dir($staticRoot)) {
            return null;
        }

        $rel = substr($path, strlen('/static'));
        $local = $staticRoot . $rel;
        $realLocal = realpath($local);

        if ($realLocal && modalfilez_starts_with($realLocal, $staticRoot) && is_file($realLocal)) {
            return $realLocal;
        }

        return null;
    }
}

try {
    $sql = "SELECT id, url, user_id FROM `file` WHERE ";
    $params = [];

    if ($id > 0) {
        $sql .= "id = :id";
        $params[':id'] = $id;
    } else {
        $sql .= "url = :url";
        $params[':url'] = $url;
    }

    if (!$isAdmin) {
        $sql .= " AND user_id = :uid";
        $params[':uid'] = $uid;
    }

    $sql .= " LIMIT 1";

    $q = $pdo->prepare($sql);
    $q->execute($params);
    $row = $q->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        adiwira_json(['ok' => false, 'error' => 'File not found'], 404);
    }

    $deletedId = (int)($row['id'] ?? 0);
    $finalUrl = (string)($row['url'] ?? '');

    $localFile = modalfilez_local_path_from_url($finalUrl);
    $warning = null;

    if ($localFile && is_file($localFile)) {
        if (!@unlink($localFile)) {
            $warning = 'File fisik gagal dihapus, tetapi record database tetap dihapus.';
        }
    }

    $pdo->prepare("DELETE FROM `file` WHERE id = :id LIMIT 1")->execute([':id' => $deletedId]);

    adiwira_json([
        'ok'            => true,
        'id'            => $deletedId,
        'deleted_ids'   => [$deletedId],
        'deleted_count' => 1,
        'url'           => $finalUrl,
        'warning'       => $warning,
    ], 200);

} catch (Throwable $e) {
    error_log('modal_file/delete.php error: ' . $e->getMessage());
    adiwira_json([
        'ok'    => false,
        'error' => 'DB error',
    ], 500);
}