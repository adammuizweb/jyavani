<?php
// /adiwira/admin/pages/halaman.php
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

// ---------- Helper waktu ----------
function to_datetime_local(?string $mysqlDt): ?string {
  if (!$mysqlDt) return null;
  try {
    $d = new DateTime($mysqlDt, new DateTimeZone('Asia/Jakarta'));
    return $d->format('Y-m-d\TH:i');
  } catch (Exception $e) {
    return null;
  }
}

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

// ---------- Proses form ----------
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $token = $_POST['csrf_token'] ?? '';
  if (!csrf_check($token)) $errors[] = 'CSRF token tidak valid.';

  $title = trim((string)($_POST['title'] ?? ''));
  $slug = trim((string)($_POST['slug'] ?? ''));
  $content = $_POST['content'] ?? '';
  $status = in_array($_POST['status'] ?? '', ['draft', 'published', 'private'], true) ? $_POST['status'] : 'draft';
  $thumbnail = trim((string)($_POST['thumbnail'] ?? '')) ?: null;

  $created_at_in = trim((string)($_POST['created_at'] ?? ''));
  $updated_at_in = trim((string)($_POST['updated_at'] ?? ''));

  if ($title === '') $errors[] = 'Judul tidak boleh kosong.';
  if (trim(strip_tags($content)) === '') $errors[] = 'Konten tidak boleh kosong.';

  $slug = $slug === '' ? slugify($title) : slugify($slug);

  // pastikan slug unik
  if (empty($errors)) {
    $s = $pdo->prepare("SELECT id FROM posts WHERE slug = :slug LIMIT 1");
    $s->execute([':slug' => $slug]);
    if ($s->fetch()) $errors[] = 'Slug sudah digunakan.';
  }

  $created_at_parsed = $created_at_in ? parse_datetime_local($created_at_in) : null;
  $updated_at_parsed = $updated_at_in ? parse_datetime_local($updated_at_in) : null;
  if ($created_at_in && !$created_at_parsed) $errors[] = 'Format Created At tidak valid.';
  if ($updated_at_in && !$updated_at_parsed) $errors[] = 'Format Updated At tidak valid.';

  if (empty($errors)) {
    $stmt = $pdo->prepare("INSERT INTO posts 
      (title, slug, content, type, meta, thumbnail, status, created_by, created_at, updated_at)
      VALUES (:title, :slug, :content, 'page', NULL, :thumbnail, :status, :created_by, :created_at, :updated_at)");

    $final_created = $created_at_parsed ?? (new DateTime('now', new DateTimeZone('Asia/Jakarta')))->format('Y-m-d H:i:s');
    $final_updated = $updated_at_parsed ?? (new DateTime('now', new DateTimeZone('Asia/Jakarta')))->format('Y-m-d H:i:s');
    
    // fix link saat user input domain saja
    $content = normalize_links_in_html($content);

    $ok = $stmt->execute([
      ':title' => $title,
      ':slug' => $slug,
      ':content' => $content,
      ':thumbnail' => $thumbnail,
      ':status' => $status,
      ':created_by' => $_SESSION['user_id'] ?? null,
      ':created_at' => $final_created,
      ':updated_at' => $final_updated,
    ]);

if ($ok) {
    $base = rtrim(str_replace('\\','/', dirname($_SERVER['SCRIPT_NAME'])), '/');
    ?>
    <div id="successModal" style="position:fixed;inset:0;background:rgba(0,0,0,0.4);display:flex;align-items:center;justify-content:center;z-index:4000;">
      <div style="background:#fff;padding:1.5rem 2rem;border-radius:8px;max-width:360px;width:90%;box-shadow:0 4px 16px rgba(0,0,0,0.2);text-align:center;">
        <h3 style="margin-top:0;color:#246;">✅ Berhasil Menambahkan Halaman !</h3>
        <p>🥳 Akan diarahkan ke daftar Halaman...</p>
      </div>
    </div>
    <script>
      setTimeout(() => {
        window.location.href = "<?= htmlspecialchars($base . '/index.php?page=admin/pages/index', ENT_QUOTES) ?>";
      }, 1500);
    </script>
    <?php
    exit;
} else {
    $errors[] = 'Gagal Membuat Halaman.';
}
  }
}

$base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
?>
<section class="adam-card">
  <h2>Tambah Halaman</h2>

  <?php if ($errors): ?>
    <div class="adam-error">
      <ul><?php foreach($errors as $e): ?><li><?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>

  <form id="page-add-form" method="post" novalidate>
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">

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
      <input type="text" name="title" value="<?= htmlspecialchars($_POST['title'] ?? '', ENT_QUOTES, 'UTF-8') ?>" class="inpud">
    </label>

    <label>Slug (opsional)<br>
      <input type="text" name="slug" value="<?= htmlspecialchars($_POST['slug'] ?? '', ENT_QUOTES, 'UTF-8') ?>" class="inpud">
    </label>

    <!-- Thumbnail: gunakan modal media (tidak ada upload file langsung di sini) -->
    <label>Thumbnail (URL) atau pilih dari Media<br>
      <div style="display:flex;gap:.5rem;align-items:center;margin-top:.4rem;">
        <input type="text" id="thumbnail-input" name="thumbnail" value="<?= htmlspecialchars($_POST['thumbnail'] ?? ($post['thumbnail'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" style="flex:1;padding:.5rem;border:1px solid #ddd;border-radius:6px" placeholder="URL thumbnail (atau pilih dari Media)">
        <button type="button" id="btn-open-media-for-thumb" class="adam-button" style="padding:.45rem .7rem;border-radius:6px;border:1px solid #ddd">Pilih dari Media</button>
        <button type="button" id="thumbnail-clear" class="adam-link" style="padding:.35rem .6rem">Clear</button>
      </div>
      <div id="thumbnail-preview" style="margin-top:.6rem;">
        <?php if (!empty($_POST['thumbnail'])): ?>
          <img src="<?= htmlspecialchars($_POST['thumbnail'], ENT_QUOTES, 'UTF-8') ?>" alt="preview" style="max-width:220px;max-height:140px;border:1px solid #eee;padding:.3rem;background:#fff">
        <?php endif; ?>
      </div>
    </label>
  </div>
</div>

    <label for="quill-editor">Konten (rich text)</label>

    <!-- WRAP EDITOR AGAR TIDAK DI DALAM LABEL -->
    <div id="quill-editor-box" style="margin-top:.4rem;">
      <div id="quill-editor"
           style="background:#fff;border:1px solid #ddd;border-radius:6px;min-height:220px;padding:.75rem;">
      </div>
    </div>

    <input type="hidden" name="content" id="content-input" value="<?= htmlspecialchars($_POST['content'] ?? '', ENT_QUOTES, 'UTF-8') ?>">

    <!-- optional: panel to load single media details (like posts/add) -->
    <div id="media-single-panel" style="margin-top:12px;border:1px solid #eee;padding:10px;border-radius:6px;display:none;background:#fff;max-width:480px">
      <div id="media-single-content">Klik gambar pada Media untuk melihat detail & edit.</div>
    </div>

    <label>Status<br>
      <select name="status" style="padding:.4rem;border:1px solid #ddd;border-radius:6px;">
        <option value="draft" <?= (($_POST['status'] ?? '') === 'draft') ? 'selected' : '' ?>>Draft</option>
        <option value="published" <?= (($_POST['status'] ?? '') === 'published') ? 'selected' : '' ?>>Published</option>
        <option value="private" <?= (($_POST['status'] ?? '') === 'private') ? 'selected' : '' ?>>Private</option>
      </select>
    </label>

    <label style="display:block;margin-top:.6rem">Created At (opsional)<br>
      <input type="datetime-local" name="created_at" value="<?= htmlspecialchars($_POST['created_at'] ?? '', ENT_QUOTES, 'UTF-8') ?>" style="padding:.4rem;border:1px solid #ddd;border-radius:6px">
      <div style="font-size:12px;color:#666;margin-top:4px">Kosongkan untuk waktu sekarang (GMT+7)</div>
    </label>

    <label style="display:block;margin-top:.6rem">Updated At (opsional)<br>
      <input type="datetime-local" name="updated_at" value="<?= htmlspecialchars($_POST['updated_at'] ?? '', ENT_QUOTES, 'UTF-8') ?>" style="padding:.4rem;border:1px solid #ddd;border-radius:6px">
      <div style="font-size:12px;color:#666;margin-top:4px">Kosongkan untuk waktu sekarang (GMT+7)</div>
    </label>

    <p style="margin-top:.8rem">
      <button type="submit" class="adam-button">Simpan</button>
      <a href="<?= htmlspecialchars($base . '/index.php?page=admin/pages/index', ENT_QUOTES, 'UTF-8') ?>" class="adam-cancle">Batal</a>
    </p>
  </form>
</section>

<!-- expose ADIWIRA globals so shared modules work the same as posts -->
<script>
  window.ADIWIRA = window.ADIWIRA || {};
  window.ADIWIRA_BASE = <?= json_encode($base) ?>;
  window.ADIWIRA_FORM_ID = 'page-add-form';
</script>

<!-- shared modular JS (reuse posts' add modules) -->
<script src="/adiwira/static/js/add/modal-helpers.js"></script>
<script src="/adiwira/static/js/add/media-selector.js"></script>
<script src="/adiwira/static/js/add/quill-init.js"></script>
<script src="/adiwira/static/js/add/thumbnail-handler.js"></script>
<script src="/adiwira/static/js/add/youtube_preview.js"></script>