<?php
// /adiwira/admin/pages/index.php
if (!defined('DASHBOARD_CONTEXT') && !defined('ADAM_THEME')) {
    http_response_code(403);
    exit('Forbidden');
}

$errors = [];
$messages = [];

// menampilkan pesan dari redirect bulk action
if (!empty($_GET['msg'])) {
    $messages[] = urldecode($_GET['msg']);
}

// ambil filter dari query
$filter_status = $_GET['status'] ?? '';
$filter_author = $_GET['author'] ?? '';
$search = trim($_GET['q'] ?? '');

// pagination
$page_num = max(1, (int)($_GET['p'] ?? 1));
$per_page = 10;
$offset = ($page_num - 1) * $per_page;

// base where & params (only pages and not deleted)
$where = ["p.is_deleted = 0", "p.type = 'page'"];
$params = [];

// filter status
if ($filter_status !== '') {
    $where[] = "p.status = :status";
    $params[':status'] = $filter_status;
}

// filter author (expects user id)
if ($filter_author !== '') {
    $aid = (int)$filter_author;
    if ($aid > 0) {
        $where[] = "p.created_by = :author_id";
        $params[':author_id'] = $aid;
    }
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

// ambil data page dengan author label (name > username > id)
$sql = "
  SELECT 
    p.id, p.title, p.slug, p.status, p.created_at, p.updated_at,
    u.id AS author_id,
    u.username AS author_username,
    COALESCE(NULLIF(u.name, ''), NULLIF(u.username, ''), CAST(u.id AS CHAR)) AS author_name
  FROM posts p
  LEFT JOIN users u ON u.id = p.created_by
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
$pages_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ambil daftar penulis untuk dropdown (label: name > username > id)
$authorsStmt = $pdo->query("SELECT id, name, username FROM users WHERE is_deleted = 0 ORDER BY name ASC, username ASC");
$authors = $authorsStmt->fetchAll(PDO::FETCH_ASSOC);

// base
$base = rtrim(str_replace('\\','/', dirname($_SERVER['SCRIPT_NAME'])), '/');

$role = current_user_role($pdo) ?: 'guest';
$canBulk = !in_array($role, ['author'], true); // boleh bulk kalau bukan author

/**
 * helper: render pagination compact (sama pola seperti posts)
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
  <h2>Pages</h2>

  <form method="get" style="margin-bottom:1rem;display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;">
    <input type="hidden" name="page" value="admin/pages/index">
    <input type="text" name="q" placeholder="Cari judul atau slug..." value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>" style="padding:.4rem;min-width:200px">

    <select name="status" style="padding:.4rem;">
      <option value="">-- Semua Status --</option>
      <option value="draft" <?= $filter_status==='draft'?'selected':'' ?>>Draft</option>
      <option value="published" <?= $filter_status==='published'?'selected':'' ?>>Published</option>
      <option value="private" <?= $filter_status==='private'?'selected':'' ?>>Private</option>
    </select>

    <select name="author" style="padding:.4rem;">
      <option value="">-- Semua Penulis --</option>
      <?php foreach ($authors as $a):
        $label = $a['name'] ?: ($a['username'] ?: $a['id']);
      ?>
        <option value="<?= (int)$a['id'] ?>" <?= ((string)$filter_author === (string)$a['id']) ? 'selected' : '' ?>>
          <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
        </option>
      <?php endforeach; ?>
    </select>

    <button type="submit" class="adam-button">Terapkan</button>
    <a href="<?= htmlspecialchars($base . '/index.php?page=admin/pages/index', ENT_QUOTES, 'UTF-8') ?>" class="adam-cancle">Reset</a>
  </form>

<p style="margin-bottom:1rem">
  <a class="adam-button" href="<?= htmlspecialchars($base . '/index.php?page=admin/pages/halaman', ENT_QUOTES, 'UTF-8') ?>">+ Tambah Page</a>
<?php if ($userRole === 'admin') : ?>
  &nbsp;&nbsp;
  <a class="adam-att" href="<?= htmlspecialchars($base . '/index.php?page=admin/bin/page/index', ENT_QUOTES, 'UTF-8') ?>">🗑️ Trash</a>
<?php endif; ?>
</p>

  <!-- Bulk controls -->
  <?php if ($canBulk): ?>
  <form method="post" action="<?= htmlspecialchars($base . '/admin/pages/bulk_action.php', ENT_QUOTES, 'UTF-8') ?>" onsubmit="return confirmBulkActionPages()">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <div style="display:flex;gap:.5rem;align-items:center;margin-bottom:.75rem;flex-wrap:wrap;">
      <label style="display:flex;align-items:center;gap:.4rem;">
        <input type="checkbox" id="selectAllPages"> Pilih semua di halaman
      </label>

      <select id="bulkActionPages" name="action" style="padding:.4rem;">
        <option value="">-- Bulk action --</option>
        <option value="delete">Hapus</option>
        <option value="change_status">Ubah Status</option>
        <option value="change_author">Ubah Author</option>
      </select>

      <select id="bulkStatusPages" name="status" style="padding:.4rem;display:none;">
        <option value="draft">Draft</option>
        <option value="published">Published</option>
        <option value="private">Private</option>
      </select>

      <select id="bulkAuthorPages" name="author_id" style="padding:.4rem;display:none;">
        <option value="">-- Pilih Author --</option>
        <?php foreach ($authors as $a):
          $label = $a['name'] ?: ($a['username'] ?: $a['id']);
        ?>
          <option value="<?= (int)$a['id'] ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option>
        <?php endforeach; ?>
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
          <th>Judul</th>
          <th>Slug</th>
          <th>Status</th>
          <th>Dibuat</th>
          <th>Penulis</th>
          <th>Aksi</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($pages_list)): ?>
          <tr><td colspan="7" style="padding:1rem;">Tidak ada page ditemukan.</td></tr>
        <?php else: ?>
          <?php foreach ($pages_list as $p): ?>
            <?php
              // per-row status handling (mirip posts)
              $status = strtolower(trim($p['status'] ?? 'unknown'));
              $statusClass = in_array($status, ['published','draft','private'], true) ? $status : 'unknown';

              // status icons (small inline svg)
              $icons = [
                'published' => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false"><path d="M20 6L9 17l-5-5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
                'draft'     => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false"><path d="M3 21v-3l11-11 3 3L6 21H3z" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>',
                'private'   => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false"><rect x="3" y="11" width="18" height="10" rx="2" stroke="currentColor" stroke-width="1.5"/><path d="M7 11V8a5 5 0 0 1 10 0v3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>',
                'unknown'   => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.4"/><path d="M9.5 9a2.5 2.5 0 1 1 5 1c0 1.5-1.5 1.75-1.5 2.5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/><circle cx="12" cy="17.2" r="0.6" fill="currentColor"/></svg>',
              ];
              $iconSvg = $icons[$statusClass] ?? $icons['unknown'];
            ?>
            <tr>
              <td style="text-align:center;">
                <?php if ($canBulk): ?>
                  <input type="checkbox" class="bulkCheckboxPage" name="ids[]" value="<?= (int)$p['id'] ?>">
                <?php else: ?>
                  &mdash;
                <?php endif; ?>
              </td>

              <!-- Judul: link ke /{slug}/ -->
              <td>
                <?php
                  $postSlug = trim((string)($p['slug'] ?? ''));
                  $titleHref = $postSlug !== '' ? '/' . rawurlencode($postSlug) . '/' : '#';
                ?>
                <a class="adam-link" href="<?= htmlspecialchars($titleHref, ENT_QUOTES, 'UTF-8') ?>">
                  <?= htmlspecialchars($p['title'] ?? '-', ENT_QUOTES, 'UTF-8') ?>
                </a>
              </td>

              <td><?= htmlspecialchars($p['slug'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>

              <!-- Status pill -->
              <td>
                <span class="adam-status <?= htmlspecialchars($statusClass, ENT_QUOTES, 'UTF-8') ?>"
                      role="status" aria-label="<?= htmlspecialchars(ucfirst($status), ENT_QUOTES, 'UTF-8') ?>">
                  <span class="adam-status-icon"><?= $iconSvg ?></span>
                  <span class="adam-status-text"><?= htmlspecialchars(ucfirst($status), ENT_QUOTES, 'UTF-8') ?></span>
                </span>
              </td>

              <td><?= htmlspecialchars(format_date_ddmmyyyy_time_bracket($p['created_at']), ENT_QUOTES, 'UTF-8') ?></td>

              <!-- Penulis: link ke /author/{username}/ jika tersedia -->
              <td>
                <?php
                  $authorName = $p['author_name'] ?? '-';
                  $authorUsername = trim((string)($p['author_username'] ?? ''));
                  if ($authorUsername !== '') {
                    $authorHref = '/author/' . rawurlencode($authorUsername) . '/';
                    echo '<a class="adam-penulis" href="' . htmlspecialchars($authorHref, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($authorName, ENT_QUOTES, 'UTF-8') . '</a>';
                  } else {
                    echo htmlspecialchars($authorName, ENT_QUOTES, 'UTF-8');
                  }
                ?>
              </td>

              <td>
                <a class="adam-ubah" href="<?= htmlspecialchars($base . '/index.php?page=admin/pages/edit&id=' . (int)$p['id'], ENT_QUOTES, 'UTF-8') ?>">Edit</a>
                &nbsp;|&nbsp;
                <button type="button" class="adam-hapus" onclick="confirmSingleDelete(<?= (int)$p['id'] ?>, '<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>')">Hapus</button>
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
  
  <!-- form global untuk submit hapus single page (agar tidak nested dengan bulk form) -->
  <form id="singleDeleteForm" method="post" action="<?= htmlspecialchars($base . '/admin/pages/delete.php', ENT_QUOTES, 'UTF-8') ?>" style="display:none;">
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

    // select all toggle for pages
    const selectAllPages = document.getElementById('selectAllPages');
    if (selectAllPages) {
      selectAllPages.addEventListener('change', function() {
        var checked = this.checked;
        document.querySelectorAll('.bulkCheckboxPage').forEach(cb => cb.checked = checked);
      });
    }

    // show/hide controls depending on action
    const bulkActionPages = document.getElementById('bulkActionPages');
    if (bulkActionPages) {
      bulkActionPages.addEventListener('change', function() {
        const bulkStatusPages = document.getElementById('bulkStatusPages');
        const bulkAuthorPages = document.getElementById('bulkAuthorPages');

        if (bulkStatusPages)
          bulkStatusPages.style.display = (this.value === 'change_status') ? 'inline-block' : 'none';

        if (bulkAuthorPages)
          bulkAuthorPages.style.display = (this.value === 'change_author') ? 'inline-block' : 'none';
      });
    }

    // confirm bulk action for pages (called by form onsubmit)
    function confirmBulkActionPages() {
      var actionEl = document.getElementById('bulkActionPages');
      if (!actionEl) return true;
      var action = actionEl.value;
      if (!action) {
        alert('Pilih bulk action terlebih dahulu.');
        return false;
      }

      if (action === 'change_author') {
        const author = document.getElementById('bulkAuthorPages').value;
        if (!author) {
          alert('Pilih author terlebih dahulu.');
          return false;
        }
      }

      var any = Array.from(document.querySelectorAll('.bulkCheckboxPage')).some(cb => cb.checked);
      if (!any) {
        alert('Pilih minimal satu halaman.');
        return false;
      }
      if (action === 'delete') {
        return confirm('Yakin ingin menghapus semua halaman terpilih?');
      }
      return true;
    }

    function confirmSingleDelete(id, csrf) {
      if (!confirm('Hapus page ini?')) return;
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
