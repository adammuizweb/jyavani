<?php 
// /adiwira/admin/themes/edit.php
if (!defined('DASHBOARD_CONTEXT') && !defined('ADAM_THEME')) {
    http_response_code(403);
    exit('Forbidden');
}

// role cek start
if (session_status() === PHP_SESSION_NONE) session_start();

if (empty($_SESSION['user_id'])) {
    http_response_code(403);
    exit('<p>Akses ditolak: belum login.</p>');
}

$user_id = (int)$_SESSION['user_id'];
$user_role = $_SESSION['user_role'] ?? null;

if (!$user_role) {
    // ambil role langsung dari DB jika belum ada di session
    $rstmt = $pdo->prepare("SELECT role FROM users WHERE id = :id AND is_deleted = 0 LIMIT 1");
    $rstmt->execute([':id' => $user_id]);
    $user_role = $rstmt->fetchColumn();
    $_SESSION['user_role'] = $user_role; // cache untuk request berikutnya
}

// izinkan hanya editor atau admin
if (!in_array($user_role, ['editor', 'admin'], true)) {
    http_response_code(403);
    exit('<p>Akses ditolak: hanya editor dan admin yang boleh mengedit tema.</p>');
}
// end role check

if (!function_exists('slugify')) {
    function slugify(string $text): string {
        $text = mb_strtolower($text, 'UTF-8');
        $text = preg_replace('/[^\p{L}\p{N}\-]+/u', '-', $text);
        $text = preg_replace('/[-]{2,}/', '-', $text);
        $text = trim($text, '-');
        return $text ?: bin2hex(random_bytes(4));
    }
}

$base = rtrim(str_replace('\\','/', dirname($_SERVER['SCRIPT_NAME'])), '/');

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    echo '<p>ID theme tidak valid.</p>';
    return;
}

$stmt = $pdo->prepare("SELECT * FROM posts WHERE id = :id AND type = 'theme' AND is_deleted = 0 LIMIT 1");
$stmt->execute([':id' => $id]);
$theme = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$theme) {
    http_response_code(404);
    echo '<p>Theme tidak ditemukan.</p>';
    return;
}

// setelah $theme di-fetch dan sebelum output form
if (session_status() === PHP_SESSION_NONE) session_start();
// buat one-time save nonce untuk mencegah double-submit
$save_nonce = bin2hex(random_bytes(12));
$_SESSION['theme_save_nonce_' . $id] = $save_nonce;

// defaults for form values — all content stored directly in posts.content
$pref_title   = $theme['title'] ?? '';
$pref_slug    = $theme['slug'] ?? '';
$pref_content = $theme['content'] ?? '';

?>
<section class="adam-card">
  <h2>Edit Theme / Partial</h2>

  <form method="post" id="theme-edit-form">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>">
    <input type="hidden" name="save_nonce" id="save_nonce" value="<?= htmlspecialchars($save_nonce, ENT_QUOTES) ?>">
    <input type="hidden" name="id" value="<?= (int)$theme['id'] ?>">

    <div class="form-toolbar" style="display:flex;align-items:center;gap:.5rem;margin-bottom:.8rem;">
      <button type="submit" class="adam-button">💾 Simpan Perubahan</button>
      <a href="<?= htmlspecialchars($base . '/index.php?page=admin/themes/index', ENT_QUOTES) ?>" class="adam-cancle">Batal</a>

      <div style="margin-left:auto;font-size:.9rem;color:#555;">
        Updated: 
        <span id="updated-at">
          <?= htmlspecialchars(format_datetime_indo($theme['updated_at'] ?? '-'), ENT_QUOTES, 'UTF-8') ?>
        </span>
      </div>

      <button type="button" id="btn-theme-toggle" class="adam-button" title="Ganti tema editor" style="background:#555;">🌙</button>
    </div>

<div class="adam-accordion"
     id="theme-meta-accordion"
     data-open="1">

<button type="button"
        class="adam-accordion-toggle"
        aria-expanded="true"
        aria-controls="theme-meta-body">
    ⚙️ Pengaturan Theme
    <span class="chevron">▸</span>
  </button>

  <div class="adam-accordion-body" id="theme-meta-body">

    <label>Judul<br>
      <input type="text" name="title"
             value="<?= htmlspecialchars($pref_title, ENT_QUOTES) ?>"
             class="inpud">
    </label>

    <label style="margin-top:.6rem;display:block">Slug (opsional)<br>
      <input type="text" name="slug"
             value="<?= htmlspecialchars($pref_slug, ENT_QUOTES) ?>"
             class="inpud">
    </label>

    <label style="margin-top:.6rem;display:block">Status<br>
      <select name="status"
              class="inpud">
        <option value="draft" <?= (($theme['status'] ?? '') === 'draft') ? 'selected' : '' ?>>Draft</option>
        <option value="published" <?= (($theme['status'] ?? '') === 'published') ? 'selected' : '' ?>>Published</option>
        <option value="private" <?= (($theme['status'] ?? '') === 'private') ? 'selected' : '' ?>>Private</option>
      </select>
    </label>

  </div>
</div>


    <!-- Konten: selalu HTML/code langsung -->
    <div style="margin-top:.75rem;">
      <label>Konten (HTML / PHP fragment)<br>
        <!-- Visible textarea untuk CodeMirror (NO name) -->
        <textarea id="cm-textarea"
                  style="width:100%;min-height:70vh;padding:.5rem;margin-top:.4rem;border:1px solid #ddd;border-radius:6px;"><?= htmlspecialchars($pref_content, ENT_QUOTES, 'UTF-8') ?></textarea>

        <!-- Canonical/submit textarea — ini yang akan dikirim ke server -->
        <textarea id="content-textarea" name="content" style="display:none;"><?= htmlspecialchars($pref_content, ENT_QUOTES, 'UTF-8') ?></textarea>
      </label>
    </div>

  </form>
</section>

<!-- Notif modal (center) -->
<div id="notif-modal" style="display:none;position:fixed;inset:0;z-index:15000;align-items:center;justify-content:center;background:rgba(0,0,0,.45);">
  <div style="background:#fff;padding:1.2rem 1.6rem;border-radius:10px;box-shadow:0 12px 40px rgba(0,0,0,.18);text-align:center;">
    <div id="notif-title" style="font-weight:600;margin-bottom:.25rem">Data sukses diperbarui!</div>
    <div id="notif-body" style="color:#334;margin-bottom:.6rem">Perubahan berhasil disimpan.</div>
    <button onclick="document.getElementById('notif-modal').style.display='none'" style="padding:.45rem .8rem;border:0;background:#246;color:#fff;border-radius:6px;cursor:pointer">Tutup</button>
  </div>
</div>

<input type="hidden" id="editor-codemirror" checked>

<script>
  window.ADIWIRA = window.ADIWIRA || {};
  window.ADIWIRA.config = {
    themeSaveUrl: <?= json_encode($base . '/admin/themes/save.php') ?>
  };
</script>

<script src="/adiwira/static/js/edit/codemirror.js"></script>
<script src="/adiwira/static/js/edit/main-init.js"></script>
<script src="/adiwira/static/js/edit/codemirror-theme.js"></script>