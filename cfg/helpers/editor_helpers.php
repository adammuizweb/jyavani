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
