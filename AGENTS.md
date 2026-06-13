# AGENTS.md — Jyavani CMS v2.1.3

Native PHP CMS by Adam Muiz. Dashboard theme is named "Adiwira". No framework, no Composer, no build tools, no tests.

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

**Locale bootstrap:** After DB is available, `bootstrap_core.php` reads `site_language` from settings, calls `set_locale($locale)` + `setlocale(LC_TIME, 'de_DE.UTF-8')` for German.

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

### Theme Store Integration

The `themes` table has `store_url` and `store_slug` columns for update checking against a remote store:

- `read_theme_manifest($folder)` includes `store` block from `theme.json` (returns `store_url` + `store_slug`)
- `register_theme_in_db($pdo, $folder, $manifest)` stores `store_url`/`store_slug` from manifest's `store` key
- `register_all_themes_from_fs($pdo)` auto-registers all themes from filesystem
- Theme update flow: Check Updates → `register_all_themes_from_fs()` → `ThemeStoreClient::checkUpdates()` fetches `{store.url}/{store.slug}/version.json` → banners by folder name → applyUpdate()
- `install_theme_from_zip($pdo, $zip, $overwrite)` includes `store` in `manifestForDb`
- Theme updates keyed by **folder name** (not manifest name or store slug)

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
| `schema/translations.sql` | UI translations seed data (id + de locales, 2,959 INSERT IGNORE rows) |
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
1. Reads `site_language` from DB settings
2. Calls `set_locale($locale)`
3. Calls `setlocale(LC_TIME, $localeMap[$locale])` for date/time formatting

### Admin language selector

`dashboard/admin/settings/site.php` has a language dropdown. POST handler saves to `site_language` setting. `<html lang>` attribute is dynamic across all admin + frontend layouts.

### Schema

`ui_translations` table: `id`, `scope` (default=`default`), `locale`, `key`, `value`, `updated_at`. `translations.sql` seeds `id` and `de` rows.

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

## Plugin System (v1.0)

Third-party features installed as removable plugins via `plugins/{name}/plugin.json`.

### State file

`plugins/disabled.json` — JSON array of disabled plugin names (instead of checking filesystem). `PLUGIN_DISABLED_JSON` constant. Created automatically if missing.

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

### Plugin Uploader

Located at `dashboard/admin/plugins/upload.php`. Accessed via `?page=admin/plugins/upload` or the "+ Upload Plugin" button on the Plugin Manager page.

**Upload flow:**
1. Drop or select `.zip` file (max 50MB)
2. Server validates `plugin.json` exists with valid `name`
3. Extracts to `plugins/{name}/`
4. Copies files declared in `static.copy[]` to `public/` (e.g., xterm JS/CSS → `public/static/vendor/xterm/`)
5. Runs `install.sh` if present and executable
6. Enables the plugin automatically (or shows Install vs Install & Activate buttons)
7. Redirects to Plugin Manager with success toast

**Two-button UI on Plugin Store:** `Install` (extract + disable) vs `Install & Activate` (extract + enable). Install-only mode calls `plugin_disable($name)` after extraction.

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

- Version: **2.1.3** (see \`VERSION\` and \`version.json\`)
- No test suite — manual testing only
- Error display controlled by `APP_DEBUG=1` in `.env`
- `dev_lock.php` can lockdown the site
- `.env` file is `cfg/.env`; template at `cfg/env-sample`
- `reset_admin_cache()` must be called after enabling a plugin for nav to appear (deletes `cfg/var/theme_cache.json`)
- **Installer:** `public/pondasi/index.php` — one-time web installer (like WordPress). Step 1: DB config → creates DB, runs `default.sql`. Step 2: admin user + site settings. After `default.sql`, imports `translations.sql` for seed data. No hardcoded defaults. Run on fresh install, then delete `pondasi/` folder. Link to dashboard uses URL `/adiwira/gerbank/melbu/` (default login path, router serves from `dashboard/`).
- **Build tools:** `tools/build-package.php` builds distributable zip, `tools/generate-manifest.php` generates `tools/cms-manifest.json`
- **Server setup guide** at `SERVER_SETUP.md`
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
