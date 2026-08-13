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
<div class="media-single-wrap asset-detail">
  <div class="media-single-card asset-detail-card">
    <div class="media-grid">
      <div class="media-left">
        <div class="img-frame" title="<?= htmlspecialchars((string)$r['filename'], ENT_QUOTES, 'UTF-8') ?>">
          <img src="<?= htmlspecialchars($displayClientUrl, ENT_QUOTES, 'UTF-8') ?>"
               alt="<?= htmlspecialchars((string)($r['alt'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
        </div>

        <div class="media-meta">
          <div class="media-meta-row">
            <span class="media-meta-label"><?=_e('Filename')?></span>
            <span class="media-meta-value"><?= htmlspecialchars((string)$r['filename'], ENT_QUOTES, 'UTF-8') ?></span>
          </div>
          <div class="media-meta-row">
            <span class="media-meta-label"><?=_e('MIME')?></span>
            <span class="media-meta-value"><?= htmlspecialchars((string)($r['mime'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></span>
          </div>
          <div class="media-meta-row">
            <span class="media-meta-label"><?=_e('Size')?></span>
            <span class="media-meta-value"><?= htmlspecialchars(human_filesize((int)($r['size'] ?? 0)), ENT_QUOTES, 'UTF-8') ?></span>
          </div>
          <?php if (!empty($r['width']) || !empty($r['height'])): ?>
          <div class="media-meta-row">
            <span class="media-meta-label"><?=_e('Dimensions')?></span>
            <span class="media-meta-value"><?= (int)$r['width'] ?> &times; <?= (int)$r['height'] ?> px</span>
          </div>
          <?php endif; ?>
          <div class="media-meta-row">
            <span class="media-meta-label"><?=_e('Uploaded')?></span>
            <span class="media-meta-value"><?= htmlspecialchars((string)($r['created_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
          </div>
          <?php if ($hasVisibility): ?>
          <div class="media-meta-badges">
            <span class="badge badge--<?= $isPrivate ? 'warn' : 'ok' ?>"><?= htmlspecialchars(strtoupper($visibility), ENT_QUOTES, 'UTF-8') ?></span>
            <span class="badge badge--info"><?= htmlspecialchars(content_access_scope_label($accessScope), ENT_QUOTES, 'UTF-8') ?></span>
            <?php if (!$isDownloadable): ?>
              <span class="badge badge--danger"><?=_e('NO DOWNLOAD')?></span>
            <?php endif; ?>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="media-right">
        <div class="asset-detail-kicker"><?=_e('Media')?> / <?=_e('Details')?></div>
        <h3 class="asset-detail-title"><?= htmlspecialchars((string)($r['title'] ?: $r['filename']), ENT_QUOTES, 'UTF-8') ?></h3>
        <div class="asset-detail-subtitle"><?= htmlspecialchars((string)$r['filename'], ENT_QUOTES, 'UTF-8') ?></div>
        <a class="asset-detail-open" href="<?= htmlspecialchars($displayClientUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener"><?=_e('Open in new tab')?> <span aria-hidden="true">&nearr;</span></a>
        <div class="media-section-title"><?=_e('Metadata')?></div>

        <form id="media-edit-form" class="asset-detail-fields" data-media-id="<?= (int)$r['id'] ?>">
          <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$csrf, ENT_QUOTES, 'UTF-8') ?>">

          <label for="field-title"><?=_e('Title')?></label>
          <input id="field-title" type="text" name="title" value="<?= htmlspecialchars((string)($r['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">

          <label for="field-alt"><?=_e('Alt Text')?></label>
          <input id="field-alt" type="text" name="alt" value="<?= htmlspecialchars((string)($r['alt'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">

          <label for="field-caption"><?=_e('Caption')?></label>
          <textarea id="field-caption" name="caption" rows="3"><?= htmlspecialchars((string)($r['caption'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>

          <label for="field-credit"><?=_e('Credit')?></label>
          <input id="field-credit" type="text" name="credit" value="<?= htmlspecialchars((string)($r['credit'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">

          <label for="field-target-url"><?=_e('Target URL')?></label>
          <input id="field-target-url" type="text" name="target_url" value="<?= htmlspecialchars($linkUrlValue, ENT_QUOTES, 'UTF-8') ?>">

          <label for="field-access-scope"><?=_e('Access Scope')?></label>
          <select id="field-access-scope" name="access_scope" <?= $visibility === 'public' ? 'disabled' : '' ?>>
            <option value="public" <?= $accessScope === 'public' ? 'selected' : '' ?>><?=_e('Public')?></option>
            <option value="editorial" <?= in_array($accessScope, ['editorial','employee','both'], true) ? 'selected' : '' ?>><?=_e('Content Team')?></option>
            <option value="admin" <?= $accessScope === 'admin' ? 'selected' : '' ?>><?=_e('Administrator')?></option>
          </select>
          <?php if ($visibility === 'public'): ?><div class="file-url-hint"><?=_e('Public media always has public access scope. For private, re-upload in Private mode.')?></div><?php endif; ?>

          <label class="file-check-label">
            <input type="checkbox" name="is_downloadable" value="1" <?= $isDownloadable ? 'checked' : '' ?>>
            <?=_e('Allow download')?>
          </label>

          <label for="field-target-attr"><?=_e('Open behavior')?></label>
          <select id="field-target-attr" name="target_attribute">
            <option value=""><?=_e('Default')?></option>
            <option value="_self"   <?= ($linkTargetValue === '_self') ? 'selected' : '' ?>><?=_e('Same tab')?> (_self)</option>
            <option value="_blank"  <?= ($linkTargetValue === '_blank') ? 'selected' : '' ?>><?=_e('New tab')?> (_blank)</option>
            <option value="_parent" <?= ($linkTargetValue === '_parent') ? 'selected' : '' ?>><?=_e('Parent')?> (_parent)</option>
            <option value="_top"    <?= ($linkTargetValue === '_top') ? 'selected' : '' ?>><?=_e('Top')?> (_top)</option>
          </select>

          <div class="media-url-section">
            <div class="media-section-title"><?=_e('File URL')?></div>
            <div class="media-url-row">
              <span class="media-url-prefix" id="media-url-prefix"><?= htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8') ?></span>
              <input type="text" id="media-url-path" class="media-url-path" readonly value="<?= htmlspecialchars($path, ENT_QUOTES, 'UTF-8') ?>">
              <button type="button" class="copy-btn" data-action="copy-url"><?=_e('Copy')?></button>
            </div>
          </div>

          <div class="actions">
            <button type="button" class="media-btn media-btn-save" id="media-save-btn"><?=_e('Save')?></button>
            <button type="button" class="media-btn media-btn-delete" id="media-delete-btn"><?=_e('Delete')?></button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>
