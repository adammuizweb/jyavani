<?php
declare(strict_types=1);

// /adiwira/admin/media/single.php
require_once __DIR__ . '/../_guard.php';

[$uid, $role] = adiwira_require_editorial($pdo, false);
$isAdmin = ($role === 'admin');

header('Content-Type: text/html; charset=utf-8');

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    echo '<div>' . __('Invalid ID') . '</div>';
    exit;
}

$sql = "SELECT * FROM media WHERE id = :id";
$params = [':id' => $id];

if (!$isAdmin) {
    $sql .= " AND user_id = :uid";
    $params[':uid'] = $uid;
}

$sql .= " LIMIT 1";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$r = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$r) {
    echo '<div>' . __('Media not found') . '</div>';
    exit;
}

$proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host  = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? '');
$baseUrl = rtrim($proto . '://' . $host, '/');

$path = parse_url((string)$r['url'], PHP_URL_PATH) ?: (string)$r['url'];

// kompatibel: bisa baca schema baru ATAU lama
$linkUrlValue = '';
if (array_key_exists('link_url', $r)) {
    $linkUrlValue = (string)($r['link_url'] ?? '');
} elseif (array_key_exists('target_url', $r)) {
    $linkUrlValue = (string)($r['target_url'] ?? '');
}

$linkTargetValue = '';
if (array_key_exists('link_target', $r)) {
    $linkTargetValue = (string)($r['link_target'] ?? '');
} elseif (array_key_exists('target_attribute', $r)) {
    $linkTargetValue = (string)($r['target_attribute'] ?? '');
}

if (!function_exists('human_filesize')) {
    function human_filesize(int $bytes, int $decimals = 1): string {
        if ($bytes <= 0) return '0 B';
        $units = ['B','KB','MB','GB','TB'];
        $i = (int)floor(log(max(1, $bytes), 1024));
        $i = min($i, count($units) - 1);
        return sprintf("%.{$decimals}f %s", $bytes / pow(1024, $i), $units[$i]);
    }
}

$csrf = csrf_token();

if (!function_exists('mdlib_has_column')) {
    function mdlib_has_column(string $col): bool {
        try {
            $st = $GLOBALS['pdo']->prepare("SELECT {$col} FROM media LIMIT 0");
            $st->execute();
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }
}
$hasVisibility = mdlib_has_column('visibility');

$visibility = $hasVisibility ? (strtolower((string)($r['visibility'] ?? 'public')) ?: 'public') : 'public';
$accessScope = $hasVisibility ? (strtolower((string)($r['access_scope'] ?? 'public')) ?: 'public') : 'public';
$isDownloadable = $hasVisibility ? (int)($r['is_downloadable'] ?? 1) : 1;
$isPrivate = ($visibility === 'private');
if (!function_exists('modalfilez_client_url')) {
    function modalfilez_client_url(array $row): string
    {
        $id = (int)($row['id'] ?? 0);
        $visibility = strtolower((string)($row['visibility'] ?? 'public'));
        $disk = strtolower((string)($row['storage_disk'] ?? 'public'));
        if ($id > 0 && ($visibility === 'private' || $disk === 'private')) {
            return '/private/media/view/?id=' . $id;
        }
        return (string)($row['url'] ?? '');
    }
}
$displayClientUrl = modalfilez_client_url($r);
?>
<div class="media-single-wrap">
  <div class="media-grid">
    <div class="media-left">
      <div class="img-frame" title="<?= htmlspecialchars((string)$r['filename'], ENT_QUOTES, 'UTF-8') ?>">
        <img src="<?= htmlspecialchars($displayClientUrl, ENT_QUOTES, 'UTF-8') ?>"
             alt="<?= htmlspecialchars((string)($r['alt'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
      </div>

      <div style="margin-top:10px;" class="meta-row">
        <div><strong>Filename:</strong> <?= htmlspecialchars((string)$r['filename'], ENT_QUOTES, 'UTF-8') ?></div>
        <div><strong>MIME:</strong> <?= htmlspecialchars((string)($r['mime'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></div>
        <div><strong>Size:</strong> <?= htmlspecialchars(human_filesize((int)($r['size'] ?? 0)), ENT_QUOTES, 'UTF-8') ?></div>
        <?php if (!empty($r['width']) || !empty($r['height'])): ?>
          <div><strong>Dim:</strong> <?= (int)$r['width'] ?> × <?= (int)$r['height'] ?></div>
        <?php endif; ?>
        <?php if ($hasVisibility): ?>
        <div style="margin-top:6px;display:flex;gap:5px;flex-wrap:wrap">
          <span class="badge" style="background:<?= $isPrivate ? '#fef3c7' : '#dcfce7' ?>;color:<?= $isPrivate ? '#92400e' : '#166534' ?>;padding:2px 7px;border-radius:999px;font-size:10px;font-weight:800"><?= htmlspecialchars(strtoupper($visibility), ENT_QUOTES, 'UTF-8') ?></span>
          <span class="badge" style="padding:2px 7px;border-radius:999px;font-size:10px;font-weight:800"><?= htmlspecialchars(strtoupper($accessScope), ENT_QUOTES, 'UTF-8') ?></span>
          <?php if (!$isDownloadable): ?>
            <span class="badge" style="background:#fee2e2;color:#991b1b;padding:2px 7px;border-radius:999px;font-size:10px;font-weight:800">NO DOWNLOAD</span>
          <?php endif; ?>
        </div>
        <?php endif; ?>
        <div style="margin-top:6px; color:#777; font-size:12px;">
          Uploaded: <?= htmlspecialchars((string)($r['created_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
        </div>
      </div>
    </div>

    <div class="media-right">
      <form id="media-edit-form" data-media-id="<?= (int)$r['id'] ?>">
        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$csrf, ENT_QUOTES, 'UTF-8') ?>">

        <label for="field-title"><?=_e('Title')?></label>
        <input id="field-title" type="text" name="title" value="<?= htmlspecialchars((string)($r['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">

        <label for="field-alt"><?=_e('Alt')?></label>
...
        <label for="field-caption"><?=_e('Caption')?></label>
...
          <label for="field-access-scope"><?=_e('Access Scope')?></label>
...
        <label for="field-target-attr"><?=_e('Open behavior')?></label>
        <select id="field-target-attr" name="target_attribute">
          <option value="">Default</option>
          <option value="_self"   <?= ($linkTargetValue === '_self') ? 'selected' : '' ?>>Open in same tab (_self)</option>
          <option value="_blank"  <?= ($linkTargetValue === '_blank') ? 'selected' : '' ?>>Open in new tab (_blank)</option>
          <option value="_parent" <?= ($linkTargetValue === '_parent') ? 'selected' : '' ?>>_parent</option>
          <option value="_top"    <?= ($linkTargetValue === '_top') ? 'selected' : '' ?>>_top</option>
        </select>

        <label>File URL (read-only)</label>
        <div class="media-url-row">
          <span class="media-url-prefix" id="media-url-prefix"><?= htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8') ?></span>
          <input type="text" id="media-url-path" class="media-url-path" readonly value="<?= htmlspecialchars($path, ENT_QUOTES, 'UTF-8') ?>">
          <button type="button" class="copy-btn" data-action="copy-url">Copy</button>
        </div>

        <div class="actions">
          <button type="button" class="media-btn media-btn-save" id="media-save-btn">Save</button>
          <button type="button" class="media-btn media-btn-delete" id="media-delete-btn">Delete</button>
        </div>
      </form>
    </div>
  </div>
</div>