<?php
declare(strict_types=1);

// /adiwira/admin/menus/items_save.php — AJAX endpoint to save menu items
if (!defined('DASHBOARD_CONTEXT')) {
    define('DASHBOARD_CONTEXT', true);
}

require_once __DIR__ . '/../_guard.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$identity = adiwira_fetch_identity($pdo);
if (($identity['ok'] ?? false) !== true) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Access denied']);
    exit;
}

$role = (string)($identity['role'] ?? 'guest');
if (!in_array($role, ['editor', 'admin'], true)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !is_array($input)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON']);
    exit;
}

$csrf = (string)($input['csrf_token'] ?? '');
if (!adiwira_csrf_validate($csrf)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'CSRF token invalid']);
    exit;
}

$menuId = (int)($input['menu_id'] ?? 0);
if ($menuId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid menu ID']);
    exit;
}

$items = $input['items'] ?? [];
if (!is_array($items)) {
    $items = [];
}

try {
    $pdo->beginTransaction();

    $keepIds = [];

    foreach ($items as $item) {
        $itemId = isset($item['id']) && $item['id'] !== null ? (int)$item['id'] : null;
        $parentId = isset($item['parent_id']) && $item['parent_id'] !== null ? (int)$item['parent_id'] : null;
        $sortOrder = (int)($item['sort_order'] ?? 0);
        $type = (string)($item['type'] ?? 'custom');
        $label = (string)($item['label'] ?? '');
        $url = (string)($item['url'] ?? '');
        $targetId = (int)($item['target_id'] ?? 0);
        $targetBlank = !empty($item['target_blank']) ? 1 : 0;

        if ($label === '') continue;

        if ($itemId && $itemId > 0) {
            $st = $pdo->prepare("UPDATE menu_items SET parent_id = :pid, sort_order = :so, type = :typ, label = :lbl, url = :url, target_id = :tid, target_blank = :tb WHERE id = :id AND menu_id = :mid");
            $st->execute([
                ':pid' => $parentId,
                ':so' => $sortOrder,
                ':typ' => $type,
                ':lbl' => $label,
                ':url' => $url,
                ':tid' => $targetId ?: null,
                ':tb' => $targetBlank,
                ':id' => $itemId,
                ':mid' => $menuId,
            ]);
            $keepIds[] = $itemId;
        } else {
            $st = $pdo->prepare("INSERT INTO menu_items (menu_id, parent_id, sort_order, type, label, url, target_id, target_blank) VALUES (:mid, :pid, :so, :typ, :lbl, :url, :tid, :tb)");
            $st->execute([
                ':mid' => $menuId,
                ':pid' => $parentId,
                ':so' => $sortOrder,
                ':typ' => $type,
                ':lbl' => $label,
                ':url' => $url,
                ':tid' => $targetId ?: null,
                ':tb' => $targetBlank,
            ]);
            $keepIds[] = (int)$pdo->lastInsertId();
        }
    }

    if (!empty($keepIds)) {
        $placeholders = implode(',', array_fill(0, count($keepIds), '?'));
        $st = $pdo->prepare("DELETE FROM menu_items WHERE menu_id = ? AND id NOT IN ($placeholders)");
        $st->execute(array_merge([$menuId], $keepIds));
    } else {
        $st = $pdo->prepare("DELETE FROM menu_items WHERE menu_id = ?");
        $st->execute([$menuId]);
    }

    $pdo->commit();
    echo json_encode(['ok' => true, 'message' => 'Menu items saved']);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
