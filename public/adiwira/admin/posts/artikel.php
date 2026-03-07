<?php
// /adiwira/admin/posts/artikel.php
if (!defined('DASHBOARD_CONTEXT') && !defined('ADAM_THEME')) {
    http_response_code(403);
    exit('Forbidden');
}

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$uid = (int)($_SESSION['user_id'] ?? 0);
if ($uid <= 0) {
    http_response_code(403);
    exit('Akses ditolak: belum login.');
}

$role = function_exists('current_user_role') ? (current_user_role($pdo) ?: null) : null;
$role = $role ?: ($_SESSION['user_role'] ?? 'guest');
$role = is_string($role) ? strtolower(trim($role)) : 'guest';
$_SESSION['user_role'] = $role;

if (!in_array($role, ['author','editor','admin'], true)) {
    http_response_code(403);
    exit('Akses ditolak.');
}

if (!function_exists('slugify')) {
    function slugify(string $text): string {
        $text = mb_strtolower($text, 'UTF-8');
        $text = preg_replace('/[^\p{L}\p{N}\-]+/u', '-', $text);
        $text = preg_replace('/[-]{2,}/', '-', $text);
        $text = trim((string)$text, '-');
        return $text ?: bin2hex(random_bytes(4));
    }
}

/**
 * Sanitasi HTML untuk AUTHOR:
 * - buang script/iframe/object/embed/style/link/meta
 * - buang atribut on* dan style
 * - whitelist tag dasar rich text + img + table sederhana
 */
if (!function_exists('sanitize_author_html')) {
    function sanitize_author_html(string $html): string {
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

// categories
$stmt = $pdo->prepare("SELECT id, name, parent_id FROM categories WHERE is_deleted = 0 ORDER BY parent_id ASC, name ASC");
$stmt->execute();
$all_categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
            return $d->format('Y-m-d\TH:i');
        } catch (Exception $e) {
            return null;
        }
    }
}

if (!function_exists('parse_datetime_local')) {
    function parse_datetime_local(string $s): ?string {
        $s = trim($s);
        if ($s === '') return null;
        $d = DateTime::createFromFormat('Y-m-d\TH:i', $s, new DateTimeZone('Asia/Jakarta'));
        if ($d !== false) return $d->format('Y-m-d H:i:s');
        try {
            $d2 = new DateTime($s, new DateTimeZone('Asia/Jakarta'));
            return $d2->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            return null;
        }
    }
}

$errors = [];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!csrf_check($token)) {
        $errors[] = 'CSRF token tidak valid.';
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
    if ($title === '') $errors[] = 'Judul tidak boleh kosong.';

    if ($role === 'author') {
        $content = sanitize_author_html($content);
        if (function_exists('normalize_links_in_html')) {
            $content = normalize_links_in_html($content);
        }
    }

    if (trim(strip_tags($content)) === '') $errors[] = 'Konten tidak boleh kosong.';

    if ($youtube !== null && !preg_match('/^(https?:\/\/)?(www\.)?(youtube\.com|youtu\.be)\//i', $youtube)) {
        $errors[] = 'Link YouTube tidak valid.';
    }

    $slug = ($slug === '') ? slugify($title) : slugify($slug);

    if (empty($errors)) {
        $s = $pdo->prepare("SELECT id FROM posts WHERE slug = :slug LIMIT 1");
        $s->execute([':slug' => $slug]);
        if ($s->fetch()) $errors[] = 'Slug sudah dipakai. Pilih slug lain.';
    }

    $created_at_in = trim((string)($_POST['created_at'] ?? ''));
    $updated_at_in = trim((string)($_POST['updated_at'] ?? ''));

    $created_at_parsed = null;
    $updated_at_parsed = null;

    if ($created_at_in !== '') {
        $created_at_parsed = parse_datetime_local($created_at_in);
        if ($created_at_parsed === null) $errors[] = 'Format Created At tidak valid.';
    }

    if ($updated_at_in !== '') {
        $updated_at_parsed = parse_datetime_local($updated_at_in);
        if ($updated_at_parsed === null) $errors[] = 'Format Updated At tidak valid.';
    }

    $category_ids = array_values(array_filter(array_map('intval', $category_ids), fn($v) => $v > 0));
    if (!empty($category_ids)) {
        $ph = implode(',', array_fill(0, count($category_ids), '?'));
        $v = $pdo->prepare("SELECT id FROM categories WHERE id IN ($ph) AND is_deleted=0");
        $v->execute($category_ids);
        $found = $v->fetchAll(PDO::FETCH_COLUMN, 0);
        $category_ids = array_values(array_intersect($category_ids, array_map('intval', $found)));
    }

    if (empty($errors)) {
        $final_created = $created_at_parsed ?? (new DateTime('now', new DateTimeZone('Asia/Jakarta')))->format('Y-m-d H:i:s');
        $final_updated = $updated_at_parsed ?? (new DateTime('now', new DateTimeZone('Asia/Jakarta')))->format('Y-m-d H:i:s');

        $insertSql = "INSERT INTO posts
            (title, slug, content, type, meta, youtube, thumbnail, status, created_by, created_at, updated_at)
            VALUES
            (:title, :slug, :content, 'article', NULL, :youtube, :thumbnail, :status, :created_by, :created_at, :updated_at)";
        $stmt = $pdo->prepare($insertSql);

        $ok = $stmt->execute([
            ':title'      => $title,
            ':slug'       => $slug,
            ':content'    => $content,
            ':youtube'    => $youtube,
            ':thumbnail'  => $thumbnail,
            ':status'     => $status,
            ':created_by' => $uid,
            ':created_at' => $final_created,
            ':updated_at' => $final_updated,
        ]);

        if ($ok) {
            $post_id = (int)$pdo->lastInsertId();

            if (!empty($category_ids)) {
                $assignStmt = $pdo->prepare("INSERT INTO post_categories (post_id, category_id, assigned_by) VALUES (:post_id, :category_id, :by)");
                foreach ($category_ids as $cid) {
                    $assignStmt->execute([
                        ':post_id'    => $post_id,
                        ':category_id'=> (int)$cid,
                        ':by'         => $uid
                    ]);
                }
            }

            $base = rtrim(str_replace('\\','/', dirname($_SERVER['SCRIPT_NAME'])), '/');
            ?>
            <div id="successModal" class="adam-modal" aria-hidden="false">
              <div class="adam-modal-card adam-modal--success" role="dialog" aria-modal="true" tabindex="-1">
                <h3 class="adam-modal-title">✅ Artikel Berhasil Disimpan!</h3>
                <p class="adam-modal-desc">🥳 Akan diarahkan ke daftar artikel...</p>
              </div>
            </div>
            <script>
              setTimeout(() => {
                window.location.href = "<?= htmlspecialchars($base . '/index.php?page=admin/posts/index', ENT_QUOTES) ?>";
              }, 1200);
            </script>
            <?php
            exit;
        }

        $errors[] = 'Gagal menyimpan post.';
    }
}

$base = rtrim(str_replace('\\','/', dirname($_SERVER['SCRIPT_NAME'])), '/');
?>

<section class="adam-card">
  <h2>Tambah Article</h2>

  <?php if ($errors): ?>
    <div class="adam-error"><ul><?php foreach($errors as $e): ?><li><?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?></li><?php endforeach; ?></ul></div>
  <?php endif; ?>

  <form id="post-add-form" method="post" novalidate>
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">

    <div class="adam-accordion" id="theme-meta-accordion" data-open="1">
      <button type="button" class="adam-accordion-toggle" aria-expanded="true" aria-controls="theme-meta-body">
        ⚙️ Pengaturan Post <span class="chevron">▸</span>
      </button>

      <div class="adam-accordion-body" id="theme-meta-body">
        <label>Judul<br>
          <input type="text" name="title" value="<?= htmlspecialchars($_POST['title'] ?? '', ENT_QUOTES, 'UTF-8') ?>" class="inpud">
        </label>

        <label>Slug (opsional)<br>
          <input type="text" name="slug" value="<?= htmlspecialchars($_POST['slug'] ?? '', ENT_QUOTES, 'UTF-8') ?>" class="inpud">
        </label>

        <label>Kategori (centang untuk memilih)<br>
          <div style="padding:.45rem;margin-top:.4rem;border:1px solid #ddd;border-radius:6px;max-height:calc(1.6em * 3 + .9rem);overflow-y:auto;overflow-x:hidden;">
            <?php
              $selectedCats = isset($_POST['categories']) ? (array)$_POST['categories'] : [];
              render_category_tree($all_categories, $selectedCats);
            ?>
          </div>
        </label>

        <div class="form-group">
          <label for="youtube">YouTube Link</label>
          <input type="text" name="youtube" id="youtube" class="form-control inpud"
                 placeholder="https://www.youtube.com/watch?v=xxxxxx"
                 value="<?= htmlspecialchars($_POST['youtube'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div id="youtube-preview" style="margin-top:8px"></div>

        <label>Thumbnail (gunakan modal media)<br>
          <div style="display:flex;gap:.5rem;align-items:center;margin-top:.4rem;">
            <input type="text" id="thumbnail-input" name="thumbnail"
                   value="<?= htmlspecialchars($_POST['thumbnail'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                   style="flex:1;padding:.5rem;border:1px solid #ddd;border-radius:6px"
                   placeholder="URL thumbnail (atau pilih dari Media)">
            <button type="button" id="btn-open-media-for-thumb" class="adam-button" style="padding:.45rem .7rem;border-radius:6px;border:1px solid #ddd">Pilih dari Media</button>
            <button type="button" id="thumbnail-clear" class="adam-link" style="padding:.35rem .6rem">Clear</button>
          </div>
          <div id="thumbnail-preview" style="margin-top:.6rem;">
            <?php if (!empty($_POST['thumbnail'])): ?>
              <img src="<?= htmlspecialchars($_POST['thumbnail'], ENT_QUOTES, 'UTF-8') ?>" alt="preview" style="max-width:220px;max-height:140px;border:1px solid #eee;padding:.3rem">
            <?php endif; ?>
          </div>
        </label>
      </div>
    </div>

    <label for="quill-editor">Konten (rich text)</label>
    <div id="quill-editor-box" class="adam-quill adam-quill--auto" style="margin-top:.4rem;">
      <div id="quill-editor"></div>
    </div>

    <input type="hidden" name="content" id="content-input" value="<?= htmlspecialchars($_POST['content'] ?? '', ENT_QUOTES, 'UTF-8') ?>">

    <?php
      $created_val = $_POST['created_at'] ?? '';
      $updated_val = $_POST['updated_at'] ?? '';
    ?>

    <div class="form-row" style="margin-top:.6rem">
      <label for="status">Status</label>
      <select name="status" id="status" style="padding:.4rem;border:1px solid #ddd;border-radius:6px">
        <option value="draft" <?= (($_POST['status'] ?? '') === 'draft') ? 'selected' : '' ?>>Draft</option>
        <option value="published" <?= (($_POST['status'] ?? '') === 'published') ? 'selected' : '' ?>>Published</option>
        <option value="private" <?= (($_POST['status'] ?? '') === 'private') ? 'selected' : '' ?>>Private</option>
      </select>
    </div>

    <label style="display:block;margin-top:.6rem">Created At (opsional)<br>
      <input type="datetime-local" name="created_at" value="<?= htmlspecialchars((string)$created_val, ENT_QUOTES, 'UTF-8') ?>" style="padding:.4rem;border:1px solid #ddd;border-radius:6px">
      <div style="font-size:12px;color:#666;margin-top:4px">Kosongkan untuk menggunakan waktu sekarang (GMT+7).</div>
    </label>

    <label style="display:block;margin-top:.6rem">Updated At (opsional)<br>
      <input type="datetime-local" name="updated_at" value="<?= htmlspecialchars((string)$updated_val, ENT_QUOTES, 'UTF-8') ?>" style="padding:.4rem;border:1px solid #ddd;border-radius:6px">
      <div style="font-size:12px;color:#666;margin-top:4px">Kosongkan untuk menggunakan waktu sekarang (GMT+7).</div>
    </label>

    <p style="margin-top:.8rem">
      <button type="submit" class="adam-button">Simpan</button>
      <a class="adam-cancle" href="<?= htmlspecialchars($base . '/index.php?page=admin/posts/index', ENT_QUOTES, 'UTF-8') ?>">Batal</a>
    </p>

    <div id="media-single-panel" style="margin-top:12px;border:1px solid #eee;padding:10px;border-radius:6px;display:none;background:#fff;max-width:480px">
      <div id="media-single-content">Klik gambar pada Media untuk melihat detail & edit.</div>
    </div>
  </form>
</section>

<script>
  window.ADIWIRA = window.ADIWIRA || {};
  window.ADIWIRA_BASE = <?= json_encode($base) ?>;
</script>

<script src="/adiwira/static/js/add/modal-helpers.js"></script>
<script src="/adiwira/static/js/add/media-selector.js"></script>
<script src="/adiwira/static/js/add/file-selector.js"></script>
<script src="/adiwira/static/js/add/quill-init.js"></script>
<script src="/adiwira/static/js/add/thumbnail-handler.js"></script>
<script src="/adiwira/static/js/add/youtube_preview.js"></script>