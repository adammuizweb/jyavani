<?php
// /adiwira/admin/themes/assign.php
declare(strict_types=1);

require_once __DIR__ . '/../_deny.php';

if (!defined('DASHBOARD_CONTEXT') && !defined('ADAM_THEME')) {
    adiwira_admin_404();
}

require_once __DIR__ . '/../_guard.php';
require_once __DIR__ . '/../_notify.php';
require_once __DIR__ . '/../../../app/controllers/ThemeStoreClient.php';
[$user_id, $user_role] = adiwira_require_role($pdo, ['admin'], false);
$user_role = strtolower(trim((string)$user_role));

$themeUpdates = ThemeStoreClient::getCachedUpdates();

$page_toasts = function_exists('adiwira_collect_query_toasts')
    ? adiwira_collect_query_toasts()
    : [];

$selfUrl = ADMIN_BASE_PATH . '/?page=admin/themes/assign';
$browseUrl = ADMIN_BASE_PATH . '/?page=admin/themes/browse';
$scriptBase = ADMIN_BASE_PATH;

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

function rrmdir(string $dir): void {
    if ($dir === '' || !is_dir($dir)) return;
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
        if (defined('THEME_DEBUG') && THEME_DEBUG) error_log('[THEME RRMDIR] ' . $e->getMessage());
    }
}

$SLOTS = [
    'header' => 'Header',
    'sidebar' => 'Sidebar (complementary)',
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
$themes = get_registered_themes($pdo);
$themes_by_id = [];
$themes_by_folder = [];
foreach ($themes as $t) {
    $themes_by_id[$t['id']] = $t;
    $themes_by_folder[$t['folder_name']] = $t;
}

$stmt = $pdo->prepare("SELECT id, title, slug, status FROM posts WHERE `type` = 'theme' AND is_deleted = 0 ORDER BY title ASC");
$stmt->execute();
$theme_posts = $stmt->fetchAll(PDO::FETCH_ASSOC);
$theme_posts_by_id = [];
foreach ($theme_posts as $tp) $theme_posts_by_id[$tp['id']] = $tp;

$assign_rows = [];
$stmt = $pdo->prepare("SELECT * FROM assignments");
$stmt->execute();
while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $assign_rows[$r['slot_key']] = $r;
}

$errors = [];
$messages = [];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $token = (string)($_POST['csrf_token'] ?? '');
    if (!adiwira_csrf_validate($token)) {
        $errors[] = __('Invalid CSRF token.');
    } else {
        $action = (string)($_POST['action'] ?? '');

        try {
            if ($action === 'register_themes') {
                $registered = register_all_themes_from_fs($pdo);
                if (!empty($registered)) {
                    $messages[] = __('Scan complete. Found: ') . implode(', ', $registered);
                } else {
                    $messages[] = __('Scan complete. No new folders or VIEWS_BASE is empty.');
                }

            } elseif ($action === 'activate_theme') {
                $folder = trim((string)($_POST['theme_folder'] ?? ''));
                if ($folder === '') throw new RuntimeException(__('Theme folder not specified.'));

                set_site_active_theme($pdo, $folder);
                $messages[] = __('Active theme changed to: ') . $folder;

                try {
                    $ok = bulk_assign_theme($pdo, $folder, null, $user_id);
                    if ($ok) {
                        $messages[] = __("Theme '{$folder}' also applied to all slots.");
                    } else {
                        $errors[] = __('Failed to apply theme to all slots after activation.');
                    }
                } catch (Throwable $e) {
                    $errors[] = __('Error applying theme to all slots:') . ' ' . $e->getMessage();
                    if (defined('THEME_DEBUG') && THEME_DEBUG) error_log('[ADMIN ACTIVATE->APPLY] ' . $e->getMessage());
                }

            } elseif ($action === 'apply_theme') {
                $folder = trim((string)($_POST['theme_folder'] ?? ''));
                if ($folder === '') throw new RuntimeException(__('Theme folder not specified.'));

                $ok = bulk_assign_theme($pdo, $folder, null, $user_id);
                if ($ok) {
                    $messages[] = __("Theme '{$folder}' successfully applied to all slots.");
                } else {
                    $errors[] = __('Failed to apply theme to all slots.');
                }

            } elseif ($action === 'save_assignments') {
                $incoming = $_POST['assign'] ?? [];

                foreach ($SLOTS as $slot_key => $slot_label) {
                    $val = trim((string)($incoming[$slot_key] ?? ''));

                    if ($val === '') {
                        clear_assignment($pdo, $slot_key);
                        continue;
                    }

                    if (strpos($val, 'post:') === 0) {
                        $post_id = (int)substr($val, 5);
                        if ($post_id <= 0 || !isset($theme_posts_by_id[$post_id])) {
                            $errors[] = __("Custom template not valid for slot {$slot_label}.");
                            continue;
                        }
                        assign_custom_post_to_slot($pdo, $slot_key, $post_id, $user_id);
                        continue;
                    }

                    if (strpos($val, 'theme:') === 0) {
                        $theme_id = (int)substr($val, 6);
                        if ($theme_id <= 0 || empty($themes_by_id[$theme_id])) {
                            $errors[] = __("Theme not found for slot {$slot_label}.");
                            continue;
                        }
                        $theme_folder = $themes_by_id[$theme_id]['folder_name'];
                        assign_theme_to_slot($pdo, $slot_key, $theme_folder, null, $user_id);
                        continue;
                    }

                    $errors[] = __("Unknown selection for slot {$slot_label}.");
                }

                if (empty($errors)) {
                    $messages[] = __('Theme settings saved successfully.');
                }

            } elseif ($action === 'delete_theme') {
                $theme_id = (int)($_POST['theme_id'] ?? 0);
                if ($theme_id <= 0) throw new RuntimeException(__('Theme not found (invalid id).'));

                $stmt = $pdo->prepare("SELECT * FROM themes WHERE id = :id LIMIT 1");
                $stmt->execute([':id' => $theme_id]);
                $th = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$th) throw new RuntimeException(__('Theme not found in database.'));

                if (($th['folder_name'] ?? '') === (defined('DEFAULT_THEME_FOLDER') ? DEFAULT_THEME_FOLDER : 'default')) {
                    throw new RuntimeException(__('Default theme cannot be deleted.'));
                }
                if (!empty($th['is_system'])) {
                    throw new RuntimeException(__('System theme cannot be deleted.'));
                }

                $pdo->beginTransaction();
                try {
                    $wasActive = !empty($th['is_active']);

                    $defaultFolder = defined('DEFAULT_THEME_FOLDER') ? DEFAULT_THEME_FOLDER : null;
                    $defaultThemeId = null;
                    if (!empty($defaultFolder)) {
                        $stmtXF = $pdo->prepare("SELECT id FROM themes WHERE folder_name = :f LIMIT 1");
                        $stmtXF->execute([':f' => $defaultFolder]);
                        $defRow = $stmtXF->fetch(PDO::FETCH_ASSOC);
                        if (!empty($defRow['id'])) $defaultThemeId = (int)$defRow['id'];
                    }

                    $stmtAny = $pdo->prepare("SELECT id, folder_name FROM themes WHERE id != :id ORDER BY id LIMIT 1");
                    $stmtAny->execute([':id' => $theme_id]);
                    $anyRow = $stmtAny->fetch(PDO::FETCH_ASSOC);

                    $replacementId = null;
                    if ($defaultThemeId) {
                        $replacementId = $defaultThemeId;
                    } elseif (!empty($anyRow['id'])) {
                        $replacementId = (int)$anyRow['id'];
                    }

                    if ($wasActive) {
                        $stmt0 = $pdo->prepare("UPDATE themes SET is_active = 0 WHERE is_active = 1");
                        $stmt0->execute();

                        if ($replacementId) {
                            $stmtA = $pdo->prepare("UPDATE themes SET is_active = 1 WHERE id = :id LIMIT 1");
                            $stmtA->execute([':id' => $replacementId]);
                        }
                    }

                    if ($replacementId) {
                        $stmt2 = $pdo->prepare("UPDATE assignments SET theme_id = :rid, theme_file = NULL WHERE theme_id = :tid");
                        $stmt2->execute([':rid' => $replacementId, ':tid' => $theme_id]);
                    } else {
                        $stmt2 = $pdo->prepare("UPDATE assignments SET theme_id = NULL, theme_file = NULL WHERE theme_id = :tid");
                        $stmt2->execute([':tid' => $theme_id]);
                    }

                    $stmt3 = $pdo->prepare("DELETE FROM themes WHERE id = :id");
                    $stmt3->execute([':id' => $theme_id]);

                    $pdo->commit();

                    $deleteFilesRequested = !empty($_POST['delete_files']);
                    $deletedFilesOk = false;
                    $deletedFilesMsg = '';

                    if ($deleteFilesRequested && !empty($th['folder_name'])) {
                        $folderPath = rtrim(VIEWS_BASE, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $th['folder_name'];
                        $realBase = realpath(VIEWS_BASE);
                        $realTarget = realpath($folderPath) ?: null;

                        if ($realBase && $realTarget && strpos($realTarget, $realBase) === 0) {
                            rrmdir($realTarget);
                            if (!is_dir($realTarget)) {
                                $deletedFilesOk = true;
                                $deletedFilesMsg = __('Physical theme folder deleted:') . ' ' . $th['folder_name'];
                            } else {
                                $deletedFilesMsg = __('Failed to delete physical folder:') . ' ' . $folderPath;
                            }
                        } else {
                            if (is_dir($folderPath)) {
                                rrmdir($folderPath);
                                if (!is_dir($folderPath)) {
                                    $deletedFilesOk = true;
                                    $deletedFilesMsg = __('Physical theme folder deleted:') . ' ' . $th['folder_name'];
                                } else {
                                    $deletedFilesMsg = __('Failed to delete physical folder:') . ' ' . $folderPath;
                                }
                            } else {
                                $deletedFilesMsg = __('Theme folder not found in filesystem:') . ' ' . $folderPath;
                            }
                        }
                    }

                    $messages[] = __("Theme '{$th['folder_name']}' deleted from database and related assignments cleaned.");

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

            } elseif ($action === 'check_updates') {
                // Re-register themes first so DB store_slug/store_url are current
                register_all_themes_from_fs($pdo);
                $count = count(ThemeStoreClient::checkUpdates($pdo));
                if ($count > 0) {
                    $messages[] = "{$count} " . __('theme update(s) available.');
                } else {
                    $messages[] = __('All themes are up to date.');
                }

            } elseif ($action === 'apply_theme_update') {
                $folder = trim((string)($_POST['theme_folder'] ?? ''));
                $themeName = $folder;
                $token = (string)($_POST['token'] ?? '');
                if ($folder === '') throw new RuntimeException(__('Theme folder not specified.'));
                if ($token === '' || !preg_match('/^[a-f0-9]{32}$/', $token)) {
                    throw new RuntimeException('Invalid progress token.');
                }
                session_write_close();
                $result = ThemeStoreClient::applyUpdate($pdo, $folder, $token);
                if ($result['success']) {
                    $messages[] = __("Theme '{$folder}' updated to v{$result['new_version']}.");
                } else {
                    $errors[] = (string)($result['error'] ?? __('Failed to update theme.'));
                }

            } elseif ($action === 'upload_theme') {
                if (!isset($_FILES['theme_zip'])) {
                    $errors[] = __('ZIP file not found in request.');
                } else {
                    $file = $_FILES['theme_zip'];
                    if (!empty($file['error'])) {
                        $errors[] = __('Upload failed (error code:') . ' ' . (int)$file['error'] . ').';
                    } else {
                        $name = $file['name'] ?? '';
                        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                        if ($ext !== 'zip') {
                            $errors[] = __('Only .zip files are allowed.');
                        } else {
                            $tmpZip = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'theme_upload_' . uniqid('', true) . '.zip';
                            if (!move_uploaded_file($file['tmp_name'], $tmpZip)) {
                                if (!@copy($file['tmp_name'], $tmpZip)) {
                                    $errors[] = __('Failed to save uploaded file.');
                                }
                            }

                            if (empty($errors)) {
                                $activate = !empty($_POST['activate']) && (string)$_POST['activate'] === '1';
                                try {
                                    $res = install_theme_from_zip($pdo, $tmpZip, $activate, $user_id);
                                    if (!empty($res['success'])) {
                                        $messages[] = __('Theme installed successfully:') . ' ' . (string)$res['folder'] . '. ' . (string)$res['message'];
                                    } else {
                                        $errors[] = __('Installation failed:') . ' ' . (string)($res['message'] ?? 'unknown');
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

            } else {
                $errors[] = __('Unknown action.');
            }

        } catch (Throwable $e) {
            $errors[] = 'Error: ' . $e->getMessage();
            if (defined('THEME_DEBUG') && THEME_DEBUG) error_log('[ADMIN ASSIGN] ' . $e->getMessage());
        }
    }

    // Redirect with flash instead of rendering inline (which would lack the admin layout)
    if (!empty($messages)) {
        adiwira_redirect_with_flash($selfUrl, 'success', implode(' ', $messages));
    } elseif (!empty($errors)) {
        adiwira_redirect_with_flash($selfUrl, 'error', implode(' ', $errors));
    } else {
        adiwira_redirect_with_flash($selfUrl, 'info', __('Action completed.'));
    }
}

// refresh data setelah aksi POST supaya tampilan terbaru langsung muncul
$themes = get_registered_themes($pdo);
$themes_by_id = [];
$themes_by_folder = [];
foreach ($themes as $t) {
    $themes_by_id[$t['id']] = $t;
    $themes_by_folder[$t['folder_name']] = $t;
}

$stmt = $pdo->prepare("SELECT id, title, slug, status FROM posts WHERE `type` = 'theme' AND is_deleted = 0 ORDER BY title ASC");
$stmt->execute();
$theme_posts = $stmt->fetchAll(PDO::FETCH_ASSOC);
$theme_posts_by_id = [];
foreach ($theme_posts as $tp) {
    $theme_posts_by_id[$tp['id']] = $tp;
}

$assign_rows = [];
$stmt = $pdo->prepare("SELECT * FROM assignments");
$stmt->execute();
while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $assign_rows[$r['slot_key']] = $r;
}

// expose active theme folder to JS for per-slot warnings
$activeThemeFolder = null;
foreach ($themes as $t) {
    if (!empty($t['is_active'])) { $activeThemeFolder = $t['folder_name']; break; }
}
?>
<div class="tm-wrap">
  <h2 class="tm-title">Theme Manager & Assignments</h2>

  <div class="tm-row" role="region" aria-label="Theme management controls">
    <div class="tm-scan" aria-hidden="false" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center">
      <a href="<?= h($browseUrl) ?>" class="btn btn-sm btn-outline" style="border-color:var(--adam-primary);color:var(--adam-primary);text-decoration:none;display:inline-flex;align-items:center;gap:6px;padding:6px 14px;border-radius:6px;font-size:.82rem"><?= svg_ico('globe', '', ['style' => 'width:14px;height:14px']) ?> Browse Themes</a>

      <form method="post" style="margin:0;display:inline-flex">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="action" value="check_updates">
        <button class="btn btn-sm btn-outline" type="submit" style="padding:6px 14px;border-radius:6px;font-size:.82rem;display:inline-flex;align-items:center;gap:4px"><?= svg_ico('refresh-cw', '', ['style' => 'width:14px;height:14px']) ?> <?=_e('Check Updates')?></button>
      </form>
    </div>
    <div class="tm-scan" aria-hidden="false">
      <form id="theme-scan-form" method="post" style="margin:0;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="action" value="register_themes">
        <button class="tm-ghost" type="submit"><?=_e('Scan filesystem')?></button>
      </form>
      <div class="tm-note">
        <?=_e('Scan folder:')?> <strong><?= htmlspecialchars(VIEWS_BASE, ENT_QUOTES, 'UTF-8') ?></strong>
      </div>
      <div class="tm-note"><?=__('Scan will find new theme folders and register them in the database.')?></div>
    </div>

    <form id="upload-theme-form" class="tm-upload" method="post" enctype="multipart/form-data" style="margin:0">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="action" value="upload_theme">

      <div class="tm-file" title="Choose a theme .zip to install">
        <label class="tm-file-btn" for="theme_zip_input" id="fileBtn"><?=_e('Choose .zip')?></label>
        <input id="theme_zip_input" type="file" name="theme_zip" accept=".zip" required style="display:none">
        <div class="tm-file-name" id="fileName"><?=_e('No file chosen')?></div>
      </div>

      <div class="tm-mode" role="radiogroup" aria-label="Install mode">
        <label>
          <input type="radio" name="install_mode" value="install" checked>
          <span><?=_e('Install')?></span>
        </label>
        <label>
          <input type="radio" name="install_mode" value="install_activate">
          <span><?=_e('Install & Activate')?></span>
        </label>
      </div>

      <button type="submit" class="tm-install" id="installBtn" disabled><?=_e('Install theme')?></button>

      <div class="tm-note" style="margin-left:8px">
        <?=_e('Select mode:')?> <strong>Install</strong> <?=_e('only adds;')?> <strong>Install &amp; Activate</strong> <?=_e('also makes it active.')?>
      </div>
    </form>
  </div>

  <div class="tm-card" style="margin-top:12px">
    <h3 class="tm-title" style="font-size:16px;margin:0"><?=_e('Installed themes')?></h3>

    <div class="tm-grid">
      <?php foreach ($themes as $th):
        $isActive = !empty($th['is_active']);
        $folder = $th['folder_name'] ?? '';

        $manifestFromDb = [];
        if (!empty($th['manifest_json'])) {
          $tmp = @json_decode($th['manifest_json'], true);
          if (is_array($tmp)) $manifestFromDb = $tmp;
        }

        $manifestFs = [];
        try {
          $manifestFs = function_exists('read_theme_manifest') ? read_theme_manifest(path_candidate(VIEWS_BASE, $folder, '')) : [];
        } catch (Throwable $e) { $manifestFs = []; }

        $manifest = array_merge($manifestFs, $manifestFromDb);
        $displayName = $manifest['name'] ?? ($th['name'] ?? $folder);
        $displayDesc = $manifest['description'] ?? ($th['description'] ?? '');
        $displayVersion = $manifest['version'] ?? ($th['version'] ?? '');
        $displayAuthor = $manifest['author'] ?? ($th['author'] ?? '');
        $sHint = $th['screenshot'] ?? ($manifest['screenshot'] ?? null);

        $previewUrl = null;
        try { $previewUrl = theme_preview_url_for_folder($folder, $sHint); }
        catch (Throwable $e) { $previewUrl = null; }

        $isDefault = (defined('DEFAULT_THEME_FOLDER') && $folder === DEFAULT_THEME_FOLDER);
        $isSystem = !empty($th['is_system']);
        $storeSlug = $th['store_slug'] ?? '';
        $updateInfo = isset($themeUpdates[$folder]) ? $themeUpdates[$folder] : null;
      ?>
        <div class="tm-theme <?= $isActive ? 'active' : '' ?> <?= $updateInfo ? 'has-update' : '' ?>">
          <div class="tm-delrow">
            <div class="tm-pill"><?= $isActive ? __('Activated') : __('Inactive') ?></div>

            <?php if ($isDefault || $isSystem): ?>
              <span class="tm-protected"><?=_e('System')?></span>

            <?php else: ?>
              <form method="post" class="js-theme-manager-delete" style="display:flex;gap:8px;align-items:center;margin:0" data-folder="<?= htmlspecialchars($folder, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="delete_theme">
                <input type="hidden" name="theme_id" value="<?= (int)$th['id'] ?>">

                <label class="tm-delcheck" title="<?=_e('Also delete the physical theme folder (if exists)')?>">
                  <input type="checkbox" name="delete_files" value="1">
                  <span><?=_e('Remove files')?></span>
                </label>

                <button class="tm-danger" type="submit" title="<?=_e('Delete theme from DB')?>"><?=_e('Delete')?></button>
              </form>
            <?php endif; ?>
          </div>

          <?php if ($previewUrl): ?>
            <img src="<?= htmlspecialchars($previewUrl, ENT_QUOTES, 'UTF-8') ?>"
                 alt="<?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?>"
                 class="tm-preview">
          <?php else: ?>
            <div class="tm-noprev"><?=_e('No preview')?></div>
          <?php endif; ?>

          <div class="tm-theme-title"><?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?></div>
          <?php if (trim((string)$displayDesc) !== ''): ?>
            <div class="tm-theme-meta"><?= nl2br(htmlspecialchars($displayDesc, ENT_QUOTES, 'UTF-8')) ?></div>
          <?php else: ?>
            <div class="tm-theme-meta">—</div>
          <?php endif; ?>

          <div class="tm-theme-meta">
            <?=_e('Version:')?> <?= htmlspecialchars($displayVersion ?: '-', ENT_QUOTES, 'UTF-8') ?>
            — <?=_e('Author:')?> <?= htmlspecialchars($displayAuthor ?: '-', ENT_QUOTES, 'UTF-8') ?>
          </div>

          <?php if ($updateInfo): ?>
            <div class="tm-update-banner">
              <span><?=__('Update')?> v<?= h($updateInfo['new_version']) ?> <?=__('available')?></span>
              <button type="button" class="btn-update-theme" data-folder="<?= h($folder) ?>" data-store-slug="<?= h($storeSlug) ?>"><?=_e('Update')?></button>
            </div>
          <?php endif; ?>

          <div class="tm-actions">
            <?php if (!$isActive): ?>
              <form method="post" class="js-theme-manager-activate" style="display:inline;margin:0" data-folder="<?= htmlspecialchars($folder, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="activate_theme">
                <input type="hidden" name="theme_folder" value="<?= htmlspecialchars($folder, ENT_QUOTES, 'UTF-8') ?>">
                <button class="tm-install" type="submit" style="padding:8px 10px"><?=_e('Activate')?></button>
              </form>
            <?php else: ?>
              <span class="tm-pill"><?=_e('Activated')?></span>
            <?php endif; ?>

            <form method="post" class="js-theme-manager-apply" style="display:inline;margin:0" data-folder="<?= htmlspecialchars($folder, ENT_QUOTES, 'UTF-8') ?>">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
              <input type="hidden" name="action" value="apply_theme">
              <input type="hidden" name="theme_folder" value="<?= htmlspecialchars($folder, ENT_QUOTES, 'UTF-8') ?>">
              <button class="tm-ghost" type="submit"><?=_e('Apply to all')?></button>
            </form>
          </div>
        </div>
      <?php endforeach; ?>

      <?php if (empty($themes)): ?>
        <div class="tm-note">
          <?=_e('No themes registered. Put theme folders under')?> <strong><?= htmlspecialchars(VIEWS_BASE, ENT_QUOTES, 'UTF-8') ?></strong> <?=_e('and scan.')?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div style="height:14px"></div>

  <div class="tm-card">
    <h3 class="tm-title" style="font-size:16px;margin:0"><?=_e('Per-slot assignments')?></h3>

    <form method="post" id="theme-assign-form" style="margin-top:12px">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="action" value="save_assignments">

      <table class="tm-table">
        <thead>
          <tr>
            <th style="width:18%"><?=_e('Slot')?></th>
            <th><?=_e('Assign (custom > theme)')?></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($SLOTS as $slot_key => $slot_label):
          $current = $assign_rows[$slot_key] ?? null;
          $safe_slot_id = str_replace('.', '__', $slot_key);
        ?>
          <tr>
            <td>
              <strong><?= htmlspecialchars($slot_label, ENT_QUOTES, 'UTF-8') ?></strong>
              <div class="tm-note"><?= htmlspecialchars($slot_key, ENT_QUOTES, 'UTF-8') ?></div>
            </td>
            <td>
              <div style="display:flex;gap:12px;align-items:flex-start;flex-wrap:wrap">
                <div style="flex:1;min-width:280px" class="choice-block" id="choice-theme-<?= htmlspecialchars($slot_key, ENT_QUOTES, 'UTF-8') ?>">
                  <label style="font-weight:900;display:block;margin-bottom:6px;color:var(--adam-text-2)"><?=_e('Registered theme')?></label>

                  <div class="theme-warning-placeholder" data-slot="<?= htmlspecialchars($slot_key, ENT_QUOTES, 'UTF-8') ?>"></div>

                  <select data-slot="<?= htmlspecialchars($slot_key, ENT_QUOTES, 'UTF-8') ?>"
                          class="theme-select tm-select"
                          aria-label="<?=_e('Registered theme for')?> <?= htmlspecialchars($slot_label, ENT_QUOTES, 'UTF-8') ?>">
                    <option value=""><?=_e('-- Site default (use active theme) --')?></option>
                    <?php foreach ($themes as $t2): ?>
                      <option data-folder="<?= htmlspecialchars($t2['folder_name'], ENT_QUOTES, 'UTF-8') ?>"
                              value="<?= 'theme:'.(int)$t2['id'] ?>"
                              <?= (!empty($current['theme_id']) && (int)$current['theme_id']===(int)$t2['id'] && empty($current['custom_post_id'])) ? 'selected' : '' ?>>
                        <?= htmlspecialchars(($t2['name'] ?? $t2['folder_name']) . ' (' . $t2['folder_name'] . ')', ENT_QUOTES, 'UTF-8') ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                  <div class="tm-note" style="margin-top:6px"><?=_e('Select "Site default" to revert to standard behavior (using active theme).')?></div>
                </div>

                <div style="width:360px;min-width:280px" class="choice-block" id="choice-post-<?= htmlspecialchars($slot_key, ENT_QUOTES, 'UTF-8') ?>">
                  <label style="font-weight:900;display:block;margin-bottom:6px;color:var(--adam-text-2)"><?=_e('Or use custom template (post type=theme)')?></label>
                  <select class="post-select tm-select" data-slot="<?= htmlspecialchars($slot_key, ENT_QUOTES, 'UTF-8') ?>">
                    <option value=""><?=_e('-- none --')?></option>
                    <?php foreach ($theme_posts as $tp): ?>
                      <option value="<?= 'post:'.(int)$tp['id'] ?>"
                        <?= (!empty($current['custom_post_id']) && (int)$current['custom_post_id']===(int)$tp['id']) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($tp['title'] . ' (' . $tp['slug'] . ')', ENT_QUOTES, 'UTF-8') ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                  <div class="tm-note" style="margin-top:6px">
                    <?=__('Custom templates are stored in')?> <code>posts.type='theme'</code>. <?=__('If selected, this will override the registered theme for this slot.')?>
                  </div>
                </div>
              </div>

              <input type="hidden"
                     name="assign[<?= htmlspecialchars($slot_key, ENT_QUOTES, 'UTF-8') ?>]"
                     id="assign-input-<?= htmlspecialchars($safe_slot_id, ENT_QUOTES, 'UTF-8') ?>"
                     value="<?php
                       if (!empty($current['custom_post_id'])) echo 'post:'.(int)$current['custom_post_id'];
                       elseif (!empty($current['theme_id'])) echo 'theme:'.(int)$current['theme_id'];
                       else echo '';
                     ?>">
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>

      <div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap;margin-top:12px">
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
          <button class="tm-install" type="submit"><?=_e('Apply changes')?></button>
          <a class="tm-ghost" href="<?= htmlspecialchars($selfUrl, ENT_QUOTES, 'UTF-8') ?>" style="text-decoration:none;display:inline-flex;align-items:center"><?=_e('Cancel')?></a>
        </div>
        <p class="tm-note" style="margin:0">
          <?=__('Dev note: To add new slots, you need to edit theme_helper and controller.')?>
        </p>
      </div>
    </form>
  </div>
</div>

<?php
$runtime_toasts = [];

foreach ($messages as $m) {
    $runtime_toasts[] = [
        'type' => 'success',
        'message' => (string)$m,
    ];
}
foreach ($errors as $e) {
    $runtime_toasts[] = [
        'type' => 'error',
        'message' => (string)$e,
    ];
}

$all_toasts = array_merge($page_toasts ?? [], $runtime_toasts);

if (!empty($all_toasts) && function_exists('adiwira_bootstrap_toasts_script')) {
    echo adiwira_bootstrap_toasts_script($all_toasts);
}
?>

<div id="themeProgressOverlay" class="progress-overlay" style="display:none">
  <div class="progress-box">
    <div class="progress-spinner"></div>
    <div class="progress-status" id="themeProgressStatus"><?=__('Starting update...')?></div>
    <div class="progress-bar-track"><div class="progress-bar-fill" id="themeProgressFill" style="width:0%"></div></div>
    <div class="progress-pct" id="themeProgressPct">0%</div>
  </div>
</div>

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
  function safeSlotId(slot){ return slot.replace(/\./g, '__'); }

  function toast(type, message, title){
    if (window.NewNotifToast && typeof window.NewNotifToast.show === 'function') {
      window.NewNotifToast.show({ type: type, title: title, message: message });
      return;
    }
    alert(message);
  }

  function ask(variant, opts){
    if (window.NewNotifConfirm) {
      if (variant === 'danger' && typeof window.NewNotifConfirm.danger === 'function') {
        return window.NewNotifConfirm.danger(opts);
      }
      if (typeof window.NewNotifConfirm.warning === 'function') {
        return window.NewNotifConfirm.warning(opts);
      }
    }
    return Promise.resolve(window.confirm(opts.message || <?= json_encode(__('Proceed with this action?')) ?>));
  }

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

  <?php foreach ($SLOTS as $slot_key => $_): ?>
    try { updateHidden("<?= addslashes($slot_key) ?>"); } catch(e){}
  <?php endforeach; ?>

  (function(){
    var assignForm = qs('#theme-assign-form');
    if (!assignForm) return;

    on(assignForm, 'submit', function(e){
      e.preventDefault();

      try {
        var slots = <?= json_encode(array_keys($SLOTS)) ?>;
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
        toast('error', <?= json_encode(__('An error occurred while preparing assignment data.')) ?>, <?= json_encode(__('Failed')) ?>);
        return;
      }

      ask('warning', {
        title: <?= json_encode(__('Save theme assignment')) ?>,
        message: <?= json_encode(__('Slot assignment changes will be saved. Proceed?')) ?>,
        confirmText: <?= json_encode(__('Yes, save')) ?>,
        cancelText: <?= json_encode(__('Cancel')) ?>
      }).then(function(ok){
        if (!ok) return;
        assignForm.submit();
      });
    });
  })();

  (function(){
    var fileInput   = qs('#theme_zip_input');
    var fileNameEl  = qs('#fileName');
    var uploadBtn   = qs('#installBtn');
    var installMode = qsa('input[name="install_mode"]') || [];

    if (!fileInput || !fileNameEl || !uploadBtn) return;

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
      for (var i = 0; i < installMode.length; i++) {
        if (installMode[i].checked) { mode = installMode[i].value; break; }
      }
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
      else fileNameEl.textContent = <?= json_encode(__('No file chosen')) ?>;
      updateUploadBtn();
    });

    installMode.forEach(function(r){ on(r, 'change', syncActivate); });
    syncActivate();
    updateUploadBtn();

    on(uploadForm, 'submit', function(e){
      var f = fileInput.files && fileInput.files[0];
      if (!f) {
        e.preventDefault();
        toast('error', <?= json_encode(__('Select a .zip file first.')) ?>, <?= json_encode(__('Upload failed')) ?>);
        return;
      }
      if (!/\.zip$/i.test(f.name)) {
        e.preventDefault();
        toast('error', <?= json_encode(__('Upload must be a .zip file.')) ?>, <?= json_encode(__('Upload failed')) ?>);
        return;
      }
      syncActivate();
      uploadBtn.disabled = true;
      try { uploadBtn.textContent = <?= json_encode(__('Installing…')) ?>; } catch(e2){}
    });
  })();

  (function(){
    qsa('.js-theme-manager-activate').forEach(function(form){
      on(form, 'submit', function(e){
        e.preventDefault();
        var folder = form.getAttribute('data-folder') || <?= json_encode(__('this theme')) ?>;
        ask('warning', {
          title: 'Activate theme',
          message: <?= json_encode(__('Activate theme "')) ?> + folder + <?= json_encode(__('" and apply to all slots?')) ?>,
          confirmText: <?= json_encode(__('Yes, activate')) ?>,
          cancelText: <?= json_encode(__('Cancel')) ?>
        }).then(function(ok){
          if (!ok) return;
          form.submit();
        });
      });
    });

    qsa('.js-theme-manager-apply').forEach(function(form){
      on(form, 'submit', function(e){
        e.preventDefault();
        var folder = form.getAttribute('data-folder') || <?= json_encode(__('this theme')) ?>;
        ask('warning', {
          title: <?= json_encode(__('Apply theme to all slots')) ?>,
          message: <?= json_encode(__('Apply theme "')) ?> + folder + <?= json_encode(__('" to all slots?')) ?>,
          confirmText: <?= json_encode(__('Yes, apply')) ?>,
          cancelText: <?= json_encode(__('Cancel')) ?>
        }).then(function(ok){
          if (!ok) return;
          form.submit();
        });
      });
    });

    qsa('.js-theme-manager-delete').forEach(function(form){
      on(form, 'submit', function(e){
        e.preventDefault();
        var folder = form.getAttribute('data-folder') || <?= json_encode(__('this theme')) ?>;
        var deleteFiles = form.querySelector('input[name="delete_files"]');
        var withFiles = !!(deleteFiles && deleteFiles.checked);

        var message = <?= json_encode(__('Delete theme "')) ?> + folder + <?= json_encode(__('" from database and clean up related assignments?')) ?>;
        if (withFiles) {
          message += ' ' + <?= json_encode(__('The physical theme folder will also be deleted.')) ?>;
        }

        ask('danger', {
          title: <?= json_encode(__('Delete theme')) ?>,
          message: message,
          confirmText: <?= json_encode(__('Yes, delete')) ?>,
          cancelText: <?= json_encode(__('Cancel')) ?>
        }).then(function(ok){
          if (!ok) return;
          form.submit();
        });
      });
    });
  })();

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
      txt.innerHTML = '<strong><?=_e('Warning:')?></strong> <?=_e('You selected theme')?> <em>'+ (optionText || selectedFolder) +'</em> <?=_e('for this slot. It will use the CSS &amp; JS from that theme and may conflict with the active theme assets')?> (' + (active || '—') + ').';

      var actions = document.createElement('div');
      actions.className = 'warn-actions';

      var dismiss = document.createElement('button');
      dismiss.className = 'btn-ghost';
      dismiss.type = 'button';
      dismiss.textContent = <?= json_encode(__('Acknowledge')) ?>;
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

  // ─── Theme Update Progress ───
  (function(){
    var overlay = document.getElementById('themeProgressOverlay');
    var statusEl = document.getElementById('themeProgressStatus');
    var fillEl = document.getElementById('themeProgressFill');
    var pctEl = document.getElementById('themeProgressPct');

    function showOverlay(){ if (overlay) overlay.style.display = 'flex'; }
    function hideOverlay(){ if (overlay) overlay.style.display = 'none'; }
    function setProgress(pct, status){
      if (fillEl) fillEl.style.width = pct + '%';
      if (pctEl) pctEl.textContent = pct + '%';
      if (statusEl) statusEl.textContent = status;
    }

    function makeProgressToken(){
      var h = '';
      for (var i = 0; i < 32; i++) h += '0123456789abcdef'[Math.floor(Math.random()*16)];
      return h;
    }

    function pollProgress(token){
      var interval = setInterval(function(){
        var xhr = new XMLHttpRequest();
        xhr.open('GET', '<?= h($scriptBase) ?>/admin/themes/update_progress.php?token=' + token, true);
        xhr.onload = function(){
          if (xhr.status === 200) {
            try {
              var data = JSON.parse(xhr.responseText);
              setProgress(data.percentage, data.status);
              if (data.done) {
                clearInterval(interval);
                if (data.error) {
                  setTimeout(function(){ hideOverlay(); toast('error', data.error, <?= json_encode(__('Update Failed')) ?>); }, 1000);
                } else {
                  setTimeout(function(){ hideOverlay(); window.location.reload(); }, 1500);
                }
              }
            } catch(e){}
          }
        };
        xhr.send();
      }, 1500);
    }

    function startThemeUpdate(folderName){
      var token = makeProgressToken();
      showOverlay();
      setProgress(2, <?= json_encode(__('Preparing...')) ?>);
      pollProgress(token);

      var xhr = new XMLHttpRequest();
      xhr.open('POST', '<?= h($scriptBase) ?>/admin/themes/update_apply.php', true);
      xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
      xhr.onload = function(){
        if (xhr.status !== 200) {
          clearInterval();
          hideOverlay();
          toast('error', <?= json_encode(__('Failed to start update.')) ?>, <?= json_encode(__('Error')) ?>);
        }
      };
      xhr.send('csrf_token=<?= h(csrf_token()) ?>&action=apply_theme_update&theme=' + encodeURIComponent(folderName) + '&token=' + token);
    }

    document.querySelectorAll('.btn-update-theme').forEach(function(btn){
      btn.addEventListener('click', function(){
        var folder = btn.getAttribute('data-folder');
        if (!folder) return;
        if (window.NewNotifConfirm && typeof window.NewNotifConfirm.warning === 'function') {
          window.NewNotifConfirm.warning({
            title: <?= json_encode(__('Update Theme')) ?>,
            message: <?= json_encode(__('Update theme "')) ?> + folder + <?= json_encode(__('" to the latest version?')) ?>,
            confirmText: <?= json_encode(__('Yes, update')) ?>,
            cancelText: <?= json_encode(__('Cancel')) ?>
          }).then(function(ok){
            if (ok) startThemeUpdate(folder);
          });
        } else if (confirm(<?= json_encode(__('Update theme "')) ?> + folder + <?= json_encode(__('" to the latest version?')) ?>)) {
          startThemeUpdate(folder);
        }
      });
    });
  })();
})();
</script>