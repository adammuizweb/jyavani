<?php
declare(strict_types=1);

// /adiwira/admin/posts/save.php
ob_start();

require_once __DIR__ . '/../_guard.php';
require_once __DIR__ . '/../_notify.php';

adiwira_cosmetic_404_on_direct_open();

[$uid, $role] = adiwira_require_role($pdo, ['author', 'editor', 'admin'], true);

if (!function_exists('adiwira_request_wants_json')) {
    function adiwira_request_wants_json(): bool {
        $xrw = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
        $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
        return ($xrw === 'xmlhttprequest') || (strpos($accept, 'application/json') !== false);
    }
}

if (!function_exists('save_success_response')) {
    function save_success_response(string $message, string $redirect, array $extra = []): void {
        if (adiwira_request_wants_json()) {
            adiwira_json(array_merge([
                'ok' => true,
                'message' => $message,
            ], $extra), 200);
        }

        adiwira_flash_push('success', $message);
        header('Location: ' . $redirect, true, 302);
        exit;
    }
}

if (!function_exists('save_error_response')) {
    function save_error_response(array $errors, string $redirect, int $httpCode = 400): void {
        $errors = array_values(array_filter(array_map('strval', $errors)));
        if (!$errors) {
            $errors = ['Gagal menyimpan perubahan.'];
        }

        if (adiwira_request_wants_json()) {
            adiwira_json([
                'ok' => false,
                'errors' => $errors,
            ], $httpCode);
        }

        adiwira_redirect_with_flash($redirect, 'error', $errors[0]);
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    adiwira_json(['ok' => false, 'error' => __('Not found')], 404);
}

$csrf = (string)($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
if (!adiwira_csrf_validate($csrf)) {
    save_error_response(['CSRF invalid'], ADMIN_BASE_PATH . '/?page=admin/posts/index', 419);
}

if (!function_exists('sanitize_author_html')) {
    function sanitize_author_html(string $html): string {
        $html = trim($html);
        if ($html === '') return '';

        $allowedTags = array_flip([
            'p','br','hr',
            'strong','b','em','i','u','s',
            'blockquote','pre','code',
            'h1','h2','h3','h4','h5','h6',
            'ul','ol','li',
            'a','img','figure','figcaption',
            'table','thead','tbody','tfoot','tr','th','td',
            'span','div'
        ]);

        $allowedAttrs = [
            'a'   => ['href','title','target','rel'],
            'img' => ['src','alt','title','width','height'],
            'div' => ['class'],
            'span'=> ['class'],
            'p'   => ['class'],
            'th'  => ['colspan','rowspan'],
            'td'  => ['colspan','rowspan'],
            '*'   => ['class'],
        ];

        $blockTags = ['script','style','iframe','object','embed','link','meta','form','svg','canvas'];

        $prev = libxml_use_internal_errors(true);
        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

        $xpath = new DOMXPath($doc);
        foreach ($xpath->query('//comment()') as $c) {
            $c->parentNode?->removeChild($c);
        }

        $walker = function(DOMNode $node) use (&$walker, $allowedTags, $allowedAttrs, $blockTags) {
            if ($node->nodeType === XML_ELEMENT_NODE) {
                $tag = strtolower($node->nodeName);

                if (in_array($tag, $blockTags, true)) {
                    $node->parentNode?->removeChild($node);
                    return;
                }

                if (!isset($allowedTags[$tag])) {
                    $parent = $node->parentNode;
                    if ($parent) {
                        while ($node->firstChild) {
                            $parent->insertBefore($node->firstChild, $node);
                        }
                        $parent->removeChild($node);
                    }
                    return;
                }

                if ($node->hasAttributes()) {
                    $toRemove = [];
                    foreach (iterator_to_array($node->attributes) as $attr) {
                        $name = strtolower($attr->name);
                        $val  = (string)$attr->value;

                        if (str_starts_with($name, 'on') || $name === 'style') {
                            $toRemove[] = $attr->name;
                            continue;
                        }

                        $allowedForTag = $allowedAttrs[$tag] ?? [];
                        $allowedForAll = $allowedAttrs['*'] ?? [];
                        if (!in_array($name, $allowedForTag, true) && !in_array($name, $allowedForAll, true)) {
                            $toRemove[] = $attr->name;
                            continue;
                        }

                        if (($tag === 'a' && $name === 'href') || ($tag === 'img' && $name === 'src')) {
                            $v = trim($val);
                            $ok = false;

                            if ($v === '' || $v[0] === '#' || $v[0] === '/') $ok = true;
                            if (!$ok && preg_match('#^https?://#i', $v)) $ok = true;
                            if (!$ok && $tag === 'a' && preg_match('#^mailto:#i', $v)) $ok = true;
                            if (!$ok && $tag === 'a' && preg_match('#^tel:#i', $v)) $ok = true;

                            if (preg_match('#^(javascript:|data:|vbscript:)#i', $v)) $ok = false;
                            if (!$ok) $toRemove[] = $attr->name;
                        }
                    }

                    foreach ($toRemove as $r) {
                        $node->removeAttribute($r);
                    }

                    if ($tag === 'a') {
                        $t = strtolower((string)$node->getAttribute('target'));
                        if ($t === '_blank') {
                            $rel = trim((string)$node->getAttribute('rel'));
                            foreach (['noopener','noreferrer'] as $n) {
                                if (!preg_match('/\b'.preg_quote($n,'/').'\b/i', $rel)) {
                                    $rel = trim($rel . ' ' . $n);
                                }
                            }
                            $node->setAttribute('rel', $rel);
                        }
                    }
                }
            }

            $children = [];
            foreach ($node->childNodes as $ch) $children[] = $ch;
            foreach ($children as $ch) $walker($ch);
        };

        $walker($doc);

        $out = $doc->saveHTML() ?: '';
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        return trim($out);
    }
}

if (!function_exists('parse_dt_jkt')) {
    function parse_dt_jkt(string $s): ?string {
        $s = trim($s);
        if ($s === '') return null;

        $d = DateTime::createFromFormat('Y-m-d\\TH:i', $s, new DateTimeZone('Asia/Jakarta'));
        if ($d !== false) return $d->format('Y-m-d H:i:s');

        try {
            $d2 = new DateTime($s, new DateTimeZone('Asia/Jakarta'));
            return $d2->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            return null;
        }
    }
}

$errors = [];
$now = (new DateTime('now', new DateTimeZone('Asia/Jakarta')))->format('Y-m-d H:i:s');

$id            = (int)($_POST['id'] ?? 0);
$title         = trim((string)($_POST['title'] ?? ''));
$slug_in       = trim((string)($_POST['slug'] ?? ''));
$content       = (string)($_POST['content'] ?? '');
$statusIn      = (string)($_POST['status'] ?? 'draft');
$status        = in_array($statusIn, ['draft','published','private'], true) ? $statusIn : 'draft';
$youtube       = trim((string)($_POST['youtube'] ?? '')) ?: null;
$thumbnail     = trim((string)($_POST['thumbnail'] ?? '')) ?: null;
$created_at_in = trim((string)($_POST['created_at'] ?? ''));
$updated_at_in = trim((string)($_POST['updated_at'] ?? ''));
$created_by_in = (int)($_POST['created_by'] ?? 0);
$categories    = (array)($_POST['categories'] ?? []);
$return_to     = function_exists('adiwira_safe_return_to')
    ? adiwira_safe_return_to((string)($_POST['return_to'] ?? ''), '<?= ADMIN_BASE_PATH ?>/?page=admin/posts/index')
    : '<?= ADMIN_BASE_PATH ?>/?page=admin/posts/index';
$edit_return   = ADMIN_BASE_PATH . '/?' . http_build_query([
    'page' => 'admin/posts/edit',
    'id' => $id,
    'return_to' => $return_to,
]);

if ($id <= 0) $errors[] = __('Invalid ID.');

$title = trim((string)preg_replace('/[\x00-\x1F\x7F]/u', '', strip_tags($title)));
if ($title === '') $errors[] = __('Title is required.');

if ($role === 'author') {
    $content = sanitize_author_html($content);

    if (function_exists('normalize_links_in_html')) {
        $content = normalize_links_in_html($content);
    }
}

if (trim(strip_tags($content)) === '') $errors[] = __('Content is required.');

if ($youtube !== null && mb_strlen($youtube) > 512) $errors[] = __('YouTube link is too long.');
if ($youtube !== null) {
    if (!preg_match('/^https?:\/\//i', $youtube)) $errors[] = __('YouTube link must start with http or https.');
    if (!preg_match('/^(https?:\/\/)?(www\.)?(youtube\.com|youtu\.be)\//i', $youtube)) $errors[] = __('Invalid YouTube link.');
}

$slugSeed = ($slug_in !== '') ? $slug_in : ($title !== '' ? $title : 'untitled');
$slug = preg_replace('/[^\p{L}\p{N}\-]+/u', '-', mb_strtolower($slugSeed, 'UTF-8'));
$slug = preg_replace('/[-]{2,}/', '-', (string)$slug);
$slug = trim((string)$slug, '-');
if ($slug === '') $slug = bin2hex(random_bytes(4));

$st = $pdo->prepare("\n    SELECT id, slug, created_by, created_at, meta\n    FROM posts\n    WHERE id = :id\n      AND type = 'article'\n      AND is_deleted = 0\n    LIMIT 1\n");
$st->execute([':id' => $id]);
$existing = $st->fetch(PDO::FETCH_ASSOC);

if (!$existing) $errors[] = __('Post not found.');

if (empty($errors) && $role !== 'admin') {
    if ((int)($existing['created_by'] ?? 0) !== $uid) {
        $errors[] = __('Access denied: you can only save your own posts.');
    }
}

if (empty($errors)) {
    $q = $pdo->prepare("\n        SELECT id\n        FROM posts\n        WHERE slug = :slug\n          AND id != :id\n          AND type = 'article'\n          AND is_deleted = 0\n        LIMIT 1\n    ");
    $q->execute([
        ':slug' => $slug,
        ':id'   => $id,
    ]);
    if ($q->fetch()) $errors[] = __('Slug already used by another post.');
}

if (!empty($errors)) {
    save_error_response($errors, $edit_return, 400);
}

$pc = null;
$pu = null;

if ($created_at_in !== '') {
    $pc = parse_dt_jkt($created_at_in);
    if ($pc === null) {
        save_error_response(['Format Created At tidak valid.'], $edit_return, 400);
    }
}

if ($updated_at_in !== '') {
    $pu = parse_dt_jkt($updated_at_in);
    if ($pu === null) {
        save_error_response(['Format Updated At tidak valid.'], $edit_return, 400);
    }
}

$final_created = $pc ?: (string)($existing['created_at'] ?? $now);
$final_updated = $pu ?: $now;

$final_creator = (int)($existing['created_by'] ?? $uid);
if ($role === 'admin' && $created_by_in > 0) {
    $chk = $pdo->prepare("\n        SELECT id\n        FROM users\n        WHERE id = :id\n          AND is_deleted = 0\n          AND is_locked = 0\n        LIMIT 1\n    ");
    $chk->execute([':id' => $created_by_in]);
    if ($chk->fetchColumn()) {
        $final_creator = $created_by_in;
    }
}

$sidebarOverride = (string)($_POST['sidebar_override'] ?? '');
if ($sidebarOverride !== '' && !in_array($sidebarOverride, ['right', 'left', 'hide'], true)) {
    $sidebarOverride = '';
}
$metaDescription = trim((string)($_POST['meta_description'] ?? ''));
$currentMeta = !empty($existing['meta']) ? json_decode($existing['meta'], true) : [];
if (!is_array($currentMeta)) $currentMeta = [];
if ($sidebarOverride !== '') {
    $currentMeta['sidebar'] = $sidebarOverride;
} else {
    unset($currentMeta['sidebar']);
}
if ($metaDescription !== '') {
    $currentMeta['meta_tags']['description'] = $metaDescription;
} else {
    unset($currentMeta['meta_tags']['description']);
    if (empty($currentMeta['meta_tags'])) {
        unset($currentMeta['meta_tags']);
    }
}
$finalMeta = !empty($currentMeta) ? json_encode($currentMeta, JSON_UNESCAPED_UNICODE) : null;

try {
    shortcode_collection_layout_content_mutation($pdo, static function () use ($pdo, $title, $slug, $content, $youtube, $thumbnail, $status, $finalMeta, $final_creator, $final_created, $final_updated, $id, $categories, $uid): void {
    $pdo->beginTransaction();
    try {

    $upd = $pdo->prepare("\n        UPDATE posts\n        SET title      = :title,\n            slug       = :slug,\n            content    = :content,\n            youtube    = :youtube,\n            thumbnail  = :thumbnail,\n            status     = :status,\n            meta       = :meta,\n            created_by = :created_by,\n            created_at = :created_at,\n            updated_at = :updated_at\n        WHERE id = :id\n          AND type = 'article'\n          AND is_deleted = 0\n        LIMIT 1\n    ");

    $ok = $upd->execute([
        ':title'      => $title,
        ':slug'       => $slug,
        ':content'    => $content,
        ':youtube'    => $youtube,
        ':thumbnail'  => $thumbnail,
        ':status'     => $status,
        ':meta'       => $finalMeta,
        ':created_by' => $final_creator,
        ':created_at' => $final_created,
        ':updated_at' => $final_updated,
        ':id'         => $id,
    ]);

    if (!$ok) throw new RuntimeException('DB update failed.');

    $cats = array_values(array_filter(array_map('intval', $categories), fn($v) => $v > 0));
    if (!empty($cats)) {
        $ph = implode(',', array_fill(0, count($cats), '?'));
        $v = $pdo->prepare("SELECT id FROM categories WHERE id IN ($ph) AND is_deleted = 0");
        $v->execute($cats);
        $found = $v->fetchAll(PDO::FETCH_COLUMN, 0);
        $cats = array_values(array_intersect($cats, array_map('intval', $found)));
    }

    $pdo->prepare("DELETE FROM post_categories WHERE post_id = :pid")->execute([':pid' => $id]);

    if (!empty($cats)) {
        $insC = $pdo->prepare("\n            INSERT INTO post_categories (post_id, category_id, assigned_by)\n            VALUES (:pid, :cid, :by)\n        ");
        foreach ($cats as $cid) {
            $insC->execute([
                ':pid' => $id,
                ':cid' => (int)$cid,
                ':by'  => $uid,
            ]);
        }
    }

    $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
    });

    do_action('admin_post_after_edit', $id, $pdo, $_POST);

    save_success_response(__('Article updated successfully.'), $return_to, [
        'post' => [
            'id'         => $id,
            'slug'       => $slug,
            'status'     => $status,
            'created_by' => $final_creator,
            'updated_at' => $final_updated,
            'youtube'    => $youtube,
        ],
        'updated_at' => $final_updated,
    ]);

} catch (Throwable $e) {
    error_log('posts/save.php error: ' . $e->getMessage());
    save_error_response(['Gagal menyimpan posting.'], $edit_return, 500);
}
