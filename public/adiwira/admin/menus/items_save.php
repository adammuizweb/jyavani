<?php
declare(strict_types=1);

// /adiwira/admin/menus/items_save.php — AJAX endpoint to save menu items
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../_deny.php';

if (!defined('DASHBOARD_CONTEXT') && !defined('ADAM_THEME')) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Not found']);
    exit;
}

require_once __DIR__ . '/../_guard.php';

[$uid, $role] = adiwira_require_role($pdo, ['editor', 'admin'], true);

$base = rtrim(str_replace('\\','/', dirname($_SERVER['SCRIPT_NAME'])), '/');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
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

    // Track which items we keep
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
            // Update existing item
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
            // Insert new item
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

    // Delete items that are no longer in the list
    if (!empty($keepIds)) {
        $placeholders = implode(',', array_fill(0, count($keepIds), '?'));
        $st = $pdo->prepare("DELETE FROM menu_items WHERE menu_id = ? AND id NOT IN ($placeholders)");
        $st->execute(array_merge([$menuId], $keepIds));
    } else {
        // No items, delete all for this menu
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
