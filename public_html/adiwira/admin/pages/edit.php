<?php
// /adiwira/admin/pages/edit.php
if (!defined('DASHBOARD_CONTEXT') && !defined('ADAM_THEME')) {
    http_response_code(403);
    exit('Forbidden');
}

// helper slugify
if (!function_exists('slugify')) {
    function slugify(string $text): string {
        $text = mb_strtolower($text, 'UTF-8');
        $text = preg_replace('/[^\p{L}\p{N}\-]+/u', '-', $text);
        $text = preg_replace('/[-]{2,}/', '-', $text);
        $text = trim($text, '-');
        return $text ?: bin2hex(random_bytes(4));
    }
}

// helper: ambil daftar user untuk dropdown Created By
if (!function_exists('fetch_users_for_dropdown')) {
    function fetch_users_for_dropdown(PDO $pdo): array {
        try {
            $uStmt = $pdo->query("
                SELECT 
                    id, 
                    CASE 
                        WHEN name IS NOT NULL AND name != '' THEN 
                            CONCAT(name, ' (', email, ')')
                        WHEN email IS NOT NULL AND email != '' THEN 
                            email
                        ELSE 
                            CONCAT('user-', id)
                    END AS label,
                    username,
                    img
                FROM users
                ORDER BY name ASC, email ASC
            ");
            return $uStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return []; // aman jika tabel users belum ada
        }
    }
}

// helper: konversi datetime MySQL ke datetime-local
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

$base = rtrim(str_replace('\\','/', dirname($_SERVER['SCRIPT_NAME'])), '/');

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    echo '<p>ID page tidak valid.</p>';
    return;
}

// ambil data page
$stmt = $pdo->prepare("SELECT * FROM posts WHERE id = :id AND type = 'page' AND is_deleted = 0 LIMIT 1");
$stmt->execute([':id' => $id]);
$post = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$post) {
    http_response_code(404);
    echo '<p>Page tidak ditemukan atau bukan page.</p>';
    return;
}

// pastikan session aktif
if (session_status() === PHP_SESSION_NONE) session_start();

// pastikan user info ada
$currentUserId = (int)($_SESSION['user_id'] ?? 0);
$currentRole = $_SESSION['user_role'] ?? null;
if (!$currentRole && $currentUserId > 0) {
    $r = $pdo->prepare("SELECT role FROM users WHERE id = :id LIMIT 1");
    $r->execute([':id' => $currentUserId]);
    $currentRole = $r->fetchColumn();
    $_SESSION['user_role'] = $currentRole;
}

// block jika author mencoba edit milik orang lain
if ($currentRole === 'author' && (int)$post['created_by'] !== $currentUserId) {
    echo '<p>Akses ditolak: Anda hanya dapat mengedit halaman yang Anda buat.</p>';
    return;
}

// ambil daftar user untuk dropdown Created By
$users = fetch_users_for_dropdown($pdo);

// ambil ID pembuat saat ini
$currentCreator = isset($_POST['created_by'])
    ? (int)$_POST['created_by']
    : (int)($post['created_by'] ?? 0);

// content canonical (prefill)
$content = isset($_POST['content']) ? $_POST['content'] : ($post['content'] ?? '');

// safe thumbnail handling: strip tags and ignore HTML-injected junk
$thumbnail_raw = isset($_POST['thumbnail']) ? $_POST['thumbnail'] : ($post['thumbnail'] ?? '');
$thumbnail_clean = '';
if (is_string($thumbnail_raw)) {
    // remove any HTML tags (this neutralizes the "<br /><b>Deprecated</b>" issue)
    $thumbnail_clean = trim(strip_tags($thumbnail_raw));
    // if the cleaned value looks like an HTML fragment or is empty, treat as empty
    if ($thumbnail_clean === '' || preg_match('/[<>&]/', $thumbnail_raw)) {
        $thumbnail_clean = '';
    }
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

<div class="adam-accordion"
     id="theme-meta-accordion"
     data-open="1">

<button type="button"
        class="adam-accordion-toggle"
        aria-expanded="true"
        aria-controls="theme-meta-body">
    ⚙️ Pengaturan Halaman
    <span class="chevron">▸</span>
  </button>

  <div class="adam-accordion-body" id="theme-meta-body">
    <label>Judul<br>
      <input type="text" name="title" value="<?= htmlspecialchars($_POST['title'] ?? $post['title'], ENT_QUOTES, 'UTF-8') ?>" class="inpud">
    </label>

    <label>Slug (opsional)<br>
      <input type="text" name="slug" value="<?= htmlspecialchars($_POST['slug'] ?? $post['slug'], ENT_QUOTES, 'UTF-8') ?>" class="inpud">
    </label>

    <label style="display:block;margin-top:.6rem">
      Pilih Editor<br>
      <div style="margin-top:.4rem;display:flex;gap:.5rem;align-items:center">
        <label><input type="radio" name="editor_mode" value="quill" id="editor-quill" <?= ((($_POST['editor_mode'] ?? '') === 'codemirror') ? '' : 'checked') ?>> Quill (rich)</label>
        <label><input type="radio" name="editor_mode" value="codemirror" id="editor-codemirror" <?= (($_POST['editor_mode'] ?? '') === 'codemirror') ? 'checked' : '' ?>> CodeMirror (HTML)</label>
      </div>
    </label>
  </div>
</div>

    <!-- canonical content field (hidden) -->
    <textarea name="content" id="content-textarea" style="display:none"><?= htmlspecialchars($content, ENT_QUOTES, 'UTF-8') ?></textarea>

    <!-- Quill area (match posts/edit: let quill.js initialize toolbar) -->
    <div id="quill-area" style="margin-top:.6rem;">
      <div id="quill-toolbar" style="margin-top:.4rem;"></div>
      <div id="quill-editor" style="background:#fff;border:1px solid #ddd;border-radius:6px;min-height:220px;margin-top:.5rem;padding:.75rem;"><?= $content ? $content : '' ?></div>
    </div>

    <!-- CodeMirror area -->
    <div id="codemirror-area" style="margin-top:.6rem;display:none;">
      <div id="cm-wrap" style="border:1px solid #333;border-radius:6px;overflow:hidden">
        <textarea id="cm-textarea" style="width:100%;min-height:300px;"><?= htmlspecialchars($_POST['content'] ?? $post['content'], ENT_QUOTES, 'UTF-8') ?></textarea>
      </div>
    </div>

    <!-- Thumbnail (match posts/edit: URL + media picker + preview) -->
    <label>Thumbnail (URL) atau pilih dari Media<br>
      <div style="display:flex;gap:.5rem;align-items:center;margin-top:.4rem;">
        <input type="text" id="thumbnail-input" name="thumbnail"
               value="<?= htmlspecialchars($thumbnail_clean, ENT_QUOTES, 'UTF-8') ?>"
               style="flex:1;padding:.5rem;border:1px solid #ddd;border-radius:6px"
               placeholder="URL thumbnail (atau pilih dari Media)">
        <button type="button" id="btn-open-media-for-thumb" class="adam-button"
                style="padding:.45rem .7rem;border-radius:6px;border:1px solid #ddd">Pilih dari Media</button>
        <button type="button" id="thumbnail-clear" class="adam-link" style="padding:.35rem .6rem">Clear</button>
      </div>
      <div id="thumbnail-preview" style="margin-top:.6rem;">
        <?php if (!empty($thumbnail_clean)): ?>
          <img src="<?= htmlspecialchars($thumbnail_clean, ENT_QUOTES, 'UTF-8') ?>" alt="preview" style="max-width:220px;max-height:140px;border:1px solid #eee;padding:.3rem;background:#fff">
        <?php endif; ?>
      </div>
    </label>

    <!-- Status -->
    <label style="display:block;margin-top:.6rem">Status<br>
      <select name="status" id="status" style="padding:.4rem;border:1px solid #ddd;border-radius:6px">
        <?php
          $currentStatus = $_POST['status'] ?? $post['status'] ?? 'draft';
        ?>
        <option value="draft" <?= ($currentStatus === 'draft') ? 'selected' : '' ?>>Draft</option>
        <option value="published" <?= ($currentStatus === 'published') ? 'selected' : '' ?>>Published</option>
        <option value="private" <?= ($currentStatus === 'private') ? 'selected' : '' ?>>Private</option>
      </select>
    </label>

    <!-- Created By -->
    <label style="display:block;margin-top:.6rem">
      Created By<br>
      <select name="created_by" style="margin-top:.4rem;padding:.4rem;border:1px solid #ddd;border-radius:6px">
        <?php
          if (!empty($users)) {
              foreach ($users as $u) {
                  $uid = (int)$u['id'];
                  $label = htmlspecialchars($u['label'], ENT_QUOTES, 'UTF-8');
                  $sel = ($uid === $currentCreator) ? 'selected' : '';
                  echo "<option value=\"{$uid}\" {$sel}>{$label}</option>";
              }
          } else {
              $cb = (int)($post['created_by'] ?? 0);
              $sel = ($cb === $currentCreator) ? 'selected' : '';
              echo "<option value=\"{$cb}\" {$sel}>User ID {$cb}</option>";
          }
        ?>
      </select>
      <div style="font-size:12px;color:#666;margin-top:6px">Pilih pembuat jika ingin mengatur manual. Biarkan jika tidak perlu diubah.</div>
    </label>

    <!-- Created / Updated -->
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
      <a class="adam-cancle" href="<?= htmlspecialchars($base . '/index.php?page=admin/pages/index', ENT_QUOTES, 'UTF-8') ?>">Batal</a>
    </p>

  </form>
</section>

<!-- Notif Modal -->
<div id="notif-modal" style="display:none;position:fixed;inset:0;z-index:15000;align-items:center;justify-content:center;background:rgba(0,0,0,.45);">
  <div style="background:#fff;padding:1.2rem 1.6rem;border-radius:10px;box-shadow:0 12px 40px rgba(0,0,0,.18);text-align:center;max-width:300px;">
    <div id="notif-title" style="font-weight:600;margin-bottom:.25rem">Data sukses diperbarui!</div>
    <div id="notif-body" style="color:#334;margin-bottom:.6rem">Perubahan berhasil disimpan.</div>
    <button onclick="hideNotif()" style="padding:.45rem .8rem;border:0;background:#246;color:#fff;border-radius:6px;cursor:pointer">Tutup</button>
  </div>
</div>

<!-- expose base/url constants used by modular scripts -->
<script>
  window.ADIWIRA = window.ADIWIRA || {};
  window.ADIWIRA_BASE = <?= json_encode($base) ?>;
  window.ADIWIRA_UPLOAD_URL = <?= json_encode($base . '/admin/upload_image.php') ?>;
  window.ADIWIRA_SAVE_URL = <?= json_encode($base . '/admin/pages/save.php') ?>;
  window.ADIWIRA_FORM_ID = 'page-edit-form';
</script>

<!-- shared modular scripts (same as posts) -->
<script src="/adiwira/static/js/edit/utils.js"></script>
<script src="/adiwira/static/js/edit/modal.js"></script>
<script src="/adiwira/static/js/edit/media_selector.js"></script>
<script src="/adiwira/static/js/edit/codemirror.js"></script>
<script src="/adiwira/static/js/edit/quill.js"></script>
<script src="/adiwira/static/js/edit/editor_mode.js"></script>
<script src="/adiwira/static/js/edit/thumbnail.js"></script>
<script src="/adiwira/static/js/edit/youtube_preview.js"></script>

<!-- shared save + init -->
<script src="/adiwira/static/js/edit/ajax_save.js"></script>
<script src="/adiwira/static/js/edit/main-init.js"></script>
<script src="/adiwira/static/js/edit/preview.js"></script>