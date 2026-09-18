<?php
// /home/u528279701/jyavani-cfg/helpers/editor_helpers.php

/**
 * Normalisasi link di HTML:
 * - Biarkan fragment-only (href="#...") apa adanya
 * - Biarkan mailto:, tel:, data:, javascript:
 * - Jika ada scheme tetapi tidak ada host dan ada fragment (contoh: https://#frag) -> ubah jadi "#frag"
 * - Jika seperti domain tanpa scheme (contoh: "google.com" atau "example.com/path") -> prepend "https://"
 * - Biarkan path relatif (/foo, ./bar, ?q=...) apa adanya
 */
if (!function_exists('normalize_links_in_html')) {
    function normalize_links_in_html(string $html): string {
        if (trim($html) === '') return $html;

        // supress warnings
        libxml_use_internal_errors(true);

        $doc = new DOMDocument();

        // Prefix encoding supaya karakter UTF-8 terjaga
        $loaded = $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        if (!$loaded) {
            // fallback: kembalikan original jika parsing gagal
            return $html;
        }

        foreach ($doc->getElementsByTagName('a') as $a) {
            $href = $a->getAttribute('href');
            if ($href === null) continue;
            $href = trim($href);
            if ($href === '') {
                // kosongkan href jika kosong
                $a->removeAttribute('href');
                continue;
            }

            // fragment-only: #foo -> biarkan
            if (strpos($href, '#') === 0) {
                continue;
            }

            // skema yang harus dibiarkan
            if (preg_match('#^(mailto:|tel:|javascript:|data:)#i', $href)) {
                continue;
            }

            // protocol-relative //example.com -> biarkan
            if (strpos($href, '//') === 0) {
                continue;
            }

            // jika sudah ada scheme (http://, https://, ftp://, dsb)
            if (preg_match('#^[a-z][a-z0-9+\-.]*://#i', $href)) {
                // jika ada scheme tapi tidak ada host dan ada fragment => ubah jadi fragment-only
                $host = parse_url($href, PHP_URL_HOST);
                $fragment = parse_url($href, PHP_URL_FRAGMENT);
                if ((empty($host) || $host === false) && !empty($fragment)) {
                    $a->setAttribute('href', '#' . $fragment);
                }
                // selain kasus di atas: biarkan (mis. https://example.com, https://example.com/page#frag)
                continue;
            }

            // relative paths atau query -> biarkan
            if (preg_match('#^(/|\.?/|\.\./|\?)#', $href)) {
                continue;
            }

            // jika tampak seperti domain tanpa scheme (mengandung titik, bukan email)
            // contoh: example.com or example.com/path
            if (preg_match('/^[^@\s]+?\.[^\/\s]+(?:\/.*)?$/', $href)) {
                // tambahkan https://
                $new = 'https://' . ltrim($href, '/');
                $a->setAttribute('href', $new);
                continue;
            }

            // default: biarkan apa adanya
        }

        $out = $doc->saveHTML();

        // bersihkan xml prolog yang kita tambahkan
        $out = preg_replace('/^<\\?xml.*?\\?>/i', '', $out);
        // optional: hapus doctype jika ada
        $out = preg_replace('/^<!DOCTYPE.+?>/i', '', $out);

        return $out;
    }
}

if (!function_exists('content_editor_render_mount')) {
    /** Render a scoped editor shell; authorization and persistence remain caller-owned. */
    function content_editor_render_mount(array $options): string {
        $id = is_string($options['id'] ?? null) ? trim($options['id']) : '';
        $name = is_string($options['name'] ?? null) ? trim($options['name']) : 'content';
        $mode = is_string($options['initial_mode'] ?? null) ? $options['initial_mode'] : 'quill';
        $direction = is_string($options['direction'] ?? null) ? $options['direction'] : 'ltr';
        if (preg_match('/\A[a-zA-Z][a-zA-Z0-9_-]{0,63}\z/', $id) !== 1) {
            throw new InvalidArgumentException('Editor mount ID is invalid.');
        }
        $modeName = is_string($options['mode_name'] ?? null) ? trim($options['mode_name']) : $id . '_editor_mode';
        foreach ([$name, $modeName] as $fieldName) {
            if (preg_match('/\A[a-zA-Z_][a-zA-Z0-9_.-]{0,127}(?:\[\])?\z/', $fieldName) !== 1) {
                throw new InvalidArgumentException('Editor field name is invalid.');
            }
        }
        if (!in_array($mode, ['quill', 'codemirror'], true)) {
            throw new InvalidArgumentException('Editor initial mode is invalid.');
        }
        if (!in_array($direction, ['ltr', 'rtl'], true)) {
            throw new InvalidArgumentException('Editor direction is invalid.');
        }
        $value = is_string($options['value'] ?? null) ? $options['value'] : '';
        $label = is_string($options['label'] ?? null) ? trim($options['label']) : '';
        $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $translate = static fn(string $value): string => function_exists('__') ? (string)__($value) : $value;
        $quillHidden = $mode === 'quill' ? '' : ' hidden';
        $codeHidden = $mode === 'codemirror' ? '' : ' hidden';

        ob_start();
        ?>
        <div id="<?= $escape($id) ?>" class="jy-editor-mount" data-jyavani-editor-mount>
          <?php if ($label !== ''): ?><div class="field-label" data-editor-label><?= $escape($label) ?></div><?php endif; ?>
          <fieldset class="jy-editor-modes" data-editor-modes>
            <legend class="sr-only"><?= $escape($translate('Select Editor')) ?></legend>
            <label><input type="radio" name="<?= $escape($modeName) ?>" value="quill" data-editor-mode="quill"<?= $mode === 'quill' ? ' checked' : '' ?>> <?= $escape($translate('Quill (rich)')) ?></label>
            <label><input type="radio" name="<?= $escape($modeName) ?>" value="codemirror" data-editor-mode="codemirror"<?= $mode === 'codemirror' ? ' checked' : '' ?>> <?= $escape($translate('CodeMirror (HTML)')) ?></label>
          </fieldset>
          <p class="field-note jy-editor-complex-hint" data-editor-complex-hint hidden><?= $escape($translate('Complex HTML detected. CodeMirror preserves the source markup.')) ?></p>
          <div class="jy-editor-actions" data-editor-actions hidden></div>
          <textarea name="<?= $escape($name) ?>" data-editor-canonical hidden><?= $escape($value) ?></textarea>
          <div class="adam-quill adam-quill--auto" data-editor-area="quill"<?= $quillHidden ?>>
            <div data-editor-toolbar></div>
            <div data-editor-quill dir="<?= $direction ?>"></div>
          </div>
          <div class="jy-editor-code-wrap" data-editor-area="codemirror"<?= $codeHidden ?>>
            <textarea data-editor-codemirror dir="ltr"><?= $escape($value) ?></textarea>
          </div>
        </div>
        <?php
        return (string)ob_get_clean();
    }
}
