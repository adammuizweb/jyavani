<?php
// /adiwira/admin/posts/edit.php
if (!defined('DASHBOARD_CONTEXT') && !defined('ADAM_THEME')) {
    http_response_code(403);
    exit('Forbidden');
}

if (!function_exists('slugify')) {
    function slugify(string $text): string {
        $text = mb_strtolower($text, 'UTF-8');
        $text = preg_replace('/[^\p{L}\p{N}\-]+/u', '-', $text);
        $text = preg_replace('/[-]{2,}/', '-', $text);
        $text = trim($text, '-');
        return $text ?: bin2hex(random_bytes(4));
    }
}

// helpers (assume $pdo is available)
if (!function_exists('fetch_users_for_dropdown')) {
    function fetch_users_for_dropdown(PDO $pdo): array {
        try {
            $sql = "
                SELECT id, name, username, email, img,
                CASE
                  WHEN name IS NOT NULL AND name != '' THEN CONCAT(name, ' (', email, ')')
                  WHEN email IS NOT NULL AND email != '' THEN email
                  ELSE CONCAT('user-', id)
                END AS label
                FROM users
                ORDER BY name ASC, email ASC
            ";
            $stmt = $pdo->query($sql);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('to_datetime_local')) {
    function to_datetime_local(?string $mysqlDt): ?string {
        if (!$mysqlDt) return null;
        try {
            $d = new DateTime($mysqlDt);
            return $d->format('Y-m-d\TH:i');
        } catch (Exception $e) {
            return null;
        }
    }
}

// base for paths
$base = rtrim(str_replace('\\','/', dirname($_SERVER['SCRIPT_NAME'])), '/');

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    echo '<p>ID post tidak valid.</p>';
    return;
}

// fetch master post
$stmt = $pdo->prepare("SELECT * FROM posts WHERE id = :id AND type = 'article' AND is_deleted = 0 LIMIT 1");
$stmt->execute([':id' => $id]);
$post = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$post) {
    http_response_code(404);
    echo '<p>Post tidak ditemukan atau bukan artikel.</p>';
    return;
}

require_author_owns_post($post, $pdo);

// categories for tree
$stmt = $pdo->prepare("SELECT id, name, parent_id FROM categories WHERE is_deleted = 0 ORDER BY parent_id ASC, name ASC");
$stmt->execute();
$all_categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

// category current
$stmt2 = $pdo->prepare("SELECT category_id FROM post_categories WHERE post_id = :post_id");
$stmt2->execute([':post_id' => $id]);
$current_cats = $stmt2->fetchAll(PDO::FETCH_COLUMN, 0);
$current_cats = array_map('intval', $current_cats);

// users
$users = fetch_users_for_dropdown($pdo);

// helper render_category_tree
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

// choose source values (prefer $_POST for redisplay on error otherwise use master post)
$val = function($key, $default = '') {
    return isset($_POST[$key]) ? $_POST[$key] : $default;
};

$title     = $val('title', $post['title'] ?? '');
$slug      = $val('slug', $post['slug'] ?? '');
$content   = $val('content', $post['content'] ?? '');
$status    = $val('status', $post['status'] ?? 'draft');
$youtube   = $val('youtube', $post['youtube'] ?? '');
$thumbnail = $val('thumbnail', $post['thumbnail'] ?? '');
$created_by = (int)($val('created_by', $post['created_by'] ?? 0));
?>

<section class="adam-card">
  <h2>Edit Article</h2>

  <form method="post"
        id="post-edit-form"
        action="<?= htmlspecialchars($base . '/admin/posts/save.php', ENT_QUOTES, 'UTF-8') ?>"
        novalidate>
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="id" value="<?= (int)$post['id'] ?>">

<div class="adam-accordion"
     id="theme-meta-accordion"
     data-open="1">

<button type="button"
        class="adam-accordion-toggle"
        aria-expanded="true"
        aria-controls="theme-meta-body">
    ⚙️ Pengaturan Post
    <span class="chevron">▸</span>
  </button>

  <div class="adam-accordion-body" id="theme-meta-body">
    <label>Judul<br>
      <input type="text" name="title" value="<?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?>" class="inpud">
    </label>

    <label>Slug (opsional)<br>
      <input type="text" name="slug" value="<?= htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') ?>" class="inpud">
    </label>

<label>Kategori (centang untuk memilih)<br>
  <div style="padding:.45rem;margin-top:.4rem;border:1px solid #ddd;border-radius:6px;max-height:calc(1.6em * 6 + .9rem);overflow-y:auto;">
    <?php
      $selected = isset($_POST['categories']) ? array_map('intval', (array)$_POST['categories']) : $current_cats;
      render_category_tree($all_categories, $selected);
    ?>
  </div>
</label>

<div class="form-group" style="margin-top:.6rem">
  <label for="youtube-input">YouTube link</label>
  <input
    type="text"
    id="youtube-input"
    name="youtube"
    class="form-control inpud"
    style="max-width: 700px;"
    placeholder="https://www.youtube.com/watch?v=xxxxxx atau https://youtu.be/xxxxxx"
    value="<?= htmlspecialchars($youtube, ENT_QUOTES, 'UTF-8') ?>"
  >
  <small id="youtube-help" style="color:#556;"><i>Masukkan URL watch atau short link.</i></small>
</div>
<div id="youtube-preview" style="margin-top:8px"></div>

<label>Thumbnail (URL) atau pilih dari Media<br>
  <div style="display:flex;gap:.5rem;align-items:center;margin-top:.4rem;">
    <input type="text" id="thumbnail-input" name="thumbnail"
           value="<?= htmlspecialchars($thumbnail, ENT_QUOTES, 'UTF-8') ?>"
           style="flex:1;padding:.5rem;border:1px solid #ddd;border-radius:6px"
           placeholder="URL thumbnail (atau pilih dari Media)">
    <button type="button" id="btn-open-media-for-thumb" class="adam-button"
            style="padding:.45rem .7rem;border-radius:6px;border:1px solid #ddd">Pilih dari Media</button>
    <button type="button" id="thumbnail-clear" class="adam-link" style="padding:.35rem .6rem">Clear</button>
  </div>
  <div id="thumbnail-preview" style="margin-top:.6rem;">
    <?php if (!empty($thumbnail)): ?>
      <img src="<?= htmlspecialchars($thumbnail, ENT_QUOTES, 'UTF-8') ?>" alt="preview" style="max-width:220px;max-height:140px;border:1px solid #eee;padding:.3rem;background:#fff">
    <?php endif; ?>
  </div>
</label>
  </div>
</div>
<!-- Editor Mode -->
<label style="display:block;margin-top:.6rem">
  Pilih Editor<br>
  <div style="margin-top:.4rem;display:flex;gap:.5rem;align-items:center">
    <label><input type="radio" name="editor_mode" value="quill" id="editor-quill" <?= ((($_POST['editor_mode'] ?? '') === 'codemirror') ? '' : 'checked') ?>> Quill (rich)</label>
    <label><input type="radio" name="editor_mode" value="codemirror" id="editor-codemirror" <?= (($_POST['editor_mode'] ?? '') === 'codemirror') ? 'checked' : '' ?>> CodeMirror (HTML)</label>
  </div>
</label>

<textarea name="content" id="content-textarea" style="display:none"><?= htmlspecialchars($content, ENT_QUOTES, 'UTF-8') ?></textarea>

<!-- Quill -->
<div id="quill-area" style="margin-top:.6rem;">
  <div id="quill-toolbar" style="margin-top:.4rem;">
  </div>
  <div id="quill-editor" style="background:#fff;border:1px solid #ddd;border-radius:6px;min-height:220px;margin-top:.5rem;padding:.75rem;"><?= $content ? $content : '' ?></div>
</div>

<!-- CodeMirror -->
<div id="codemirror-area" style="margin-top:.6rem;display:none;">
  <div id="cm-wrap" style="border:1px solid #333;border-radius:6px;overflow:hidden">
    <textarea id="cm-textarea" style="width:100%;min-height:300px;"><?= htmlspecialchars($content, ENT_QUOTES, 'UTF-8') ?></textarea>
  </div>
</div>

<div class="form-row" style="margin-top:.6rem">
  <label for="status">Status</label>
  <?php
    $currentStatus = $status;
  ?>
  <select name="status" id="status" style="padding:.4rem;border:1px solid #ddd;border-radius:6px">
    <option value="draft" <?= ($currentStatus === 'draft') ? 'selected' : '' ?>>Draft</option>
    <option value="published" <?= ($currentStatus === 'published') ? 'selected' : '' ?>>Published</option>
    <option value="private" <?= ($currentStatus === 'private') ? 'selected' : '' ?>>Private</option>
  </select>
</div>

<!-- CREATED BY -->
<label style="display:block;margin-top:.6rem">
  Created By<br>
  <select name="created_by" style="margin-top:.4rem;padding:.4rem;border:1px solid #ddd;border-radius:6px">
    <?php
if (!empty($users)) {
    foreach ($users as $u) {
        $uid = (int)$u['id'];
        $label = htmlspecialchars($u['label'], ENT_QUOTES, 'UTF-8');
        $username = htmlspecialchars($u['username'] ?? '', ENT_QUOTES, 'UTF-8');
        $img = htmlspecialchars($u['img'] ?? '', ENT_QUOTES, 'UTF-8');
        $sel = ($uid === $created_by) ? 'selected' : '';
        echo "<option value=\"{$uid}\" data-username=\"{$username}\" data-img=\"{$img}\" {$sel}>{$label}</option>";
    }
} else {
    $cb = (int)($post['created_by'] ?? 0);
    $sel = ($cb === $created_by) ? 'selected' : '';
    echo "<option value=\"{$cb}\" {$sel}>User ID {$cb}</option>";
}
    ?>
  </select>
  <div style="font-size:12px;color:#666;margin-top:6px">Pilih pembuat jika ingin mengatur manual.</div>
</label>

<label style="display:block;margin-top:.6rem">Created At<br>
  <input type="datetime-local" name="created_at" value="<?= htmlspecialchars($_POST['created_at'] ?? to_datetime_local($post['created_at']), ENT_QUOTES, 'UTF-8') ?>" style="padding:.4rem;border:1px solid #ddd;border-radius:6px">
  <div style="font-size:12px;color:#666;margin-top:4px">Kosongkan untuk mempertahankan nilai semula (<?= htmlspecialchars($post['created_at'], ENT_QUOTES, 'UTF-8') ?>).</div>
</label>

<label style="display:block;margin-top:.6rem">Updated At<br>
  <input type="datetime-local" name="updated_at" value="<?= htmlspecialchars($_POST['updated_at'] ?? to_datetime_local($post['updated_at']), ENT_QUOTES, 'UTF-8') ?>" style="padding:.4rem;border:1px solid #ddd;border-radius:6px">
  <div style="font-size:12px;color:#666;margin-top:4px">Kosongkan untuk mempertahankan nilai semula (<?= htmlspecialchars($post['updated_at'], ENT_QUOTES, 'UTF-8') ?>).</div>
</label>

<p style="margin-top:.8rem">
  <button type="submit" class="adam-button" id="btn-save">Simpan Perubahan</button>
  <a class="adam-cancle" href="<?= htmlspecialchars($base . '/index.php?page=admin/posts/index', ENT_QUOTES, 'UTF-8') ?>">Batal</a>
</p>
</form>
</section>

<!-- small notif modal -->
<div id="notif-modal" style="display:none;position:fixed;inset:0;z-index:15000;align-items:center;justify-content:center;background:rgba(0,0,0,.45);">
  <div style="background:#fff;padding:1.2rem 1.6rem;border-radius:10px;box-shadow:0 12px 40px rgba(0,0,0,.18);text-align:center;max-width:320px;">
    <div id="notif-title" style="font-weight:600;margin-bottom:.25rem">Data sukses diperbarui!</div>
    <div id="notif-body" style="color:#334;margin-bottom:.6rem">Perubahan berhasil disimpan.</div>
    <button onclick="hideNotif()" style="padding:.45rem .8rem;border:0;background:#246;color:#fff;border-radius:6px;cursor:pointer">Tutup</button>
  </div>
</div>

<script>
  // Expose small globals so modular JS can use them (ajax_save.js expects these)
  window.ADIWIRA = window.ADIWIRA || {};
  window.ADIWIRA_SAVE_URL = <?= json_encode($base . '/admin/posts/save.php') ?>;
  window.ADIWIRA_FORM_ID = 'post-edit-form';
</script>

<!-- Modular JS (editor / media / save) - note: live preview and i18n removed -->
<script src="/adiwira/static/js/edit/utils.js"></script>
<script src="/adiwira/static/js/edit/modal.js"></script>
<script src="/adiwira/static/js/edit/media_selector.js"></script>
<script src="/adiwira/static/js/edit/codemirror.js"></script>
<script src="/adiwira/static/js/edit/quill.js"></script>
<script src="/adiwira/static/js/edit/editor_mode.js"></script>
<script src="/adiwira/static/js/edit/thumbnail.js"></script>
<script src="/adiwira/static/js/edit/youtube_preview.js"></script>
<script src="/adiwira/static/js/edit/ajax_save.js"></script>
<script src="/adiwira/static/js/edit/main-init.js"></script>

</body>
</html>
