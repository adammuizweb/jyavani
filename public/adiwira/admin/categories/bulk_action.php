<?php
declare(strict_types=1);

// /adiwira/admin/categories/bulk_action.php
if (!defined('DASHBOARD_CONTEXT')) {
    define('DASHBOARD_CONTEXT', true);
}

require_once __DIR__ . '/../_guard.php';

adiwira_cosmetic_404_on_direct_open();

function adiwira_categories_root(): string {
    $base = rtrim(str_replace('\\','/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    $pos  = strpos($base, '/admin');
    return ($pos !== false) ? substr($base, 0, $pos) : $base;
}

function is_ajax_request(): bool {
    $xrw = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    return (strtolower($xrw) === 'xmlhttprequest') || (strpos($accept, 'application/json') !== false);
}

function respond(bool $ok, string $message, int $httpCode = 200, array $extra = [], ?string $redirect = null): void {
    $isAjax = is_ajax_request();

    if ($isAjax) {
        http_response_code($httpCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array_merge(['ok'=>$ok,'message'=>$message], $extra), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        exit;
    }

    $_SESSION['flash'] = $_SESSION['flash'] ?? [];
    $_SESSION['flash'][] = ['type' => $ok ? 'success' : 'error', 'text' => $message];

    $to = $redirect ?? (adiwira_categories_root() . '/index.php?page=admin/categories/index');
    header('Location: ' . $to);
    exit;
}

[$uid, $role] = adiwira_user($pdo);

if ($uid <= 0) {
    respond(false, 'Akses ditolak: belum login.', 403);
}

if (!in_array($role, ['editor','admin'], true)) {
    respond(false, 'Akses ditolak: Anda tidak memiliki izin untuk melakukan bulk action.', 403);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond(false, 'Method Not Allowed', 405);
}

$back = adiwira_categories_root() . '/index.php?page=admin/categories/index';

$token = (string)($_POST['csrf_token'] ?? '');
if (!adiwira_csrf_validate($token)) {
    respond(false, 'CSRF token tidak valid.', 419, [], $back);
}

$ids = $_POST['ids'] ?? [];
if (!is_array($ids) || empty($ids)) {
    respond(false, 'Tidak ada kategori dipilih.', 400, [], $back);
}

$ids = array_values(array_filter(array_map('intval', $ids), fn($v) => $v > 0));
if (empty($ids)) {
    respond(false, 'ID kategori tidak valid.', 400, [], $back);
}

$action = (string)($_POST['action'] ?? '');
$in = implode(',', array_fill(0, count($ids), '?'));

try {
    $pdo->beginTransaction();

    if ($action === 'delete') {
        // disallow delete jika masih punya subkategori aktif
        $chk = $pdo->prepare("
            SELECT DISTINCT parent_id
            FROM categories
            WHERE parent_id IN ($in)
              AND is_deleted = 0
        ");
        $chk->execute($ids);
        $badParents = $chk->fetchAll(PDO::FETCH_COLUMN, 0);

        if (!empty($badParents)) {
            $pdo->rollBack();
            respond(false, 'Gagal: ada kategori yang masih punya subkategori aktif. IDs: ' . implode(',', array_slice(array_map('intval', $badParents), 0, 30)), 400, [], $back);
        }

        $stmt = $pdo->prepare("
            UPDATE categories
            SET is_deleted = 1,
                deleted_at = NOW(),
                updated_at = NOW()
            WHERE id IN ($in)
              AND is_deleted = 0
        ");
        $stmt->execute($ids);
        $affected = $stmt->rowCount();

        // soft delete jangan hapus relasi post_categories
        $pdo->commit();
        respond(true, "Berhasil memindahkan {$affected} kategori ke Trash.", 200, ['count'=>$affected], $back);
    }

    if ($action === 'change_parent') {
        $parentRaw = $_POST['parent_id'] ?? null;
        if ($parentRaw === null || $parentRaw === '') {
            $pdo->rollBack();
            respond(false, 'Parent wajib dipilih (atau pilih Tanpa Parent).', 400, [], $back);
        }

        $parent = (int)$parentRaw;

        if ($parent > 0 && in_array($parent, $ids, true)) {
            $pdo->rollBack();
            respond(false, 'Parent tidak boleh termasuk kategori yang dipilih.', 400, [], $back);
        }

        if ($parent !== 0) {
            $v = $pdo->prepare("
                SELECT id
                FROM categories
                WHERE id = ?
                  AND is_deleted = 0
                LIMIT 1
            ");
            $v->execute([$parent]);
            if (!$v->fetchColumn()) {
                $pdo->rollBack();
                respond(false, 'Parent kategori tidak ditemukan.', 400, [], $back);
            }

            // cegah cycle: parent baru tidak boleh descendant dari salah satu kategori terpilih
            $allStmt = $pdo->prepare("
                SELECT id, parent_id
                FROM categories
                WHERE is_deleted = 0
            ");
            $allStmt->execute();
            $allCats = $allStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $children = [];
            foreach ($allCats as $c) {
                $pid = $c['parent_id'] === null ? 0 : (int)$c['parent_id'];
                $children[$pid][] = (int)$c['id'];
            }

            $collectDesc = function(int $start) use (&$children, &$collectDesc): array {
                $out = [];
                if (empty($children[$start])) return $out;
                foreach ($children[$start] as $cid) {
                    if (isset($out[$cid])) continue;
                    $out[$cid] = true;
                    foreach ($collectDesc($cid) as $k => $v) $out[$k] = $v;
                }
                return $out;
            };

            foreach ($ids as $cid) {
                $desc = $collectDesc($cid);
                if (isset($desc[$parent])) {
                    $pdo->rollBack();
                    respond(false, 'Parent tidak valid: akan membentuk loop hirarki kategori.', 400, [], $back);
                }
            }
        }

        if ($parent === 0) {
            $stmt = $pdo->prepare("
                UPDATE categories
                SET parent_id = NULL,
                    updated_at = NOW()
                WHERE id IN ($in)
                  AND is_deleted = 0
            ");
            $stmt->execute($ids);
        } else {
            $stmt = $pdo->prepare("
                UPDATE categories
                SET parent_id = ?,
                    updated_at = NOW()
                WHERE id IN ($in)
                  AND is_deleted = 0
            ");
            $stmt->execute(array_merge([$parent], $ids));
        }

        $affected = $stmt->rowCount();
        $pdo->commit();
        respond(true, "Parent berhasil diubah untuk {$affected} kategori.", 200, ['count'=>$affected], $back);
    }

    $pdo->rollBack();
    respond(false, 'Aksi bulk tidak dikenal.', 400, [], $back);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('categories/bulk_action.php error: '.$e->getMessage());
    respond(false, 'Terjadi kesalahan saat proses bulk.', 500, [], $back);
}