<?php
// /adiwira/admin/categories/add.php
if (!defined('DASHBOARD_CONTEXT') && !defined('ADAM_THEME')) {
    http_response_code(403);
    exit('Forbidden');
}

// helper slugify (simple)
if (!function_exists('slugify')) {
    function slugify(string $text): string {
        $text = mb_strtolower($text, 'UTF-8');
        // replace non alnum with -
        $text = preg_replace('/[^\p{L}\p{N}\-]+/u', '-', $text);
        $text = preg_replace('/[-]{2,}/', '-', $text);
        $text = trim($text, '-');
        return $text ?: bin2hex(random_bytes(4));
    }
}

$errors = [];
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!csrf_check($token)) {
        $errors[] = 'CSRF token tidak valid.';
    }

    $name = trim((string)($_POST['name'] ?? ''));
    $slug = trim((string)($_POST['slug'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $parent_id = isset($_POST['parent_id']) && $_POST['parent_id'] !== '' ? (int)$_POST['parent_id'] : null;

    if ($name === '') $errors[] = 'Nama kategori tidak boleh kosong.';

    if ($slug === '') {
        $slug = slugify($name);
    } else {
        $slug = slugify($slug);
    }

    // uniqueness check
    if (empty($errors)) {
        $stmt = $pdo->prepare("SELECT id FROM categories WHERE slug = :slug LIMIT 1");
        $stmt->execute([':slug' => $slug]);
        if ($stmt->fetch()) {
            $errors[] = 'Slug sudah dipakai. Silakan gunakan slug lain.';
        }
    }

    if (empty($errors)) {
        $stmt = $pdo->prepare("INSERT INTO categories (name, slug, description, parent_id, created_by, created_at, updated_at) VALUES (:name, :slug, :desc, :parent, :created_by, NOW(), NOW())");
        $ok = $stmt->execute([
            ':name' => $name,
            ':slug' => $slug,
            ':desc' => $description ?: null,
            ':parent' => $parent_id,
            ':created_by' => $_SESSION['user_id'] ?? null
        ]);
        if ($ok) {
            $base = rtrim(str_replace('\\','/', dirname($_SERVER['SCRIPT_NAME'])), '/');
            ?>
            <div id="successModal" style="position:fixed;inset:0;background:rgba(0,0,0,0.4);display:flex;align-items:center;justify-content:center;z-index:4000;">
              <div style="background:#fff;padding:1.5rem 2rem;border-radius:8px;max-width:360px;width:90%;box-shadow:0 4px 16px rgba(0,0,0,0.2);text-align:center;">
                <h3 style="margin-top:0;color:#246;">✅ Kategori Berhasil Ditambahkan</h3>
                <p>🥳 Akan diarahkan ke daftar kategori...</p>
              </div>
            </div>
            <script>
              setTimeout(() => {
                window.location.href = "<?= htmlspecialchars($base . '/index.php?page=admin/categories/index', ENT_QUOTES) ?>";
              }, 1500);
            </script>
            <?php
            exit;
        } else {
            $errors[] = 'Gagal memperbarui kategori.';
        }
    }
}

// --- build category tree for hierarchical select ---
// ambil semua kategori
$stmt = $pdo->prepare("SELECT id, name, parent_id FROM categories WHERE is_deleted = 0");
$stmt->execute();
$allCats = $stmt->fetchAll(PDO::FETCH_ASSOC);

// map children: key = parent_id (0 untuk NULL)
$children = [];
foreach ($allCats as $c) {
    $pid = $c['parent_id'] === null ? 0 : (int)$c['parent_id'];
    $children[$pid][] = $c;
}

// flatten tree (pre-order) with depth info
$flatten = [];
$walk = function($pid, $depth) use (&$children, &$flatten, &$walk) {
    if (!isset($children[$pid])) return;
    // sort per-level agar tampil rapi (optional)
    usort($children[$pid], function($a, $b){ return strcmp($a['name'], $b['name']); });
    foreach ($children[$pid] as $node) {
        $flatten[] = [
            'id' => (int)$node['id'],
            'name' => $node['name'],
            'depth' => $depth,
            'parent_id' => $node['parent_id'] === null ? null : (int)$node['parent_id']
        ];
        $walk((int)$node['id'], $depth + 1);
    }
};
$walk(0, 0);

$base = rtrim(str_replace('\\','/', dirname($_SERVER['SCRIPT_NAME'])), '/');
?>
<section class="adam-card">
  <h2>Tambah Kategori</h2>

  <?php if ($errors): ?>
    <div class="adam-error">
      <ul><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>

  <form method="post" novalidate>
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    
<div class="adam-accordion"
     id="theme-meta-accordion"
     data-open="1">

<button type="button"
        class="adam-accordion-toggle"
        aria-expanded="true"
        aria-controls="theme-meta-body">
    ⚙️ Pengaturan Category
    <span class="chevron">▸</span>
  </button>

  <div class="adam-accordion-body" id="theme-meta-body">
    <label>Nama<br>
      <input type="text" name="name" value="<?= htmlspecialchars($_POST['name'] ?? '', ENT_QUOTES, 'UTF-8') ?>" class="inpud">
    </label>

    <label>Slug (opsional)<br>
      <input type="text" name="slug" value="<?= htmlspecialchars($_POST['slug'] ?? '', ENT_QUOTES, 'UTF-8') ?>" class="inpud">
    </label>
    
      </div>
</div>

    <label>Parent (opsional)<br>
      <select name="parent_id" style="width:100%;padding:.45rem;margin-top:.4rem;border:1px solid #ddd;border-radius:6px">
        <option value="">-- Tidak ada --</option>
        <?php
        $selectedParent = isset($_POST['parent_id']) && $_POST['parent_id'] !== '' ? (int)$_POST['parent_id'] : null;
        foreach ($flatten as $row):
            // prefix per level, contoh: "— " per depth
            $prefix = str_repeat('— ', max(0, $row['depth']));
        ?>
          <option value="<?= (int)$row['id'] ?>" <?= ($selectedParent !== null && (int)$selectedParent === (int)$row['id']) ? 'selected' : '' ?>>
            <?= $prefix . htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8') ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>

    <label>Deskripsi<br>
      <textarea name="description" style="width:100%;min-height:100px;padding:.5rem;margin-top:.4rem;border:1px solid #ddd;border-radius:6px"><?= htmlspecialchars($_POST['description'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
    </label>

    <p><button type="submit" class="adam-button">Simpan</button> <a href="<?= htmlspecialchars($base . '/index.php?page=admin/categories/index', ENT_QUOTES, 'UTF-8') ?>" class="adam-cancle">Batal</a></p>
  </form>
</section>
