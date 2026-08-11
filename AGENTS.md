# AGENTS.md — Jyavani CMS v2.1.3

Native PHP CMS by Adam Muiz. Dashboard theme is named "Adiwira". No framework, no Composer, no build tools. Playwright regression tests available.

## v2.0 — Hidden Admin (Admin PHP Outside `public/`)

The admin PHP files live at `dashboard/` (project root, alongside `app/`, `cfg/`, `public/`, `schema/`), completely outside the web root. No `/dashboard/` or `/adiwira/` directory exists in `public/`. Controllers, layout, and bootstrap files are in `app/` — also outside web root. Theme files (PHP + CSS/JS) are in `public/views/` so static assets are web-servable.

**How it works:**
- Admin PHP files at `dashboard/` (project root, outside web root)
- `ADMIN_BASE_PATH` constant (defined in `dashboard/bootstrap.php`) — used for all internal URLs
- `/static/dashboard/css/` + `/static/dashboard/js/` — served from `public/static/dashboard/`
- `/static/components/`, `/static/vendor/`, `/static/js/` — served from `public/static/`
- `get_admin_path($pdo)` helper in `auth_helpers.php` — reads `admin_path` setting
- Router catch in `router.php` — catches custom admin paths and serves from `dashboard/`
- Path guard in `dashboard/index.php` — 404s if request URI doesn't match configured admin_path
- `FRONTEND_404_PATH` constant — all admin 404s render `app/frontend_404.php`
- JS global `window.ADMIN_PATH` (set in `app/layout.php`) — used by static JS files for AJAX calls

## Entrypoints

- **Frontend:** `public/router.php` (front controller, bootstraps everything)
- **Admin:** `dashboard/index.php` (via router catch only — no physical path in web root)
- **Layout:** `app/layout.php` (slot-based theme rendering)

## Boot order

```
public/router.php
  → app/bootstrap_core.php           (env, DB, helpers, session, locale bootstrap)
  → cfg/config.php                   (.env via env.php, DB via db.php → $pdo,
                                      26 helpers in cfg/helpers/, session via session.php)
  → app/bootstrap_theme.php          (theme helper, widget helper, asset_url)
  → route matching
```

**Welcome guard:** If `cfg/.env` doesn't exist (fresh install), `bootstrap_core.php` shows a standalone HTML welcome page with a link to `/pondasi/` — no 500 error.

**Locale bootstrap:** After DB is available, `bootstrap_core.php` reads two settings:
- `site_language` — language for the admin dashboard UI (stored in `__APP_ADMIN_LOCALE`).
- `content_default_language` — default language for site content/URLs (stored in `__APP_DEFAULT_LOCALE` and returned by `default_locale()`). Falls back to `site_language` if not set.

It then calls `set_locale($contentDefault)` so the public frontend uses the content default, while `dashboard/index.php` re-applies `site_language` for admin users.

`$pdo` is created in `cfg/db.php` and is available as `$pdo` global + `$GLOBALS['pdo']`. All controllers receive it as parameter.

## Routing (`public/router.php`)

| Prefix | Controller | Notes |
|---|---|---|
| (empty) | `index.php` | Homepage |
| `{login_path}` | `melbu/index.php` | Custom login path (configurable in settings) |
| `{register_path}` | `daptar/index.php` | Custom register path (configurable in settings) |
| `{admin_path}` | `dashboard/index.php` (via router) | Custom admin path (configurable in settings; default: `adiwira`) |
| `/private/media/` | `PrivateMediaController` | Private image serving via PHP stream |
| `/private/file/`, `/private/pdf/` | `PrivateFileController` | Private file serving + PDF.js viewer |
| `/author/` | `AuthorController` | |
| `/category/` | `CategoryController` | Nested category paths |
| `/YYYY/` | `ArchiveController` | Year/month archive |
| `sitemap*.xml` | `SitemapController` | XML sitemaps |
| `/artikel/` or `/posts/` | `PostController::listArticles()` | |
| `/halaman/` | `PageController::listPages()` | |
| `/gallery/` | `PhotoController` | Gallery with categories |
| fallback slug | `PostController::dispatchBySlug()` | Single post/page by slug |

All controllers are in `app/controllers/`, all are static methods.

**Login/register path matching:** The router uses `auth_path_matches()` from `cfg/helpers/auth_helpers.php` which compares the normalized request URI against the configured path. Paths can be anything like `masuk`, `login`, `pintu/rahasia/masuk`. Since admin files are outside `public/`, nginx/Apache always falls through to the router, which includes the correct file from `dashboard/gerbank/*/`.

## Admin (`dashboard/` — outside web root)

- Entry: `dashboard/index.php` — path guard (matches `admin_path` setting) + session check + DB status check
- Admin pages: `dashboard/admin/` — each requires `_guard.php` which calls `adiwira_require_editorial($pdo)` (author/editor/admin) or `adiwira_require_admin($pdo)` (admin only)
- Admin layout: `dashboard/theme/adam/layout.php` (requires `DASHBOARD_CONTEXT` defined)
- Admin pages can be loaded via AJAX (no layout) or navigation (with layout); `adiwira_is_navigate_request()` detects this
- `ADMIN_BASE_PATH` constant — used for all internal navigation links (`$base = ADMIN_BASE_PATH;` replaces old `dirname(SCRIPT_NAME)`)
- `get_admin_path($pdo)` helper — reads `admin_path` setting, used in login/register pages for redirects
- `FRONTEND_404_PATH` constant — resolves to `app/frontend_404.php` for all admin 404s
- Static assets at `/static/dashboard/` + `/static/` (absolute paths, no `$base_url` prefix)

## Auth & Session (`cfg/session.php`)

- `is_logged_in()` — checks `$_SESSION['user_id']` + fingerprint (UA hash)
- `ensure_session_started(false)` — resume only, no auto-create
- `login_user($id, $email)` — creates session, regenerates ID, sets cookie
- `logout_user()` — clears + destroys session on multiple paths
- Roles: `author` < `editor` < `admin` (enum in `users.role`)
- Session cookie config is in `.env`: `SESSION_NAME`, `SESSION_COOKIE_DOMAIN`, `FORCE_HTTPS`, `SESSION_ALLOW_INSECURE_COOKIES`
- With `FORCE_HTTPS=1` + nginx `fastcgi_param HTTPS on` (see `SERVER_SETUP.md`)
- CSRF: stateless HMAC for public endpoints, session-backed for admin; `csrf_token()` / `csrf_check()`

### Login/Register pages

- **Login:** `dashboard/gerbank/melbu/index.php` (outside web root) — standalone HTML page, configurable brute-force protection, reCAPTCHA toggle, blocked IP/email detection. Guard checks `login_path` setting against request URI.
- **Register:** `dashboard/gerbank/daptar/index.php` (outside web root) — standalone HTML page, can be disabled entirely (`registration_enabled`), optional admin approval (`is_locked`), reCAPTCHA toggle. Guard checks `register_path` setting.
- Both use `get_admin_path($pdo)` for redirects after login/register.

### Settings (`settings/auth.php`)

- Registration on/off, approval required, reCAPTCHA toggle
- reCAPTCHA sitekey/secret stored in DB (fallback to `.env` if empty)
- Brute-force: max attempts + block duration
- `login_path`, `register_path`, `admin_path` — fully custom relative paths
- Migration from old `login_slug` setting runs on page load if detected
- Login attempts table with pagination and delete (modal, admin only)

## Theme system

- Themes in `public/views/themes/{default, adam}/` with same file structure
- Slot-based rendering: `assignments` table maps `slot_key` → theme_id or custom_post_id
- `render_slot($pdo, 'slot_key', ...)` → `resolve_template()` → slot-to-file mapping + variable interpolation
- Slots: `header`, `footer`, `sidebar`, `main.homepage`, `list.post`, `single.post`, etc.
- `$context_for_layout` variable determines which main slot renders
- Fallback chain: assigned theme → active theme → `default` theme
- Widgets search: active theme → default theme → `public/views/widget/`
- **Theme Zones**: Blogspot-style visual layout editor. Themes declare `layout` in `theme.json` with zones (`header`/`footer`) and positions (e.g. header: `logo`, `nav`, `controls`). Header/footer theme files call `theme_zone_render_position()` and fall back to hardcoded HTML when a position has no gadgets. Admin `Customize` shows the layout grid; gadgets can be added, configured, reordered, and removed per position. Built-in gadgets: `tz_logo`, `tz_nav_menu`, `tz_theme_toggle`, `tz_lang_switcher`, `tz_search`, `tz_html`.

### Theme Customizer (v2.3.13)

- Theme declares editable sections in `theme.json`:
  ```json
  "customizer": {
    "sections": {
      "header": {
        "label": "Header",
        "fields": {
          "logo": {"type": "image", "label": "Logo image"},
          "nav_menu": {"type": "menu", "label": "Navigation menu"},
          "show_theme": {"type": "toggle", "label": "Show theme selector"},
          "show_lang": {"type": "toggle", "label": "Show language selector"},
          "show_search": {"type": "toggle", "label": "Show search box"}
        }
      },
      "footer": {
        "label": "Footer",
        "fields": {
          "footer_text": {"type": "textarea", "label": "Footer / copyright text"},
          "footer_menu": {"type": "menu", "label": "Footer menu"},
          "footer_sidebar_zone": {"type": "sidebar_zone", "label": "Footer sidebar zone"},
          "show_social": {"type": "toggle", "label": "Show social icons"}
        }
      }
    }
  }
  ```
- Supported field types: `image` (URL + preview), `menu` (dropdown from Menu Manager), `sidebar_zone` (dropdown from Sidebar Settings), `textarea`, `text`, `toggle`.
- Values stored per-theme in settings key `theme_mods_{folder}` (JSON).
- Helpers (`cfg/helpers/theme_customizer.php`): `theme_mod($key, $default)`, `theme_mods_all()`, `theme_mods_save()`, `theme_customizer_fields($folder)`.
- Admin page: `admin/themes/customize` — polished per-section cards, live logo preview, dropdowns reuse Menu Manager and Sidebar Settings. Link "Customize" under Themes (admin only).
- Themes consume via `theme_mod('logo')`, `theme_mod('nav_menu')`, `theme_mod('show_search', true)`, `theme_mod('footer_text')`, `theme_mod('footer_sidebar_zone')`, etc. Defaults must preserve original behavior when no mods set.
- Legacy flat format (`customizer: {"logo": true, "nav_menu": true, "controls": [...]}`) is auto-converted to sections for backward compatibility.

### Theme Zones (v2.3.17)

- Blogspot-style visual layout editor for themes. Admin page: `admin/themes/customize`.
- Themes declare `layout` in `theme.json` with zones, positions, and optional `defaults` (gadgets to pre-fill when clicking **Load Default Layout**).
- Table: `theme_zone_items` (`theme_folder`, `zone_slug`, `position`, `type`, `title`, `config`, `ordering`, `active`). Each theme has its own isolated gadget rows; switching themes does not leak gadgets across themes.
- Migration `010-theme-zone-theme-folder.sql` adds `theme_folder` column and backfills existing rows with the active theme.
- Helper: `cfg/helpers/theme_zones.php` — `theme_zone_items()`, `theme_zone_layout()`, `theme_zone_discover_partials()`, `theme_zone_partial_positions()`, `theme_zone_render()`, `theme_zone_render_position()`, `theme_zone_has_position()`, `theme_zone_add_item()`, `theme_zone_delete_item()`, `theme_zone_set_order()`, `theme_zone_toggle_item()`, plus `theme_zone_render_title()`, `theme_zone_content_align()`, `theme_zone_universal_defaults()`.
- 13 built-in gadgets: `tz_image`, `tz_nav_menu`, `tz_theme_toggle`, `tz_lang_switcher`, `tz_search`, `tz_html`, `tz_richtext`, `tz_pages`, `tz_social`, `tz_sidebar_zone`, `tz_post_author`, `tz_post_meta`, `tz_post_contact`. All gadgets support universal title/alignment settings (`_title_tag`, `_align_title`, `_align_content`). Gadgets are registered via filters so plugins can add their own.
- Admin UI shows a full-page canvas: Header band → Main row (partial selector + Sidebar) → Footer band. Partials are discovered automatically from `main/**/*.php`. Supports drag & drop between positions, multi-row positions, and gadget configuration.
- Theme template files check `theme_zone_has_position()` and use `theme_zone_render_position()`; positions without gadgets fall back to the theme's original hardcoded HTML.
- Migrations: `schema/migrations/008-theme-zones.sql` + `009-theme-zone-position.sql` + `010-theme-zone-theme-folder.sql` (also in `schema/default.sql`).

The `themes` table has `store_url` and `store_slug` columns for update checking against a remote store:

- `read_theme_manifest($folder)` includes `store` block from `theme.json` (returns `store_url` + `store_slug`)
- `register_theme_in_db($pdo, $folder, $manifest)` stores `store_url`/`store_slug` from manifest's `store` key
- `register_all_themes_from_fs($pdo)` auto-registers all themes from filesystem
- Theme update flow: Check Updates → `register_all_themes_from_fs()` → `ThemeStoreClient::checkUpdates()` fetches `{store.url}/{store.slug}/version.json` → banners by folder name → applyUpdate()
- `install_theme_from_zip($pdo, $zip, $overwrite)` includes `store` in `manifestForDb`
- Theme updates keyed by **folder name** (not manifest name or store slug)

## Theme Authoring Guide (Customize / Theme Zones)

This guide is for theme developers who want to support the visual Customize editor (`admin/themes/customize`).

### File structure

```
public/views/themes/{folder}/
├── theme.json          # manifest + layout contract
├── assets/
│   ├── css/style.css
│   └── js/script.js
├── header.php          # zone: header
├── footer.php          # zone: footer
├── sidebar.php         # optional sidebar
├── main/
│   ├── homepage.php    # partial: main.homepage
│   ├── search.php      # partial: main.search
│   ├── 404.php         # partial: main.404
│   └── ...
├── single/
│   ├── post.php        # partial: single.post
│   └── page.php        # partial: single.page
├── list/
│   ├── post.php        # partial: list.post
│   ├── category.php    # partial: list.category
│   └── ...
└── index/
    └── category.php    # partial: index.category
```

### `theme.json` layout contract

The `layout` key tells the Customize editor which zones/positions exist and how to render the admin canvas. It does **not** store user data — user data lives in `theme_zone_items`.

```json
{
  "folder": "mytheme",
  "name": "My Theme",
  "layout": {
    "header": {
      "label": "Header",
      "columns": 3,
      "positions": {
        "logo":    { "label": "Logo" },
        "nav":     { "label": "Navigasi" },
        "controls":{ "label": "Controller" }
      },
      "defaults": { ... }
    },
    "footer": {
      "label": "Footer",
      "positions": {
        "about":     { "label": "About/Logo", "row": 1, "align": "left" },
        "pages":     { "label": "Pages",      "row": 1, "align": "center" },
        "social":    { "label": "Social",     "row": 1, "align": "center" },
        "copyright": { "label": "Copyright",  "row": 2, "align": "center" }
      },
      "defaults": { ... }
    },
    "single.post": {
      "label": "Single Post",
      "columns": 1,
      "positions": {
        "before_content": { "label": "Sebelum Konten" },
        "after_content":  { "label": "Sesudah Konten" }
      }
    },
    "list.post": {
      "label": "Post List",
      "columns": 1,
      "positions": {
        "before_loop": { "label": "Sebelum Daftar" },
        "after_loop":  { "label": "Sesudah Daftar" }
      }
    }
  }
}
```

Position options:

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `label` | string | — | Label shown in the admin canvas. |
| `row` | int | `1` | Row grouping for multi-row zones (e.g. footer row 1 + row 2). |
| `align` | `left`\|`center`\|`right` | `center` | Frontend alignment hint for the position cell. |
| `grid` | string | — | (legacy header only) CSS grid area like `1 / 2`. Prefer `columns` + `row`. |

Zone-level options:

| Key | Description |
|-----|-------------|
| `columns` | Number of columns in the admin canvas (1–4). Visual only; templates are free to use any CSS. |
| `defaults` | Gadgets pre-filled when user clicks **Load Default Layout**. Format: `{ "position": { "type": "tz_html", "title": "...", "config": {...} }` or array for multiple gadgets per position. |

### Rendering zones in templates

Always check `theme_zone_has_position()` first, then render with `theme_zone_render_position()`. Provide fallback HTML so the theme still works when a position has no gadgets or when the active theme has no `layout` declared.

```php
<?php if (function_exists('theme_zone_has_position') && theme_zone_has_position($pdo, 'header', 'logo')): ?>
  <?= theme_zone_render_position($pdo, 'header', 'logo') ?>
<?php else: ?>
  <!-- fallback hardcoded logo -->
  <a href="/" class="brand">My Theme</a>
<?php endif; ?>
```

For full zones (all positions in one call):

```php
<?= function_exists('theme_zone_render') ? theme_zone_render($pdo, 'header') : '' ?>
```

### Partials discovery

Any PHP file inside `main/` becomes a partial selectable in Customize. Examples:

| File | Partial slug | Positions (if not declared in `theme.json`) |
|------|--------------|---------------------------------------------|
| `main/homepage.php` | `main.homepage` | `before`, `after` |
| `main/search.php` | `main.search` | `before_loop`, `after_loop` |
| `single/post.php` | `single.post` | `before_content`, `after_content` |
| `list/post.php` | `list.post` | `before_loop`, `after_loop` |
| `index/category.php` | `index.category` | `before_loop`, `after_loop` |

Files starting with `_` are ignored. Subfolders become dotted slugs (`dir.file` → `dir.file`).

### Post-aware gadgets

`tz_post_author`, `tz_post_meta`, and `tz_post_contact` read `$GLOBALS['jy_current_post']`. Single/page templates must set it before rendering the zone:

```php
<?php $GLOBALS['jy_current_post'] = $post; ?>
<?= theme_zone_render_position($pdo, 'single.post', 'after_content') ?>
```

If the global is not set, these gadgets render empty string.

### Built-in gadget reference

| Gadget | Purpose | Key config keys |
|--------|---------|-----------------|
| `tz_html` | Raw HTML / CodeMirror editor. | `html` |
| `tz_richtext` | WYSIWYG via Quill. | `html` |
| `tz_image` | Image with media picker. | `src`, `alt`, `link`, `max_width` |
| `tz_nav_menu` | Menu from Menu Manager. | `menu`, `menu_class`, `depth`, `ul_attr` |
| `tz_search` | Search form. | `placeholder`, `button` |
| `tz_theme_toggle` | Light/dark toggle. | — |
| `tz_lang_switcher` | Language switcher. | `label` |
| `tz_pages` | List of published pages. | `pages[]`, `list_class` |
| `tz_social` | Social icon links. | `enabled[]`, `links` |
| `tz_sidebar_zone` | Embed a Sidebar zone. | `zone` |
| `tz_post_author` | Author box (single context). | `show_avatar` |
| `tz_post_meta` | Date/read time (single context). | `show_date`, `show_updated`, `show_read_time` |
| `tz_post_contact` | Contact info (single context). | — |

All gadgets support universal settings: `_title_tag` (`div`/`h1`–`h6`), `_align_title` (`left`/`center`/`right`), `_align_content` (`left`/`center`/`right`).

### Adding custom gadgets (plugin/theme)

Use filters so the gadget appears in the Customize "Add gadget" dropdown and renders correctly.

```php
// Register gadget type
add_filter('theme_zone_widget_types', function(array $types): array {
    $types['tz_hello'] = [
        'label' => __('Hello World'),
        'desc'  => __('Simple hello gadget.'),
        'default_config' => ['message' => 'Hello'],
    ];
    return $types;
});

// Render gadget
add_filter('theme_zone_render_widget', function(string $html, string $type, array $config, PDO $pdo): string {
    if ($type !== 'tz_hello') return $html;
    $msg = htmlspecialchars((string)($config['message'] ?? 'Hello'), ENT_QUOTES, 'UTF-8');
    return '<p class="tz-hello">' . $msg . '</p>';
}, 10, 5);
```

If a gadget is registered with `sidebar_widget_types`/`render_sidebar_widget` filters, it will also work in sidebar zones.

### Builder integration (theme-builder / jyavani-builder / form-builder)

Theme Zones expose two filter-based extension points so builder plugins can register their own gadgets. Builder plugins running inside the dashboard should call the PHP helpers directly instead of using a public HTTP API.

**Gadget registry filter:** `theme_zone_widget_types`

```php
add_filter('theme_zone_widget_types', function(array $types): array {
    $types['tz_my_block'] = [
        'label' => __('My Block'),
        'desc'  => __('Custom block from my plugin.'),
        'default_config' => ['foo' => 'bar'],
    ];
    return $types;
});
```

**Gadget renderer filter:** `theme_zone_render_widget`

```php
add_filter('theme_zone_render_widget', function(string $html, string $type, array $config, PDO $pdo): string {
    if ($type !== 'tz_my_block') return $html;
    return '<div class="tz-my-block">' . htmlspecialchars((string)($config['foo'] ?? '')) . '</div>';
}, 10, 5);
```

Built-in gadgets render through the same filter chain at priority 10. Use a higher priority to override a built-in renderer, or lower priority to provide a fallback.

PHP helpers available anywhere after bootstrap:

- `theme_zone_render(PDO $pdo, string $zone, ?string $folder = null): string`
- `theme_zone_render_position(PDO $pdo, string $zone, string $position, ?string $folder = null): string`
- `theme_zone_has_position(PDO $pdo, string $zone, string $position, ?string $folder = null): bool`
- `theme_zone_widget_types(): array`
- `theme_zone_render_widget(PDO $pdo, string $type, array $config): string`

### Best practices

1. **Never hardcode user-editable content.** Put it in `theme_zone_items` (via `layout.defaults` or let users add gadgets). Hardcode only the HTML skeleton (rows, columns, containers).
2. **Always provide fallback HTML.** If a position has no gadgets, the theme must still render correctly.
3. **Use `theme.json` `align` for footer cells**, but apply the actual CSS in your theme. The value is just a hint; your template decides how to use it.
4. **Keep `columns` as a visual hint** for the admin canvas; do not let it force your frontend CSS grid.
5. **Test `layout.defaults`** by clicking **Load Default Layout** on a fresh install or after deleting all gadgets.
6. **Run Playwright tests** after changing theme structure (`tests/playwright/`).

## Content & Shortcodes

- Posts/pages share `posts` table; `type` column: `article`, `page`, `theme`, `sc_preset`
- Status: `draft`, `published`, `private`
- Hierarchical categories via `categories.parent_id`
- Shortcodes in post content are expanded at render time:
  - `[[widget:name key=val]]` → `widget_expand_shortcodes()`
  - `[post_cat_shortcode category="..." layout="cards"]` → `post_cat_shortcode_expand()`
  - `[private_pdf id="123"]` → `private_file_shortcode_expand()`
  - `[video id="789"]` → `video_shortcode_expand()`

## Private files

- Media images: `private_files/media/{year}/{month}/{file}` served by `PrivateMediaController`
- Other files: `private_files/files/{path}` served by `PrivateFileController`
- Both stream via PHP (`fopen`/`fread`), not nginx static serving
- Access: if `visibility=public` AND `storage_disk=public` AND `access_scope=public` → public; otherwise requires login
- Private PDFs require signed time-limited token (`t` param) for raw streaming
- Uploads go through `dashboard/admin/upload_image.php` and `upload_file.php`

## Database

### Schema files

| File | Purpose |
|---|---|
| `schema/default.sql` | Core tables (single file, no migration files — merged into one) |
| `schema/translations.sql` | UI translations seed data (id + de locales, 3,804 INSERT IGNORE rows) |
| `schema/migrations/006-theme-store.sql` | Adds `is_system`, `store_url`, `store_slug` to `themes` table |
| `schema/migrations/007-i18n.sql` | Adds `ui_translations` and `post_translations` tables |

### Core tables

`users`, `posts`, `categories`, `post_categories`, `media`, `post_media_items`, `file`, `themes`, `assignments`, `menus`, `menu_items`, `settings`, `sidebar_zones`, `sidebar_zone_items`, `login_attempts`, `post_translations`, `ui_translations`

- Soft-delete pattern: `is_deleted` column on most tables
- `users` has `is_locked` for manual ban

## i18n (Internationalization)

### System (`cfg/helpers/lang_helpers.php`)

- `__($key, ...$vars)` — lookup `ui_translations` by key + current locale, supports sprintf vars. Short-circuits for `en` (no DB lookup).
- `_e($key, ...$vars)` — echo version of `__()`
- `set_locale($locale)` — set current locale for request
- `get_supported_locales()` — returns `$supported_locales` array

### Supported locales

Defined in `$supported_locales` (`cfg/helpers/lang_helpers.php`):
- `en` — English (source, no DB lookup)
- `id` — Indonesian
- `de` — German (LC_TIME: `de_DE.UTF-8`)

### Locale bootstrap

`app/bootstrap_core.php`:
1. Reads `site_language` from DB settings (admin UI locale)
2. Reads `content_default_language` from DB settings (frontend content default), falling back to `site_language`
3. Sets `__APP_ADMIN_LOCALE` (used by `admin_ui_locale()`) and `__APP_DEFAULT_LOCALE` (used by `default_locale()`)
4. Calls `set_locale($contentDefault)` for the public frontend
5. `dashboard/index.php` re-applies `set_locale(admin_ui_locale())` so the admin dashboard stays in `site_language`
6. Calls `setlocale(LC_TIME, ...)` for date/time formatting

Helper functions:
- `default_locale()` — returns the configured content default (`__APP_DEFAULT_LOCALE`).
- `admin_ui_locale()` — returns the configured admin UI language (`__APP_ADMIN_LOCALE`).
- `content_default_locale()` — same as `default_locale()`, but wrapped with the `content_default_locale` filter so plugins can override it.

`<html lang>` is rendered via the `html_lang_attribute` filter:
- Public layout (`app/layout.php`) uses `apply_filters('html_lang_attribute', content_default_locale())`.
- Admin layout (`dashboard/theme/adam/layout.php`) uses `apply_filters('html_lang_attribute', admin_ui_locale())`.

Because `default_locale()` is now the content default, plugins like Content Translation treat `content_default_language` as the base language: default-locale URLs have no prefix, and every other supported locale becomes a translation target. The admin dashboard language remains independent.

### Admin language selector

`dashboard/admin/settings/site.php` has a language dropdown. POST handler saves to `site_language` setting. `<html lang>` attribute is dynamic across all admin + frontend layouts.

### Schema

`ui_translations` table: `id`, `scope` (default=`default`), `locale`, `key`, `value`, `updated_at`. `translations.sql` seeds `id` and `de` rows.

### Auto-importing seed data for existing sites

`app/bootstrap_core.php` calls `ensure_ui_translations_seeded($pdo)` on every request (defined in `cfg/helpers/lang_helpers.php`). It hashes `schema/translations.sql` and stores the hash in `settings.ui_translations_seed_hash`. When the file changes or the setting is missing, it re-imports the seed using `INSERT IGNORE`, so:

- Sites that were installed before `translations.sql` existed get the seed automatically.
- New strings added to `translations.sql` appear on existing sites after the file is updated.
- User-edited translations are never overwritten (INSERT IGNORE only adds missing rows).

### Rule: every new user-facing string must be translatable

All new dashboard UI text — labels, headings, button text, placeholders, `title`/`aria-label`/`alt` attributes, `<option>` labels, status badges, empty states, flash messages, and JS toast/dialog strings — must go through `__()` / `_e()`. For strings used in JavaScript, emit them via `<?= json_encode(__('Source text')) ?>`.

When adding a new string:
1. Use an English source text as the key, e.g. `__('Save failed')`.
2. Add the English key plus an Indonesian translation to `schema/translations.sql`. Add a German translation if you can; otherwise add the English source as the German value as a placeholder.
3. Run `php -l` on the changed file and re-test the page with the admin language set to `id` and `de`.

Common examples that should NOT be hard-coded:
- Status labels (`Draft`, `Published`, `Private`). Wrap the output of `ucfirst($status)` with `__()`.
- Modal dropzone text, storage mode labels, access scope options.
- Theme manager headings/buttons and theme-browser labels.
- File/media manager toast prefixes and fallback messages.

### Quill editor

Placeholder string translatable via JS global `window.QUILL_PLACEHOLDER` (set in layout).

## Project structure

- `app/` — controllers, layout, bootstrap, frontend 404 (outside web root)
- `cfg/` — config, helpers, sessions, .env (outside web root)
- `dashboard/` — admin PHP files (outside web root)
- `public/` — web root (entrypoints + static assets + `views/` with themes)
- `private_files/` — user uploads (outside web root)
- `schema/` — SQL schema + migrations
- `plugins/` — plugin registry + installed plugins
- `tools/` — build tools

## Hooks System (`cfg/helpers/hooks.php`)

WordPress-style hook system:

| Function | Description |
|---|---|
| `add_action(string $hook, callable $callback, int $priority = 10)` | Register action hook |
| `do_action(string $hook, mixed ...$args)` | Execute action hooks |
| `add_filter(string $hook, callable $callback, int $priority = 10)` | Register filter hook |
| `apply_filters(string $hook, mixed $value, mixed ...$args)` | Execute filters, return modified value |
| `remove_action(string $hook, callable $callback, int $priority = 10)` | Remove action |
| `remove_filter(string $hook, callable $callback, int $priority = 10)` | Remove filter |

Used for bin items (`apply_filters('bin_items', ...)`), allowing plugins to extend admin bin pages.

**Admin lifecycle hooks** (for plugins like Content Translation):
- `admin_post_after_add($post_id, $pdo, $_POST)` — after a new article is saved.
- `admin_post_after_edit($post_id, $pdo, $_POST)` — after an article is updated.
- `admin_post_after_delete($post_id, $pdo)` — after an article is moved to trash.
- `admin_page_after_add($page_id, $pdo, $_POST)`, `admin_page_after_edit($page_id, $pdo, $_POST)`, `admin_page_after_delete($page_id, $pdo)` — same for pages.

Fired from `dashboard/admin/posts/{add,save,delete}.php` and `dashboard/admin/pages/{add,save,delete}.php`.

## Plugin System (v1.0)

Third-party features installed as removable plugins via `plugins/{name}/plugin.json`.

### State file

`cfg/var/plugins-disabled.json` (via `PLUGIN_DISABLED_JSON` constant) — JSON array of disabled plugin names (instead of checking filesystem). Created automatically if missing.

### Registry API (`plugins/index.php`)

| Function | Returns | Description |
|---|---|---|
| `plugin_manifest(string $name)` | `?array` | Read a single plugin's `plugin.json` |
| `plugins_all()` | `array` | All plugins (enabled & disabled) |
| `plugins_active()` | `array` | Only enabled plugins (cached per-request) |
| `plugin_enable(string $name)` | `bool` | Enable a plugin (removes from disabled.json) |
| `plugin_disable(string $name)` | `bool` | Disable a plugin (adds to disabled.json) |
| `plugin_is_active(string $name)` | `bool` | Check if plugin is enabled |
| `plugin_admin_routes()` | `array` | Routes from active plugins |
| `plugin_nav_items()` | `array` | Nav items from active plugins |
| `plugin_assets()` | `array` | CSS/JS assets from active plugins |
| `plugin_resolve_route(string $route)` | `?array` | Find a specific route |
| `plugin_include_file(string $file)` | `bool` | `require` a plugin file |
| `plugin_delete(string $name)` | `bool` | Remove plugin from filesystem entirely |
| `plugin_checks(string $name)` | `array` | Run setup checks for a plugin |

### Plugin Manifest (`plugin.json`) — Key Fields

- `name` (req): unique identifier, alphanumeric + dash/underscore
- `admin.pages[]` (req): `route`, `file`, `title`, `roles`, `hidden`
- `admin.nav[]`: `label`, `icon`, `page`, `parent` (`"settings"` / `"tools"`), `roles`
- `static.copy[]`: `from` (relative to plugin dir), `to` (relative to `public/`) — files copied on upload
- `assets.css` / `assets.js`: URLs loaded on admin pages
- `requires.plugins`: object mapping required plugin slugs to semver constraints, e.g. `{"content-api": "^1.2.0"}`. Required plugins must be installed, active, compatible, and loadable before the dependent can activate.

### Plugin Uploader

Located at `dashboard/admin/plugins/upload.php`. Accessed via `?page=admin/plugins/upload` or the "+ Upload Plugin" button on the Plugin Manager page.

**Upload flow:**
1. Drop or select `.zip` file (max 50MB)
2. Server validates `plugin.json` exists with valid `name`
3. Extracts to `plugins/{name}/`
4. Copies files declared in `static.copy[]` to `public/` (e.g., xterm JS/CSS → `public/static/vendor/xterm/`)
5. Runs the fixed `install.sh` convention with a bounded timeout/output capture; manifests cannot provide shell commands
6. Enables the plugin only for activation actions; install-only may stage an inactive plugin before its plugin dependencies are available
7. Redirects to Plugin Manager with success toast

**Two-button UI on Plugin Store:** `Install` (extract + disable) vs `Install & Activate` (extract + enable). Install-only mode calls `plugin_disable($name)` after extraction.

Core always serves `/sw.js` through `public/router.php`. Core owns `install`/`activate` lifecycle handlers (`skipWaiting` and `clients.claim`), while active plugins append handlers through the `service_worker_script` filter. With no contributions, the lifecycle-only worker replaces stale plugin workers.

The `install.sh` runner defaults to 120 seconds and 64 KiB captured output. Deployments can set `PLUGIN_INSTALL_TIMEOUT_SECONDS` and `PLUGIN_INSTALL_OUTPUT_LIMIT`; Core hard-caps these at 900 seconds and 1 MiB. On supported Unix hosts Core starts a fixed `setsid` process group and terminates the full group on timeout; other hosts retain direct-process termination as a portability fallback.

### Plugin Browser (Store)

`dashboard/admin/plugins/browse.php` — fetches plugin list from remote store API. Uses `PluginStoreController` (in `app/controllers/`) for:
- `checkUpdates($pdo)` — compare installed vs store versions
- `applyUpdate($pdo, $name, $progressToken)` — download + backup + extract
- `readProgress($token)` / `clearProgress($token)` — AJAX progress polling

### Integration Points

- `dashboard/index.php` — loads `plugins/index.php` after bootstrap; plugin routes checked before direct file router
- `dashboard/theme/adam/part/main.php` — plugin pages resolved before DASH_PATH file lookup via `plugin_resolve_route()`
- `dashboard/theme/adam/part/aside.php` — renders plugin nav items: `parent: "settings"` as sublinks under Settings, `parent: "tools"` under collapsible Tools menu
- Plugin Manager at `dashboard/admin/plugins/index.php` (core admin page, not a plugin)
- Bin pages use `apply_filters('bin_items', ...)` hook for plugin-extendable trash listing

### Example: Terminal Plugin

`plugins/terminal/` — multi-tab WebSocket PTY terminal:
- `plugin.json` declares `static.copy` for xterm assets, `parent: "tools"` for nav
- `install.sh` handles npm install + systemd service
- `uninstall.sh` removes service + files

## Development

- Read the current version from `VERSION` and `version.json`; do not rely on a version copied into documentation.
- Playwright regression tests available — run after changes to verify
- Error display controlled by `APP_DEBUG=1` in `.env`
- `dev_lock.php` can lockdown the site
- `.env` file is `cfg/.env`; template at `cfg/env-sample`
- `reset_admin_cache()` must be called after enabling a plugin for nav to appear (deletes `cfg/var/theme_cache.json`)
- **Installer:** `public/pondasi/index.php` — one-time web installer (like WordPress). Step 1: DB config → creates DB, runs `default.sql`. Step 2: admin user + site settings. After `default.sql`, imports `translations.sql` for seed data. No hardcoded defaults. Run on fresh install, then delete `pondasi/` folder. Link to dashboard uses URL `/adiwira/gerbank/melbu/` (default login path, router serves from `dashboard/`).
- **Release workflow:** `/var/www/md/update.md` defines version bump semantics, candidate build, commit, push, canonical package publication, and endpoint verification.
- **Build tools:** `tools/build-package.php [output-path]` regenerates the manifest and builds a verified ZIP atomically; `tools/generate-manifest.php` only regenerates `tools/cms-manifest.json`.
- **Server setup guide** at `SERVER_SETUP.md`
- `PUBLIC_PATH` is resolved once in `cfg/config.php` after `.env` loading. It must be an existing absolute deployed web root; updater manifests still use canonical `public/...` paths.
- `e()` is `htmlspecialchars()` (from `cfg/helpers/null_helpers.php`)
- Timezone: `Asia/Jakarta`
- `.gitignore` excludes: `.env`, `private_files/`, `cfg/var/sessions/`, `public/static/img/{year}/`

## Conventions

- Controllers: static methods receiving `PDO $pdo`
- Helpers: loose functions in `cfg/helpers/`, loaded automatically
- Views: plain PHP templates with inline echo, no template engine
- No namespaces — all code in global namespace
- Strict types: `declare(strict_types=1);` on most files
- Admin AJAX endpoints return JSON via `adiwira_json()`

## Helpers (`cfg/helpers/` — 26 files)

`sessions.php` and `lang_helpers.php` are loaded inline (not via config.php loop):

| Helper | Description |
|---|---|
| `auth_helpers.php` | Login/register path matching, brute force |
| `author_helpers.php` | Author metadata display |
| `cms_content.php` | Content formatting, excerpt, read time |
| `datetime.php` | Date/time formatting |
| `debug_helpers.php` | Debug/dump utilities |
| `editor_helpers.php` | Editor toolbar configuration |
| **`hooks.php`** | Action/filter hook system (add_action, apply_filters, etc.) |
| **`lang_helpers.php`** | i18n: `__()`, `_e()`, `set_locale()`, `get_supported_locales()` |
| `menu_helper.php` | Menu rendering |
| `null_helpers.php` | `e()` = `htmlspecialchars()`, null-safe utilities |
| `permalink_helpers.php` | Custom permalink structures |
| `private_file_shortcodes.php` | `[private_pdf]` shortcode |
| `redirct_helpers.php` | Redirect helpers (note: typo in filename preserved) |
| `role_helpers.php` | Role checking utilities |
| `settings_helpers.php` | Settings CRUD |
| `shortcode_builder.php` | Shortcode registration |
| `sidebar_helper.php` | Sidebar zone rendering |
| `success_redirect.php` | Flash messages |
| `theme_helper.php` | Theme resolution, slot rendering, manifest, store integration |
| `time_helpers.php` | Time ago, time formatting |
| `url_helpers.php` | URL building |
| `video_shortcodes.php` | `[video]` shortcode |
| `widget_helper.php` | Widget rendering |
| `widget_shortcodes_p.php` | `[[widget:...]]` shortcode expansion |

### Key auth helpers (`cfg/helpers/auth_helpers.php`)

- `auth_path_matches(string $path): bool` — compares request URI against configured path
- `get_login_path(PDO $pdo): string` — reads `login_path` with fallback to old `login_slug`
- `get_register_path(PDO $pdo): string` — reads `register_path` with fallback to old `login_slug`
- `get_admin_path(PDO $pdo): string` — reads `admin_path` with fallback to `adiwira`
- `is_blocked($attempt): bool` — checks if IP/email is blocked
- `get_login_attempt(PDO $pdo, $email, $ip): ?array`
- `record_failed_attempt(PDO $pdo, $email, $ip): int` — hardcoded 5 attempts / 15 min (legacy default; login page uses `melbu_record_failed()` with configurable params)
- `reset_login_attempts(PDO $pdo, $email, $ip): void`
