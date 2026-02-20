<?php
// /adiwira/admin/themes/assign.php
declare(strict_types=1);

if (!defined('DASHBOARD_CONTEXT') && !defined('ADAM_THEME')) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/../../../theme_helper.php';

if (session_status() === PHP_SESSION_NONE) session_start();

if (empty($_SESSION['user_id'])) {
    http_response_code(403);
    exit('<p>Akses ditolak: belum login.</p>');
}
$user_id = (int)($_SESSION['user_id']);
$user_role = $_SESSION['user_role'] ?? null;
if (!$user_role) {
    $rstmt = $pdo->prepare("SELECT role FROM users WHERE id = :id AND is_deleted = 0 LIMIT 1");
    $rstmt->execute([':id' => $user_id]);
    $user_role = $rstmt->fetchColumn();
    $_SESSION['user_role'] = $user_role;
}
if (!in_array($user_role, ['editor','admin'], true)) {
    http_response_code(403);
    exit('<p>Akses ditolak: hanya editor dan admin yang boleh mengedit tema.</p>');
}

/** Helpers (kept) */
function theme_preview_url_for_folder(string $folder_name, ?string $screenshotHint = null): ?string {
    if (!empty($screenshotHint)) {
        $trim = trim($screenshotHint);
        if (preg_match('#^https?://#i', $trim)) {
            return $trim;
        }
        if (strpos($trim, '/') === 0) {
            return $trim;
        }
        $fsCandidate = path_candidate(VIEWS_BASE, $folder_name, $trim);
        $real = realpath($fsCandidate);
        if ($real && is_file($real)) {
            $publicReal = realpath(PUBLIC_PATH) ?: null;
            if ($publicReal && strpos($real, $publicReal) === 0) {
                $webPath = str_replace('\\','/', substr($real, strlen($publicReal)));
                if ($webPath === '' || $webPath[0] !== '/') $webPath = '/' . ltrim($webPath, '/');
                $v = @filemtime($real) ?: time();
                return $webPath . '?v=' . $v;
            }
        }
    }

    $candidates = [
        'img.png','img.jpg','img.jpeg','screenshot.png','screenshot.jpg','preview.png','preview.jpg',
        'assets/img.png','assets/screenshot.png','assets/preview.png'
    ];

    foreach ($candidates as $cand) {
        $fsCandidate = path_candidate(VIEWS_BASE, $folder_name, $cand);
        $real = realpath($fsCandidate);
        if ($real && is_file($real)) {
            $publicReal = realpath(PUBLIC_PATH) ?: null;
            $fsReal = $real;
            if ($publicReal && strpos($fsReal, $publicReal) === 0) {
                $webPath = str_replace('\\','/', substr($fsReal, strlen($publicReal)));
                if ($webPath === '' || $webPath[0] !== '/') $webPath = '/' . ltrim($webPath, '/');
                $v = @filemtime($fsReal) ?: time();
                return $webPath . '?v=' . $v;
            }
        }
    }
    return null;
}

/**
 * Hapus folder secara rekursif. Silent-fail (tidak throw).
 * Pastikan Anda memanggil ini hanya setelah melakukan validasi path.
 */
function rrmdir(string $dir): void {
    if ($dir === '' || !is_dir($dir)) return;
    // safety: gunakan SPL iterators untuk performa
    try {
        $it = new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS);
        $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $path = $file->getRealPath();
            if ($file->isDir()) {
                @rmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    } catch (Throwable $e) {
        // jangan gagalkan proses utama — log jika debug aktif
        if (defined('THEME_DEBUG') && THEME_DEBUG) error_log('[THEME RRMDIR] ' . $e->getMessage());
    }
}


/** Slots
 *
 * NOTE:
 * - Removed legacy 'hero' slot.
 * - Keep old slot keys like 'main.homepage' to preserve existing assignments.
 */
$SLOTS = [
    'header' => 'Header',
    'main.homepage' => 'Main - Homepage',
    'main.search' => 'Search results',
    'list.post' => 'List - Post',
    'list.page' => 'List - Page',
    'list.category' => 'List - Category',
    'list.archive' => 'List - Archive',
    'list.author' => 'List - Author',
    'single.post' => 'Single - Post',
    'single.page' => 'Single - Page',
    'index.category' => 'Index - Category (parent list)',
    'index.author' => 'Index - Author (user list)',
    'main.404' => '404 - Not Found',
    'footer' => 'Footer',
];

/** Helpers to render current assignment (kept) */
function render_assignment_current(array $assign_rows, string $slot_key, array $themes_by_id, array $theme_posts_by_id): string {
    if (!isset($assign_rows[$slot_key])) return '<span class="muted">— (site default)</span>';
    $r = $assign_rows[$slot_key];
    if (!empty($r['custom_post_id'])) {
        $pid = (int)$r['custom_post_id'];
        $title = $theme_posts_by_id[$pid]['title'] ?? '#'.$pid;
        return '<span class="badge badge-custom">Custom: ' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</span>';
    }
    if (!empty($r['theme_id'])) {
        $tid = (int)$r['theme_id'];
        $t = $themes_by_id[$tid] ?? null;
        $file = $r['theme_file'] ?? '';
        $lbl = 'Theme: ' . htmlspecialchars($t ? $t['name'] . " ({$t['folder_name']})" : "id={$tid}", ENT_QUOTES, 'UTF-8');
        return '<span class="badge badge-theme">' . $lbl . '</span>' . ($file ? ' <small class="muted">(file legacy)</small>' : '');
    }
    return '<span class="muted">— (site default)</span>';
}

/** Fetch data for UI */
$themes = get_registered_themes($pdo); // from theme_helper
$themes_by_id = [];
$themes_by_folder = [];
foreach ($themes as $t) {
    $themes_by_id[$t['id']] = $t;
    $themes_by_folder[$t['folder_name']] = $t;
}

// posts type='theme' (custom templates)
$stmt = $pdo->prepare("SELECT id, title, slug, status FROM posts WHERE `type` = 'theme' AND is_deleted = 0 ORDER BY title ASC");
$stmt->execute();
$theme_posts = $stmt->fetchAll(PDO::FETCH_ASSOC);
$theme_posts_by_id = [];
foreach ($theme_posts as $tp) $theme_posts_by_id[$tp['id']] = $tp;

// assignments
$assign_rows = [];
$stmt = $pdo->prepare("SELECT * FROM assignments");
$stmt->execute();
while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $assign_rows[$r['slot_key']] = $r;
}

// messages/errors
$errors = [];
$messages = [];

/** POST handling kept as-is (no functional change) */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!function_exists('csrf_check') || !csrf_check($token)) {
        $errors[] = 'CSRF token tidak valid.';
    } else {
        $action = $_POST['action'] ?? '';
        try {
            if ($action === 'register_themes') {
                $registered = register_all_themes_from_fs($pdo);
                if (!empty($registered)) {
                    $messages[] = 'Scan theme selesai. Ditemukan: ' . htmlspecialchars(implode(', ', $registered), ENT_QUOTES, 'UTF-8');
                } else {
                    $messages[] = 'Scan theme selesai. Tidak ada folder baru atau VIEWS_BASE kosong.';
                }
                $themes = get_registered_themes($pdo);
                $themes_by_id = []; $themes_by_folder = [];
                foreach ($themes as $t) {
                    $themes_by_id[$t['id']] = $t;
                    $themes_by_folder[$t['folder_name']] = $t;
                }
            } elseif ($action === 'activate_theme') {
                $folder = trim((string)($_POST['theme_folder'] ?? ''));
                if ($folder === '') throw new RuntimeException('Folder theme tidak disebutkan.');
                set_site_active_theme($pdo, $folder);
                $messages[] = "Theme aktif diubah ke: " . htmlspecialchars($folder, ENT_QUOTES, 'UTF-8');
                try {
                    $ok = bulk_assign_theme($pdo, $folder, null, $user_id);
                    if ($ok) {
                        $messages[] = "Theme '" . htmlspecialchars($folder, ENT_QUOTES, 'UTF-8') . "' juga diterapkan ke semua slot (Apply to All).";
                    } else {
                        $errors[] = "Gagal menerapkan theme ke semua slot setelah aktivasi (cek logs).";
                    }
                } catch (Throwable $e) {
                    $errors[] = "Error saat menerapkan theme ke semua slot: " . $e->getMessage();
                    if (defined('THEME_DEBUG') && THEME_DEBUG) error_log('[ADMIN ACTIVATE->APPLY] ' . $e->getMessage());
                }
                $themes = get_registered_themes($pdo);
                $themes_by_id = []; $themes_by_folder = [];
                foreach ($themes as $t) {
                    $themes_by_id[$t['id']] = $t;
                    $themes_by_folder[$t['folder_name']] = $t;
                }
                $assign_rows = [];
                $stmt = $pdo->prepare("SELECT * FROM assignments");
                $stmt->execute();
                while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) $assign_rows[$r['slot_key']] = $r;
            } elseif ($action === 'apply_theme') {
                $folder = trim((string)($_POST['theme_folder'] ?? ''));
                if ($folder === '') throw new RuntimeException('Folder theme tidak disebutkan.');
                $ok = bulk_assign_theme($pdo, $folder, null, $user_id);
                if ($ok) {
                    $messages[] = "Theme '" . htmlspecialchars($folder, ENT_QUOTES, 'UTF-8') . "' berhasil diterapkan ke semua slot.";
                } else {
                    $errors[] = "Gagal menerapkan theme ke semua slot (cek logs).";
                }
                $assign_rows = [];
                $stmt = $pdo->prepare("SELECT * FROM assignments");
                $stmt->execute();
                while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) $assign_rows[$r['slot_key']] = $r;
            } elseif ($action === 'save_assignments') {
                $incoming = $_POST['assign'] ?? [];
                foreach ($SLOTS as $slot_key => $slot_label) {
                    $val = $incoming[$slot_key] ?? '';
                    $val = trim((string)$val);
                    if ($val === '') {
                        clear_assignment($pdo, $slot_key);
                        continue;
                    }
                    if (strpos($val, 'post:') === 0) {
                        $post_id = (int)substr($val, 5);
                        if ($post_id <= 0 || !isset($theme_posts_by_id[$post_id])) {
                            $errors[] = "Custom template tidak valid untuk slot {$slot_label}.";
                            continue;
                        }
                        assign_custom_post_to_slot($pdo, $slot_key, $post_id, $user_id);
                        continue;
                    }
                    if (strpos($val, 'theme:') === 0) {
                        $theme_id = (int)substr($val, 6);
                        if ($theme_id <= 0 || empty($themes_by_id[$theme_id])) {
                            $errors[] = "Theme tidak ditemukan untuk slot {$slot_label}.";
                            continue;
                        }
                        $theme_folder = $themes_by_id[$theme_id]['folder_name'];
                        assign_theme_to_slot($pdo, $slot_key, $theme_folder, null, $user_id);
                        continue;
                    }
                    $errors[] = "Pilihan tidak dikenal untuk slot {$slot_label}.";
                }
                if (empty($errors)) {
                    $messages[] = 'Pengaturan theme berhasil disimpan.';
                    $assign_rows = [];
                    $stmt = $pdo->prepare("SELECT * FROM assignments");
                    $stmt->execute();
                    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) $assign_rows[$r['slot_key']] = $r;
                }
            } elseif ($action === 'delete_theme') {
                $theme_id = (int)($_POST['theme_id'] ?? 0);
                if ($theme_id <= 0) throw new RuntimeException('Tema tidak ditemukan (invalid id).');
                $stmt = $pdo->prepare("SELECT * FROM themes WHERE id = :id LIMIT 1");
                $stmt->execute([':id' => $theme_id]);
                $th = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$th) throw new RuntimeException('Tema tidak ditemukan di database.');
                if (($th['folder_name'] ?? '') === (defined('DEFAULT_THEME_FOLDER') ? DEFAULT_THEME_FOLDER : 'default')) {
                    throw new RuntimeException('Tema default tidak boleh dihapus.');
                }
                if (!in_array($user_role, ['admin'], true)) {
                    throw new RuntimeException('Hanya admin yang boleh menghapus theme.');
                }
$pdo->beginTransaction();
try {
    // jika tema yang dihapus sedang aktif, pastikan ada pengganti aktif
    $wasActive = !empty($th['is_active']);

    // cari default theme id (jika constant di-set dan ada di DB)
    $defaultFolder = defined('DEFAULT_THEME_FOLDER') ? DEFAULT_THEME_FOLDER : null;
    $defaultThemeId = null;
    if (!empty($defaultFolder)) {
        $stmtXF = $pdo->prepare("SELECT id FROM themes WHERE folder_name = :f LIMIT 1");
        $stmtXF->execute([':f' => $defaultFolder]);
        $defRow = $stmtXF->fetch(PDO::FETCH_ASSOC);
        if (!empty($defRow['id'])) $defaultThemeId = (int)$defRow['id'];
    }

    // find another theme (any) that is NOT the one being deleted
    $stmtAny = $pdo->prepare("SELECT id, folder_name FROM themes WHERE id != :id ORDER BY id LIMIT 1");
    $stmtAny->execute([':id' => $theme_id]);
    $anyRow = $stmtAny->fetch(PDO::FETCH_ASSOC);
    $replacementId = null;
    $replacementFolder = null;

    if ($defaultThemeId) {
        $replacementId = $defaultThemeId;
    } elseif (!empty($anyRow['id'])) {
        $replacementId = (int)$anyRow['id'];
        $replacementFolder = $anyRow['folder_name'] ?? null;
    }

    // If the theme to delete is active, set the replacement active (if exists),
    // otherwise just ensure no stale 'is_active' remains.
    if ($wasActive) {
        // clear any current active flags first
        $stmt0 = $pdo->prepare("UPDATE themes SET is_active = 0 WHERE is_active = 1");
        $stmt0->execute();

        if ($replacementId) {
            // activate replacement
            $stmtA = $pdo->prepare("UPDATE themes SET is_active = 1 WHERE id = :id LIMIT 1");
            $stmtA->execute([':id' => $replacementId]);
        }
    }

    // Route assignments:
    if ($replacementId) {
        // point assignments that referenced the deleted theme to the replacement
        $stmt2 = $pdo->prepare("UPDATE assignments SET theme_id = :rid, theme_file = NULL WHERE theme_id = :tid");
        $stmt2->execute([':rid' => $replacementId, ':tid' => $theme_id]);
    } else {
        // no replacement found, clear assignments
        $stmt2 = $pdo->prepare("UPDATE assignments SET theme_id = NULL, theme_file = NULL WHERE theme_id = :tid");
        $stmt2->execute([':tid' => $theme_id]);
    }

    // finally delete theme row from DB
    $stmt3 = $pdo->prepare("DELETE FROM themes WHERE id = :id");
    $stmt3->execute([':id' => $theme_id]);

    // commit DB changes first
    $pdo->commit();

    // AFTER COMMIT: optional filesystem deletion if user requested
    $deleteFilesRequested = !empty($_POST['delete_files']);
    $deletedFilesOk = false;
    $deletedFilesMsg = '';

    if ($deleteFilesRequested && !empty($th['folder_name'])) {
        $folderPath = rtrim(VIEWS_BASE, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $th['folder_name'];
        $realBase = realpath(VIEWS_BASE);
        $realTarget = realpath($folderPath) ?: null;

        if ($realBase && $realTarget && strpos($realTarget, $realBase) === 0) {
            // safe: inside views base
            rrmdir($realTarget);
            // check if removed
            if (!is_dir($realTarget)) {
                $deletedFilesOk = true;
                $deletedFilesMsg = "Folder tema fisik dihapus: {$th['folder_name']}";
            } else {
                $deletedFilesMsg = "Gagal menghapus folder fisik (permission?) : {$folderPath}";
            }
        } else {
            // attempt cautious delete if realpath not available but dir exists
            if (is_dir($folderPath)) {
                rrmdir($folderPath);
                if (!is_dir($folderPath)) {
                    $deletedFilesOk = true;
                    $deletedFilesMsg = "Folder tema fisik dihapus (fallback): {$th['folder_name']}";
                } else {
                    $deletedFilesMsg = "Gagal menghapus folder fisik (permission atau unsafe path): {$folderPath}";
                }
            } else {
                $deletedFilesMsg = "Folder tema tidak ditemukan di filesystem: {$folderPath}";
            }
        }
    }

    // messages
    $messages[] = "Tema '" . htmlspecialchars($th['folder_name'], ENT_QUOTES, 'UTF-8') . "' dihapus dari database dan assignment terkait dibersihkan.";
    if ($deleteFilesRequested) {
        if ($deletedFilesOk) {
            $messages[] = $deletedFilesMsg;
        } else {
            $errors[] = $deletedFilesMsg;
            if (defined('THEME_DEBUG') && THEME_DEBUG) error_log('[THEME DELETE FILES] ' . $deletedFilesMsg);
        }
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
}
                $themes = get_registered_themes($pdo);
                $themes_by_id = []; $themes_by_folder = [];
                foreach ($themes as $t) {
                    $themes_by_id[$t['id']] = $t;
                    $themes_by_folder[$t['folder_name']] = $t;
                }
                $assign_rows = [];
                $stmt = $pdo->prepare("SELECT * FROM assignments");
                $stmt->execute();
                while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) $assign_rows[$r['slot_key']] = $r;
            } elseif ($action === 'upload_theme') {
                if (!isset($_FILES['theme_zip'])) {
                    $errors[] = 'File zip tidak ditemukan pada request.';
                } else {
                    $file = $_FILES['theme_zip'];
                    if (!empty($file['error'])) {
                        $errors[] = 'Upload gagal (error code: ' . (int)$file['error'] . ').';
                    } else {
                        $name = $file['name'] ?? '';
                        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                        if ($ext !== 'zip') {
                            $errors[] = 'Hanya file .zip yang diperbolehkan.';
                        } else {
                            $tmpZip = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'theme_upload_' . uniqid() . '.zip';
                            if (!move_uploaded_file($file['tmp_name'], $tmpZip)) {
                                if (!@copy($file['tmp_name'], $tmpZip)) {
                                    $errors[] = 'Gagal menyimpan file upload.';
                                }
                            }
                            if (empty($errors)) {
                                $activate = !empty($_POST['activate']) && (string)$_POST['activate'] === '1';
                                try {
                                    $res = install_theme_from_zip($pdo, $tmpZip, $activate, $user_id);
                                    if (!empty($res['success'])) {
                                        $messages[] = 'Tema berhasil diinstall: ' . htmlspecialchars((string)$res['folder'], ENT_QUOTES, 'UTF-8') . '. ' . htmlspecialchars((string)$res['message'], ENT_QUOTES, 'UTF-8');
                                    } else {
                                        $errors[] = 'Instalasi gagal: ' . htmlspecialchars((string)($res['message'] ?? 'unknown'), ENT_QUOTES, 'UTF-8');
                                    }
                                } catch (Throwable $e) {
                                    $errors[] = 'Error saat instalasi tema: ' . $e->getMessage();
                                    if (defined('THEME_DEBUG') && THEME_DEBUG) error_log('[ADMIN UPLOAD THEME] ' . $e->getMessage());
                                } finally {
                                    if (isset($tmpZip) && is_file($tmpZip)) @unlink($tmpZip);
                                }
                            }
                        }
                    }
                }
                $themes = get_registered_themes($pdo);
                $themes_by_id = []; $themes_by_folder = [];
                foreach ($themes as $t) {
                    $themes_by_id[$t['id']] = $t;
                    $themes_by_folder[$t['folder_name']] = $t;
                }
                $assign_rows = [];
                $stmt = $pdo->prepare("SELECT * FROM assignments");
                $stmt->execute();
                while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) $assign_rows[$r['slot_key']] = $r;
            } else {
                $errors[] = 'Aksi tidak dikenali.';
            }
        } catch (Throwable $e) {
            $errors[] = 'Error: ' . $e->getMessage();
            if (defined('THEME_DEBUG') && THEME_DEBUG) error_log('[ADMIN ASSIGN] ' . $e->getMessage());
        }
    }
}

$scriptBase = rtrim(str_replace('\\','/', dirname($_SERVER['SCRIPT_NAME'])), '/');

// expose active theme folder to JS for per-slot warnings
$activeThemeFolder = null;
foreach ($themes as $t) {
    if (!empty($t['is_active'])) { $activeThemeFolder = $t['folder_name']; break; }
}
?>
<!-- rest of the file (HTML + JS) is unchanged from your original and assumed to follow below -->
<!-- ===========================
     Styles (scan/upload improved)
     =========================== -->
<style>
:root{
  --muted: #6b7280;
  --accent: #0b6df0;
  --accent-600: #095acb;
  --bg: #fafafa;
  --card: #ffffff;
  --success: #e6ffed;
  --danger: #ffecec;
  --soft-border: #e6e6e9;
  --radius: 10px;
  --gap: 16px;
  --max-width: 1200px;
  --box-shadow-soft: 0 6px 18px rgba(15,15,15,0.02);
  --box-shadow-accent: 0 6px 18px rgba(11,109,240,0.08);
}

/* Basic layout */
*{box-sizing:border-box}
body{
  font-family: system-ui, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
  margin:18px;background:var(--bg);color:#111;line-height:1.45;
}
.wrap{max-width:var(--max-width);margin:0 auto;padding:0 16px}
.card{background:var(--card);border:1px solid var(--soft-border);padding:16px;border-radius:10px;box-shadow:var(--box-shadow-soft)}

/* Upload + scan layout polish */
.upload-row{display:flex;gap:14px;align-items:flex-start;flex-wrap:wrap;margin:18px 0}
.scan-area{
  min-width:260px;max-width:480px;flex:0 1 380px;background:linear-gradient(180deg,#fff,#fbfdff);
  border:1px solid #e8eefc;padding:12px;border-radius:10px;box-shadow:var(--box-shadow-accent);display:flex;flex-direction:column;gap:8px;
}
.scan-area form{display:flex;gap:8px;align-items:center}
.scan-note{font-size:.9rem;color:var(--muted);line-height:1.25;word-break:break-all}

/* Upload form */
.upload-form{
  flex:1 1 520px;display:flex;align-items:center;gap:12px;padding:12px;border-radius:10px;border:1px solid #e9eef4;background:#fff;box-shadow:0 6px 18px rgba(11,109,240,0.03);
}
.file-input-wrapper{display:flex;align-items:center;gap:10px}
.file-btn{
  background:linear-gradient(180deg,#fff,#f6f9ff);border:1px solid #dbeafe;box-shadow:0 2px 6px rgba(11,109,240,0.04);
  padding:.5rem .9rem;border-radius:8px;cursor:pointer;font-weight:600;
}
.file-name{font-size:.9rem;color:var(--muted);max-width:420px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}

/* Install button */
.btn-install{
  background: linear-gradient(180deg,var(--accent),var(--accent-600)); color:#fff; border-radius:8px; padding:.55rem .95rem; font-weight:700; border:0;
  box-shadow: 0 8px 18px rgba(11,109,240,0.12);
}
.btn-install[disabled]{opacity:.5;cursor:not-allowed;box-shadow:none}

/* Install mode pill */
.install-mode{margin-left:auto;background:#f7fbff;padding:6px;border-radius:999px;border:1px solid #e6f0ff}
.install-mode label{font-size:.9rem;color:var(--muted);margin:0 .35rem}

.install-mode {
  margin-left: auto;
  background: #f7fbff;
  padding: 6px 10px;
  border-radius: 999px;
  border: 1px solid #e6f0ff;
  display: flex;
  gap: 10px;
}

.install-option {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 0.9rem;
  color: var(--muted);
  padding: 4px 10px;
  border-radius: 999px;
  cursor: pointer;
  transition: background 0.2s ease;
}

.install-option:hover {
  background-color: rgba(11, 109, 240, 0.05);
}

.install-option input[type="radio"] {
  accent-color: var(--accent);
  cursor: pointer;
}


/* Warning banner (per-slot) */
.warning-banner{
  margin-top:8px;border-left:4px solid #f59e0b;background:linear-gradient(180deg,#fff7ed,#fffdfa);
  padding:10px 12px;border-radius:8px;color:#92400e;font-size:.95rem;display:flex;gap:8px;align-items:center;justify-content:space-between;
}
.warning-banner .warn-text{flex:1}
.warning-banner .warn-actions{display:flex;gap:8px}

/* Table & theme grid basics (kept minimal) */
.themes-grid{display:flex;gap:16px;flex-wrap:wrap;align-items:stretch}
.theme-card{background:var(--card);border:1px solid var(--soft-border);padding:12px;border-radius:10px;position:relative;display:flex;flex-direction:column;gap:8px;flex:1 1 320px;min-width:240px;max-width:360px}
.theme-card img{width:100%;height:160px;object-fit:cover;border-radius:6px;margin-bottom:6px}

/* small helpers */
.small{font-size:.9rem;color:var(--muted)}
.muted{color:var(--muted)}
.btn-ghost{background:#f3f4f6;color:#111;border:1px solid #ddd;padding:.45rem .7rem;border-radius:6px}
.btn-danger{background:#e11d48;color:#fff;padding:.35rem .6rem;border-radius:6px;border:0}

/* responsive */
@media (max-width:880px){
  .upload-form{flex-direction:column;align-items:stretch}
  .scan-area{width:100%}
  .file-name{max-width:100%}
}
</style>

<div class="wrap">
  <h2>Theme Manager & Assignments</h2>

  <?php if ($errors): ?>
    <div class="error card" style="border-left:4px solid #f44336;padding:10px;margin-top:12px"><ul style="margin:0;padding-left:18px"><?php foreach($errors as $e) echo '<li>'.htmlspecialchars($e,ENT_QUOTES,'UTF-8').'</li>'; ?></ul></div>
  <?php endif; ?>
  <?php if ($messages): ?>
    <div class="success card" style="border-left:4px solid #16a34a;padding:10px;margin-top:12px"><ul style="margin:0;padding-left:18px"><?php foreach($messages as $m) echo '<li>'.htmlspecialchars($m,ENT_QUOTES,'UTF-8').'</li>'; ?></ul></div>
  <?php endif; ?>

  <div class="upload-row" role="region" aria-label="Theme management controls">
    <!-- Scan area -->
    <div class="scan-area" aria-hidden="false">
      <form method="post" style="margin:0">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(function_exists('csrf_token') ? csrf_token() : '', ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="action" value="register_themes">
        <button class="btn-ghost" type="submit">Scan filesystem</button>
      </form>
      <div class="scan-note small">Scan folder: <strong><?=htmlspecialchars(VIEWS_BASE,ENT_QUOTES,'UTF-8')?></strong></div>
      <div class="small muted">Scan akan mencari folder tema baru dan mendaftarkannya ke database.</div>
    </div>

    <!-- Upload area -->
    <form id="upload-theme-form" class="upload-form" method="post" enctype="multipart/form-data" style="margin:0">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(function_exists('csrf_token') ? csrf_token() : '', ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="action" value="upload_theme">

      <div class="file-input-wrapper" title="Choose a theme .zip to install">
        <label class="file-btn" for="theme_zip_input" id="fileBtn">Choose .zip</label>
        <input id="theme_zip_input" type="file" name="theme_zip" accept=".zip" required style="display:none">
        <div class="file-name" id="fileName">No file chosen</div>
      </div>

<div class="install-mode" role="radiogroup" aria-label="Install mode">
  <label class="install-option">
    <input type="radio" name="install_mode" value="install" checked>
    <span>Install</span>
  </label>
  <label class="install-option">
    <input type="radio" name="install_mode" value="install_activate">
    <span>Install & Activate</span>
  </label>
</div>


      <button type="submit" class="btn-install" id="installBtn" disabled>Install theme</button>

      <div class="small muted" style="margin-left:8px">Pilih mode: <strong>Install</strong> hanya menambahkan; <strong>Install &amp; Activate</strong> juga menjadikan aktif.</div>
    </form>
  </div> <!-- upload-row -->

  <div class="card" style="margin-top:8px">
    <h3>Installed themes</h3>
    <div class="themes-grid" style="margin-top:12px">
      <?php foreach ($themes as $th):
        $isActive = !empty($th['is_active']);
        $folder = $th['folder_name'] ?? '';
        $manifestFromDb = [];
        if (!empty($th['manifest_json'])) {
            $tmp = @json_decode($th['manifest_json'], true);
            if (is_array($tmp)) $manifestFromDb = $tmp;
        }
        $manifestFs = [];
        try { $manifestFs = function_exists('read_theme_manifest') ? read_theme_manifest(path_candidate(VIEWS_BASE, $folder, '')) : []; } catch (Throwable $e) { $manifestFs = []; }
        $manifest = array_merge($manifestFs, $manifestFromDb);
        $displayName = $manifest['name'] ?? ($th['name'] ?? $folder);
        $displayDesc = $manifest['description'] ?? ($th['description'] ?? '');
        $displayVersion = $manifest['version'] ?? ($th['version'] ?? '');
        $displayAuthor = $manifest['author'] ?? ($th['author'] ?? '');
        $sHint = $th['screenshot'] ?? ($manifest['screenshot'] ?? null);
        $previewUrl = null;
        try { $previewUrl = theme_preview_url_for_folder($folder, $sHint); } catch (Throwable $e) { $previewUrl = null; }
        $isDefault = (defined('DEFAULT_THEME_FOLDER') && $folder === DEFAULT_THEME_FOLDER);
      ?>
        <div class="theme-card <?= $isActive ? 'active' : '' ?>">
          <div class="delete-top">
            <?php if ($isDefault): ?>
              <span class="small protected">Default</span>
            <?php elseif ($user_role === 'admin'): ?>
<form method="post" style="display:inline;margin:0" onsubmit="return confirm('Hapus tema <?= htmlspecialchars($folder, ENT_QUOTES, 'UTF-8') ?> dari database? Assignment yang menunjuk akan dibersihkan.');">
  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(function_exists('csrf_token') ? csrf_token() : '', ENT_QUOTES, 'UTF-8') ?>">
  <input type="hidden" name="action" value="delete_theme">
  <input type="hidden" name="theme_id" value="<?= (int)$th['id'] ?>">
<label style="
  display: inline-flex;
  align-items: center;
  gap: 6px;
  margin-left: 0.35rem;
  font-size: 0.85rem;
  background-color: rgba(255, 192, 203, 0.8);
  padding: 4px 10px;
  border-radius: 6px;
  cursor: pointer;
">
  <input type="checkbox" name="delete_files" value="1">
  <span>Remove files</span>
</label>
  <button class="btn-danger" type="submit" title="Delete theme from DB">Delete</button>
</form>
            <?php else: ?>
              <span class="small protected" title="Hanya admin yang bisa menghapus">Protected</span>
            <?php endif; ?>
          </div>

          <?php if ($previewUrl): ?>
            <img src="<?= htmlspecialchars($previewUrl, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?>" class="img">
          <?php else: ?>
            <div style="height:160px;display:flex;align-items:center;justify-content:center;background:#fafafa;color:#999;border-radius:6px;margin-bottom:10px">No preview</div>
          <?php endif; ?>

          <div class="title"><?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?></div>
          <div class="meta small muted"><?= nl2br(htmlspecialchars($displayDesc, ENT_QUOTES, 'UTF-8')) ?></div>

          <div style="display:flex;justify-content:space-between;align-items:center;margin-top:8px">
            <div class="small muted">Version: <?= htmlspecialchars($displayVersion ?: '-', ENT_QUOTES, 'UTF-8') ?> — Author: <?= htmlspecialchars($displayAuthor ?: '-', ENT_QUOTES, 'UTF-8') ?></div>
            <div class="small muted"><?= $isActive ? '<strong>Activated</strong>' : 'Inactive' ?></div>
          </div>

          <div class="actions" style="margin-top:8px">
            <?php if (!$isActive): ?>
              <form method="post" style="display:inline;margin:0" onsubmit="return confirm('Activate and apply <?= htmlspecialchars($folder, ENT_QUOTES, 'UTF-8') ?> to all slots?');">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(function_exists('csrf_token') ? csrf_token() : '', ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="activate_theme">
                <input type="hidden" name="theme_folder" value="<?= htmlspecialchars($folder, ENT_QUOTES, 'UTF-8') ?>">
                <button class="btn-ghost" type="submit" style="background:var(--accent);color:#fff;border:0;padding:.45rem .7rem;border-radius:6px">Activate</button>
              </form>
            <?php else: ?>
              <span class="btn-ghost" style="opacity:.9;padding:.35rem .6rem;border-radius:6px">Activated</span>
            <?php endif; ?>

            <form method="post" style="display:inline;margin:0" onsubmit="return confirm('Apply theme <?= htmlspecialchars($folder, ENT_QUOTES, 'UTF-8') ?> to ALL slots?');">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(function_exists('csrf_token') ? csrf_token() : '', ENT_QUOTES, 'UTF-8') ?>">
              <input type="hidden" name="action" value="apply_theme">
              <input type="hidden" name="theme_folder" value="<?= htmlspecialchars($folder, ENT_QUOTES, 'UTF-8') ?>">
              <button class="btn-ghost" type="submit">Apply to all</button>
            </form>
          </div>
        </div>
      <?php endforeach; ?>

      <?php if (empty($themes)): ?>
        <div class="muted">No themes registered. Put theme folders under <?=htmlspecialchars(VIEWS_BASE,ENT_QUOTES,'UTF-8')?> and scan.</div>
      <?php endif; ?>
    </div>
  </div>

  <hr style="margin:18px 0">

  <div class="card" style="margin-top:18px">
    <h3>Per-slot assignments</h3>

    <form method="post" id="theme-assign-form">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(function_exists('csrf_token') ? csrf_token() : '', ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="action" value="save_assignments">

      <table style="width:100%;border-collapse:collapse">
        <thead>
        <tr>
              <th style="text-align:left;padding:.6rem;border-bottom:1px solid #f1f1f1">Slot</th>
              <th style="text-align:left;padding:.6rem;border-bottom:1px solid #f1f1f1">Assign (custom &gt; theme)</th>
        </tr>
        </thead>
        <tbody>
          <?php foreach ($SLOTS as $slot_key => $slot_label):
              $current = $assign_rows[$slot_key] ?? null;
          ?>
            <tr>
              <td style="width:18%;padding:.6rem;vertical-align:top"><strong><?= htmlspecialchars($slot_label, ENT_QUOTES, 'UTF-8') ?></strong><div class="muted"><?= htmlspecialchars($slot_key, ENT_QUOTES, 'UTF-8') ?></div></td>
              <td style="padding:.6rem;vertical-align:top">
                <div style="display:flex;gap:12px;align-items:flex-start">
                  <div style="flex:1" class="choice-block" id="choice-theme-<?= htmlspecialchars($slot_key, ENT_QUOTES, 'UTF-8') ?>">
                    <label style="font-weight:600;display:block;margin-bottom:6px">Registered theme</label>

                    <!-- WARNING placeholder will be inserted here by JS -->
                    <div class="theme-warning-placeholder" data-slot="<?= htmlspecialchars($slot_key, ENT_QUOTES, 'UTF-8') ?>"></div>

                    <select data-slot="<?= htmlspecialchars($slot_key, ENT_QUOTES, 'UTF-8') ?>" class="theme-select" aria-label="Registered theme for <?= htmlspecialchars($slot_label, ENT_QUOTES, 'UTF-8') ?>">
                      <option value="">-- Site default (use active theme) --</option>
                      <?php foreach ($themes as $th): ?>
                        <option data-folder="<?= htmlspecialchars($th['folder_name'], ENT_QUOTES, 'UTF-8') ?>"
                                value="<?= 'theme:'.(int)$th['id'] ?>"
                                <?= (!empty($current['theme_id']) && (int)$current['theme_id']===(int)$th['id'] && empty($current['custom_post_id'])) ? 'selected' : ''?>>
                          <?= htmlspecialchars(($th['name'] ?? $th['folder_name']) . ' (' . $th['folder_name'] . ')', ENT_QUOTES, 'UTF-8') ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                    <div class="small muted">Pilih "Site default" untuk kembali ke perilaku standar (menggunakan tema aktif).</div>
                  </div>

                  <div style="width:360px" class="choice-block" id="choice-post-<?= htmlspecialchars($slot_key, ENT_QUOTES, 'UTF-8') ?>">
                    <label style="font-weight:600;display:block;margin-bottom:6px">Or use custom template (post type=theme)</label>
                    <select class="post-select" data-slot="<?= htmlspecialchars($slot_key, ENT_QUOTES, 'UTF-8') ?>">
                      <option value="">-- none --</option>
                      <?php foreach ($theme_posts as $tp): ?>
                        <option value="<?= 'post:'.(int)$tp['id'] ?>" <?= (!empty($current['custom_post_id']) && (int)$current['custom_post_id']===(int)$tp['id']) ? 'selected' : '' ?>>
                          <?= htmlspecialchars($tp['title'] . ' (' . $tp['slug'] . ')', ENT_QUOTES, 'UTF-8') ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                    <div class="small muted" style="margin-top:6px">Custom templates disimpan di <code>posts.type='theme'</code>. Jika dipilih, ini akan mengalahkan registered theme untuk slot ini.</div>
                  </div>
                </div>

<?php $safe_slot_id = str_replace('.', '__', $slot_key); ?>
<input type="hidden" name="assign[<?= htmlspecialchars($slot_key, ENT_QUOTES, 'UTF-8') ?>]"
       id="assign-input-<?= htmlspecialchars($safe_slot_id, ENT_QUOTES, 'UTF-8') ?>"
       value="<?php
           if (!empty($current['custom_post_id'])) {
               echo 'post:'.(int)$current['custom_post_id'];
           } elseif (!empty($current['theme_id'])) {
               $tid = (int)$current['theme_id'];
               echo 'theme:'.$tid;
           } else {
               echo '';
           }
       ?>">

              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

      <div style="display:flex;justify-content:space-between;margin-top:12px">
        <div>
        <button class="btn-install" type="submit" style="background:var(--accent);color:#fff;border:0">Apply changes</button>
        <a class="btn-ghost" href="?">Cancel</a>
        </div>
        <div>
        <p class="small muted">Catatan Dev: Untuk meambahkan slot maka perlu mengedit theme_helper dan controller.</p>
        </div>
      </div>
    </form>
  </div>

</div> <!-- wrap -->

<!-- Expose JS globals -->
<script>
window.ADIWIRA = window.ADIWIRA || {};
window.ADIWIRA.activeTheme = <?= json_encode($activeThemeFolder, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
window.ADIWIRA.scriptBase = <?= json_encode($scriptBase, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
</script>

<script>
(function(){
  'use strict';
  function qs(sel, root){ try { return (root||document).querySelector(sel); } catch(e){ return null; } }
  function qsa(sel, root){ try { return Array.prototype.slice.call((root||document).querySelectorAll(sel)); } catch(e){ return []; } }
  function getById(id){ try { return document.getElementById(id); } catch(e){ return null; } }
  function on(el, ev, fn){ if (!el) return; el.addEventListener(ev, fn); }

  // normalize slot -> safe id fragment (dots -> __)
  function safeSlotId(slot){ return slot.replace(/\./g, '__'); }

  function updateHidden(slot) {
    try {
      var themeSel  = qs('.theme-select[data-slot="'+slot+'"]');
      var postSel   = qs('.post-select[data-slot="'+slot+'"]');
      var hiddenId  = 'assign-input-' + safeSlotId(slot);
      var hidden    = getById(hiddenId);
      var themeBlock= getById('choice-theme-' + slot);
      var postBlock = getById('choice-post-' + slot);

      if (!hidden) return;

      var val = '';
      if (postSel && postSel.value) {
        val = postSel.value;
        if (postBlock) postBlock.classList.add('choice-active');
        if (themeBlock) { themeBlock.classList.add('choice-muted'); themeBlock.classList.remove('choice-active'); }
        if (themeSel) themeSel.value = '';
      } else if (themeSel && themeSel.value) {
        val = themeSel.value;
        if (themeBlock) themeBlock.classList.add('choice-active');
        if (postBlock) { postBlock.classList.add('choice-muted'); postBlock.classList.remove('choice-active'); }
        if (postSel) postSel.value = '';
      } else {
        if (themeBlock) { themeBlock.classList.remove('choice-active'); themeBlock.classList.remove('choice-muted'); }
        if (postBlock)  { postBlock.classList.remove('choice-active'); postBlock.classList.remove('choice-muted'); }
      }
      hidden.value = val;
    } catch (err) {
      if (window.console && console.error) console.error('updateHidden error', err);
    }
  }

  // attach listeners
  qsa('.theme-select').forEach(function(sel){
    try {
      var slot = sel.getAttribute('data-slot');
      on(sel, 'change', function(){
        var post = qs('.post-select[data-slot="'+slot+'"]');
        if (post) post.value = '';
        updateHidden(slot);
      });
    } catch(e){}
  });

  qsa('.post-select').forEach(function(sel){
    try {
      var slot = sel.getAttribute('data-slot');
      on(sel, 'change', function(){
        var theme = qs('.theme-select[data-slot="'+slot+'"]');
        if (theme) theme.value = '';
        updateHidden(slot);
      });
    } catch(e){}
  });

  // initialize (PHP will inject slots)
  <?php foreach ($SLOTS as $slot_key => $_): ?>
    try { updateHidden("<?= addslashes($slot_key) ?>"); } catch(e){}
  <?php endforeach; ?>

  // on submit: make sure hidden values are synced (use same safe id logic)
  (function(){
    var assignForm = qs('#theme-assign-form');
    if (!assignForm) return;
    on(assignForm, 'submit', function(e){
      try {
        var slots = <?php echo json_encode(array_keys($SLOTS)); ?>;
        slots.forEach(function(slot){
          var themeSel = qs('.theme-select[data-slot="'+slot+'"]');
          var postSel  = qs('.post-select[data-slot="'+slot+'"]');
          var hidden   = getById('assign-input-' + safeSlotId(slot));
          if (!hidden) return;
          if (postSel && postSel.value) hidden.value = postSel.value;
          else if (themeSel && themeSel.value) hidden.value = themeSel.value;
          else hidden.value = '';
        });
      } catch(err){
        e.preventDefault();
        console && console.error && console.error('Failed to sync assignments before submit', err);
        alert('Terjadi kesalahan saat menyimpan. Silakan refresh halaman dan coba lagi.');
      }
    });
  })();

  // ---------- UPLOAD FORM: isolate upload controls ----------
  (function(){
    var fileInput   = qs('#theme_zip_input');                  // explicit ID
    var fileNameEl  = qs('#fileName');                         // display area
    var uploadBtn   = qs('#installBtn');                       // explicit ID for upload button
    var installMode = qsa('input[name="install_mode"]') || [];

    if (!fileInput || !fileNameEl || !uploadBtn) {
      return;
    }

    var uploadForm = qs('#upload-theme-form');
    if (uploadForm && !getById('activateInput')) {
      var hid = document.createElement('input');
      hid.type = 'hidden';
      hid.name = 'activate';
      hid.id = 'activateInput';
      hid.value = '';
      uploadForm.appendChild(hid);
    }
    var activateInput = getById('activateInput');

    function syncActivate(){
      var mode = 'install';
      for (var i = 0; i < installMode.length; i++) if (installMode[i].checked) { mode = installMode[i].value; break; }
      if (activateInput) activateInput.value = (mode === 'install_activate') ? '1' : '';
    }

    function updateUploadBtn(){
      var f = fileInput.files && fileInput.files[0];
      var ok = f && f.name && /\.zip$/i.test(f.name);
      uploadBtn.disabled = !ok;
      uploadBtn.setAttribute('aria-disabled', (!ok).toString());
    }

    on(fileInput, 'change', function(){
      var f = fileInput.files && fileInput.files[0];
      if (f) fileNameEl.textContent = f.name + ' — ' + Math.round((f.size||0)/1024) + ' KB';
      else fileNameEl.textContent = 'No file chosen';
      updateUploadBtn();
    });

    installMode.forEach(function(r){ on(r,'change', syncActivate); });
    syncActivate();
    updateUploadBtn();

    on(uploadForm, 'submit', function(e){
      var f = fileInput.files && fileInput.files[0];
      if (!f) { e.preventDefault(); fileNameEl.textContent = 'Please choose a .zip file before installing.'; return; }
      if (!/\.zip$/i.test(f.name)) { e.preventDefault(); fileNameEl.textContent = 'Upload harus berekstensi .zip'; return; }
      syncActivate();
      uploadBtn.disabled = true;
      try { uploadBtn.textContent = 'Installing…'; } catch(e){}
    });
  })();

  // ---------- THEME WARNING BANNER (no preview) ----------
  (function(){
    var active = (window.ADIWIRA && window.ADIWIRA.activeTheme) ? window.ADIWIRA.activeTheme : null;

    function renderThemeWarning(slot, selectedFolder, optionText) {
      var placeholder = qs('.theme-warning-placeholder[data-slot="'+slot+'"]');
      if (!placeholder) return;
      placeholder.innerHTML = '';
      if (!selectedFolder || selectedFolder === active) return;

      var banner = document.createElement('div');
      banner.className = 'warning-banner';
      banner.setAttribute('role','alert');

      var txt = document.createElement('div');
      txt.className = 'warn-text';
      txt.innerHTML = '<strong>Peringatan:</strong> Anda memilih tema <em>'+ (optionText || selectedFolder) +'</em> untuk slot ini. Ini akan menggunakan CSS &amp; JS dari tema tersebut dan dapat berpotensi menimbulkan konflik dengan assets tema aktif (' + (active || '—') + ').';

      var actions = document.createElement('div');
      actions.className = 'warn-actions';

      var dismiss = document.createElement('button');
      dismiss.className = 'btn-ghost';
      dismiss.type = 'button';
      dismiss.textContent = 'Acknowledge';
      dismiss.onclick = function(){ placeholder.innerHTML = ''; };

      actions.appendChild(dismiss);
      banner.appendChild(txt);
      banner.appendChild(actions);
      placeholder.appendChild(banner);
    }

    function attachWarnings(){
      qsa('.theme-select').forEach(function(sel){
        var slot = sel.getAttribute('data-slot');
        sel.addEventListener('change', function(){
          var opt = sel.options[sel.selectedIndex];
          var folder = opt ? opt.getAttribute('data-folder') || '' : '';
          var text = opt ? opt.text : '';
          renderThemeWarning(slot, folder, text);
        });

        // init state
        (function init(){
          var opt = sel.options[sel.selectedIndex];
          var folder = opt ? opt.getAttribute('data-folder') || '' : '';
          var text = opt ? opt.text : '';
          renderThemeWarning(slot, folder, text);
        })();
      });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', attachWarnings);
    else attachWarnings();
  })();

})(); // end
</script>
