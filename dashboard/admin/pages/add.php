<?php
declare(strict_types=1);

// /adiwira/admin/pages/halaman.php
require_once __DIR__ . '/../_deny.php';

if (!defined('DASHBOARD_CONTEXT') && !defined('ADAM_THEME')) {
    adiwira_admin_404();
}

require_once __DIR__ . '/../_guard.php';
require_once __DIR__ . '/../_notify.php';

[$uid] = adiwira_require_permission($pdo, 'core.pages.create', false);
$canPublish = user_can($pdo, $uid, 'core.pages.publish', ['owner_id' => $uid]);
$canChangeDates = user_can($pdo, $uid, 'core.pages.change_dates', ['owner_id' => $uid]);
$canUseUnfilteredHtml = user_can($pdo, $uid, 'core.pages.unfiltered_html');

if (!function_exists('slugify')) {
    function slugify(string $text): string {
        $text = mb_strtolower($text, 'UTF-8');
        $text = preg_replace('/[^\p{L}\p{N}\-]+/u', '-', $text);
        $text = preg_replace('/[-]{2,}/', '-', $text);
        $text = trim((string)$text, '-');
        return $text ?: bin2hex(random_bytes(4));
    }
}

if (!function_exists('parse_datetime_local')) {
    function parse_datetime_local(string $s): ?string {
        $s = trim($s);
        if ($s === '') return null;
        $d = DateTime::createFromFormat('Y-m-d\\TH:i', $s, new DateTimeZone('Asia/Jakarta'));
        if ($d !== false) return $d->format('Y-m-d H:i:s');
        try {
            $d2 = new DateTime($s, new DateTimeZone('Asia/Jakarta'));
            return $d2->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            return null;
        }
    }
}

$base = ADMIN_BASE_PATH;
$return_to = function_exists('adiwira_safe_return_to')
    ? adiwira_safe_return_to((string)($_REQUEST['return_to'] ?? ''), $base . '/?page=admin/pages/index')
    : ($base . '/?page=admin/pages/index');

$errors = [];
$enable_custom_meta = ($pdo instanceof PDO && function_exists('settings_get'))
    ? (settings_get($pdo, 'enable_custom_meta', '0') === '1')
    : false;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $token = (string)($_POST['csrf_token'] ?? '');
    if (!adiwira_csrf_validate($token)) {
        $errors[] = __('Invalid CSRF token.');
    }

    $title     = trim((string)($_POST['title'] ?? ''));
    $slug      = trim((string)($_POST['slug'] ?? ''));
    $content   = (string)($_POST['content'] ?? '');
    $status    = in_array($_POST['status'] ?? '', ['draft', 'published', 'private'], true) ? (string)$_POST['status'] : 'draft';
    $thumbnail = trim((string)($_POST['thumbnail'] ?? '')) ?: null;

    $created_at_in = trim((string)($_POST['created_at'] ?? ''));
    $updated_at_in = trim((string)($_POST['updated_at'] ?? ''));

    $title = trim((string)preg_replace('/[\x00-\x1F\x7F]/u', '', strip_tags($title)));

    if ($title === '') {
        $errors[] = __('Title is required.');
    }

    if (!$canUseUnfilteredHtml) $content = cms_sanitize_restricted_html($content);

if (function_exists('normalize_links_in_html') && class_exists('DOMDocument')) {
    $content = normalize_links_in_html($content);
}

    if (trim(strip_tags($content)) === '') {
        $errors[] = __('Content is required.');
    }

    $slug = $slug === '' ? slugify($title) : slugify($slug);

    if (empty($errors)) {
        $s = $pdo->prepare("
            SELECT id
            FROM posts
            WHERE slug = :slug
              AND type IN ('article', 'page', 'theme')
              AND is_deleted = 0
            LIMIT 1
        ");
        $s->execute([':slug' => $slug]);
        if ($s->fetch()) {
            $errors[] = __('Slug already used.');
        }
    }

    $created_at_parsed = null;
    $updated_at_parsed = null;

    if ($status !== 'draft' && !$canPublish) $errors[] = __('Access denied.');
    if (($created_at_in !== '' || $updated_at_in !== '') && !$canChangeDates) $errors[] = __('Access denied.');
    if ($created_at_in !== '') {
        $created_at_parsed = parse_datetime_local($created_at_in);
        if ($created_at_parsed === null) {
            $errors[] = __('Invalid Created At format.');
        }
    }
    if ($updated_at_in !== '') {
        $updated_at_parsed = parse_datetime_local($updated_at_in);
        if ($updated_at_parsed === null) {
            $errors[] = __('Invalid Updated At format.');
        }
    }

    if (empty($errors)) {
        $final_created = $created_at_parsed ?? (new DateTime('now', new DateTimeZone('Asia/Jakarta')))->format('Y-m-d H:i:s');
        $final_updated = $updated_at_parsed ?? (new DateTime('now', new DateTimeZone('Asia/Jakarta')))->format('Y-m-d H:i:s');

        $sidebarOverride = (string)($_POST['sidebar_override'] ?? '');
        if ($sidebarOverride !== '' && !in_array($sidebarOverride, ['right', 'left', 'hide'], true)) {
            $sidebarOverride = '';
        }
        $metaDescription = trim((string)($_POST['meta_description'] ?? ''));
        $pageMeta = [];
        if ($sidebarOverride !== '') {
            $pageMeta['sidebar'] = $sidebarOverride;
        }
        if ($metaDescription !== '') {
            $pageMeta['meta_tags']['description'] = $metaDescription;
        }
        $metaVal = !empty($pageMeta) ? json_encode($pageMeta, JSON_UNESCAPED_UNICODE) : null;

        try {
            $requiresDatePermission = $created_at_in !== '' || $updated_at_in !== '';
            $page_id = shortcode_collection_layout_content_mutation($pdo, static function () use ($pdo, $title, $slug, $content, $metaVal, $thumbnail, $status, $uid, $final_created, $final_updated, $requiresDatePermission): int {
                $pdo->beginTransaction();
                try {
                if (!authorization_lock_actor_permissions($pdo, $uid)) throw new DomainException('Page actor permission lock failed.');
                if (!user_can($pdo, $uid, 'core.pages.unfiltered_html')) $content = cms_sanitize_restricted_html($content);
                if (!user_can($pdo, $uid, 'core.pages.create')) throw new DomainException('Page create permission changed.');
                if ($status !== 'draft' && !user_can($pdo, $uid, 'core.pages.publish', ['owner_id' => $uid])) {
                    throw new DomainException('Page publish permission changed.');
                }
                if ($requiresDatePermission && !user_can($pdo, $uid, 'core.pages.change_dates', ['owner_id' => $uid])) {
                    throw new DomainException('Page date permission changed.');
                }
                $slugLock = $pdo->prepare("SELECT id FROM posts WHERE slug = :slug AND type IN ('article', 'page', 'theme') AND is_deleted = 0 LIMIT 1 FOR UPDATE");
                $slugLock->execute([':slug' => $slug]);
                if ($slugLock->fetchColumn()) throw new DomainException('Page slug changed.');
                $stmt = $pdo->prepare("
                    INSERT INTO posts
                    (title, slug, content, type, meta, thumbnail, status, created_by, updated_by, created_at, updated_at)
                    VALUES
                    (:title, :slug, :content, 'page', :meta, :thumbnail, :status, :created_by, :updated_by, :created_at, :updated_at)
                ");
                $ok = $stmt->execute([
                    ':title'      => $title,
                    ':slug'       => $slug,
                    ':content'    => $content,
                    ':meta'       => $metaVal,
                    ':thumbnail'  => $thumbnail,
                    ':status'     => $status,
                    ':created_by' => $uid,
                    ':updated_by' => $uid,
                    ':created_at' => $final_created,
                    ':updated_at' => $final_updated,
                ]);
                if (!$ok) throw new RuntimeException('Page insert failed.');
                $pageId = (int)$pdo->lastInsertId();
                do_action('admin_page_before_add_commit', $pageId, $pdo, [
                    'title' => $title,
                    'slug' => $slug,
                    'content' => $content,
                    'status' => $status,
                    'created_by' => $uid,
                ]);
                $pdo->commit();
                return $pageId;
                } catch (Throwable $error) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    throw $error;
                }
            });

            if ($page_id > 0) {
                do_action('admin_page_after_add', $page_id, $pdo, $_POST);
                adiwira_redirect_with_flash($return_to, 'success', __('Page saved successfully.'));
            }

            $errors[] = __('Failed to create page.');
        } catch (Throwable $e) {
            error_log('pages/halaman.php insert error: ' . $e->getMessage());
            $errors[] = __('Failed to create page.');
        }
    }
}
?>

<section class="adam-card">
  <h2 class="edit-heading"><?=_e('Add Page')?></h2>

  <form id="page-add-form" method="post" novalidate data-unsaved-guard>
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="return_to" value="<?= htmlspecialchars($return_to, ENT_QUOTES, 'UTF-8') ?>">

    <div class="adam-accordion" id="theme-meta-accordion" data-open="1">
      <button type="button"
              class="adam-accordion-toggle"
              aria-expanded="true"
              aria-controls="theme-meta-body">
        <?= svg_ico('cog', '', ['style' => 'width:16px;height:16px;vertical-align:middle;margin-right:4px']) ?> <?=_e('Page Settings')?>
        <span class="chevron">▸</span>
      </button>

      <div class="adam-accordion-body" id="theme-meta-body">
        <label><?=_e('Title')?> <span class="field-required" aria-hidden="true">*</span><span class="sr-only"> (<?=_e('Required')?>)</span><br>
          <input type="text" name="title" value="<?= htmlspecialchars($_POST['title'] ?? '', ENT_QUOTES, 'UTF-8') ?>" class="inpud" required aria-required="true">
        </label>

        <div class="field-heading">
          <label for="page-add-slug"><?=_e('Slug (optional)')?></label>
          <span class="field-help"><button type="button" class="field-help__trigger" aria-label="<?= htmlspecialchars(__('What is a slug?'), ENT_QUOTES, 'UTF-8') ?>" aria-describedby="page-add-slug-help" aria-controls="page-add-slug-help" aria-expanded="false">?</button><span id="page-add-slug-help" class="field-help__tooltip" role="tooltip"><?= htmlspecialchars(__('A slug is the URL-friendly part of the web address. Leave it empty to generate one automatically from the title.'), ENT_QUOTES, 'UTF-8') ?></span></span>
        </div>
        <input type="text" id="page-add-slug" name="slug" value="<?= htmlspecialchars($_POST['slug'] ?? '', ENT_QUOTES, 'UTF-8') ?>" class="inpud">

        <label><?=_e('Thumbnail')?><br>
          <div class="thumb-row">
            <?php if (!$canUseUnfilteredHtml): ?>
            <input type="hidden" id="thumbnail-input" name="thumbnail" value="<?= htmlspecialchars($_POST['thumbnail'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
            <?php else: ?>
            <input type="text" id="thumbnail-input" name="thumbnail" value="<?= htmlspecialchars($_POST['thumbnail'] ?? '', ENT_QUOTES, 'UTF-8') ?>" class="inpud" placeholder="<?=_e('Thumbnail URL')?>" style="display:none">
            <?php endif; ?>
            <button type="button" id="btn-open-media-for-thumb" class="thumb-gallery-btn">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/></svg>
              <?=_e('Gallery')?>
            </button>
            <?php if ($canUseUnfilteredHtml): ?>
            <button type="button" id="btn-toggle-url-input" class="thumb-url-btn"><?=_e('Insert via URL')?></button>
            <?php endif; ?>
            <button type="button" id="thumbnail-clear" class="thumb-clear-btn" title="<?=_e('Clear')?>" style="<?= empty($_POST['thumbnail']) ? 'display:none' : '' ?>">&times;</button>
          </div>
          <div id="thumbnail-preview" style="margin-top:.6rem;">
            <?php if (!empty($_POST['thumbnail'])): ?>
              <img src="<?= htmlspecialchars($_POST['thumbnail'], ENT_QUOTES, 'UTF-8') ?>" alt="preview" style="max-width:220px;max-height:140px;border:1px solid #eee;padding:.3rem">
            <?php endif; ?>
          </div>
        </label>
      </div>
    </div>

    <div id="page-add-content-label" class="field-label" data-required-editor-label><?=_e('Content (rich text)')?> <span class="field-required" aria-hidden="true">*</span><span class="sr-only"> (<?=_e('Required')?>)</span></div>
    <div id="quill-editor-box" class="adam-quill adam-quill--auto" style="margin-top:.4rem;">
      <div id="quill-editor"></div>
    </div>

    <input type="hidden" name="content" id="content-input" value="<?= htmlspecialchars($_POST['content'] ?? '', ENT_QUOTES, 'UTF-8') ?>">

    <div id="media-single-panel" style="margin-top:12px;border:1px solid #eee;padding:10px;border-radius:6px;display:none;background:#fff;max-width:480px">
      <div id="media-single-content"><?=_e('Click an image in Media to view details & edit.')?></div>
    </div>

    <label><?=_e('Status')?><br>
      <select name="status" style="padding:.4rem;border:1px solid #ddd;border-radius:6px;">
        <option value="draft" <?= (($_POST['status'] ?? '') === 'draft') ? 'selected' : '' ?>><?= _e('Draft') ?></option>
        <?php if ($canPublish): ?>
          <option value="published" <?= (($_POST['status'] ?? '') === 'published') ? 'selected' : '' ?>><?= _e('Published') ?></option>
          <option value="private" <?= (($_POST['status'] ?? '') === 'private') ? 'selected' : '' ?>><?= _e('Private') ?></option>
        <?php endif; ?>
      </select>
    </label>

    <?php if ($canChangeDates): ?>
      <label style="display:block;margin-top:.6rem"><?=_e('Created At (optional)')?><br>
        <input type="datetime-local" name="created_at" value="<?= htmlspecialchars($_POST['created_at'] ?? '', ENT_QUOTES, 'UTF-8') ?>" style="padding:.4rem;border:1px solid #ddd;border-radius:6px">
        <div style="font-size:12px;color:#666;margin-top:4px"><?=_e('Leave empty for current time (GMT+7).')?></div>
      </label>

      <label style="display:block;margin-top:.6rem"><?=_e('Updated At (optional)')?><br>
        <input type="datetime-local" name="updated_at" value="<?= htmlspecialchars($_POST['updated_at'] ?? '', ENT_QUOTES, 'UTF-8') ?>" style="padding:.4rem;border:1px solid #ddd;border-radius:6px">
        <div style="font-size:12px;color:#666;margin-top:4px"><?=_e('Leave empty for current time (GMT+7).')?></div>
      </label>
    <?php endif; ?>

    <div style="margin-top:.6rem;padding-top:.6rem;border-top:1px solid var(--adam-border);">
      <div style="font-size:13px;font-weight:600;margin-bottom:.4rem"><?= svg_ico('columns-2', '', ['style' => 'width:16px;height:16px;vertical-align:middle;margin-right:4px']) ?> <?=_e('Sidebar Position')?></div>
      <select name="sidebar_override" style="width:100%;padding:.4rem .5rem;border:1px solid var(--adam-border-2);border-radius:6px;background:var(--adam-card);color:var(--adam-text);font-size:.9rem;box-sizing:border-box">
        <option value=""><?=_e('Default (follow global hierarchy)')?></option>
        <option value="right" <?= (($_POST['sidebar_override'] ?? '') === 'right') ? 'selected' : '' ?><?=_e('Right')?></option>
        <option value="left" <?= (($_POST['sidebar_override'] ?? '') === 'left') ? 'selected' : '' ?><?=_e('Left')?></option>
        <option value="hide" <?= (($_POST['sidebar_override'] ?? '') === 'hide') ? 'selected' : '' ?><?=_e('Hide')?></option>
      </select>
    </div>

    <?php if ($enable_custom_meta): ?>
    <div style="margin-top:.6rem;padding-top:.6rem;border-top:1px solid var(--adam-border);">
      <div style="font-size:13px;font-weight:600;margin-bottom:.4rem"><?= svg_ico('search', '', ['style' => 'width:16px;height:16px;vertical-align:middle;margin-right:4px']) ?> <?=_e('Meta Description')?></div>
      <textarea name="meta_description" rows="3" style="width:100%;padding:.4rem;border:1px solid var(--adam-border-2);border-radius:4px;background:var(--adam-card);color:var(--adam-text);font-size:13px;resize:vertical;box-sizing:border-box" maxlength="320" placeholder="<?= _e('Custom meta description for SEO & social share. Leave empty to auto-generate from content.') ?>"><?= htmlspecialchars($_POST['meta_description'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
      <div style="font-size:11px;color:#888;margin-top:3px"><?=_e('Recommended: 150-160 characters. Falls back to excerpt when empty.')?></div>
    </div>
    <?php endif; ?>

    <p style="margin-top:.8rem">
      <button type="submit" class="adam-button"><?=_e('Save')?></button>
      <a href="<?= htmlspecialchars($return_to, ENT_QUOTES, 'UTF-8') ?>" class="adam-cancle"><?=_e('Cancel')?></a>
    </p>
  </form>
</section>

<?php
if (!empty($errors) && function_exists('adiwira_bootstrap_toasts_script')) {
    $items = array_map(static fn($msg) => ['type' => 'error', 'message' => (string)$msg], $errors);
    echo adiwira_bootstrap_toasts_script($items);
}
?>

<script>
  window.ADIWIRA = window.ADIWIRA || {};
  window.ADIWIRA_BASE = <?= json_encode($base) ?>;
</script>

<script src="/static/js/add/modal-helpers.js"></script>
<script src="/static/js/add/media-selector.js"></script>
<script src="/static/js/add/file-selector.js"></script>
<script>window.QUILL_PLACEHOLDER = <?= json_encode(__('Write article content here...')) ?>;</script>
<script src="/static/js/add/quill-init.js"></script>
<script src="/static/js/add/thumbnail-handler.js"></script>
