# AGENTS-CUSTOMIZE.md — Pedoman BIG Project: Visual Customize

> Branch kerja: `feat/customize` pada repo `jyavani.git`.
> Dokumen ini adalah pedoman utama (source of truth) untuk refactor fitur Customize.
> Baca dokumen ini SEBELUM mengerjakan apapun di branch ini. Jangan lupakan isinya.

## 1. Visi

Jyavani CMS harus memiliki fitur **Customize visual ala Blogspot**: layout tema dipetakan
ke zone/position, dan user mengisi tiap position dengan gadget yang bisa di-drag, dikonfigurasi,
diaktif/nonaktifkan, dan dihapus. Menu **Customize** di dashboard
(`/adiwira/?page=admin/themes/customize`) **otomatis menarget tema yang sedang aktif**.

Target pemetaan layout:

```
                    | partials |
 ______________________________
|           Header             |
| Logo | Navigasi | Controller |
 ------------------------------
|   Main | Sidebar (jika aktif)|
 ------------------------------
|          Footer              |
|   Logo |  Pages  |  Contact  |
 ------------------------------
```

Selain Header/Main/Footer, Customize juga harus bisa mengatur halaman lain:
- **Post list** (arsip/index)
- **Single post** — contoh: tampilkan/sembunyikan profil author, tanggal, read time;
  urutan partial bisa di-drag & drop
- **Partial lain** yang dideklarasikan tema

## 2. Integrasi Wajib

Customize harus terintegrasi (baca + pilih dari) menu admin yang sudah ada:
a. `dashboard/admin/menus` — gadget navigasi memilih menu dari Menu Manager.
b. `dashboard/admin/sidebar` — position bisa menautkan sidebar zone dari Sidebar Settings.
c. `dashboard/admin/shortcodes` — gadget HTML/shortcode bisa memakai shortcode builder.

## 3. Prinsip Arsitektur (JANGAN DILANGGAR)

1. **Core tetap plugin-agnostic** — tidak ada hardcode plugin apapun di core.
2. **Tema tidak boleh hardcode bagian yang customizable.** Semua yang bisa diubah user
   lewat Customize disimpan di **database**, bukan di file tema.
3. **`theme.json` hanya mendeklarasikan kontrak** (zones, positions, gadget defaults,
   opsi toggle) — nilai aktual tetap di DB.
4. **Fallback aman** — jika position kosong / tema belum declare layout, render HTML
   bawaan tema seperti sekarang (tidak ada halaman rusak).
5. **Customize harus theme-aware** — selalu membaca manifest tema aktif; konfigurasi
   gadget harus **per-tema** (pindah tema tidak membawa gadget tema lain).
6. **Dua tema referensi**: `default` dan `adam` di-update sebagai contoh canonical
   supaya developer tema lain paham algoritmanya.
7. Schema di `/var/www/jyavani.lan/schema` harus menyesuaikan (migration baru, plus
   update `schema/default.sql`).

## 4. Keadaan Saat Ini (final v2.3.17 — Fase 3 100%)

Sudah ada:
- `cfg/helpers/theme_zones.php` — schema gadget, CRUD, render, `theme_zone_render_position()`,
  `theme_zone_render_title()`, `theme_zone_content_align()`, `theme_zone_universal_defaults()`.
- Tabel `theme_zone_items` dengan kolom `theme_folder` (per-theme scoping, migration `010`).
- 13 gadget bawaan: `tz_image`, `tz_nav_menu`, `tz_theme_toggle`, `tz_lang_switcher`, `tz_search`,
  `tz_html`, `tz_richtext`, `tz_pages`, `tz_social`, `tz_sidebar_zone`, `tz_post_author`,
  `tz_post_meta`, `tz_post_contact`. Semua support universal title/alignment settings.
- Admin `dashboard/admin/themes/customize.php` — kanvas full-page ala Blogspot (Header band →
  Main row + Sidebar → Footer band), select partials, drag & drop antar-position,
  gadget config form dengan alignment icon buttons + title tag selector.
- `theme_mods_{folder}` (JSON di settings) untuk Theme Customizer fields.
- Header/footer default & adam sudah zone-aware dengan fallback hardcode.
- Semua gadget renderer sudah menggunakan `theme_zone_render_title()` + `width:100%` agar
  `text-align` berfungsi dalam flex container dengan `align-items:center`.

## 5. Rencana Schema (draft — finalisasi saat implementasi)

- Migration baru: tambah `theme_folder VARCHAR(100)` ke `theme_zone_items` + index
  (`zone_slug`, `position`, `theme_folder`). Data lama di-backfill dengan tema aktif saat migrasi.
- Pertimbangkan tabel `theme_partials` / extend assignments untuk partial single/list
  jika model zone tidak cukup. Keputusan dicatat di dokumen ini saat implementasi.
- `schema/default.sql` ikut di-update agar install baru langsung punya schema final.

## 6. Kontrak `theme.json` (target)

```json
"layout": {
  "header":  { "columns": 3, "positions": { "logo": {}, "nav": {}, "controls": {} }, "defaults": { ... } },
  "main":    { "positions": { "content": {}, "sidebar": {} } },
  "footer":  { "positions": {
      "about": {"row": 1}, "pages": {"row": 1}, "social": {"row": 1},
      "copyright": {"row": 2}
    }, "defaults": { ... } },
  "single.post": { "positions": { "before_content": {}, "after_content": {} } },
  "list.post":   { "positions": { "before_loop": {}, "after_loop": {} } }
}
```

- `columns` (opsional, 1–4) — jumlah kolom visual position di editor; fallback
  `min(jumlah positions, 4)`. Murni untuk tampilan admin, tidak memaksa CSS tema.
- `row` (opsional, default 1) — pengelompokan baris position; admin menggambar tiap baris
  sebagai grid terpisah, template render per baris (contoh: footer About|Pages|Social + Copyright).
- `align` (opsional, `left`|`center`|`right`, default `center`) — alignment isi position di
  frontend; template footer memetakan ke `align-items` + `text-align` per cell.

Tema consume lewat `theme_zone_render_position('single.post', 'after_content')` dst.,
dengan fallback ke partial bawaan jika kosong. Untuk gadget yang butuh data post
(`tz_post_author`, `tz_post_meta`, dst.), template single HARUS set
`$GLOBALS['jy_current_post'] = $post;` sebelum memanggil render zone.

## 7. Ekosistem Plugin Masa Depan (desain harus siap)

Customize akan saling support dengan:
a. `adammuizweb/theme-builder` — builder tema visual.
b. `adammuizweb/jyavani-builder` — page builder.
c. `adammuizweb/form-builder` — form builder.

Konsekuensi desain:
- Registry gadget HARUS filter-based (`add_filter`) supaya plugin bisa menambah gadget.
- Render gadget HARUS filter-based supaya plugin bisa override renderer.
- Config gadget disimpan JSON — plugin bebas menambah key sendiri.
- API render zone harus bisa dipanggil dari luar admin (frontend + builder preview).

## 8. Roadmap (kerjakan bertahap, satu fase = satu commit/logis)

- **Fase 1:** ✅ Per-theme scoping gadget (`theme_folder` di `theme_zone_items`, migrasi
  `010-theme-zone-theme-folder.sql` + backfill, runtime `ensure_schema` ikut ALTER,
  admin menampilkan gadget inactive). Done 2026-07-21.
- **Fase 2:** ✅ Zone `single.post` + `list.post` + partials; default & adam jadi contoh.
  Done 2026-07-21:
  - `theme.json` (default + adam): zone `single.post` (positions `before_content`/`after_content`)
    dan `list.post` (`before_loop`/`after_loop`).
  - Gadget baru: `tz_post_author` (config `show_avatar`) dan `tz_post_meta`
    (`show_date`/`show_updated`/`show_read_time`).
  - **Kontrak konteks post:** template single WAJIB set `$GLOBALS['jy_current_post'] = $post;`
    sebelum render zone; gadget post-aware membaca global itu. Tanpa konteks, gadget render `''`.
  - Template single (default + adam) render `single.post/before_content` sebelum `.adam-post-body`
    dan `single.post/after_content` sesudahnya. Template list render `list.post/before_loop`
    sebelum loop dan `list.post/after_loop` setelah pagination.
  - Admin Customize: tab sekarang **dinamis dari `theme.json`** (bukan hardcode header/main/footer);
    POST zone divalidasi terhadap layout tema; Load Default Layout untuk zone tanpa `defaults`
    menolak dengan warning (tidak lagi fallback sembarangan).
  - Test gadget contoh ada di DB untuk tema `default` (single.post/after_content: Post Meta +
    Author Box; list.post/after_loop: demo HTML) — bisa dihapus lewat admin.
- **Fase 3:** ✅ Drag & drop antar-position (HTML5 sortable) + visual mapping ala Blogspot.
  Done 2026-07-21:
  - Drag handle `.tz-grip` (mousedown baru `draggable=true` — teks di input tetap bisa diseleksi).
  - Drop placeholder mengikuti posisi cursor (insert sebelum/sesudah item), indikator `.tz-drag-over`.
  - Pindah antar-position: hidden input `widget[id][position]` diupdate saat drop; save handler
    server memang sudah mengupdate `position` per item — tidak ada perubahan server.
  - Kedua form (sumber + target) diberi tanda dirty (`•` + ring biru pada tombol Save).
  - List kosong menampilkan kembali teks "Drop gadget here" secara dinamis.
  - Grid kolom visual mengikuti `columns` di `theme.json` (fallback `min(jumlah positions, 4)`);
    collapse ke 1 kolom di layar sempit.
  - Tombol ▲▼ tetap ada sebagai fallback aksesibilitas (juga menandai dirty).

- **Fase 3b:** ✅ Kanvas halaman utuh + select partials. Done 2026-07-21:
  - Tabs DIHAPUS — diganti satu kanvas: **Header band → Main row (partial | Sidebar) → Footer band**.
  - **Select partials** di atas tengah: opsi = semua zone selain header/footer (`main`,
    `single.post`, `list.post`, dst.). Mengganti isi kolom Main tanpa reload (panel pre-render,
    toggle via JS) + `history.replaceState(?partial=...)`; semua form membawa hidden
    `tz_partial` supaya redirect POST kembali ke partial yang sama (`&partial=`, `&tab=` legacy
    tetap diterima untuk kompatibilitas).
  - Position editor diekstrak ke `tz_zone_editor_html()` — dipakai header band, footer band,
    dan tiap partial panel.
  - **Panel Sidebar**: status aktif/nonaktif (`theme_mod('show_sidebar')`), daftar sidebar zones,
    link cepat ke `?page=admin/sidebar/index` (integrasi Sidebar Settings).
  - **Footer 3 kolom**: positions `left`/`middle`/`right` (label Logo/Pages/Contact, `columns: 3`)
    di theme.json default + adam; `footer.php` render grid 3 kolom bila ada isinya, fallback ke
    position `main` lama, lalu fallback hardcode — backward compatible.

- **Fase 3c:** ✅ Global Settings modal + partials lengkap dari filesystem. Done 2026-07-21:
  - Panel Theme Settings pindah ke **modal "Global Settings"**; topbar
    `|Global Settings| ⇄ |Partials select|` (space-between). Modal custom (overlay, tombol ×,
    Escape) — bukan native dialog.
  - **Select partials sekarang lengkap**: `theme_zone_discover_partials($folder)` memindai
    `main/**/*.php` tema (skip `_*.php`) → slug mengikuti slot mapping
    (`main.homepage`, `main.search`, `main.404`, `main.download-intro`, `list.*`, `single.*`, `index.*`).
  - Partial tanpa deklarasi di theme.json memakai konvensi positions dari
    `theme_zone_partial_positions()`: `single.*` → before_content/after_content;
    `list.*`/`index.*`/`main.search` → before_loop/after_loop; lainnya → before/after.
  - Hook render ditambahkan ke 11 partial (default + adam): single/page, list/page,
    list/category, list/archive, list/author, index/category, index/author, homepage,
    search, 404, download-intro. `single/page.php` juga set `$GLOBALS['jy_current_post']`.
  - Tombol Load Default Layout hanya tampil jika zone declare `defaults` (atau header/footer).
  - Urutan select: `main` → `main.homepage` → sisanya alfabetis.

- **Fase 3d:** ✅ Global Settings modal DIHAPUS + footer 2 baris. Done 2026-07-21:
  - Modal Global Settings dihapus total (button, markup, JS, CSS, handler `tc_save`) —
    redundan dengan kanvas. Kemampuan uniknya dimigrasi ke gadget:
    - `tz_social` — social icons (Twitter/GitHub/Instagram, SVG bawaan tema).
    - `tz_sidebar_zone` — render sidebar zone dari Sidebar Settings (config dropdown).
    - `tz_logo` — field config `logo` (URL gambar; fallback `theme_mod('logo')`).
  - **Footer 2 baris**: positions `about`/`pages`/`social` (`row: 1`) + `copyright` (`row: 2`)
    di theme.json. `footer.php` render per-baris (grid auto-fit), fallback `main` → hardcode.
  - Admin `tz_zone_editor_html()` mengelompokkan positions per `row` — zone multi-baris
    digambar sebagai beberapa grid bertumpuk di kanvas.
  - `theme_mods` tetap ada sebagai sumber fallback tema; hanya UI editor legacy yang dihapus.

- **Fase 3e:** ✅ Gadget proper: pages, social dinamis, richtext, CodeMirror. Done 2026-07-21:
  - `tz_pages` — daftar link page dari `posts` type=page published; config checkbox pilih page
    tertentu (kosong = semua) + `list_class`. Urutan mengikuti urutan pilihan user.
  - `tz_social` **dinamis** — `theme_zone_social_networks()` berisi 8 jaringan populer
    (facebook, x, instagram, youtube, github, tiktok, telegram, whatsapp) dengan SVG path dari
    **Simple Icons (CC0)** — aman secara lisensi. Config: checkbox enable + URL per network.
  - `tz_richtext` — editor **Quill** (sudah di-load global oleh admin layout core, jadi tidak
    perlu depend ke plugin builder). Init lazy saat body widget dibuka (`tzInitEditors`).
  - `tz_html` — textarea sekarang di-upgrade ke **CodeMirror** (`htmlmixed`, lineNumbers,
    autoCloseTags) via lazy init yang sama.
  - `tz_logo` — field `logo` URL (sudah ada sejak 3d).
  - Footer default: about = tz_logo (logo `/static/img/jyavani.svg`) + tz_html about text;
    pages = tz_pages; social = tz_social (x/github/instagram); copyright = teks kecil proper
    (`font-size:.85rem; opacity:.75`).

- **Fase 3f:** ✅ `tz_logo` DIHAPUS, diganti `tz_image` + brand HTML. Done 2026-07-21:
  - Gadget `tz_logo` dihapus total (registry, renderer, sanitizer, config form, fallback defaults).
    Logo bermerek (brand HTML animasi Jyavani/Adamz) sekarang cukup pakai `tz_html` —
    header DAN footer memakai brand HTML yang sama dari `theme.json` header defaults.
  - Gadget baru `tz_image` — config `src` (input + tombol **Pilih dari Media** yang memanggil
    `openMediaSelector()` dari CMS core: `modal-helpers.js` + `media-selector.js` di-include
    di halaman customize), `alt`, `link`, `max_width`. Live preview saat URL diketik.
  - `tz_pages` me-render judul dari `theme_zone_render_title()` (title tag + alignment).
  - Migrasi DB: gadget `tz_logo` header/footer → `tz_html` brand; gadget kosong dihapus.
  - **Universal gadget settings (3g):** ✅ Dijalankan bersamaan dengan 3f. Done 2026-07-21:
    - `theme_zone_render_title(string $titleText, array $config): string` — render title dengan
      tag (div/h1–h6) + inline `style="width:100%;text-align:left|center|right"`. Default left.
    - `theme_zone_content_align(array $config): string` — baca `_align_content` → css value.
    - `theme_zone_universal_defaults(): array` — return `['_title_tag'=>'div', '_align_title'=>'left',
      '_align_content'=>'left']`.
    - Semua 13 gadget registrasi pakai `array_merge($uni, ...)`.
    - Renderer `tz_html`, `tz_richtext`, `tz_social`, `tz_pages` panggil helpers.
    - Admin config form: dropdown Title Tag, icon buttons Title Align + Content Align
      (Lucide SVG icons). Backward compat: gadget tanpa universal keys render tanpa inline style.
    - Seed data `default.sql`: semua 11 rows include key universal eksplisit.
    - `customize.php` define `.btn-primary`, `.btn-secondary`, `.tz-align-btn:hover` —
      tombol konsisten dengan halaman admin lain.
- **Fase 4:** Integrasi shortcode builder ke gadget HTML/richtext (UI picker di CodeMirror/Quill);
  panel pilih menu/sidebar zone yang lebih baik (link cepat ke Menu Manager / Sidebar Settings).
- **Fase 5:** Dokumentasi authoring tema (update AGENTS.md utama + cms.md) + test Playwright.
- **Fase 6:** Hook/kontrak untuk theme-builder, jyavani-builder, form-builder.

## 9. Aturan Kerja di Branch Ini

- Jangan merge ke `main` sebelum user setuju.
- Commit kecil, pesan jelas (`feat(customize): ...`).
- Setiap mengubah file core: regenerate `tools/cms-manifest.php` (generate-manifest) sebelum commit.
- Sync ke jyavani.com / apu.lan / adammuiz.com HANYA setelah di-merge ke main.
- Jaga kompatibilitas: tema lama tanpa `layout` tetap jalan (fallback hardcode).
- Tidak ada native `alert/confirm`; toast color semantics; SVG icons only.
