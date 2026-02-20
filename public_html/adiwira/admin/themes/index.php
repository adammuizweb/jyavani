<?php
// /adiwira/admin/themes/index.php
if (!defined('DASHBOARD_CONTEXT') && !defined('ADAM_THEME')) {
    http_response_code(403);
    exit('Forbidden');
}

$messages = [];
$errors = [];

// menampilkan pesan dari redirect bulk action atau single delete
if (!empty($_GET['msg'])) {
    $messages[] = urldecode($_GET['msg']);
}
if (!empty($_GET['err'])) {
    $errors[] = urldecode($_GET['err']);
}

// filter status (GET)
$filter_status = (string)($_GET['status'] ?? '');
// pencarian (title/slug)
$search = trim((string)($_GET['q'] ?? ''));

// pagination
$page_num = max(1, (int)($_GET['p'] ?? 1));
$per_page = 10;
$offset = ($page_num - 1) * $per_page;

// base where & params (only theme and not deleted)
$where = ["p.is_deleted = 0", "p.type = 'theme'"];
$params = [];

// filter status
if ($filter_status !== '') {
    $where[] = "p.status = :status";
    $params[':status'] = $filter_status;
}

// pencarian (title/slug)
if ($search !== '') {
    $where[] = "(p.title LIKE :search OR p.slug LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}

$where_sql = implode(' AND ', $where);

// total count (with filters)
$count_sql = "SELECT COUNT(*) FROM posts p WHERE $where_sql";
$countStmt = $pdo->prepare($count_sql);
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
$pages = max(1, (int)ceil($total / $per_page));

// ambil list themes (tanpa meta besar — meta tetap di-select jika perlu)
$sql = "
  SELECT p.id, p.title, p.slug, p.status, p.created_at, p.updated_at
  FROM posts p
  WHERE $where_sql
  ORDER BY p.created_at DESC
  LIMIT :limit OFFSET :offset
";
$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$themes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// base
$base = rtrim(str_replace('\\','/', dirname($_SERVER['SCRIPT_NAME'])), '/');

$role = current_user_role($pdo) ?: 'guest';
$canBulk = !in_array($role, ['author'], true); // bulk allowed kalau bukan author

/**
 * helper: render pagination compact (sama pola seperti pages)
 */
function build_pagination_items(int $current, int $total, int $max_visible = 9): array {
    if ($total <= $max_visible) {
        return range(1, $total);
    }

    $items = [];

    $reserved = 6;
    $middle_slots = max(1, $max_visible - $reserved);

    $half = (int)floor($middle_slots / 2);
    $start = max(3, $current - $half);
    $end = min($total - 2, $current + $half);

    if ($start === 3) {
        $end = min($total - 2, $start + $middle_slots - 1);
    }
    if ($end === $total - 2) {
        $start = max(3, $end - $middle_slots + 1);
    }

    $items[] = 1;
    $items[] = 2;

    if ($start > 3) {
        $items[] = '...';
    }

    for ($i = $start; $i <= $end; $i++) {
        $items[] = $i;
    }

    if ($end < $total - 2) {
        $items[] = '...';
    }

    $items[] = $total - 1;
    $items[] = $total;

    while (count($items) > $max_visible) {
        for ($i = 0; $i < count($items); $i++) {
            if (is_int($items[$i]) && $items[$i] !== 1 && $items[$i] !== 2 && $items[$i] !== $total - 1 && $items[$i] !== $total) {
                array_splice($items, $i, 1);
                break;
            }
        }
    }

    return $items;
}

$paging_items = build_pagination_items($page_num, $pages, 9);
?>

<section class="adam-card">
  <h2>Themes / Partials</h2>

  <form method="get" style="margin-bottom:1rem;display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;">
    <input type="hidden" name="page" value="admin/themes/index">

    <input type="text" name="q" placeholder="Cari judul atau slug..." value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>" style="padding:.4rem;min-width:200px">

    <label style="display:flex;align-items:center;gap:.5rem">
      <span>Status:</span>
      <select name="status" style="padding:.4rem;">
        <option value="">-- Semua Status --</option>
        <option value="draft" <?= $filter_status==='draft'?'selected':'' ?>>Draft</option>
        <option value="published" <?= $filter_status==='published'?'selected':'' ?>>Published</option>
        <option value="private" <?= $filter_status==='private'?'selected':'' ?>>Private</option>
      </select>
    </label>

    <button type="submit" class="adam-button">Terapkan</button>
    <a href="<?= htmlspecialchars($base . '/index.php?page=admin/themes/index', ENT_QUOTES, 'UTF-8') ?>" class="adam-cancle">Reset</a>
  </form>

<p style="margin-bottom:1rem">
  <a class="adam-button" href="<?= htmlspecialchars($base . '/index.php?page=admin/themes/add', ENT_QUOTES, 'UTF-8') ?>">+ Tambah Theme Partial</a>
<?php if ($userRole === 'admin') : ?>
  &nbsp;&nbsp;
  <a class="adam-att" href="<?= htmlspecialchars($base . '/index.php?page=admin/bin/theme/index', ENT_QUOTES, 'UTF-8') ?>">🗑️ Trash</a>
<?php endif; ?>
</p>

  <!-- Bulk controls -->
  <?php if ($canBulk): ?>
  <form method="post" action="<?= htmlspecialchars($base . '/admin/themes/bulk_action.php', ENT_QUOTES, 'UTF-8') ?>" onsubmit="return confirmBulkActionThemes()">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <div style="display:flex;gap:.5rem;align-items:center;margin-bottom:.75rem;flex-wrap:wrap;">
      <label style="display:flex;align-items:center;gap:.4rem;">
        <input type="checkbox" id="selectAllThemes"> Pilih semua di halaman
      </label>

      <select id="bulkActionThemes" name="action" style="padding:.4rem;">
        <option value="">-- Bulk action --</option>
        <option value="delete">Hapus</option>
        <option value="change_status">Ubah Status</option>
      </select>

      <select id="bulkStatusThemes" name="status" style="padding:.4rem;display:none;">
        <option value="draft">Draft</option>
        <option value="published">Published</option>
        <option value="private">Private</option>
      </select>

      <button type="submit" class="adam-button">Terapkan</button>
      <small style="color:#666;margin-left:.5rem;">(Bulk akan mempengaruhi item yang dicentang)</small>
    </div>
  <?php else: ?>
    <div style="margin-bottom:1rem;color:#666;">
      Bulk actions disembunyikan untuk role <strong>author</strong>.
    </div>
  <?php endif; ?>

  <?php if (!empty($messages)): ?>
    <div class="adam-alert success" style="margin-bottom:1rem;padding:.8rem 1rem;background:#e8f7ec;border:1px solid #b6e2c2;border-radius:6px;color:#246;">
      <?php foreach ($messages as $m): ?>
        <div><?= htmlspecialchars($m, ENT_QUOTES, 'UTF-8') ?></div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if (!empty($errors)): ?>
    <div class="adam-alert error" style="margin-bottom:1rem;padding:.8rem 1rem;background:#fee;border:1px solid #fbb;color:#600;border-radius:6px;">
      <?php foreach ($errors as $e): ?>
        <div><?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?></div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="adam-table-wrapper">
    <table class="adam-table" style="margin-top:.5rem;">
      <thead>
        <tr>
          <th style="width:40px"></th>
          <th>Nama</th>
          <th>Slug</th>
          <th>Status</th>
          <th>Dibuat</th>
          <th>Aksi</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($themes)): ?>
          <tr><td colspan="6" style="padding:1rem;">Belum ada theme partial.</td></tr>
        <?php else: ?>
          <?php foreach ($themes as $t): ?>
            <tr>
              <td style="text-align:center;">
                <?php if ($canBulk): ?>
                  <input type="checkbox" class="bulkCheckboxTheme" name="ids[]" value="<?= (int)$t['id'] ?>">
                <?php else: ?>
                  &mdash;
                <?php endif; ?>
              </td>

              <!-- Nama: link ke /{slug}/ -->
              <td>
                <?php
                  $postSlug = trim((string)($t['slug'] ?? ''));
                  if ($postSlug !== '') {
                    $titleHref = '/' . rawurlencode($postSlug) . '/';
                  } else {
                    $titleHref = '#';
                  }
                ?>
                <a class="adam-link" href="<?= htmlspecialchars($titleHref, ENT_QUOTES, 'UTF-8') ?>">
                  <?= htmlspecialchars($t['title'] ?? '-', ENT_QUOTES, 'UTF-8') ?>
                </a>
              </td>

              <td><?= htmlspecialchars($t['slug'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= htmlspecialchars($t['status'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= htmlspecialchars($t['created_at'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
              <td>
                <a class="adam-ubah" href="<?= htmlspecialchars($base . '/index.php?page=admin/themes/edit&id=' . (int)$t['id'], ENT_QUOTES, 'UTF-8') ?>">Edit</a>
                &nbsp;|&nbsp;
                <button type="button"
                  class="adam-hapus"
                  onclick="confirmSingleDeleteTheme(<?= (int)$t['id'] ?>, '<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>')">
                  Hapus
                </button>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <?php if ($canBulk): ?>
  </form> <!-- akhir bulk form -->
  <?php endif; ?>

  <!-- form global untuk submit hapus single theme (agar tidak nested dengan bulk form) -->
<form id="singleDeleteForm" method="post" action="<?= htmlspecialchars($base . '/admin/themes/delete.php', ENT_QUOTES, 'UTF-8') ?>" style="display:none;">
  <input type="hidden" name="csrf_token" id="singleDeleteCsrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
  <input type="hidden" name="id" id="singleDeleteId" value="">
</form>

  <?php if ($pages > 1): ?>
    <nav class="adam-pagination" style="margin-top:1rem;">
      <?php foreach ($paging_items as $item):
        if ($item === '...') {
          echo '<span class="dots">…</span> ';
          continue;
        }
        $i = (int)$item;
        $query = $_GET;
        $query['p'] = $i;
        $link = $base . '/index.php?' . http_build_query($query);
      ?>
        <?php if ($i === $page_num): ?>
          <strong><?= $i ?></strong>
        <?php else: ?>
          <a href="<?= htmlspecialchars($link, ENT_QUOTES, 'UTF-8') ?>"><?= $i ?></a>
        <?php endif; ?>
      <?php endforeach; ?>
    </nav>
  <?php endif; ?>

  <!-- Skrip interaksi: letakkan di akhir section agar DOM sudah siap -->
  <script>
    // auto-hide any alert after 3s
    setTimeout(() => {
      const alert = document.querySelector('.adam-alert');
      if (alert) {
        alert.style.transition = 'opacity 0.5s ease';
        alert.style.opacity = '0';
        setTimeout(() => alert.remove(), 600);
      }
    }, 3000);

    // select all toggle for themes
    const selectAllThemes = document.getElementById('selectAllThemes');
    if (selectAllThemes) {
      selectAllThemes.addEventListener('change', function() {
        var checked = this.checked;
        document.querySelectorAll('.bulkCheckboxTheme').forEach(cb => cb.checked = checked);
      });
    }

    // show/hide controls depending on action
    const bulkActionThemes = document.getElementById('bulkActionThemes');
    if (bulkActionThemes) {
      bulkActionThemes.addEventListener('change', function() {
        const bulkStatusThemes = document.getElementById('bulkStatusThemes');
        if (bulkStatusThemes)
          bulkStatusThemes.style.display = (this.value === 'change_status') ? 'inline-block' : 'none';
      });
    }

    // confirm bulk action for themes (called by form onsubmit)
    function confirmBulkActionThemes() {
      var actionEl = document.getElementById('bulkActionThemes');
      if (!actionEl) return true;
      var action = actionEl.value;
      if (!action) {
        alert('Pilih bulk action terlebih dahulu.');
        return false;
      }

      if (action === 'change_status') {
        const status = document.getElementById('bulkStatusThemes').value;
        if (!status) {
          alert('Pilih status terlebih dahulu.');
          return false;
        }
      }

      var any = Array.from(document.querySelectorAll('.bulkCheckboxTheme')).some(cb => cb.checked);
      if (!any) {
        alert('Pilih minimal satu theme.');
        return false;
      }
      if (action === 'delete') {
        return confirm('Yakin ingin menghapus semua theme terpilih?');
      }
      return true;
    }

    // single delete helper
    function confirmSingleDeleteTheme(id, csrf) {
      if (!confirm('Hapus theme partial ini?')) return;
      const form = document.getElementById('singleDeleteForm');
      if (!form) {
        alert('Form hapus tidak ditemukan (internal).');
        return;
      }
      document.getElementById('singleDeleteId').value = id;
      document.getElementById('singleDeleteCsrf').value = csrf;
      form.submit();
    }
  </script>
</section>
