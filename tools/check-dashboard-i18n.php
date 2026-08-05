<?php
/**
 * check-dashboard-i18n.php
 *
 * Static helper to catch likely untranslated user-facing strings in the admin
 * dashboard. It is intentionally conservative: it reports candidates that need
 * human review rather than failing silently. Run with:
 *
 *   php tools/check-dashboard-i18n.php
 *
 * The script only looks at HTML/JS emitted by dashboard PHP files. It does not
 * execute controllers or database queries.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$dashboardDir = $root . '/dashboard';

if (!is_dir($dashboardDir)) {
    fwrite(STDERR, "Dashboard directory not found: {$dashboardDir}\n");
    exit(1);
}

// Patterns that are almost certainly internal tokens, not user-facing text.
$globalIgnorePatterns = [
    '/^[\s\-—:|,.\/\\_#0-9]+$/u',                  // only whitespace / punctuation / numbers
    '/^[a-z0-9_\-]+\.[a-z0-9_\-]+$/i',              // file.ext or slug.slug
    '/^(v|V)[0-9]+/',                                // version numbers
    '/^(\d{1,2}:\d{2}(:\d{2})?|\d{4}-\d{2}-\d{2})$/', // dates/times
    '/^(#[0-9a-fA-F]{3,8}|rgba?\(|hsla?\()$/',      // colors
    '/^(https?|ftp|mailto|tel):/i',                // URLs
    '/^(admin|adiwira|pondasi|public|private|static|js|css|api|ajax)\b/i', // internal paths
    '/^[\w\-]+\/[\w\-]+(\/[\w\-]+)*$/',             // path-like
    '/^(svg|path|rect|circle|polyline|polygon|g|defs)$/i', // SVG tags
    '/^(width|height|viewBox|fill|stroke|d|M\s|L\s|C\s)/i', // SVG attributes
    '/^(php|html|js|css|json|xml|sql|pdf|doc|xls|ppt|zip|mp4|mp3|webp|png|jpg|jpeg|avif|mov|webm|ogg|wav|rtf|txt)$/i', // file extensions / formats
    '/^(on|off|true|false|yes|no|ok|ID|UUID|HTML|URL|PHP|JS|CSS|SQL|SEO|API|AJAX|HTTP|HTTPS)$/i', // internal constants
];

// Substrings that should be ignored as part of a larger candidate.
$ignoreSubstrings = [
    'var ', 'let ', 'const ', 'function ', '=>', 'document.', 'window.',
    'console.', 'return ', 'if(', 'if (', 'for(', 'for (', 'else ', 'catch',
    'new ', 'null', 'undefined', 'true', 'false', 'this.', 'self.',
    'addEventListener', 'getElementById', 'querySelector', 'fetch(', 'then(',
    'JSON.stringify', 'JSON.parse', 'FormData', 'XMLHttpRequest',
    '©', '...', 'md5', 'sha256', 'base64', 'csrf', 'btn', 'class=', 'style=',
    'data-', 'aria-', 'role=', 'tabindex=', 'type=', 'name=', 'value=',
    'method=', 'action=', 'enctype=', 'accept=', 'maxlength=', 'minlength=',
    'required', 'disabled', 'readonly', 'hidden', 'selected', 'checked',
    'svg', 'viewBox', 'path', 'fill', 'stroke', 'xmlns', 'd="', 'http://www.w3.org',
    'getenv', 'dirname', 'realpath', 'is_file', 'is_dir', 'file_exists',
    'htmlspecialchars', 'ENT_QUOTES', 'UTF-8', 'PDO', 'array', 'foreach', 'as ',
    '<?php', '?>', '<?=', '<? ', 'echo', 'print', 'sprintf', 'printf',
    'str_replace', 'implode', 'explode', 'trim', 'strtolower', 'strtoupper',
    'in_array', 'array_merge', 'count(', 'empty(', 'isset(', 'is_array',
    '===', '!==', '&&', '||', '=>', '->', '::', '\\', '/*', '*/', '//',
];

function isIgnored(string $s): bool {
    global $globalIgnorePatterns, $ignoreSubstrings;

    $trimmed = trim($s);
    if ($trimmed === '') return true;
    if (mb_strlen($trimmed) < 2) return true;

    foreach ($globalIgnorePatterns as $pattern) {
        if (preg_match($pattern, $trimmed)) return true;
    }

    $lower = mb_strtolower($trimmed);
    foreach ($ignoreSubstrings as $sub) {
        $lsub = mb_strtolower($sub);
        if (mb_strpos($lower, $lsub) !== false) return true;
    }

    // Looks like a CSS selector/class list
    if (preg_match('/^([a-z0-9_\-]+\s+)+[a-z0-9_\-]+$/i', $trimmed)) return true;

    // Purely numeric / punctuation / emoji only
    if (preg_match('/^[\d\s\p{P}\p{S}]+$/u', $trimmed)) return true;

    return false;
}

function isWrappedInContext(string $context, string $candidate): bool {
    // If the candidate appears inside __() or _e() or json_encode(__('...')) in the same line.
    if (preg_match('/__\s*\(\s*[\'"]' . preg_quote($candidate, '/') . '[\'"]/', $context)) return true;
    if (preg_match('/json_encode\s*\(\s*__\s*\(\s*[\'"]' . preg_quote($candidate, '/') . '[\'"]/', $context)) return true;
    return false;
}

function scanHtml(string $html, string $fileRel, int $baseLine): array {
    $findings = [];
    $lines = explode("\n", $html);

    foreach ($lines as $idx => $line) {
        $lineNo = $baseLine + $idx;

        // 1. HTML text nodes: > ... <
        if (preg_match_all('/>([^<]{2,})</u', $line, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[1] as $m) {
                $text = trim(html_entity_decode($m[0], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if (isIgnored($text)) continue;
                if (isWrappedInContext($line, $text)) continue;
                $findings[] = [
                    'file' => $fileRel,
                    'line' => $lineNo,
                    'type' => 'text',
                    'text' => $text,
                    'context' => trim($line),
                ];
            }
        }

        // 2. Attribute text nodes
        if (preg_match_all('/\s(?:title|aria-label|placeholder|alt|data-label)=([\'"])([^\1]*?)\1/u', $line, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[2] as $m) {
                $text = trim(html_entity_decode($m[0], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if (isIgnored($text)) continue;
                if (isWrappedInContext($line, $text)) continue;
                $findings[] = [
                    'file' => $fileRel,
                    'line' => $lineNo,
                    'type' => 'attr',
                    'text' => $text,
                    'context' => trim($line),
                ];
            }
        }
    }

    return $findings;
}

function walkDashboard(string $dir): array {
    $findings = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $abs = $file->getPathname();
            $rel = substr($abs, strlen(dirname($dir)) + 1);
            $tokens = token_get_all(file_get_contents($abs));
            $lineNo = 1;
            foreach ($tokens as $tok) {
                if (is_array($tok)) {
                    $tokenId = $tok[0];
                    $tokenValue = $tok[1];
                    $tokenLine = $tok[2];
                    if ($tokenId === T_INLINE_HTML) {
                        $findings = array_merge($findings, scanHtml($tokenValue, $rel, $tokenLine));
                    }
                    $lineNo = $tokenLine + substr_count($tokenValue, "\n");
                } else {
                    $lineNo += substr_count((string)$tok, "\n");
                }
            }
        }
    }

    return $findings;
}

$findings = walkDashboard($dashboardDir);

if (empty($findings)) {
    echo "No likely untranslated dashboard strings found.\n";
    exit(0);
}

$byFile = [];
foreach ($findings as $f) {
    $byFile[$f['file']][] = $f;
}

$total = count($findings);
echo "Likely untranslated dashboard strings: {$total}\n";
echo str_repeat('=', 70) . "\n";

foreach ($byFile as $file => $items) {
    echo "\n{$file}\n";
    foreach ($items as $f) {
        $truncated = mb_strlen($f['context']) > 90 ? mb_substr($f['context'], 0, 87) . '...' : $f['context'];
        echo "  L{$f['line']} [{$f['type']}] \"{$f['text']}\"\n";
        echo "      {$truncated}\n";
    }
}

echo "\n" . str_repeat('=', 70) . "\n";
echo "Tip: wrap the candidate text with <?=_e('...')?> (or <?=json_encode(__('...'))?> in JS).\n";
echo "Then add the English source key and Indonesian value to schema/translations.sql.\n";
exit(0);
