<?php
declare(strict_types=1);

// /adiwira/admin/file/list.php
if (!defined('DASHBOARD_CONTEXT') && !defined('ADAM_THEME')) {
    require_once __DIR__ . '/../_guard.php';

    if (adiwira_is_navigate_request()) {
        http_response_code(404);
        require __DIR__ . '/../../../frontend_404.php';
        exit;
    }

    adiwira_require_editorial($pdo, false);
}

[$uid, $role] = adiwira_require_editorial($pdo, false);
$isAdmin = ($role === 'admin');

if (!headers_sent()) {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}

if (!function_exists('human_filesize')) {
    function human_filesize(int $bytes, int $decimals = 1): string {
        if ($bytes <= 0) return '0 B';
        $units = ['B','KB','MB','GB','TB'];
        $factor = (int) floor((strlen((string)$bytes) - 1) / 3);
        $factor = min($factor, count($units) - 1);
        $size = $bytes / pow(1024, $factor);
        return sprintf("%.{$decimals}f %s", $size, $units[$factor]);
    }
}

if (!function_exists('e')) {
    function e($s): string {
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('build_pagination_items')) {
    function build_pagination_items(int $current, int $total_pages, int $max_visible = 9): array {
        if ($total_pages <= $max_visible) return range(1, $total_pages);

        $items = [];
        $reserved = 6;
        $middle_slots = max(1, $max_visible - $reserved);
        $half = (int) floor($middle_slots / 2);
        $start = max(3, $current - $half);
        $end = min($total_pages - 2, $current + $half);

        if ($start === 3) $end = min($total_pages - 2, $start + $middle_slots - 1);
        if ($end === $total_pages - 2) $start = max(3, $end - $middle_slots + 1);

        $items[] = 1;
        $items[] = 2;
        if ($start > 3) $items[] = '...';
        for ($i = $start; $i <= $end; $i++) $items[] = $i;
        if ($end < $total_pages - 2) $items[] = '...';
        $items[] = $total_pages - 1;
        $items[] = $total_pages;

        while (count($items) > $max_visible) {
            for ($i = 0; $i < count($items); $i++) {
                if (
                    is_int($items[$i]) &&
                    $items[$i] !== 1 &&
                    $items[$i] !== 2 &&
                    $items[$i] !== $total_pages - 1 &&
                    $items[$i] !== $total_pages
                ) {
                    array_splice($items, $i, 1);
                    break;
                }
            }
        }

        return $items;
    }
}

$search   = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$page     = max(1, (int)($_GET['p'] ?? 1));
$per_page = 10;
$offset   = ($page - 1) * $per_page;

$where = [];
$params = [];

if (!$isAdmin) {
    $where[] = "user_id = :uid";
    $params[':uid'] = $uid;
}

if ($search !== '') {
    $where[] = "(title LIKE :q OR filename LIKE :q OR caption LIKE :q OR mime LIKE :q OR ext LIKE :q)";
    $params[':q'] = '%' . $search . '%';
}

$where_sql = '';
if (!empty($where)) {
    $where_sql = 'WHERE ' . implode(' AND ', $where);
}

try {
    $count_sql = "SELECT COUNT(*) FROM `file` $where_sql";
    $countStmt = $pdo->prepare($count_sql);
    foreach ($params as $k => $v) $countStmt->bindValue($k, $v);
    $countStmt->execute();
    $total = (int) $countStmt->fetchColumn();
} catch (Throwable $e) {
    error_log('file/list.php count error: ' . $e->getMessage());
    $total = 0;
}

$rows = [];
if ($total > 0) {
    $sql = "SELECT * FROM `file` $where_sql ORDER BY id DESC LIMIT :limit OFFSET :offset";
    try {
        $stmt = $pdo->prepare($sql);
        foreach ($params as $k => $v) $stmt->bindValue($k, $v);
        $stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('file/list.php rows error: ' . $e->getMessage());
        $rows = [];
    }
}

$total_pages = max(1, (int) ceil($total / $per_page));
$paging_items = build_pagination_items($page, $total_pages, 9);
?>

<div class="media-list">
  <div class="controls">
    <input type="checkbox" id="select-all" class="select-all" />
    <label for="select-all" class="small">Pilih semua</label>

    <button id="delete-bulk-btn" class="btn danger" type="button">Delete Selected</button>

    <input
      type="text"
      id="media-search"
      class="search"
      placeholder="Cari title / filename / caption / mime"
      value="<?= e($search) ?>"
      style="margin-left:12px;"
    >
    <button id="media-search-btn" class="btn" type="button">Cari</button>

    <div style="margin-left:auto" class="small">Total: <?= (int)$total ?></div>
  </div>

  <?php if (empty($rows)): ?>
    <div class="empty">Tidak ada file</div>
  <?php else: ?>
    <table class="table" id="media-table">
      <thead>
        <tr>
          <th></th>
          <th>Preview</th>
          <th>Title / Filename</th>
          <th>Meta</th>
          <th style="width:180px">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <?php
            $ext = strtoupper((string)($r['ext'] ?? ''));
            if ($ext === '') {
                $ext = strtoupper((string) pathinfo((string)($r['filename'] ?? ''), PATHINFO_EXTENSION));
            }
            if ($ext === '') $ext = 'FILE';
          ?>
          <tr data-id="<?= (int)$r['id'] ?>">
            <td><input type="checkbox" class="row-checkbox" value="<?= (int)$r['id'] ?>"></td>
            <td><div class="file-thumb"><?= e($ext) ?></div></td>
            <td>
              <div style="font-weight:700"><?= e((string)($r['title'] ?? '')) ?></div>
              <div class="small"><?= e((string)($r['filename'] ?? '')) ?></div>
              <?php if (!empty($r['media_type'])): ?>
                <div class="badge"><?= e((string)$r['media_type']) ?></div>
              <?php endif; ?>
            </td>
            <td>
              <div class="small">
                MIME: <?= e((string)($r['mime'] ?? '')) ?> —
                Size: <?= e(human_filesize((int)($r['size'] ?? 0))) ?>
              </div>
              <?php if (!empty($r['duration'])): ?>
                <div class="small">Durasi: <?= (int)$r['duration'] ?>s</div>
              <?php endif; ?>
              <div class="small">Caption: <?= nl2br(e((string)($r['caption'] ?? ''))) ?></div>
            </td>
            <td>
              <button class="btn btn-open" type="button" data-id="<?= (int)$r['id'] ?>">Open</button>
              <a class="btn" href="<?= e((string)$r['url']) ?>" target="_blank" rel="noopener">Download</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <?php if ($total_pages > 1): ?>
      <div class="media-pagination" role="navigation" aria-label="Pagination">
        <?php foreach ($paging_items as $item): ?>
          <?php
            if ($item === '...') {
                echo '<span class="dots">…</span>';
                continue;
            }
            $i = (int) $item;
          ?>
          <?php if ($i === $page): ?>
            <strong data-page="<?= $i ?>"><?= $i ?></strong>
          <?php else: ?>
            <a href="#" class="media-page-link" data-page="<?= $i ?>" data-q="<?= e($search) ?>"><?= $i ?></a>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div>