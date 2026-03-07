<?php
// /adiwira/admin/themes/add.php
if (!defined('DASHBOARD_CONTEXT') && !defined('ADAM_THEME')) {
    http_response_code(403);
    exit('Forbidden');
}

if (session_status() === PHP_SESSION_NONE) session_start();

if (empty($_SESSION['user_id'])) {
    http_response_code(403);
    exit('<p>Akses ditolak: belum login.</p>');
}

$user_id = (int)$_SESSION['user_id'];
$user_role = $_SESSION['user_role'] ?? null;

if (!$user_role) {
    $rstmt = $pdo->prepare("SELECT role FROM users WHERE id = :id AND is_deleted = 0 LIMIT 1");
    $rstmt->execute([':id' => $user_id]);
    $user_role = $rstmt->fetchColumn();
    $_SESSION['user_role'] = $user_role;
}

$user_role = strtolower(trim((string)$user_role));
if ($user_role !== 'admin') {
   http_response_code(403);
   exit('Akses ditolak: hanya admin.');
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

/* ---------- SAVE NONCE (anti double insert) ---------- */
$nonce_key = 'theme_add_nonce';
if (empty($_SESSION[$nonce_key])) {
    $_SESSION[$nonce_key] = bin2hex(random_bytes(12));
}
$save_nonce = $_SESSION[$nonce_key];

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf_token'] ?? '')) {
        $errors[] = 'CSRF token tidak valid.';
    }

    if (!hash_equals($save_nonce, $_POST['save_nonce'] ?? '')) {
        $errors[] = 'Token penyimpanan tidak valid. Muat ulang halaman.';
    }

    $title   = trim($_POST['title'] ?? '');
    $slug    = trim($_POST['slug'] ?? '');
    $content = $_POST['content'] ?? '';
    $status  = in_array($_POST['status'] ?? '', ['draft','published','private'], true)
        ? $_POST['status']
        : 'draft';

    if ($title === '')   $errors[] = 'Judul tidak boleh kosong.';
    if ($content === '') $errors[] = 'Konten tidak boleh kosong.';

    $slug = $slug === '' ? slugify($title) : slugify($slug);

    if (empty($errors)) {
        $s = $pdo->prepare("SELECT id FROM posts WHERE slug = :slug LIMIT 1");
        $s->execute([':slug' => $slug]);
        if ($s->fetch()) {
            $errors[] = 'Slug sudah digunakan.';
        }
    }

    if (empty($errors)) {
        unset($_SESSION[$nonce_key]); // invalidate nonce

        $stmt = $pdo->prepare(
            "INSERT INTO posts
             (title, slug, content, type, status, created_by, created_at, updated_at)
             VALUES
             (:title, :slug, :content, 'theme', :status, :uid, NOW(), NOW())"
        );

        $ok = $stmt->execute([
            ':title' => $title,
            ':slug' => $slug,
            ':content' => $content,
            ':status' => $status,
            ':uid' => $user_id
        ]);

        if ($ok) {
            $base = rtrim(str_replace('\\','/', dirname($_SERVER['SCRIPT_NAME'])), '/');
            ?>
            <div style="position:fixed;inset:0;z-index:5000;background:rgba(0,0,0,.45);display:flex;align-items:center;justify-content:center;">
              <div style="background:#fff;padding:1.5rem 2rem;border-radius:10px;text-align:center;">
                <h3>✅ Tema berhasil ditambahkan</h3>
                <p>Akan diarahkan ke daftar tema…</p>
              </div>
            </div>
            <script>
              setTimeout(() => {
                location.href = "<?= htmlspecialchars($base . '/index.php?page=admin/themes/index', ENT_QUOTES) ?>";
              }, 1200);
            </script>
            <?php
            exit;
        }

        $errors[] = 'Gagal menyimpan ke database.';
    }
}

$base = rtrim(str_replace('\\','/', dirname($_SERVER['SCRIPT_NAME'])), '/');
?>
<section class="adam-card">
  <h2>Tambah Theme / Partial</h2>

  <?php if ($errors): ?>
    <div class="adam-error">
      <ul><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>

  <form method="post" id="theme-add-form">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>">
    <input type="hidden" name="save_nonce" value="<?= htmlspecialchars($save_nonce, ENT_QUOTES) ?>">

    <div class="form-toolbar" style="display:flex;gap:.5rem;margin-bottom:.8rem;">
      <button type="submit" class="adam-button">💾 Simpan</button>
      <a href="<?= htmlspecialchars($base . '/index.php?page=admin/themes/index', ENT_QUOTES) ?>" class="adam-cancle">Batal</a>
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
                 value="<?= htmlspecialchars($_POST['title'] ?? '', ENT_QUOTES) ?>"
                 class="inpud">
        </label>

        <label style="margin-top:.6rem;display:block">Slug (opsional)<br>
          <input type="text" name="slug"
                 value="<?= htmlspecialchars($_POST['slug'] ?? '', ENT_QUOTES) ?>"
                 class="inpud">
        </label>

        <label style="margin-top:.6rem;display:block">Status<br>
          <select name="status"
                  class="inpud">
            <option value="draft" <?= (($_POST['status'] ?? '') === 'draft') ? 'selected' : '' ?>>Draft</option>
            <option value="published" <?= (($_POST['status'] ?? '') === 'published') ? 'selected' : '' ?>>Published</option>
            <option value="private" <?= (($_POST['status'] ?? '') === 'private') ? 'selected' : '' ?>>Private</option>
          </select>
        </label>

      </div>
    </div>
    <label style="margin-top:.75rem">Konten (HTML / PHP fragment)<br>
<div class="adam-cm-wrap">
  <textarea id="cm-textarea" style="width:100%;min-height:70vh;"><?= htmlspecialchars($pref_content ?? ($_POST['content'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
</div>
      <textarea id="content-textarea" name="content" style="display:none"><?= htmlspecialchars($_POST['content'] ?? '') ?></textarea>
    </label>
  </form>
</section>

<input type="hidden" id="editor-codemirror" checked>

<script src="/adiwira/static/js/edit/codemirror.js"></script>
<script src="/adiwira/static/js/edit/main-init.js"></script>

<script>
(function(){
  const form = document.getElementById('theme-add-form');
  const btn = form.querySelector('button[type="submit"]');
  let saving = false;

  if (form) {
    form.addEventListener('submit', () => {
      if (saving) return false;
      saving = true;
      btn.disabled = true;

      const cm = window.ADIWIRA?.codemirror?.getInstance?.();
      if (cm) document.getElementById('content-textarea').value = cm.getValue();
    });
  }

  function shortcut(e){
    if ((e.ctrlKey || e.metaKey) && e.key === 's') {
      e.preventDefault();
      if (!saving) form.requestSubmit();
    }
  }

  document.addEventListener('keydown', shortcut);

  window.ADIWIRA?.codemirror?.whenCMReady?.(() => {
    const cm = window.ADIWIRA.codemirror.getInstance();
    cm.addKeyMap({
      'Ctrl-S': () => form.requestSubmit(),
      'Cmd-S': () => form.requestSubmit()
    });
  });
})();
</script>
