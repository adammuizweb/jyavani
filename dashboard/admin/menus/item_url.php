<?php
declare(strict_types=1);

if (!defined('DASHBOARD_CONTEXT')) {
    define('DASHBOARD_CONTEXT', true);
}

require_once __DIR__ . '/../_guard.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => __('Method not allowed')]);
    exit;
}

adiwira_require_permission($pdo, 'core.menus.manage', true);

$type = strtolower(trim((string)($_GET['type'] ?? '')));
$targetId = (int)($_GET['target_id'] ?? 0);
if (!in_array($type, ['article', 'page', 'theme', 'category'], true) || $targetId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => __('Invalid menu.')]);
    exit;
}

try {
    if ($type === 'category') {
        $st = $pdo->prepare('SELECT id FROM categories WHERE id = :id AND is_deleted = 0 LIMIT 1');
        $st->execute([':id' => $targetId]);
    } else {
        $st = $pdo->prepare("SELECT id FROM posts WHERE id = :id AND type = :type AND status = 'published' AND is_deleted = 0 LIMIT 1");
        $st->execute([':id' => $targetId, ':type' => $type]);
    }

    if (!$st->fetchColumn()) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => __('URL not found.')]);
        exit;
    }

    $url = menu_resolve_url($pdo, ['type' => $type, 'target_id' => $targetId]);
    if ($url === '#' || !menu_url_is_safe($url)) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => __('URL not found.')]);
        exit;
    }

    echo json_encode(['ok' => true, 'url' => $url], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[menu_item_url] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => __('URL not found.')]);
}
