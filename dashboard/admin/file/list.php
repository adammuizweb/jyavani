<?php
declare(strict_types=1);

// /adiwira/admin/file/list.php
require_once __DIR__ . '/../_guard.php';

if (!defined('DASHBOARD_CONTEXT') && !defined('ADAM_THEME')) {
    if (adiwira_is_navigate_request()) {
        http_response_code(404);
        require FRONTEND_404_PATH;
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

if (!function_exists('modalfilez_client_url')) {
    function modalfilez_client_url(array $row): string
    {
        $id = (int)($row['id'] ?? 0);
        $visibility = strtolower((string)($row['visibility'] ?? 'public'));
        $disk = strtolower((string)($row['storage_disk'] ?? 'public'));
        if ($id > 0 && ($visibility === 'private' || $disk === 'private')) {
            $mime = strtolower((string)($row['mime'] ?? ''));
            $ext = strtolower((string)($row['ext'] ?? pathinfo((string)($row['filename'] ?? ''), PATHINFO_EXTENSION)));
            if ($mime === 'application/pdf' || $ext === 'pdf') {
                return '/private/pdf/view/?id=' . $id;
            }
            return '/private/file/view/?id=' . $id;
        }
        return (string)($row['url'] ?? '');
    }
}

if (!function_exists('build_pagination_items')) {
    function build_pagination_items(int $current, int $total_pages, int $max_visible = 9): array {
        if ($total_pages <= 7) return range(1, $total_pages);

        $pages = [1, 2, $current - 1, $current, $current + 1, $total_pages - 1, $total_pages];
        $pages = array_values(array_unique(array_filter(
            $pages,
            static fn(int $number): bool => $number >= 1 && $number <= $total_pages
        )));
        sort($pages);

        $items = [];
        $previous = 0;
        foreach ($pages as $number) {
            if ($previous > 0 && $number > $previous + 1) $items[] = '...';
            $items[] = $number;
            $previous = $number;
        }
        return $items;
    }
}

if (!function_exists('mdlib_has_column')) {
    function mdlib_has_column(string $col): bool {
        try {
            $st = $GLOBALS['pdo']->prepare("SELECT {$col} FROM `file` LIMIT 0");
            $st->execute();
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }
}
$hasVisibility = mdlib_has_column('visibility');

$search   = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$visFilter = $hasVisibility ? trim((string)($_GET['v'] ?? '')) : '';
$page     = max(1, (int)($_GET['p'] ?? 1));
$perPageOptions = [20, 50, 100, 200];
$requestedPerPage = (int)($_GET['per_page'] ?? 20);
$per_page = in_array($requestedPerPage, $perPageOptions, true) ? $requestedPerPage : 20;

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

if ($hasVisibility && $visFilter !== '' && in_array($visFilter, ['public','private','auto'], true)) {
    $where[] = "visibility = :vis";
    $params[':vis'] = $visFilter;
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

$total_pages = max(1, (int)ceil($total / $per_page));
$page = min($page, $total_pages);
$offset = ($page - 1) * $per_page;

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

$paging_items = build_pagination_items($page, $total_pages, 9);
?>

<div class="media-list">
  <div class="controls">
    <input type="checkbox" id="select-all" class="select-all" />
    <label for="select-all" class="small"><?= _e('Select all') ?></label>

    <button id="delete-bulk-btn" class="btn danger" type="button"><?=_e('Delete Selected')?></button>

    <?php if ($hasVisibility): ?>
    <select id="visibility-filter" style="margin-left:12px;padding:3px 6px;font-size:12px">
      <option value=""><?= _e('All') ?></option>
      <option value="public" <?= $visFilter === 'public' ? 'selected' : '' ?>><?=_e('Public')?></option>
      <option value="private" <?= $visFilter === 'private' ? 'selected' : '' ?>><?=_e('Private')?></option>
      <option value="auto" <?= $visFilter === 'auto' ? 'selected' : '' ?>><?=_e('Auto')?></option>
    </select>
    <?php endif; ?>

    <label class="media-page-size" for="media-per-page">
      <span><?= _e('Items per page') ?></span>
      <select id="media-per-page" class="media-page-select" aria-label="<?= _e('Items per page') ?>">
        <?php foreach ($perPageOptions as $option): ?>
          <option value="<?= $option ?>" <?= $per_page === $option ? 'selected' : '' ?>><?= $option ?></option>
        <?php endforeach; ?>
      </select>
    </label>

    <input
      type="text"
      id="media-search"
      class="search"
      placeholder="<?= _e('Search title / filename / caption / mime') ?>"
      value="<?= e($search) ?>"
      style="margin-left:12px;"
    >
    <button id="media-search-btn" class="btn" type="button"><?= _e('Search') ?></button>

    <div class="media-list-total small"><?= _e('Total') ?>: <?= (int)$total ?></div>
  </div>

  <?php if (empty($rows)): ?>
    <div class="empty"><?= _e('No files') ?></div>
  <?php else: ?>
    <table class="table" id="media-table">
      <thead>
        <tr>
          <th></th>
          <th><?=_e('Preview')?></th>
          <th><?=_e('Title / Filename')?></th>
          <th><?=_e('Meta')?></th>
          <th style="width:180px"><?=_e('Actions')?></th>
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

            $visibility = strtolower((string)($r['visibility'] ?? 'public')) ?: 'public';
            $accessScope = strtolower((string)($r['access_scope'] ?? 'public')) ?: 'public';
            $isDownloadable = (int)($r['is_downloadable'] ?? 1);
            $clientUrl = modalfilez_client_url($r);
            $isPrivate = ($visibility === 'private');
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
              <div style="margin-top:6px;display:flex;gap:5px;flex-wrap:wrap">
                <span class="badge" style="background:<?= $isPrivate ? '#fef3c7' : '#dcfce7' ?>;color:<?= $isPrivate ? '#92400e' : '#166534' ?>;padding:2px 7px;border-radius:999px;font-size:10px;font-weight:800"><?= e(strtoupper($visibility)) ?></span>
                <span class="badge" style="padding:2px 7px;border-radius:999px;font-size:10px;font-weight:800"><?= e(content_access_scope_label($accessScope)) ?></span>
                <?php if (!$isDownloadable): ?>
                  <span class="badge" style="background:#fee2e2;color:#991b1b;padding:2px 7px;border-radius:999px;font-size:10px;font-weight:800"><?=__('NO DOWNLOAD')?></span>
                <?php endif; ?>
              </div>
            </td>
            <td>
              <div class="small">
                <?=_e('MIME:')?> <?= e((string)($r['mime'] ?? '')) ?> —
                <?=_e('Size:')?> <?= e(human_filesize((int)($r['size'] ?? 0))) ?>
              </div>
              <?php if (!empty($r['duration'])): ?>
                <div class="small"><?=_e('Duration:')?> <?= (int)$r['duration'] ?>s</div>
              <?php endif; ?>
              <div class="small"><?=_e('Caption:')?> <?= nl2br(e((string)($r['caption'] ?? ''))) ?></div>
            </td>
            <td>
              <button class="btn btn-open" type="button" data-id="<?= (int)$r['id'] ?>"><?=_e('Open')?></button>
              <a class="btn" href="<?= e($clientUrl) ?>" target="_blank" rel="noopener"><?= $isPrivate ? __('View') : __('Download') ?></a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <div class="media-list-footer">
      <?php if ($total_pages > 1): ?>
      <nav class="media-pagination" aria-label="<?= _e('Pagination') ?>">
        <?php if ($page > 1): ?>
          <a href="#" class="media-page-link media-page-nav" rel="prev" data-page="<?= $page - 1 ?>" data-q="<?= e($search) ?>" data-v="<?= e($visFilter) ?>" data-per-page="<?= $per_page ?>"><?= _e('Previous') ?></a>
        <?php endif; ?>
        <?php foreach ($paging_items as $item): ?>
          <?php
            if ($item === '...') {
                echo '<span class="dots">…</span>';
                continue;
            }
            $i = (int) $item;
          ?>
          <?php if ($i === $page): ?>
            <strong data-page="<?= $i ?>" aria-current="page"><?= $i ?></strong>
          <?php else: ?>
            <a href="#" class="media-page-link" data-page="<?= $i ?>" data-q="<?= e($search) ?>" data-v="<?= e($visFilter) ?>" data-per-page="<?= $per_page ?>"><?= $i ?></a>
          <?php endif; ?>
        <?php endforeach; ?>
        <?php if ($page < $total_pages): ?>
          <a href="#" class="media-page-link media-page-nav" rel="next" data-page="<?= $page + 1 ?>" data-q="<?= e($search) ?>" data-v="<?= e($visFilter) ?>" data-per-page="<?= $per_page ?>"><?= _e('Next') ?></a>
        <?php endif; ?>
      </nav>
      <?php endif; ?>
      <div class="media-list-range small">
        <?= $total > 0 ? (int)($offset + 1) . '&ndash;' . (int)min($offset + $per_page, $total) : '0' ?> / <?= (int)$total ?>
      </div>
    </div>
  <?php endif; ?>
</div>
