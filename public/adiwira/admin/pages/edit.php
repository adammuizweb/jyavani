<?php
// /adiwira/admin/pages/edit.php
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

if (!function_exists('fetch_users_for_dropdown')) {
    function fetch_users_for_dropdown(PDO $pdo): array {
        try {
            $sql = "
                SELECT
                    id,
                    name,
                    username,
                    email,
                    img,
                    CASE
                      WHEN name IS NOT NULL AND name != '' THEN CONCAT(name, ' (', email, ')')
                      WHEN email IS NOT NULL AND email != '' THEN email
                      ELSE CONCAT('user-', id)
                    END AS label
                FROM users
                WHERE is_deleted = 0
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

$base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    echo '<p>ID page tidak valid.</p>';
    return;
}

// ambil page
$stmt = $pdo->prepare("
    SELECT *
    FROM posts
    WHERE id = :id
      AND type = 'page'
      AND is_deleted = 0
    LIMIT 1
");
$stmt->execute([':id' => $id]);
$post = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$post) {
    http_response_code(404);
    echo '<p>Page tidak ditemukan atau bukan page.</p>';
    return;
}

// session + role
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$me = (int)($_SESSION['user_id'] ?? 0);

if ($me <= 0) {
    http_response_code(403);
    echo '<p>Akses ditolak: belum login.</p>';
    return;
}

$role = 'guest';
try {
    $stRole = $pdo->prepare("SELECT role FROM users WHERE id = :id AND is_deleted = 0 LIMIT 1");
    $stRole->execute([':id' => $me]);
    $dbRole = $stRole->fetchColumn();
    if (is_string($dbRole) && trim($dbRole) !== '') {
        $role = strtolower(trim($dbRole));
    }
} catch (Throwable $e) {
    $role = strtolower(trim((string)($_SESSION['user_role'] ?? 'guest')));
}

$_SESSION['user_role'] = $role;

if (!in_array($role, ['author', 'editor', 'admin'], true)) {
    http_response_code(403);
    echo '<p>Akses ditolak.</p>';
    return;
}

// admin bebas, author/editor hanya miliknya sendiri
if ($role !== 'admin' && (int)($post['created_by'] ?? 0) !== $me) {
    http_response_code(403);
    echo '<p>Akses ditolak: kamu hanya boleh mengedit halaman milikmu sendiri.</p>';
    return;
}

// users admin-only
$users = ($role === 'admin') ? fetch_users_for_dropdown($pdo) : [];

// prefer POST value kalau ada redisplay
$val = function($key, $default = '') {
    return isset($_POST[$key]) ? $_POST[$key] : $default;
};

$title      = (string)$val('title', $post['title'] ?? '');
$slug       = (string)$val('slug', $post['slug'] ?? '');
$content    = (string)$val('content', $post['content'] ?? '');
$status     = (string)$val('status', $post['status'] ?? 'draft');
$thumbnail  = (string)$val('thumbnail', $post['thumbnail'] ?? '');
$created_by = (int)$val('created_by', $post['created_by'] ?? 0);

// mode editor default
$chosenMode = (string)($_POST['editor_mode'] ?? '');
$isComplex  = preg_match('/<(script|style|iframe|embed|object|form|svg|canvas|php|link|meta)[\s>]|on[a-z]+\s*=|style\s*=/i', $content);

if ($chosenMode === '') {
    $chosenMode = $isComplex ? 'codemirror' : 'quill';
}
if (!in_array($chosenMode, ['quill', 'codemirror'], true)) {
    $chosenMode = $isComplex ? 'codemirror' : 'quill';
}
?>

<section class="adam-card">
  <h2>Edit Page</h2>

  <form method="post"
        id="page-edit-form"
        action="<?= htmlspecialchars($base . '/admin/pages/save.php', ENT_QUOTES, 'UTF-8') ?>"
        novalidate>
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="id" value="<?= (int)$post['id'] ?>">

    <div class="adam-accordion" id="page-meta-accordion" data-open="1">
      <button type="button"
              class="adam-accordion-toggle"
              aria-expanded="true"
              aria-controls="page-meta-body">
        ⚙️ Pengaturan Halaman
        <span class="chevron">▸</span>
      </button>

      <div class="adam-accordion-body" id="page-meta-body">
        <label>Judul<br>
          <input type="text"
                 name="title"
                 value="<?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?>"
                 class="inpud">
        </label>

        <label style="display:block;margin-top:.6rem">Slug (opsional)<br>
          <input type="text"
                 name="slug"
                 value="<?= htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') ?>"
                 class="inpud">
        </label>

        <label style="display:block;margin-top:.6rem">
          Thumbnail (URL) atau pilih dari Media<br>
          <div style="display:flex;gap:.5rem;align-items:center;margin-top:.4rem;">
            <input type="text"
                   id="thumbnail-input"
                   name="thumbnail"
                   value="<?= htmlspecialchars($thumbnail, ENT_QUOTES, 'UTF-8') ?>"
                   style="flex:1;padding:.5rem;border:1px solid #ddd;border-radius:6px"
                   placeholder="URL thumbnail (atau pilih dari Media)">
            <button type="button"
                    id="btn-open-media-for-thumb"
                    class="adam-button"
                    style="padding:.45rem .7rem;border-radius:6px;border:1px solid #ddd">
              Pilih dari Media
            </button>
            <button type="button"
                    id="thumbnail-clear"
                    class="adam-link"
                    style="padding:.35rem .6rem">
              Clear
            </button>
          </div>

          <div id="thumbnail-preview" style="margin-top:.6rem;">
            <?php if (!empty($thumbnail)): ?>
              <img src="<?= htmlspecialchars($thumbnail, ENT_QUOTES, 'UTF-8') ?>"
                   alt="preview"
                   style="max-width:220px;max-height:140px;border:1px solid #eee;padding:.3rem">
            <?php endif; ?>
          </div>
        </label>
      </div>
    </div>

    <label style="display:block;margin-top:.6rem">
      Pilih Editor<br>
      <div style="margin-top:.4rem;display:flex;gap:.5rem;align-items:center">
        <label>
          <input type="radio"
                 name="editor_mode"
                 value="quill"
                 id="editor-quill"
                 <?= ($chosenMode === 'quill') ? 'checked' : '' ?>>
          Quill (rich)
        </label>
        <label>
          <input type="radio"
                 name="editor_mode"
                 value="codemirror"
                 id="editor-codemirror"
                 <?= ($chosenMode === 'codemirror') ? 'checked' : '' ?>>
          CodeMirror (HTML)
        </label>
      </div>
    </label>

    <textarea name="content" id="content-textarea" style="display:none"><?= htmlspecialchars($content, ENT_QUOTES, 'UTF-8') ?></textarea>

    <div id="quill-area" class="adam-quill adam-quill--auto" style="margin-top:.6rem;">
      <div id="quill-toolbar"></div>
      <div id="quill-editor"></div>
    </div>

    <div id="codemirror-area" style="margin-top:.6rem;display:none;">
      <div id="cm-wrap" style="border:1px solid #333;border-radius:6px;overflow:hidden">
        <textarea id="cm-textarea" style="width:100%;min-height:300px;"><?= htmlspecialchars($content, ENT_QUOTES, 'UTF-8') ?></textarea>
      </div>
    </div>

    <div class="form-row" style="margin-top:.6rem">
      <label for="status">Status</label>
      <select name="status" id="status" style="padding:.4rem;border:1px solid #ddd;border-radius:6px">
        <option value="draft" <?= ($status === 'draft') ? 'selected' : '' ?>>Draft</option>
        <option value="published" <?= ($status === 'published') ? 'selected' : '' ?>>Published</option>
        <option value="private" <?= ($status === 'private') ? 'selected' : '' ?>>Private</option>
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
                    $label  = htmlspecialchars($u['label'], ENT_QUOTES, 'UTF-8');
                    $sel    = ($uidOpt === $created_by) ? 'selected' : '';
                    echo "<option value=\"{$uidOpt}\" {$sel}>{$label}</option>";
                }
            } else {
                echo '<option value="' . (int)$created_by . '" selected>User ID ' . (int)$created_by . '</option>';
            }
          ?>
        </select>
        <div style="font-size:12px;color:#666;margin-top:6px">Admin-only.</div>
      </label>

      <label style="display:block;margin-top:.6rem">
        Created At<br>
        <input type="datetime-local"
               name="created_at"
               value="<?= htmlspecialchars($_POST['created_at'] ?? to_datetime_local($post['created_at']), ENT_QUOTES, 'UTF-8') ?>"
               style="padding:.4rem;border:1px solid #ddd;border-radius:6px">
      </label>

      <label style="display:block;margin-top:.6rem">
        Updated At<br>
        <input type="datetime-local"
               name="updated_at"
               value="<?= htmlspecialchars($_POST['updated_at'] ?? to_datetime_local($post['updated_at']), ENT_QUOTES, 'UTF-8') ?>"
               style="padding:.4rem;border:1px solid #ddd;border-radius:6px">
      </label>
    <?php else: ?>
      <div style="margin-top:.8rem;color:#666;font-size:12px">
        Creator & tanggal dibuat tidak bisa diubah (khusus admin).
      </div>
    <?php endif; ?>

    <p style="margin-top:.8rem">
      <button type="submit" class="adam-button" id="btn-save">Simpan Perubahan</button>
      <a class="adam-cancle"
         href="<?= htmlspecialchars($base . '/index.php?page=admin/pages/index', ENT_QUOTES, 'UTF-8') ?>">
         Batal
      </a>
    </p>
  </form>
</section>

<div id="notif-modal" class="adam-modal">
  <div class="adam-modal-card">
    <div id="notif-title" class="adam-modal-title">Data sukses diperbarui!</div>
    <div id="notif-body" class="adam-modal-desc">Perubahan berhasil disimpan.</div>
    <button onclick="hideNotif()" class="adam-button">Tutup</button>
  </div>
</div>

<script>
  window.ADIWIRA = window.ADIWIRA || {};
  window.ADIWIRA_SAVE_URL = <?= json_encode($base . '/admin/pages/save.php') ?>;
  window.ADIWIRA_FORM_ID = 'page-edit-form';
</script>

<script src="/adiwira/static/js/edit/utils.js"></script>
<script src="/adiwira/static/js/edit/modal.js"></script>
<script src="/adiwira/static/js/edit/media_selector.js"></script>
<script src="/adiwira/static/js/edit/codemirror.js"></script>
<script src="/adiwira/static/js/add/file-selector.js"></script>
<script src="/adiwira/static/js/edit/quill.js"></script>
<script src="/adiwira/static/js/edit/editor_mode.js"></script>
<script src="/adiwira/static/js/edit/thumbnail.js"></script>
<script src="/adiwira/static/js/edit/ajax_save.js"></script>
<script src="/adiwira/static/js/edit/main-init.js"></script>