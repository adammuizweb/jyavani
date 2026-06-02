<?php
// /adiwira/admin/posts/edit.php
require_once __DIR__ . '/../_deny.php';

if (!defined('DASHBOARD_CONTEXT') && !defined('ADAM_THEME')) {
    adiwira_admin_404();
}

require_once __DIR__ . '/../_guard.php';
require_once __DIR__ . '/../_notify.php';

[$me, $role] = adiwira_require_role($pdo, ['author', 'editor', 'admin'], false);

if (!function_exists('slugify')) {
    function slugify(string $text): string {
        $text = mb_strtolower($text, 'UTF-8');
        $text = preg_replace('/[^\p{L}\p{N}\-]+/u', '-', $text);
        $text = preg_replace('/[-]{2,}/', '-', $text);
        $text = trim($text, '-');
        return $text ?: bin2hex(random_bytes(4));
    }
}

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
                WHERE is_deleted = 0
                  AND is_locked = 0
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
            $d = new DateTime($mysqlDt, new DateTimeZone('Asia/Jakarta'));
            return $d->format('Y-m-d\\TH:i');
        } catch (Exception $e) {
            return null;
        }
    }
}

$base = ADMIN_BASE_PATH;
$return_to = function_exists('adiwira_safe_return_to')
    ? adiwira_safe_return_to((string)($_REQUEST['return_to'] ?? ''), $base . '/?page=admin/posts/index')
    : ($base . '/?page=admin/posts/index');

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    echo '<p>ID post tidak valid.</p>';
    return;
}

$stmt = $pdo->prepare("\n    SELECT *\n    FROM posts\n    WHERE id = :id\n      AND type = 'article'\n      AND is_deleted = 0\n    LIMIT 1\n");
$stmt->execute([':id' => $id]);
$post = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$post) {
    http_response_code(404);
    echo '<p>Post tidak ditemukan atau bukan artikel.</p>';
    return;
}

if ($role !== 'admin' && (int)($post['created_by'] ?? 0) !== $me) {
    http_response_code(403);
    echo '<p>Akses ditolak: kamu hanya boleh mengedit posting milikmu sendiri.</p>';
    return;
}

$stmt = $pdo->prepare("\n    SELECT id, name, parent_id\n    FROM categories\n    WHERE is_deleted = 0\n    ORDER BY parent_id ASC, name ASC\n");
$stmt->execute();
$all_categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt2 = $pdo->prepare("SELECT category_id FROM post_categories WHERE post_id = :post_id");
$stmt2->execute([':post_id' => $id]);
$current_cats = $stmt2->fetchAll(PDO::FETCH_COLUMN, 0);
$current_cats = array_map('intval', $current_cats);

$users = ($role === 'admin') ? fetch_users_for_dropdown($pdo) : [];

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

$val = function($key, $default = '') {
    return isset($_POST[$key]) ? $_POST[$key] : $default;
};

$title      = $val('title', $post['title'] ?? '');
$slug       = $val('slug', $post['slug'] ?? '');
$content    = $val('content', $post['content'] ?? '');
$status     = $val('status', $post['status'] ?? 'draft');
$youtube    = $val('youtube', $post['youtube'] ?? '');
$thumbnail  = $val('thumbnail', $post['thumbnail'] ?? '');
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
    <input type="hidden" name="return_to" value="<?= htmlspecialchars($return_to, ENT_QUOTES, 'UTF-8') ?>">

    <div class="adam-accordion" id="theme-meta-accordion" data-open="1">
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
              <img src="<?= htmlspecialchars($thumbnail, ENT_QUOTES, 'UTF-8') ?>" alt="preview" style="max-width:220px;max-height:140px;border:1px solid #eee;padding:.3rem">
            <?php endif; ?>
          </div>
        </label>
      </div>
    </div>

    <label style="display:block;margin-top:.6rem">
      Pilih Editor<br>
      <div style="margin-top:.4rem;display:flex;gap:.5rem;align-items:center">
        <label><input type="radio" name="editor_mode" value="quill" id="editor-quill" <?= ((($_POST['editor_mode'] ?? '') === 'codemirror') ? '' : 'checked') ?>> Quill (rich)</label>
        <label><input type="radio" name="editor_mode" value="codemirror" id="editor-codemirror" <?= (($_POST['editor_mode'] ?? '') === 'codemirror') ? 'checked' : '' ?>> CodeMirror (HTML)</label>
      </div>
    </label>

    <textarea name="content" id="content-textarea" style="display:none"><?= htmlspecialchars($content, ENT_QUOTES, 'UTF-8') ?></textarea>

    <div id="quill-area" class="adam-quill adam-quill--auto" style="margin-top:.6rem;">
      <div id="quill-toolbar"></div>
      <div id="quill-editor"><?= $content ? $content : '' ?></div>
    </div>

    <div id="codemirror-area" style="margin-top:.6rem;display:none;">
      <div id="cm-wrap" style="border:1px solid #333;border-radius:6px;overflow:hidden">
        <textarea id="cm-textarea" style="width:100%;min-height:300px;"><?= htmlspecialchars($content, ENT_QUOTES, 'UTF-8') ?></textarea>
      </div>
    </div>

    <div class="form-row" style="margin-top:.6rem">
      <label for="status">Status</label>
      <?php $currentStatus = $status; ?>
      <select name="status" id="status" style="padding:.4rem;border:1px solid #ddd;border-radius:6px">
        <option value="draft" <?= ($currentStatus === 'draft') ? 'selected' : '' ?>>Draft</option>
        <option value="published" <?= ($currentStatus === 'published') ? 'selected' : '' ?>>Published</option>
        <option value="private" <?= ($currentStatus === 'private') ? 'selected' : '' ?>>Private</option>
      </select>
    </div>

    <?php if ($role === 'admin'): ?>
    <label style="display:block;margin-top:.6rem">
      Created By<br>
      <select name="created_by" style="margin-top:.4rem;padding:.4rem;border:1px solid #ddd;border-radius:6px">
        <?php
        if (!empty($users)) {
            foreach ($users as $u) {
                $uidOpt = (int)$u['id'];
                $label = htmlspecialchars($u['label'], ENT_QUOTES, 'UTF-8');
                $username = htmlspecialchars($u['username'] ?? '', ENT_QUOTES, 'UTF-8');
                $img = htmlspecialchars($u['img'] ?? '', ENT_QUOTES, 'UTF-8');
                $sel = ($uidOpt === $created_by) ? 'selected' : '';
                echo "<option value=\"{$uidOpt}\" data-username=\"{$username}\" data-img=\"{$img}\" {$sel}>{$label}</option>";
            }
        } else {
            $cb = (int)($post['created_by'] ?? 0);
            $sel = ($cb === $created_by) ? 'selected' : '';
            echo "<option value=\"{$cb}\" {$sel}>User ID {$cb}</option>";
        }
        ?>
      </select>
      <div style="font-size:12px;color:#666;margin-top:6px">Admin-only.</div>
    </label>
    <?php else: ?>
      <div style="font-size:12px;color:#666;margin-top:.6rem">Creator tidak bisa diubah. Timestamp boleh diubah.</div>
    <?php endif; ?>

    <label style="display:block;margin-top:.6rem">Created At<br>
      <input type="datetime-local" name="created_at" value="<?= htmlspecialchars($_POST['created_at'] ?? to_datetime_local($post['created_at']), ENT_QUOTES, 'UTF-8') ?>" style="padding:.4rem;border:1px solid #ddd;border-radius:6px">
      <div style="font-size:12px;color:#666;margin-top:4px">Kosongkan untuk mempertahankan nilai semula (<?= htmlspecialchars($post['created_at'], ENT_QUOTES, 'UTF-8') ?>).</div>
    </label>

    <label style="display:block;margin-top:.6rem">Updated At<br>
      <input type="datetime-local" name="updated_at" value="<?= htmlspecialchars($_POST['updated_at'] ?? to_datetime_local($post['updated_at']), ENT_QUOTES, 'UTF-8') ?>" style="padding:.4rem;border:1px solid #ddd;border-radius:6px">
      <div style="font-size:12px;color:#666;margin-top:4px">Kosongkan untuk menggunakan waktu sekarang.</div>
    </label>

    <?php
    $current_sidebar = '';
    if (!empty($post['meta'])) {
        $pm = is_string($post['meta']) ? json_decode($post['meta'], true) : $post['meta'];
        if (is_array($pm) && isset($pm['sidebar'])) {
            $current_sidebar = $pm['sidebar'];
        }
    }
    ?>
    <div style="margin-top:.6rem;padding-top:.6rem;border-top:1px solid var(--adam-border);">
      <div style="font-size:13px;font-weight:600;margin-bottom:.4rem">📐 Posisi Sidebar</div>
      <select name="sidebar_override" style="padding:3px 5px;border:1px solid var(--adam-border-2);border-radius:4px;background:var(--adam-card);color:var(--adam-text);font-size:12px">
        <option value="">Default (ikuti hierarki global)</option>
        <option value="right" <?= $current_sidebar === 'right' ? 'selected' : '' ?>>Kanan</option>
        <option value="left" <?= $current_sidebar === 'left' ? 'selected' : '' ?>>Kiri</option>
        <option value="hide" <?= $current_sidebar === 'hide' ? 'selected' : '' ?>>Sembunyikan</option>
      </select>
    </div>

    <p style="margin-top:.8rem">
      <button type="submit" class="adam-button" id="btn-save">Simpan Perubahan</button>
      <a class="adam-cancle" href="<?= htmlspecialchars($return_to, ENT_QUOTES, 'UTF-8') ?>">Batal</a>
    </p>
  </form>
</section>

<script>
  window.ADIWIRA = window.ADIWIRA || {};
  window.ADIWIRA_FORM_ID = 'post-edit-form';
</script>

<script src="/static/js/edit/utils.js"></script>

<!-- pakai helper modal + selector yang sudah terbukti jalan di halaman add -->
<script src="/static/js/add/modal-helpers.js"></script>
<script src="/static/js/add/media-selector.js"></script>
<script src="/static/js/add/file-selector.js"></script>

<!-- editor spesifik edit tetap -->
<script src="/static/js/edit/codemirror.js"></script>
<script src="/static/js/edit/quill.js"></script>
<script src="/static/js/edit/editor_mode.js"></script>
<script src="/static/js/edit/thumbnail.js"></script>
<script src="/static/js/edit/youtube_preview.js"></script>
<script src="/static/js/edit/ajax_save.js"></script>
<script src="/static/js/edit/main-init.js"></script>

<script>
(function(){
  const form = document.getElementById('post-edit-form');
  const saveBtn = document.getElementById('btn-save');
  const contentField = document.getElementById('content-textarea');

  if (!form || !contentField) return;

  function notify(type, message, title){
    if (window.NewNotifToast && typeof window.NewNotifToast.show === 'function') {
      window.NewNotifToast.show({ type: type, title: title, message: message });
      return;
    }
    alert(message);
  }

  function askWarning(opts){
    if (window.NewNotifConfirm && typeof window.NewNotifConfirm.warning === 'function') {
      return window.NewNotifConfirm.warning(opts);
    }
    return Promise.resolve(window.confirm(opts.message || 'Lanjutkan aksi ini?'));
  }

  function currentEditorMode(){
    const checked = document.querySelector('input[name="editor_mode"]:checked');
    return checked ? checked.value : 'quill';
  }

  function getCodeMirrorValue(){
    const direct = window.ADIWIRA && window.ADIWIRA.cm;
    if (direct && typeof direct.getValue === 'function') {
      return direct.getValue();
    }

    const wrapper = document.querySelector('#codemirror-area .CodeMirror');
    if (wrapper && wrapper.CodeMirror && typeof wrapper.CodeMirror.getValue === 'function') {
      return wrapper.CodeMirror.getValue();
    }

    const textarea = document.getElementById('cm-textarea');
    return textarea ? textarea.value : '';
  }

  function getQuillValue(){
    const direct = window.ADIWIRA && window.ADIWIRA.quill;
    if (direct && direct.root) {
      return direct.root.innerHTML;
    }

    if (window.quill && window.quill.root) {
      return window.quill.root.innerHTML;
    }

    const editor = document.querySelector('#quill-editor .ql-editor');
    return editor ? editor.innerHTML : '';
  }

  function syncContent(){
    contentField.value = currentEditorMode() === 'codemirror'
      ? getCodeMirrorValue()
      : getQuillValue();
  }

  async function submitAjax(){
    syncContent();

    const oldLabel = saveBtn ? saveBtn.textContent : '';
    if (saveBtn) {
      saveBtn.disabled = true;
      saveBtn.textContent = 'Menyimpan...';
    }

    try {
      const res = await fetch(form.action, {
        method: 'POST',
        body: new FormData(form),
        headers: {
          'X-Requested-With': 'XMLHttpRequest',
          'Accept': 'application/json'
        }
      });

      const data = await res.json().catch(function(){
        return { ok:false, errors:['Respons server tidak valid.'] };
      });

      if (!res.ok || !data.ok) {
        const errors = Array.isArray(data.errors) && data.errors.length
          ? data.errors
          : [data.error || data.message || 'Gagal menyimpan perubahan.'];

        errors.filter(Boolean).forEach(function(msg, idx){
          notify('error', String(msg), idx === 0 ? 'Gagal menyimpan' : 'Detail error');
        });
        return;
      }

      if (data.redirect) {
        window.location.href = data.redirect;
        return;
      }

      notify('success', data.message || 'Perubahan berhasil disimpan.', 'Berhasil');

    } catch (err) {
      notify('error', 'Terjadi gangguan jaringan saat menyimpan.', 'Jaringan');
    } finally {
      if (saveBtn) {
        saveBtn.disabled = false;
        saveBtn.textContent = oldLabel || 'Simpan Perubahan';
      }
    }
  }

  form.addEventListener('submit', function(ev){
    ev.preventDefault();
    syncContent();

    askWarning({
      title: 'Simpan perubahan',
      message: 'Perubahan artikel ini akan disimpan. Lanjutkan?',
      confirmText: 'Ya, simpan',
      cancelText: 'Batal'
    }).then(function(ok){
      if (!ok) return;
      submitAjax();
    });
  });
})();
</script>