<?php
declare(strict_types=1);

// /adiwira/admin/pages/save.php
ob_start();

require_once __DIR__ . '/../_guard.php';

// masking 404 kalau endpoint dibuka langsung di browser
adiwira_cosmetic_404_on_direct_open();

// login + role dari guard
[$uid, $role] = adiwira_require_role($pdo, ['author', 'editor', 'admin'], true);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    adiwira_json(['ok' => false, 'error' => 'Not found'], 404);
}

// CSRF
$csrf = (string)($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
if (!adiwira_csrf_validate($csrf)) {
    adiwira_json(['ok' => false, 'errors' => ['CSRF invalid']], 419);
}

// Sanitizer khusus author saja
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
                            foreach (['noopener', 'noreferrer'] as $n) {
                                if (!preg_match('/\b'.preg_quote($n, '/').'\b/i', $rel)) {
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

        $d = DateTime::createFromFormat('Y-m-d\TH:i', $s, new DateTimeZone('Asia/Jakarta'));
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
$status        = in_array($statusIn, ['draft', 'published', 'private'], true) ? $statusIn : 'draft';
$thumbnail     = trim((string)($_POST['thumbnail'] ?? '')) ?: null;
$created_at_in = trim((string)($_POST['created_at'] ?? ''));
$updated_at_in = trim((string)($_POST['updated_at'] ?? ''));
$created_by_in = (int)($_POST['created_by'] ?? 0);

if ($id <= 0) {
    $errors[] = 'ID tidak valid.';
}

$title = trim((string)preg_replace('/[\x00-\x1F\x7F]/u', '', strip_tags($title)));
if ($title === '') {
    $errors[] = 'Judul tidak boleh kosong.';
}

// hanya author yang disanitasi
if ($role === 'author') {
    $content = sanitize_author_html($content);
}

if (function_exists('normalize_links_in_html')) {
    $content = normalize_links_in_html($content);
}

// editor/admin raw
if (trim(strip_tags($content)) === '') {
    $errors[] = 'Konten tidak boleh kosong.';
}

// slug
$slugSeed = ($slug_in !== '') ? $slug_in : ($title !== '' ? $title : 'untitled');
$slug = preg_replace('/[^\p{L}\p{N}\-]+/u', '-', mb_strtolower($slugSeed, 'UTF-8'));
$slug = preg_replace('/[-]{2,}/', '-', (string)$slug);
$slug = trim((string)$slug, '-');
if ($slug === '') {
    $slug = bin2hex(random_bytes(4));
}

// existing page
$st = $pdo->prepare("
    SELECT id, slug, created_by, created_at
    FROM posts
    WHERE id = :id
      AND type = 'page'
      AND is_deleted = 0
    LIMIT 1
");
$st->execute([':id' => $id]);
$existing = $st->fetch(PDO::FETCH_ASSOC);

if (!$existing) {
    $errors[] = 'Halaman tidak ditemukan.';
}

// admin bebas, author/editor hanya miliknya sendiri
if (empty($errors) && $role !== 'admin') {
    if ((int)($existing['created_by'] ?? 0) !== $uid) {
        $errors[] = 'Akses ditolak: kamu hanya boleh menyimpan halaman milikmu sendiri.';
    }
}

// unique slug
if (empty($errors)) {
    $q = $pdo->prepare("
        SELECT id
        FROM posts
        WHERE slug = :slug
          AND id != :id
          AND type = 'page'
          AND is_deleted = 0
        LIMIT 1
    ");
    $q->execute([
        ':slug' => $slug,
        ':id'   => $id,
    ]);

    if ($q->fetch()) {
        $errors[] = 'Slug sudah dipakai oleh halaman lain.';
    }
}

if (!empty($errors)) {
    adiwira_json(['ok' => false, 'errors' => array_values($errors)], 400);
}

// default admin fields
$final_created = (string)($existing['created_at'] ?? $now);
$final_updated = $now;

// hanya admin boleh override timestamp
if ($role === 'admin') {
    $pc = parse_dt_jkt($created_at_in);
    $pu = parse_dt_jkt($updated_at_in);

    if ($created_at_in !== '' && $pc === null) {
        adiwira_json(['ok' => false, 'errors' => ['Format Created At tidak valid.']], 400);
    }
    if ($updated_at_in !== '' && $pu === null) {
        adiwira_json(['ok' => false, 'errors' => ['Format Updated At tidak valid.']], 400);
    }

    if ($pc) $final_created = $pc;
    if ($pu) $final_updated = $pu;
}

// hanya admin boleh ganti creator
$final_creator = (int)($existing['created_by'] ?? $uid);
if ($role === 'admin' && $created_by_in > 0) {
    $chk = $pdo->prepare("
        SELECT id
        FROM users
        WHERE id = :id
          AND is_deleted = 0
          AND is_locked = 0
        LIMIT 1
    ");
    $chk->execute([':id' => $created_by_in]);
    if ($chk->fetchColumn()) {
        $final_creator = $created_by_in;
    }
}

try {
    $upd = $pdo->prepare("
        UPDATE posts
        SET title      = :title,
            slug       = :slug,
            content    = :content,
            thumbnail  = :thumbnail,
            status     = :status,
            created_by = :created_by,
            created_at = :created_at,
            updated_at = :updated_at
        WHERE id = :id
          AND type = 'page'
          AND is_deleted = 0
        LIMIT 1
    ");

    $ok = $upd->execute([
        ':title'      => $title,
        ':slug'       => $slug,
        ':content'    => $content,
        ':thumbnail'  => $thumbnail,
        ':status'     => $status,
        ':created_by' => $final_creator,
        ':created_at' => $final_created,
        ':updated_at' => $final_updated,
        ':id'         => $id,
    ]);

    if (!$ok) {
        throw new RuntimeException('DB update failed.');
    }

    adiwira_json([
        'ok' => true,
        'message' => 'Halaman diperbarui.',
        'page' => [
            'id'         => $id,
            'slug'       => $slug,
            'status'     => $status,
            'created_by' => $final_creator,
            'updated_at' => $final_updated,
        ],
        'updated_at' => $final_updated,
    ], 200);

} catch (Throwable $e) {
    error_log('pages/save.php error: ' . $e->getMessage());
    adiwira_json(['ok' => false, 'errors' => ['Gagal menyimpan halaman']], 500);
}