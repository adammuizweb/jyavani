<?php
// /adiwira/admin/posts/artikel.php
require_once __DIR__ . '/../_deny.php';

if (!defined('DASHBOARD_CONTEXT') && !defined('ADAM_THEME')) {
    adiwira_admin_404();
}

require_once __DIR__ . '/../_guard.php';
require_once __DIR__ . '/../_notify.php';

[$uid] = adiwira_require_permission($pdo, 'core.posts.create', false);
$canPublish = user_can($pdo, $uid, 'core.posts.publish', ['owner_id' => $uid]);
$canChangeDates = user_can($pdo, $uid, 'core.posts.change_dates', ['owner_id' => $uid]);
$canUseUnfilteredHtml = user_can($pdo, $uid, 'core.posts.unfiltered_html');

if (!function_exists('slugify')) {
    function slugify(string $text): string {
        $text = mb_strtolower($text, 'UTF-8');
        $text = preg_replace('/[^\p{L}\p{N}\-]+/u', '-', $text);
        $text = preg_replace('/[-]{2,}/', '-', $text);
        $text = trim((string)$text, '-');
        return $text ?: bin2hex(random_bytes(4));
    }
}

if (!function_exists('sanitize_author_html')) {
    function sanitize_author_html(string $html): string {
        if (function_exists('cms_sanitize_restricted_html')) return cms_sanitize_restricted_html($html);
        $html = trim($html);
        if ($html === '') return '';

        $allowedTags = array_flip([
            'p','br','hr',
            'strong','b','em','i','u','s',
            'blockquote','pre','code',
            'h1','h2','h3','h4','h5','h6',
            'ul','ol','li',
            'a',
            'img','figure','figcaption',
            'table','thead','tbody','tfoot','tr','th','td',
            'span','div'
        ]);

        $allowedAttrs = [
            'a'   => ['href','title','target','rel'],
            'img' => ['src','alt','title','width','height'],
            'div' => ['class'],
            'span'=> ['class'],
            'p'   => ['class'],
            'th'  => ['colspan','rowspan'],
            'td'  => ['colspan','rowspan'],
            '*'   => ['class'],
        ];

        $blockTags = ['script','iframe','object','embed','link','meta','style'];

        $prev = libxml_use_internal_errors(true);
        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

        $xpath = new DOMXPath($doc);
        foreach ($xpath->query('//comment()') as $c) {
            $c->parentNode?->removeChild($c);
        }

        $walker = function(DOMNode $node) use (&$walker, $allowedTags, $allowedAttrs, $blockTags) {
            if ($node->nodeType === XML_ELEMENT_NODE) {
                $tag = strtolower($node->nodeName);

                if (in_array($tag, $blockTags, true)) {
                    $node->parentNode?->removeChild($node);
                    return;
                }

                if (!isset($allowedTags[$tag])) {
                    $parent = $node->parentNode;
                    if ($parent) {
                        while ($node->firstChild) {
                            $parent->insertBefore($node->firstChild, $node);
                        }
                        $parent->removeChild($node);
                    }
                    return;
                }

                if ($node->hasAttributes()) {
                    $toRemove = [];
                    foreach (iterator_to_array($node->attributes) as $attr) {
                        $name = strtolower($attr->name);
                        $val  = (string)$attr->value;

                        if (str_starts_with($name, 'on') || $name === 'style') {
                            $toRemove[] = $attr->name;
                            continue;
                        }

                        $allowedForTag = $allowedAttrs[$tag] ?? [];
                        $allowedForAll = $allowedAttrs['*'] ?? [];

                        if (!in_array($name, $allowedForTag, true) && !in_array($name, $allowedForAll, true)) {
                            $toRemove[] = $attr->name;
                            continue;
                        }

                        if (($tag === 'a' && $name === 'href') || ($tag === 'img' && $name === 'src')) {
                            $v = trim($val);
                            $ok = false;
                            if ($v === '' || $v[0] === '#' || $v[0] === '/') $ok = true;
                            if (!$ok && preg_match('#^https?://#i', $v)) $ok = true;
                            if (!$ok && $tag === 'a' && preg_match('#^mailto:#i', $v)) $ok = true;

                            if (preg_match('#^(javascript:|data:|vbscript:)#i', $v)) $ok = false;

                            if (!$ok) $toRemove[] = $attr->name;
                        }
                    }

                    foreach ($toRemove as $r) {
                        $node->removeAttribute($r);
                    }

                    if ($tag === 'a') {
                        $t = $node->getAttribute('target');
                        if (strtolower($t) === '_blank') {
                            $rel = trim($node->getAttribute('rel'));
                            $need = ['noopener','noreferrer'];
                            foreach ($need as $n) {
                                if (!preg_match('/\b'.preg_quote($n,'/').'\b/i', $rel)) {
                                    $rel = trim($rel . ' ' . $n);
                                }
                            }
                            $node->setAttribute('rel', $rel);
                        }
                    }
                }
            }

            $children = [];
            foreach ($node->childNodes as $ch) $children[] = $ch;
            foreach ($children as $ch) $walker($ch);
        };

        $walker($doc);

        $out = $doc->saveHTML() ?: '';
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        return trim($out);
    }
}

$categoryReadCondition = authorization_owner_scope_condition(
    $pdo,
    $uid,
    'core.categories.read',
    'categories.created_by',
    'post_add_category_read'
);
$categoryReadWhere = $categoryReadCondition !== null ? ' AND (' . $categoryReadCondition['sql'] . ')' : ' AND 1=0';
$stmt = $pdo->prepare("SELECT id, name, parent_id FROM categories WHERE is_deleted = 0 $categoryReadWhere ORDER BY parent_id ASC, name ASC");
$stmt->execute($categoryReadCondition['params'] ?? []);
$all_categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
$visibleCategoryIds = array_fill_keys(array_map(static fn(array $category): int => (int)$category['id'], $all_categories), true);
foreach ($all_categories as &$visibleCategory) {
    $parentId = (int)($visibleCategory['parent_id'] ?? 0);
    if ($parentId > 0 && !isset($visibleCategoryIds[$parentId])) $visibleCategory['parent_id'] = 0;
}
unset($visibleCategory);

if (!function_exists('render_category_tree')) {
    function render_category_tree(array $categories, array $selected = [], int $parent_id = 0, int $depth = 0): void {
        foreach ($categories as $cat) {
            if ((int)$cat['parent_id'] !== $parent_id) continue;
            $id = (int)$cat['id'];
            $checked = in_array($id, $selected) ? 'checked' : '';
            echo '<label style="display:block;margin:3px 0 3px '.(10 * $depth).'px">';
            echo '<input type="checkbox" name="categories[]" value="'.$id.'" '.$checked.'> ';
            echo htmlspecialchars($cat['name'], ENT_QUOTES, 'UTF-8');
            echo '</label>';
            render_category_tree($categories, $selected, $id, $depth + 1);
        }
    }
}

if (!function_exists('to_datetime_local')) {
    function to_datetime_local(?string $mysqlDt): ?string {
        if (!$mysqlDt) return null;
        try {
            $d = new DateTime($mysqlDt, new DateTimeZone('Asia/Jakarta'));
            return $d->format('Y-m-d\\TH:i');
        } catch (Exception $e) {
            return null;
        }
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
    ? adiwira_safe_return_to((string)($_REQUEST['return_to'] ?? ''), $base . '/?page=admin/posts/index')
    : ($base . '/?page=admin/posts/index');

$errors = [];
$enable_custom_meta = ($pdo instanceof PDO && function_exists('settings_get'))
    ? (settings_get($pdo, 'enable_custom_meta', '0') === '1')
    : false;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!csrf_check($token)) {
        $errors[] = __('Invalid CSRF token.');
    }

    $title   = trim((string)($_POST['title'] ?? ''));
    $slug    = trim((string)($_POST['slug'] ?? ''));
    $content = (string)($_POST['content'] ?? '');

    $status = in_array($_POST['status'] ?? '', ['draft','published','private'], true)
        ? (string)$_POST['status']
        : 'draft';

    $youtube      = trim((string)($_POST['youtube'] ?? '')) ?: null;
    $thumbnail    = trim((string)($_POST['thumbnail'] ?? '')) ?: null;
    $category_ids = (array)($_POST['categories'] ?? []);

    $title = trim((string)preg_replace('/[\x00-\x1F\x7F]/u', '', strip_tags($title)));
    if ($title === '') $errors[] = __('Title is required.');

    if (!$canUseUnfilteredHtml) {
        $content = sanitize_author_html($content);
        if (function_exists('normalize_links_in_html')) {
            $content = normalize_links_in_html($content);
        }
    }

    if (trim(strip_tags($content)) === '') $errors[] = __('Content is required.');

    if ($youtube !== null && !preg_match('/^(https?:\/\/)?(www\.)?(youtube\.com|youtu\.be)\//i', $youtube)) {
        $errors[] = __('Invalid YouTube link.');
    }

    $slug = ($slug === '') ? slugify($title) : slugify($slug);

    if (empty($errors)) {
        $s = $pdo->prepare("SELECT id FROM posts WHERE slug = :slug LIMIT 1");
        $s->execute([':slug' => $slug]);
        if ($s->fetch()) $errors[] = __('Slug already taken. Choose another slug.');
    }

    $created_at_in = trim((string)($_POST['created_at'] ?? ''));
    $updated_at_in = trim((string)($_POST['updated_at'] ?? ''));

    if ($status !== 'draft' && !$canPublish) {
        $errors[] = __('Access denied.');
    }
    if (($created_at_in !== '' || $updated_at_in !== '') && !$canChangeDates) {
        $errors[] = __('Access denied.');
    }

    $created_at_parsed = null;
    $updated_at_parsed = null;

    if ($created_at_in !== '') {
        $created_at_parsed = parse_datetime_local($created_at_in);
        if ($created_at_parsed === null) $errors[] = __('Invalid Created At format.');
    }

    if ($updated_at_in !== '') {
        $updated_at_parsed = parse_datetime_local($updated_at_in);
        if ($updated_at_parsed === null) $errors[] = __('Invalid Updated At format.');
    }

    $category_ids = array_values(array_unique(array_filter(array_map('intval', $category_ids), fn($v) => $v > 0)));
    if (!empty($category_ids)) {
        $ph = implode(',', array_fill(0, count($category_ids), '?'));
        $v = $pdo->prepare("SELECT id, created_by FROM categories WHERE id IN ($ph) AND is_deleted=0");
        $v->execute($category_ids);
        $categoryRows = $v->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (count($categoryRows) !== count($category_ids)) {
            $errors[] = __('Invalid category.');
        }
        foreach ($categoryRows as $categoryRow) {
            if (!user_can($pdo, $uid, 'core.categories.read', ['owner_id' => (int)($categoryRow['created_by'] ?? 0)])) {
                $errors[] = __('Access denied.');
                break;
            }
        }
    }

    if (empty($errors)) {
        $final_created = $created_at_parsed ?? (new DateTime('now', new DateTimeZone('Asia/Jakarta')))->format('Y-m-d H:i:s');
        $final_updated = $updated_at_parsed ?? (new DateTime('now', new DateTimeZone('Asia/Jakarta')))->format('Y-m-d H:i:s');
        $requiresDatePermission = $created_at_in !== '' || $updated_at_in !== '';

        $sidebarOverride = (string)($_POST['sidebar_override'] ?? '');
        if ($sidebarOverride !== '' && !in_array($sidebarOverride, ['right', 'left', 'hide'], true)) {
            $sidebarOverride = '';
        }
        $metaDescription = trim((string)($_POST['meta_description'] ?? ''));
        $postMeta = [];
        if ($sidebarOverride !== '') {
            $postMeta['sidebar'] = $sidebarOverride;
        }
        if ($metaDescription !== '') {
            $postMeta['meta_tags']['description'] = $metaDescription;
        }
        $metaVal = !empty($postMeta) ? json_encode($postMeta, JSON_UNESCAPED_UNICODE) : null;

        $insertSql = "INSERT INTO posts
            (title, slug, content, type, meta, youtube, thumbnail, status, created_by, created_at, updated_at)
            VALUES
            (:title, :slug, :content, 'article', :meta, :youtube, :thumbnail, :status, :created_by, :created_at, :updated_at)";
        try {
            $post_id = shortcode_collection_layout_content_mutation($pdo, static function () use ($pdo, $insertSql, $title, $slug, $content, $metaVal, $youtube, $thumbnail, $status, $uid, $final_created, $final_updated, $category_ids, $requiresDatePermission): int {
                $pdo->beginTransaction();
                try {
                if (!authorization_lock_actor_permissions($pdo, $uid)) {
                    throw new DomainException('Post actor permission lock failed.');
                }
                if (!user_can($pdo, $uid, 'core.posts.unfiltered_html')) {
                    $content = cms_sanitize_restricted_html($content);
                }
                if (!user_can($pdo, $uid, 'core.posts.create')) {
                    throw new DomainException('Post create permission changed.');
                }
                if ($status !== 'draft' && !user_can($pdo, $uid, 'core.posts.publish', ['owner_id' => $uid])) {
                    throw new DomainException('Post publish permission changed.');
                }
                if ($requiresDatePermission && !user_can($pdo, $uid, 'core.posts.change_dates', ['owner_id' => $uid])) {
                    throw new DomainException('Post date permission changed.');
                }
                $slugLock = $pdo->prepare("SELECT id FROM posts WHERE slug = :slug AND type IN ('article', 'page', 'theme') AND is_deleted = 0 LIMIT 1 FOR UPDATE");
                $slugLock->execute([':slug' => $slug]);
                if ($slugLock->fetchColumn()) throw new DomainException('Post slug changed.');
                if (!empty($category_ids)) {
                    $categoryPlaceholders = implode(',', array_fill(0, count($category_ids), '?'));
                    $categoryLock = $pdo->prepare("SELECT id, created_by FROM categories WHERE id IN ($categoryPlaceholders) AND is_deleted = 0 FOR UPDATE");
                    $categoryLock->execute($category_ids);
                    $lockedCategories = $categoryLock->fetchAll(PDO::FETCH_ASSOC) ?: [];
                    if (count($lockedCategories) !== count($category_ids)) {
                        throw new DomainException('Category selection changed.');
                    }
                    if (!authorization_lock_owner_contexts($pdo, array_column($lockedCategories, 'created_by'))) {
                        throw new DomainException('Category owner context lock failed.');
                    }
                    foreach ($lockedCategories as $lockedCategory) {
                        if (!user_can($pdo, $uid, 'core.categories.read', ['owner_id' => (int)($lockedCategory['created_by'] ?? 0)])) {
                            throw new DomainException('Category permission changed.');
                        }
                    }
                }
                $stmt = $pdo->prepare($insertSql);
                $ok = $stmt->execute([
                    ':title'      => $title,
                    ':slug'       => $slug,
                    ':content'    => $content,
                    ':meta'       => $metaVal,
                    ':youtube'    => $youtube,
                    ':thumbnail'  => $thumbnail,
                    ':status'     => $status,
                    ':created_by' => $uid,
                    ':created_at' => $final_created,
                    ':updated_at' => $final_updated,
                ]);
                if (!$ok) throw new RuntimeException('Post insert failed.');

                $postId = (int)$pdo->lastInsertId();
                if (!empty($category_ids)) {
                    $assignStmt = $pdo->prepare("INSERT INTO post_categories (post_id, category_id, assigned_by) VALUES (:post_id, :category_id, :by)");
                    foreach ($category_ids as $cid) {
                        $assignStmt->execute([
                            ':post_id' => $postId,
                            ':category_id' => (int)$cid,
                            ':by' => $uid,
                        ]);
                    }
                }
                do_action('admin_post_before_add_commit', $postId, $pdo, $_POST);
                $pdo->commit();
                return $postId;
                } catch (Throwable $error) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    throw $error;
                }
            });
        } catch (Throwable $error) {
            error_log('posts/add.php error: ' . $error->getMessage());
            $post_id = 0;
        }

        if ($post_id > 0) {
            do_action('admin_post_after_add', $post_id, $pdo, $_POST);
            adiwira_redirect_with_flash($return_to, 'success', __('Article saved successfully.'));
        }

        $errors[] = __('Failed to save post.');
    }
}
?>

<section class="adam-card">
  <h2 class="edit-heading"><?=_e('Add Article')?></h2>

  <form id="post-add-form" method="post" novalidate>
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="return_to" value="<?= htmlspecialchars($return_to, ENT_QUOTES, 'UTF-8') ?>">

    <div class="adam-accordion" id="theme-meta-accordion" data-open="1">
      <button type="button" class="adam-accordion-toggle" aria-expanded="true" aria-controls="theme-meta-body">
        <?= svg_ico('cog') ?> <?=_e('Post Settings')?> <span class="chevron">▸</span>
      </button>

      <div class="adam-accordion-body" id="theme-meta-body">
        <label><?=_e('Title')?><br>
          <input type="text" name="title" value="<?= htmlspecialchars($_POST['title'] ?? '', ENT_QUOTES, 'UTF-8') ?>" class="inpud">
        </label>

        <label><?=_e('Slug (optional)')?><br>
          <input type="text" name="slug" value="<?= htmlspecialchars($_POST['slug'] ?? '', ENT_QUOTES, 'UTF-8') ?>" class="inpud">
        </label>

        <div class="cat-accordion">
          <button type="button" class="cat-accordion-toggle" aria-expanded="false" aria-controls="cat-accordion-body">
            <?= svg_ico('folder', '', ['style'=>'width:14px;height:14px']) ?> <?=_e('Categories')?>
            <span class="chevron">▸</span>
          </button>
          <div class="cat-accordion-body" id="cat-accordion-body">
            <?php
              $selectedCats = isset($_POST['categories']) ? (array)$_POST['categories'] : [];
              render_category_tree($all_categories, $selectedCats);
            ?>
          </div>
        </div>
        <script>
        (function(){
          var btn = document.querySelector('.cat-accordion-toggle');
          var body = document.getElementById('cat-accordion-body');
          if(btn && body){
            btn.addEventListener('click', function(){
              var expanded = btn.getAttribute('aria-expanded') === 'true';
              btn.setAttribute('aria-expanded', String(!expanded));
              body.classList.toggle('is-open', !expanded);
            });
          }
        })();
        </script>

        <div class="cat-accordion">
          <button type="button" class="cat-accordion-toggle" aria-expanded="false" aria-controls="youtube-accordion-body">
            <?= svg_ico('link', '', ['style'=>'width:14px;height:14px']) ?> <?=_e('YouTube Link')?>
            <span class="chevron">▸</span>
          </button>
          <div class="cat-accordion-body" id="youtube-accordion-body">
            <input type="text" name="youtube" id="youtube" class="form-control inpud"
                   style="max-width:100%;margin-top:0"
                   placeholder="https://www.youtube.com/watch?v=xxxxxx"
                   value="<?= htmlspecialchars($_POST['youtube'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
            <div id="youtube-preview" style="margin-top:8px"></div>
          </div>
        </div>
        <script>
        (function(){
          var btn = document.querySelector('.cat-accordion-toggle[aria-controls="youtube-accordion-body"]');
          var body = document.getElementById('youtube-accordion-body');
          if(btn && body){
            btn.addEventListener('click', function(){
              var expanded = btn.getAttribute('aria-expanded') === 'true';
              btn.setAttribute('aria-expanded', String(!expanded));
              body.classList.toggle('is-open', !expanded);
            });
          }
        })();
        </script>

        <label><?=_e('Thumbnail')?><br>
          <div class="thumb-row">
            <?php if (!$canUseUnfilteredHtml): ?>
            <input type="hidden" id="thumbnail-input" name="thumbnail"
                   value="<?= htmlspecialchars($_POST['thumbnail'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
            <?php else: ?>
            <input type="text" id="thumbnail-input" name="thumbnail"
                   value="<?= htmlspecialchars($_POST['thumbnail'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                   class="inp flex-1"
                   placeholder="<?=_e('Thumbnail URL')?>"
                   style="display:none">
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
          <div id="thumbnail-preview" class="thumbnail-preview mt-12">
            <?php if (!empty($_POST['thumbnail'])): ?>
              <img src="<?= htmlspecialchars($_POST['thumbnail'], ENT_QUOTES, 'UTF-8') ?>" alt="preview">
            <?php endif; ?>
          </div>
        </label>
      </div>
    </div>

    <label for="quill-editor"><?=_e('Content (rich text)')?></label>
    <div id="quill-editor-box" class="adam-quill adam-quill--auto">
      <div id="quill-editor"></div>
    </div>

    <input type="hidden" name="content" id="content-input" value="<?= htmlspecialchars($_POST['content'] ?? '', ENT_QUOTES, 'UTF-8') ?>">

    <?php
      $created_val = $_POST['created_at'] ?? '';
      $updated_val = $_POST['updated_at'] ?? '';
    ?>

    <div class="form-row mt-12">
      <label for="status"><?=_e('Status')?></label>
      <select name="status" id="status" class="inp">
        <option value="draft" <?= (($_POST['status'] ?? '') === 'draft') ? 'selected' : '' ?>><?=_e('Draft')?></option>
        <?php if ($canPublish): ?><option value="published" <?= (($_POST['status'] ?? '') === 'published') ? 'selected' : '' ?>><?=_e('Published')?></option>
        <option value="private" <?= (($_POST['status'] ?? '') === 'private') ? 'selected' : '' ?>><?=_e('Private')?></option><?php endif; ?>
      </select>
    </div>

    <?php if ($canChangeDates): ?><label class="form-group"><?=_e('Created At (optional)')?><br>
      <input type="datetime-local" name="created_at" value="<?= htmlspecialchars((string)$created_val, ENT_QUOTES, 'UTF-8') ?>" class="inp">
      <div class="field-note"><?=_e('Leave empty to use current time (GMT+7).')?></div>
    </label>

    <label class="form-group"><?=_e('Updated At (optional)')?><br>
      <input type="datetime-local" name="updated_at" value="<?= htmlspecialchars((string)$updated_val, ENT_QUOTES, 'UTF-8') ?>" class="inp">
      <div class="field-note"><?=_e('Leave empty to use current time (GMT+7).')?></div>
    </label><?php endif; ?>

    <div class="section-divider">
      <div class="section-label"><?= svg_ico('columns-2') ?> <?=_e('Sidebar Position')?></div>
      <select name="sidebar_override" style="width:100%;padding:.4rem .5rem;border:1px solid var(--adam-border-2);border-radius:6px;background:var(--adam-card);color:var(--adam-text);font-size:.9rem;box-sizing:border-box">
        <option value=""><?=_e('Default (follow global hierarchy)')?></option>
        <option value="right"><?=_e('Right')?></option>
        <option value="left"><?=_e('Left')?></option>
        <option value="hide"><?=_e('Hide')?></option>
      </select>
    </div>

    <?php if ($enable_custom_meta): ?>
    <div class="section-divider">
      <div class="section-label"><?= svg_ico('search') ?> <?=_e('Meta Description')?></div>
      <textarea name="meta_description" rows="3" style="width:100%;padding:.4rem .5rem;border:1px solid var(--adam-border-2);border-radius:6px;background:var(--adam-card);color:var(--adam-text);font-size:.9rem;outline:none;resize:vertical;box-sizing:border-box" maxlength="320" placeholder="<?=_e('Custom meta description for SEO & social share. Leave empty to auto-generate from content.')?>"><?= htmlspecialchars($_POST['meta_description'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
      <div class="field-note"><?=_e('Recommended: 150-160 characters. Falls back to excerpt when empty.')?></div>
    </div>
    <?php endif; ?>

    <p class="mt-16">
      <button type="submit" class="adam-button"><?=_e('Save')?></button>
      <a class="adam-cancle" href="<?= htmlspecialchars($return_to, ENT_QUOTES, 'UTF-8') ?>"><?=_e('Cancel')?></a>
    </p>

    <div id="media-single-panel" class="hide" style="border:1px solid #eee;padding:10px;border-radius:6px;max-width:480px">
      <div id="media-single-content"><?=_e('Click image in Media to view details & edit.')?></div>
    </div>
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
<script src="/static/js/add/youtube_preview.js"></script>
