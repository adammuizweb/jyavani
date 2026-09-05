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
require_once __DIR__ . '/../../../app/controllers/UpdateStatusController.php';
[$user_id, $user_role] = adiwira_require_permission($pdo, 'core.themes.manage', false);
adiwira_require_site_owner($pdo, false);
$user_role = strtolower(trim((string)$user_role));

$themeUpdates = UpdateStatusController::getComponentUpdates('themes');

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

function theme_complete_physical_manifest_for_hook(string $folder): array {
    if (strlen($folder) > 128 || preg_match('/\A[a-zA-Z0-9_-][a-zA-Z0-9._-]*\z/', $folder) !== 1) return [];
    $root = realpath(rtrim(VIEWS_BASE, DIRECTORY_SEPARATOR));
    $candidate = rtrim(VIEWS_BASE, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $folder;
    $directory = realpath($candidate);
    if ($root === false || $directory === false || is_link($candidate)
        || !str_starts_with($directory, $root . DIRECTORY_SEPARATOR)) return [];
    $path = $directory . DIRECTORY_SEPARATOR . 'theme.json';
    $real = realpath($path);
    $size = @filesize($path);
    if ($real === false || is_link($path) || !is_file($path) || !is_int($size) || $size < 2 || $size > 1024 * 1024
        || !str_starts_with($real, $directory . DIRECTORY_SEPARATOR)) return [];
    $raw = @file_get_contents($real);
    if (!is_string($raw)) return [];
    try {
        $manifest = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        return [];
    }
    if (!is_array($manifest) || array_is_list($manifest)) return [];
    $manifestFolder = $manifest['folder'] ?? $folder;
    if (!is_string($manifestFolder) || $manifestFolder !== $folder) return [];
    $manifest['folder'] = $folder;
    return $manifest;
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

$slotDefinitions = theme_slot_definitions($pdo);
$SLOTS = [];
foreach ($slotDefinitions as $slotKey => $definition) {
    $SLOTS[$slotKey] = __((string)$definition['label']);
}

function render_assignment_current(array $assign_rows, string $slot_key, array $themes_by_id, array $theme_posts_by_id): string {
    if (!isset($assign_rows[$slot_key])) return '<span class="muted">— (' . h(__('Site default')) . ')</span>';
    $r = $assign_rows[$slot_key];
    if (!empty($r['custom_post_id'])) {
        $pid = (int)$r['custom_post_id'];
        $title = $theme_posts_by_id[$pid]['title'] ?? '#'.$pid;
        return '<span class="badge badge-custom">' . h(sprintf(__('Custom: %s'), $title)) . '</span>';
    }
    if (!empty($r['theme_id'])) {
        $tid = (int)$r['theme_id'];
        $t = $themes_by_id[$tid] ?? null;
        $file = $r['theme_file'] ?? '';
        $themeLabel = $t ? $t['name'] . " ({$t['folder_name']})" : "id={$tid}";
        $lbl = h(sprintf(__('Theme: %s'), $themeLabel));
        return '<span class="badge badge-theme">' . $lbl . '</span>' . ($file ? ' <small class="muted">(' . h(__('legacy file')) . ')</small>' : '');
    }
    return '<span class="muted">— (' . h(__('Site default')) . ')</span>';
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
foreach ($assign_rows as $slotKey => $assignment) {
    if (isset($slotDefinitions[$slotKey]) && !theme_assignment_matches_definition($assignment, $slotDefinitions[$slotKey])) {
        unset($slotDefinitions[$slotKey], $SLOTS[$slotKey]);
    }
}

$errors = [];
$messages = [];
$warnings = [];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $token = (string)($_POST['csrf_token'] ?? '');
    if (!adiwira_csrf_validate($token)) {
        $errors[] = __('Invalid CSRF token.');
    } else {
        $action = (string)($_POST['action'] ?? '');
        $managerLocks = [];
        $removedThemeFolder = null;

        try {
            if (in_array($action, ['register_themes', 'activate_theme', 'apply_theme', 'save_assignments', 'delete_theme'], true)) {
                $affectedFolders = [];
                if ($action === 'register_themes') {
                    $affectedFolders = theme_registration_lock_folders($pdo);
                } elseif (in_array($action, ['activate_theme', 'apply_theme'], true)) {
                    $affectedFolders[] = trim((string)($_POST['theme_folder'] ?? ''));
                } elseif ($action === 'delete_theme') {
                    $affectedFolders[] = trim((string)($_POST['theme_folder'] ?? ''));
                } elseif ($action === 'save_assignments') {
                    foreach ($assign_rows as $assignment) {
                        $themeId = (int)($assignment['theme_id'] ?? 0);
                        if ($themeId > 0 && is_string($themes_by_id[$themeId]['folder_name'] ?? null)) {
                            $affectedFolders[] = $themes_by_id[$themeId]['folder_name'];
                        }
                    }
                    foreach ((array)($_POST['assign'] ?? []) as $slotKey => $value) {
                        if (!is_string($slotKey) || !isset($slotDefinitions[$slotKey])) continue;
                        if (!is_string($value) || !str_starts_with($value, 'theme:')) continue;
                        $themeId = (int)substr($value, 6);
                        if ($themeId > 0 && is_string($themes_by_id[$themeId]['folder_name'] ?? null)) {
                            $affectedFolders[] = $themes_by_id[$themeId]['folder_name'];
                        }
                    }
                }
                $affectedFolders = array_values(array_unique($affectedFolders));
                $managerLocks = theme_operation_acquire(theme_lifecycle_lock_keys($affectedFolders));

                // Discard pre-lock UI snapshots before lifecycle-sensitive validation and mutation.
                $themes = get_registered_themes($pdo);
                $themes_by_id = [];
                $themes_by_folder = [];
                foreach ($themes as $themeRow) {
                    $themes_by_id[$themeRow['id']] = $themeRow;
                    $themes_by_folder[$themeRow['folder_name']] = $themeRow;
                }
                $assign_rows = [];
                $stmt = $pdo->prepare("SELECT * FROM assignments");
                $stmt->execute();
                while ($assignment = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $assign_rows[$assignment['slot_key']] = $assignment;
                }
            }

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
                if (!is_array($incoming)) $incoming = [];

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
                if ((string)($th['folder_name'] ?? '') !== trim((string)($_POST['theme_folder'] ?? ''))) {
                    throw new RuntimeException(__('Theme not found in database.'));
                }

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
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    throw $e;
                }

                $deleteFilesRequested = !empty($_POST['delete_files']);
                $deletedFilesOk = false;
                $deletedFilesMsg = '';

                if ($deleteFilesRequested && !empty($th['folder_name'])) {
                    $folderPath = rtrim(VIEWS_BASE, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $th['folder_name'];
                    $realBase = realpath(VIEWS_BASE);
                    $realTarget = realpath($folderPath) ?: null;
                    $contained = $realBase !== false && $realTarget !== null && !is_link($folderPath)
                        && $realTarget !== $realBase && str_starts_with($realTarget, $realBase . DIRECTORY_SEPARATOR);

                    if ($contained) {
                        rrmdir($realTarget);
                        if (!is_dir($realTarget)) {
                            $deletedFilesOk = true;
                            $deletedFilesMsg = __('Physical theme folder deleted:') . ' ' . $th['folder_name'];
                        } else {
                            $deletedFilesMsg = __('Failed to delete physical folder:') . ' ' . $folderPath;
                        }
                    } elseif (!file_exists($folderPath) && !is_link($folderPath)) {
                        $deletedFilesMsg = __('Theme folder not found in filesystem:') . ' ' . $folderPath;
                    } else {
                        $deletedFilesMsg = __('Failed to delete physical folder:') . ' ' . $folderPath;
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
                $removedThemeFolder = (string)$th['folder_name'];
                ThemeStoreClient::forgetInstalledTheme($removedThemeFolder);
            } elseif ($action === 'check_updates') {
                session_write_close();
                try {
                    $snapshot = UpdateStatusController::checkAll($pdo);
                } finally {
                    ensure_session_started(true);
                }
                UpdateStatusController::hydrateCoreSession($snapshot);
                $count = (int)($snapshot['total'] ?? 0);
                if (($snapshot['state'] ?? 'ok') !== 'ok') {
                    $warnings[] = __('Update check completed with partial results.') . " {$count} " . __('update(s) available across Core, plugins, and themes.');
                } elseif ($count > 0) {
                    $messages[] = "{$count} " . __('update(s) available across Core, plugins, and themes.');
                } else {
                    $messages[] = __('All Core, plugins, and themes are up to date.');
                }

            } elseif ($action === 'apply_theme_update') {
                $folder = trim((string)($_POST['theme_folder'] ?? ''));
                $themeName = $folder;
                $token = (string)($_POST['token'] ?? '');
                if ($folder === '') throw new RuntimeException(__('Theme folder not specified.'));
                if ($token === '' || !preg_match('/^[a-f0-9]{32}$/', $token)) {
                    throw new RuntimeException('Invalid progress token.');
                }
                $decisionsJson = (string)($_POST['decisions'] ?? '{}');
                $decisionsTrimmed = trim($decisionsJson);
                if (strlen($decisionsJson) > 16384 || $decisionsTrimmed === ''
                    || $decisionsTrimmed[0] !== '{' || !str_ends_with($decisionsTrimmed, '}')) {
                    throw new RuntimeException(__('Invalid update decisions.'));
                }
                try {
                    $decisions = json_decode($decisionsJson, true, 16, JSON_THROW_ON_ERROR);
                } catch (JsonException $error) {
                    throw new RuntimeException(__('Invalid update decisions.'), 0, $error);
                }
                if (!is_array($decisions)) throw new RuntimeException(__('Invalid update decisions.'));
                if (!update_operation_begin($token, (int)$user_id, 'theme', $folder, __('Starting...'))) {
                    throw new RuntimeException(__('Unable to start update operation.'));
                }
                $directUpdateLock = update_operation_acquire_lock();
                if (!is_resource($directUpdateLock)) {
                    update_operation_fail($token, __('Update failed.'), __('Another update is already running.'));
                    throw new RuntimeException(__('Another update is already running.'));
                }
                session_write_close();
                try {
                    $result = ThemeStoreClient::applyUpdate($pdo, $folder, $token, $decisions);
                } finally {
                    if (($result['success'] ?? false) !== true) {
                        $record = update_operation_read($token);
                        if (($record['outcome'] ?? '') !== 'cancelled') {
                            update_operation_fail($token, __('Update failed.'), (string)($result['error'] ?? __('Update failed.')));
                        }
                    }
                    update_operation_release_lock($directUpdateLock);
                }
                if ($result['success']) {
                    UpdateStatusController::removeUpdate('themes', $folder, (string)$result['new_version']);
                    $messages[] = __("Theme '{$folder}' updated to v{$result['new_version']}.");
                    if (is_string($result['warning'] ?? null) && $result['warning'] !== '') {
                        $messages[count($messages) - 1] .= ' ' . $result['warning'];
                    }
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
        } finally {
            if ($managerLocks !== []) theme_operation_release($managerLocks);
        }
        if (is_string($removedThemeFolder) && $removedThemeFolder !== '') {
            UpdateStatusController::removeUpdate('themes', $removedThemeFolder);
        }
    }

    // Redirect with flash instead of rendering inline (which would lack the admin layout)
    if (!empty($messages)) {
        adiwira_redirect_with_flash($selfUrl, 'success', implode(' ', $messages));
    } elseif (!empty($warnings)) {
        adiwira_redirect_with_flash($selfUrl, 'warning', implode(' ', $warnings));
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
foreach ($assign_rows as $slotKey => $assignment) {
    if (isset($slotDefinitions[$slotKey]) && !theme_assignment_matches_definition($assignment, $slotDefinitions[$slotKey])) {
        unset($slotDefinitions[$slotKey], $SLOTS[$slotKey]);
    }
}
$unavailableAssignments = array_diff_key($assign_rows, $slotDefinitions);

// expose active theme folder to JS for per-slot warnings
$activeThemeFolder = null;
foreach ($themes as $t) {
    if (!empty($t['is_active'])) { $activeThemeFolder = $t['folder_name']; break; }
}
?>
<link rel="stylesheet" href="/static/dashboard/css/update.css?v=<?= (int)(@filemtime(PUBLIC_PATH . '/static/dashboard/css/update.css') ?: 0) ?>">
<div class="tm-wrap">
  <div data-update-status-page hidden></div>
  <h2 class="tm-title"><?= _e('Theme Manager & Assignments') ?></h2>

  <div class="tm-row" role="region" aria-label="<?= _e('Theme management controls') ?>">
    <div class="tm-scan" aria-hidden="false" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center">
      <a href="<?= h($browseUrl) ?>" class="btn btn-sm btn-outline" style="border-color:var(--adam-primary);color:var(--adam-primary);text-decoration:none;display:inline-flex;align-items:center;gap:6px;padding:6px 14px;border-radius:6px;font-size:.82rem"><?= svg_ico('globe', '', ['style' => 'width:14px;height:14px']) ?> <?= _e('Browse Themes') ?></a>

      <form method="post" style="margin:0;display:inline-flex">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="action" value="check_updates">
        <button class="btn btn-sm btn-outline" type="submit" style="padding:6px 14px;border-radius:6px;font-size:.82rem;display:inline-flex;align-items:center;gap:4px"><?= svg_ico('refresh-cw', '', ['style' => 'width:14px;height:14px']) ?> <?=_e('Check All Updates')?></button>
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

      <div class="tm-file" title="<?= _e('Choose a theme .zip to install') ?>">
        <label class="tm-file-btn" for="theme_zip_input" id="fileBtn"><?=_e('Choose .zip')?></label>
        <input id="theme_zip_input" type="file" name="theme_zip" accept=".zip" required style="display:none">
        <div class="tm-file-name" id="fileName"><?=_e('No file chosen')?></div>
      </div>

      <div class="tm-mode" role="radiogroup" aria-label="<?= _e('Install mode') ?>">
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
        <?=_e('Select mode:')?> <strong><?=_e('Install')?></strong> <?=_e('only adds;')?> <strong><?=_e('Install & Activate')?></strong> <?=_e('also makes it active.')?>
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
        $completePhysicalManifest = theme_complete_physical_manifest_for_hook($folder);

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
                <input type="hidden" name="theme_folder" value="<?= htmlspecialchars($folder, ENT_QUOTES, 'UTF-8') ?>">

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
              <button type="button" class="btn-update-theme" data-folder="<?= h($folder) ?>" data-store-slug="<?= h($storeSlug) ?>"<?= ($updateInfo['actionable'] ?? false) === true ? '' : ' disabled title="' . h(__('Run "Check for Updates" first.')) . '"' ?>><?=_e('Update')?></button>
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
            <?php
            if (function_exists('do_action_isolated')) {
              $themeActionContext = [
                'folder' => $folder,
                'is_active' => $isActive,
                'is_default' => $isDefault,
                'is_system' => $isSystem,
                'update' => $updateInfo,
                'user_id' => (int)$user_id,
                'admin_base_path' => ADMIN_BASE_PATH,
                'return_url' => $selfUrl,
              ];
              foreach (do_action_isolated('theme_manager_theme_actions', $th, $completePhysicalManifest, $themeActionContext) as $hookError) {
                error_log('[theme_manager_theme_actions] ' . $hookError['message']);
              }
            }
            ?>
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

      <div class="tm-table-wrap">
        <table class="tm-table">
          <thead>
            <tr>
              <th style="width:22%"><?=_e('Slot')?></th>
              <th><?=_e('Assign (custom > theme)')?></th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($slotDefinitions as $slot_key => $slotDefinition):
            $slot_label = __((string)$slotDefinition['label']);
            $current = $assign_rows[$slot_key] ?? null;
            $safe_slot_id = str_replace('.', '__', $slot_key);
          ?>
            <tr>
              <td>
                <strong><?= htmlspecialchars($slot_label, ENT_QUOTES, 'UTF-8') ?></strong>
                <div class="tm-note"><?= htmlspecialchars($slot_key, ENT_QUOTES, 'UTF-8') ?></div>
              </td>
              <td>
                <div class="tm-assign-row">
                  <div class="choice-block" id="choice-theme-<?= htmlspecialchars($slot_key, ENT_QUOTES, 'UTF-8') ?>">
                    <label class="choice-label"><?=_e('Registered theme')?></label>

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
                    <div class="tm-note"><?=_e('Select "Site default" to revert to standard behavior (using active theme).')?></div>
                  </div>

                  <div class="choice-block" id="choice-post-<?= htmlspecialchars($slot_key, ENT_QUOTES, 'UTF-8') ?>">
                    <label class="choice-label"><?=_e('Or use custom template (post type=theme)')?></label>
                    <select class="post-select tm-select" data-slot="<?= htmlspecialchars($slot_key, ENT_QUOTES, 'UTF-8') ?>">
                      <option value=""><?=_e('-- none --')?></option>
                      <?php foreach ($theme_posts as $tp): ?>
                        <option value="<?= 'post:'.(int)$tp['id'] ?>"
                          <?= (!empty($current['custom_post_id']) && (int)$current['custom_post_id']===(int)$tp['id']) ? 'selected' : '' ?>>
                          <?= htmlspecialchars($tp['title'] . ' (' . $tp['slug'] . ')', ENT_QUOTES, 'UTF-8') ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                    <div class="tm-note">
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
      </div>

      <div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap;margin-top:12px">
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
          <button class="tm-install" type="submit"><?=_e('Apply changes')?></button>
          <a class="tm-ghost" href="<?= htmlspecialchars($selfUrl, ENT_QUOTES, 'UTF-8') ?>" style="text-decoration:none;display:inline-flex;align-items:center"><?=_e('Cancel')?></a>
        </div>
        <p class="tm-note" style="margin:0"><?=__('Extensions can register additional theme slots.')?></p>
      </div>
    </form>
  </div>

  <?php if ($unavailableAssignments !== []): ?>
    <div class="tm-card" style="margin-top:14px">
      <h3 class="tm-title" style="font-size:16px;margin:0"><?=_e('Unavailable slot assignments')?></h3>
      <p class="tm-note"><?=_e('These assignments were preserved because their extension is not currently available.')?></p>
      <div class="tm-table-wrap">
        <table class="tm-table">
          <thead><tr><th><?=_e('Slot')?></th><th><?=_e('Current assignment')?></th></tr></thead>
          <tbody>
          <?php foreach ($unavailableAssignments as $slotKey => $_assignment): ?>
            <tr>
              <td><code><?=h((string)$slotKey)?></code></td>
              <td><?=render_assignment_current($assign_rows, (string)$slotKey, $themes_by_id, $theme_posts_by_id)?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>
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

<div id="themeProgressOverlay" class="update-process-overlay" role="dialog" aria-modal="true" aria-labelledby="themeProgressTitle" style="display:none">
  <div class="update-process-panel" data-update-process-panel tabindex="-1">
    <h3 id="themeProgressTitle" class="update-process-title" data-update-process-title><?=_e('Theme update in progress')?></h3>
    <div class="update-process-spinner" data-update-process-spinner aria-hidden="true"></div>
    <div class="update-process-stage" data-update-process-stage><?=_e('Stage:')?> <?=_e('Starting...')?></div>
    <div class="update-process-status" id="themeProgressStatus" data-update-process-status aria-live="polite"><?=__('Starting update...')?></div>
    <p class="update-process-warning"><?=_e('Do not close or leave this page while the update is running.')?></p>
    <div class="update-process-track"><div class="update-process-bar" id="themeProgressFill" data-update-process-bar style="width:0%"></div></div>
    <div class="update-process-pct" id="themeProgressPct" data-update-process-pct>0%</div>
    <div class="update-process-actions" data-update-process-actions>
      <button type="button" class="btn btn-outline" data-update-process-cancel disabled><?=_e('Cancel update')?></button>
    </div>
  </div>
</div>

<script src="/static/dashboard/js/update.js?v=<?= (int)(@filemtime(PUBLIC_PATH . '/static/dashboard/js/update.js') ?: 0) ?>"></script>
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
          title: <?= json_encode(__('Activate theme')) ?>,
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

  // Theme update preflight and progress
  (function(){
    var overlay = document.getElementById('themeProgressOverlay');
    var statusEl = document.getElementById('themeProgressStatus');
    var fillEl = document.getElementById('themeProgressFill');
    var pctEl = document.getElementById('themeProgressPct');
    var updateInFlight = false;
    var updateProcess = window.createUpdateProcessUI({
      overlayId: 'themeProgressOverlay',
      processUrl: '<?= h($scriptBase) ?>/admin/update/process.php?token=',
      csrfToken: <?= json_encode(csrf_token()) ?>,
      labels: <?= json_encode([
        'runningTitle' => __('Theme update in progress'),
        'completeTitle' => __('Theme update complete'),
        'failedTitle' => __('Theme update failed'),
        'cancelledTitle' => __('Theme update cancelled'),
        'stage' => __('Stage:'),
        'starting' => __('Starting...'),
        'cancel' => __('Cancel update'),
        'cancelling' => __('Cancelling...'),
        'finishing' => __('Finishing process...'),
        'done' => __('Reload'),
        'timeout' => __('The update is taking longer than expected. Waiting for a confirmed result.'),
        'invalidResponse' => __('The update server returned an invalid response.'),
        'requestFailed' => __('The update request failed.'),
        'cancelFailed' => __('Unable to request cancellation. The update is still running.'),
      ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
      onDone: function(){ window.location.reload(); }
    });

    function setUpdateInFlight(active){
      updateInFlight = active;
      document.querySelectorAll('.btn-update-theme').forEach(function(button){ button.disabled = active; });
    }

    function makeProgressToken(){
      var bytes = new Uint8Array(16);
      window.crypto.getRandomValues(bytes);
      return Array.prototype.map.call(bytes, function(value){ return value.toString(16).padStart(2, '0'); }).join('');
    }

    function appendParams(url, params){
      var parsed = new URL(url, window.location.origin);
      if (parsed.origin !== window.location.origin) return null;
      Object.keys(params || {}).forEach(function(key){
        if (params[key] !== null) parsed.searchParams.set(key, String(params[key]));
      });
      return parsed.pathname + parsed.search + parsed.hash;
    }

    function showPreflightModal(folderName, issues){
      return new Promise(function(resolve){
        var overlayEl = document.createElement('div');
        overlayEl.className = 'theme-preflight-modal';
        overlayEl.setAttribute('role', 'dialog');
        overlayEl.setAttribute('aria-modal', 'true');
        var panel = document.createElement('div');
        panel.className = 'theme-preflight-panel';
        var title = document.createElement('h3');
        title.textContent = <?= json_encode(__('Update Theme')) ?> + ': ' + folderName;
        panel.appendChild(title);

        var selected = {};
        (issues || []).forEach(function(issue){
          var card = document.createElement('section');
          card.className = 'theme-preflight-issue' + (issue.blocking && !issue.resolved ? ' is-blocking' : '');
          var label = document.createElement('strong');
          label.textContent = issue.label || issue.id;
          card.appendChild(label);
          if (issue.message) {
            var message = document.createElement('p');
            message.textContent = issue.message;
            card.appendChild(message);
          }
          if (Array.isArray(issue.links) && issue.links.length) {
            var links = document.createElement('div');
            links.className = 'theme-preflight-links';
            issue.links.forEach(function(link){
              var safeUrl = appendParams(link.url, link.method === 'GET' ? link.params : {});
              if (!safeUrl) return;
              if (link.method === 'GET') {
                var anchor = document.createElement('a');
                anchor.className = 'tm-ghost';
                anchor.href = safeUrl;
                anchor.textContent = link.label;
                links.appendChild(anchor);
              } else if (link.method === 'POST') {
                var post = document.createElement('button');
                post.type = 'button';
                post.className = 'tm-ghost';
                post.textContent = link.label;
                post.addEventListener('click', function(){
                  var form = document.createElement('form');
                  form.method = 'post';
                  form.action = safeUrl;
                  var values = Object.assign({}, link.params || {}, {csrf_token: <?= json_encode(csrf_token()) ?>});
                  Object.keys(values).forEach(function(key){
                    if (values[key] === null) return;
                    var input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = key;
                    input.value = String(values[key]);
                    form.appendChild(input);
                  });
                  document.body.appendChild(form);
                  form.submit();
                });
                links.appendChild(post);
              }
            });
            card.appendChild(links);
          }
          if (Array.isArray(issue.choices)) {
            issue.choices.forEach(function(choice){
              var choiceLabel = document.createElement('label');
              choiceLabel.className = 'theme-preflight-choice' + (choice.destructive ? ' is-destructive' : '');
              var radio = document.createElement('input');
              radio.type = 'radio';
              radio.name = 'theme-preflight-' + issue.id;
              radio.value = choice.id;
              radio.addEventListener('change', function(){ selected[issue.id] = choice.id; });
              choiceLabel.appendChild(radio);
              choiceLabel.appendChild(document.createTextNode(choice.label));
              card.appendChild(choiceLabel);
            });
          }
          panel.appendChild(card);
        });

        var actions = document.createElement('div');
        actions.className = 'theme-preflight-actions';
        var cancel = document.createElement('button');
        cancel.type = 'button';
        cancel.className = 'tm-ghost';
        cancel.textContent = <?= json_encode(__('Cancel')) ?>;
        var proceed = document.createElement('button');
        proceed.type = 'button';
        proceed.className = 'tm-install';
        proceed.textContent = <?= json_encode(__('Continue')) ?>;
        cancel.addEventListener('click', function(){ overlayEl.remove(); resolve(null); });
        proceed.addEventListener('click', function(){
          var decisions = {};
          var missing = false;
          (issues || []).forEach(function(issue){
            if (issue.blocking && !issue.resolved && !selected[issue.id]) missing = true;
            if (selected[issue.id]) {
              decisions[issue.id] = {choice: selected[issue.id], state_token: issue.state_token || ''};
            }
          });
          if (missing) return;
          overlayEl.remove();
          resolve(decisions);
        });
        actions.appendChild(cancel);
        actions.appendChild(proceed);
        panel.appendChild(actions);
        overlayEl.appendChild(panel);
        document.body.appendChild(overlayEl);
        cancel.focus();
      });
    }

    function showCleanUpdateConfirmation(folderName){
      return new Promise(function(resolve){
        var overlayEl = document.createElement('div');
        overlayEl.className = 'theme-preflight-modal';
        overlayEl.setAttribute('role', 'dialog');
        overlayEl.setAttribute('aria-modal', 'true');
        var panel = document.createElement('div');
        panel.className = 'theme-preflight-panel';
        var title = document.createElement('h3');
        title.textContent = <?= json_encode(__('Update Theme')) ?>;
        var message = document.createElement('p');
        message.textContent = <?= json_encode(__('Update theme "')) ?> + folderName + <?= json_encode(__('" to the latest version?')) ?>;
        var actions = document.createElement('div');
        actions.className = 'theme-preflight-actions';
        var cancel = document.createElement('button');
        cancel.type = 'button';
        cancel.className = 'tm-ghost';
        cancel.textContent = <?= json_encode(__('Cancel')) ?>;
        var proceed = document.createElement('button');
        proceed.type = 'button';
        proceed.className = 'tm-install';
        proceed.textContent = <?= json_encode(__('Yes, update')) ?>;
        cancel.addEventListener('click', function(){ overlayEl.remove(); resolve(false); });
        proceed.addEventListener('click', function(){ overlayEl.remove(); resolve(true); });
        actions.appendChild(cancel);
        actions.appendChild(proceed);
        panel.appendChild(title);
        panel.appendChild(message);
        panel.appendChild(actions);
        overlayEl.appendChild(panel);
        document.body.appendChild(overlayEl);
        cancel.focus();
      });
    }

    function requestPreflight(folderName, decisions, callback){
      var xhr = new XMLHttpRequest();
      xhr.open('POST', '<?= h($scriptBase) ?>/admin/themes/update_preflight.php', true);
      xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
      xhr.onload = function(){
        var data = null;
        try { data = JSON.parse(xhr.responseText); } catch(e) {}
        if (!data || xhr.status !== 200 || !data.ok) {
          callback(null, (data && data.error) || <?= json_encode(__('Failed to start update.')) ?>);
          return;
        }
        callback(data, null);
      };
      xhr.onerror = function(){ callback(null, <?= json_encode(__('Failed to start update.')) ?>); };
      xhr.send('csrf_token=<?= h(csrf_token()) ?>&theme=' + encodeURIComponent(folderName) + '&decisions=' + encodeURIComponent(JSON.stringify(decisions || {})));
    }

    function startThemeUpdate(folderName, decisions){
      var token = makeProgressToken();
      updateProcess.start(token, folderName);

      var xhr = new XMLHttpRequest();
      xhr.open('POST', '<?= h($scriptBase) ?>/admin/themes/update_apply.php', true);
      xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
      xhr.onload = function(){
        var data = null;
        try { data = JSON.parse(xhr.responseText); } catch(e) {}
        if (data && data.code === 'theme_update_preflight_required' && Array.isArray(data.issues)) {
          updateProcess.fail(data.error || <?= json_encode(__('Theme update requirements must be resolved before continuing.')) ?>);
          updateProcess.dismissTerminal();
          showPreflightModal(folderName, data.issues).then(function(nextDecisions){
            if (nextDecisions !== null) startThemeUpdate(folderName, nextDecisions);
            else setUpdateInFlight(false);
          });
        } else if (data && !data.ok && !data.cancelled) {
          updateProcess.dispatchFailed(data.error || <?= json_encode(__('Failed to start update.')) ?>);
        } else if (!data || xhr.status !== 200) {
          updateProcess.dispatchFailed(<?= json_encode(__('The update server returned an invalid response.')) ?>);
        }
      };
      xhr.onerror = function(){
        updateProcess.dispatchFailed(<?= json_encode(__('The update request failed.')) ?>);
      };
      xhr.send('csrf_token=<?= h(csrf_token()) ?>&action=apply_theme_update&theme=' + encodeURIComponent(folderName) + '&token=' + token + '&decisions=' + encodeURIComponent(JSON.stringify(decisions || {})));
    }

    function beginThemeUpdate(folderName){
      if (updateInFlight) return;
      setUpdateInFlight(true);
      requestPreflight(folderName, {}, function(data, error){
        if (error) {
          setUpdateInFlight(false);
          toast('error', error, <?= json_encode(__('Error')) ?>);
          return;
        }
        if (data.allowed) {
          showCleanUpdateConfirmation(folderName).then(function(confirmed){
            if (confirmed) startThemeUpdate(folderName, {});
            else setUpdateInFlight(false);
          });
          return;
        }
        showPreflightModal(folderName, data.issues).then(function(decisions){
          if (decisions !== null) startThemeUpdate(folderName, decisions);
          else setUpdateInFlight(false);
        });
      });
    }

    document.querySelectorAll('.btn-update-theme').forEach(function(btn){
      btn.addEventListener('click', function(){
        var folder = btn.getAttribute('data-folder');
        if (!folder) return;
        beginThemeUpdate(folder);
      });
    });
  })();
})();
</script>
