# Theme Authoring Guide for Jyavani CMS Customize

This document is a companion to `AGENTS.md` for theme developers who want to support the visual **Customize** editor (`admin/themes/customize`).

## Quick start

A theme that supports Customize needs three things:

1. A `theme.json` manifest with a `layout` block.
2. Template files that call `theme_zone_render_position()` with fallback HTML.
3. Optionally, `layout.defaults` gadgets that pre-fill the layout when the user clicks **Load Default Layout**.

## Context-aware frontend assets

Legacy themes need no changes: Core continues to load Anime, Quill public CSS, global fonts, and Swiper, and string entries in `styles` and `scripts` remain global. Modern themes can opt into a smaller context-specific payload.

```json
{
  "core_assets": {
    "default": ["anime", "quill", "fonts", "swiper"],
    "contexts": {
      "main.homepage": []
    }
  },
  "styles": [
    "assets/css/style.css",
    {
      "src": "assets/css/blocks.css",
      "exclude_contexts": ["main.homepage"]
    }
  ],
  "scripts": [
    "assets/js/site.js",
    {
      "src": "assets/js/codeblocks.js",
      "contexts": ["single.*"]
    }
  ],
  "preloads": [
    {
      "href": "assets/img/hero-960.webp",
      "as": "image",
      "contexts": ["main.homepage"],
      "fetchpriority": "high",
      "imagesizes": "100vw",
      "imagesrcset": [
        {"href": "assets/img/hero-480.webp", "descriptor": "480w"},
        {"href": "assets/img/hero-960.webp", "descriptor": "960w"}
      ]
    }
  ]
}
```

- Valid Core dependency IDs are `anime`, `quill`, `fonts`, and `swiper`.
- A missing `core_assets` key preserves the legacy set. An empty list disables all four dependencies.
- Core uses the union required by all relevant themes. A legacy theme without `core_assets` retains the full set, so themes that opt out completely should also use `"standalone": true`.
- `contexts` and `exclude_contexts` accept exact slot contexts or a trailing wildcard such as `single.*`.
- Theme CSS and JavaScript URLs receive a filesystem modification version automatically.
- Preload paths must resolve to files inside the declaring theme. Preloads intentionally use the original URL so they match image markup exactly.

## Minimal example

```json
{
  "folder": "starter",
  "name": "Starter Theme",
  "version": "1.0.0",
  "color_mode": "both",
  "layout": {
    "header": {
      "label": "Header",
      "columns": 3,
      "positions": {
        "logo":     { "label": "Logo" },
        "nav":      { "label": "Navigasi" },
        "controls": { "label": "Controller" }
      },
      "defaults": {
        "logo": { "type": "tz_html", "title": "", "config": { "html": "<a href='/'>Starter</a>" } },
        "nav":  { "type": "tz_nav_menu", "title": "", "config": { "menu": "primary" } }
      }
    },
    "footer": {
      "label": "Footer",
      "positions": {
        "copyright": { "label": "Copyright", "row": 1, "align": "center" }
      },
      "defaults": {
        "copyright": { "type": "tz_html", "title": "", "config": { "html": "&copy; 2026 Starter Theme" } }
      }
    }
  }
}
```

Template (`header.php`):

```php
<header class="site-header">
  <div class="header-row">
    <?php if (function_exists('theme_zone_has_position') && theme_zone_has_position($pdo, 'header', 'logo')): ?>
      <div class="header-logo"><?= theme_zone_render_position($pdo, 'header', 'logo') ?></div>
    <?php else: ?>
      <a href="/" class="header-logo">Starter</a>
    <?php endif; ?>

    <?php if (function_exists('theme_zone_has_position') && theme_zone_has_position($pdo, 'header', 'nav')): ?>
      <nav class="header-nav"><?= theme_zone_render_position($pdo, 'header', 'nav') ?></nav>
    <?php else: ?>
      <nav class="header-nav"><!-- fallback menu --></nav>
    <?php endif; ?>

    <?php if (function_exists('theme_zone_has_position') && theme_zone_has_position($pdo, 'header', 'controls')): ?>
      <div class="header-controls"><?= theme_zone_render_position($pdo, 'header', 'controls') ?></div>
    <?php else: ?>
      <div class="header-controls"><!-- fallback controls --></div>
    <?php endif; ?>
  </div>
</header>
```

## Zone and position naming conventions

| Zone | Typical positions | Where to render |
|------|-------------------|-----------------|
| `header` | `logo`, `nav`, `controls` | `header.php` |
| `footer` | `about`, `pages`, `social`, `copyright`, etc. | `footer.php` |
| `single.post` | `before_content`, `after_content` | `single/post.php` |
| `single.page` | `before_content`, `after_content` | `single/page.php` |
| `list.post` | `before_loop`, `after_loop` | `list/post.php` |
| `list.category` | `before_loop`, `after_loop` | `list/category.php` |
| `main.homepage` | `before`, `after` | `main/homepage.php` |
| `main.search` | `before_loop`, `after_loop` | `main/search.php` |
| `main.404` | `before`, `after` | `main/404.php` |

Zones and positions are fully flexible — these names are conventions only. The admin canvas is generated from whatever you declare in `theme.json`.

## Partial discovery

Files inside `main/` are automatically discovered as partials. A partial gets the slug `main.{filename}` (subfolders become dotted prefixes). Files starting with `_` are ignored.

When a partial is selected in Customize, the editor exposes positions from either:

1. `theme.json` → `layout.{partial-slug}.positions`, or
2. Default positions derived from the partial name (`before`/`after` for static pages, `before_loop`/`after_loop` for list pages).

Best practice: declare partial positions explicitly in `theme.json` so labels and rows are consistent.

## Rendering helpers

All helpers live in `cfg/helpers/theme_zones.php`.

```php
// True if the position has at least one active gadget.
theme_zone_has_position(PDO $pdo, string $zone, string $position): bool

// Render all gadgets for a position as a single HTML string.
theme_zone_render_position(PDO $pdo, string $zone, string $position): string

// Render an entire zone (all positions) grouped by row.
theme_zone_render(PDO $pdo, string $zone): string
```

Use `theme_zone_has_position()` to decide whether to output fallback HTML.

## Custom theme post hooks

Custom posts with `type = theme` can be rendered directly by slug or assigned to a
slot. The Core exposes generic hooks so extensions can adapt their data without
changing the assignment model or renderer.

```php
// Direct theme post, before variables, template rendering, and layout metadata.
apply_filters('theme_post_data', array $themePost, ?PDO $pdo): array

// Custom theme post assigned to a slot, before render_custom_post_template().
apply_filters('theme_slot_post_data', array $themePost, string $slotKey, ?PDO $pdo, array $context): array

// Theme editor, after Theme Settings and before the CodeMirror content editor.
do_action('theme_editor_before_content', array $themePost, PDO $pdo): void
```

Filters must return a post array. A slot filter must only adapt the assigned theme
post; it must not replace page-level metadata for the request. This keeps direct
theme pages, assigned partials, preview tools, localization, and future extensions
independent from one another.

## Article workflow hooks

Plugins that maintain article-owned companion rows can join Core's transaction
before a source mutation commits:

```php
do_action('admin_post_before_add_commit', int $postId, PDO $pdo, array $input): void
do_action('admin_post_before_edit_commit', int $postId, PDO $pdo, array $input): void
do_action('admin_posts_bulk_before_mutation', string $action, array $lockedPosts, PDO $pdo, array $input): void
```

These actions execute after Core has normalized their input and locked the
permissions and resource rows needed by that mutation, but before the transaction
commits or performs the selected bulk mutation. A listener may write companion
state or throw; thrown errors propagate so Core rolls back the whole transaction.
Listeners must not commit, roll back, or start another transaction, and must
preserve Core's global-first lock order.

Plugins that store an aggregate `posts.status` may expose the article source status
to the editor and its locked permission check through:

```php
apply_filters('admin_post_editor_status', string $status, array $post, PDO $pdo): string
```

The result must be `draft`, `published`, or `private`. Invalid locked results fail
the save operation. Site-owned constraints can also add validation errors before
Site Settings are persisted:

```php
apply_filters(
    'site_settings_validation_errors',
    list<string> $errors,
    PDO $pdo,
    array $input,
    array $context
): list<string>
```

Validation is monotonic: Core preserves every existing error and only accepts
additional string errors from filters.

Dashboard article lists expose `post_list_join` to both count and row queries.
Plugins may align localized filters with their selected representation through:

```php
apply_filters('post_list_status_expression', string $expression, array $context): string
apply_filters('post_list_search_condition', string $condition, array $context): string
```

Expressions may use aliases contributed by `post_list_join`; malformed values
containing statement separators are ignored. The status expression controls the
displayed row status and edit/bulk authorization as well as status filtering.

Default sitemap SQL can be restricted through:

```php
apply_filters(
    'sitemap_query_clauses',
    array $clauses, // ['where' => list<string>, 'params' => array<string,int|string>]
    PDO $pdo,
    array $context // type and table_alias ('p')
): array
```

The same clauses are applied to sitemap counts and rows. Parameter names must be
plugin-namespaced and may not replace Core bindings. Malformed output is ignored
as a complete value; filters must return the complete `where`/`params` structure.

## Theme slot context hook

Plugins can augment the prepared context for every available theme slot at the
central `render_slot()` boundary:

```php
apply_filters(
    'theme_slot_context',
    array $context,
    string $slotKey,
    ?string $folder,
    ?PDO $pdo
): array
```

The filter runs exactly once per rendered slot, including `header` and
`main.homepage`, after Core resolves an available render target and before it
renders the theme file or assigned custom theme post. `$folder` is the selected
theme folder for a theme file and `null` for a custom theme post. Add
plugin-namespaced context keys and return the complete context array.

The context array is passed to each filter callback by value. If the chain throws
or returns a non-array value, Core discards the returned array and continues with
the pre-filter array value. This is not a deep transactional rollback: objects
inside both arrays remain shared references, and Core cannot undo object mutation
or other callback side effects. Plugins must treat nested objects as immutable if
they require value fallback to remain unchanged.

After filtering, Core overwrites the reserved `pdo`, `__jy_theme_folder`,
`__jy_theme_source_folder`, and `__jy_slot_key` keys with canonical values before
either renderer runs. For custom theme posts, both folder values are `null`;
`__jy_slot_key` and `pdo` still identify the current render. For fallback theme
files, `__jy_theme_folder` and the `$folder` filter argument identify the selected
theme, while `__jy_theme_source_folder` identifies the theme that supplies the
actual fallback file. Caller or plugin values cannot spoof these reserved values.

Normal public and dashboard requests already hold the request-lifetime shared
theme lifecycle reader while this filter and the template execute. The hook does
not acquire, release, or upgrade lifecycle locks.

## Theme operation hooks

Core uses a shared-reader/exclusive-writer contract for installed executable trees. Every normal public or dashboard request takes a shared reader on `0-theme-lifecycle` after the theme lock helper loads and holds it until shutdown, covering plugin bootstrap/routes and theme rendering. Install and update writers release their own request reader, acquire `0-theme-lifecycle` exclusively before exact folder locks, and revalidate state after acquisition. The shared lock is never upgraded in place, and same-request exclusive re-entry still fails fast. Update extensions contribute schema-1 issues through `theme_update_preflight($state, $folder, $cachedUpdate, $completeInstalledManifest, $pdo)`. An issue has a bounded ID, label/message, literal `blocking` and `resolved` flags, and optional state token, choices, safe relative links, and scalar details. Core, not listeners, computes whether the update is allowed and reruns the filter under the lock immediately before mutation.

Locks are shared by a trusted deployment group: `theme-operation-locks` is setgid mode `02770`, lock files are `0660`, and every cooperating PHP/CLI worker must run with that group. Successful operations emit `theme_install_completed($folder, $completeManifest)` or `theme_update_completed($folder, $oldVersion, $newVersion, $completeManifest)` while still locked. Installed-theme cards emit `theme_manager_theme_actions($themeRow, $completePhysicalManifest, $context)`. These observers use `do_action_isolated()`, so one failing listener is logged without suppressing later listeners.

Package updates publish without optional runtime extensions or platform-specific calls. Under the global exclusive lock, Core moves the exact old tree to a unique same-parent `.package-publication-recovery-*` path, moves the complete stage into place, and verifies old and new identities. The retained old path supports exact rollback through the same guarded two-rename strategy and is deleted only after post-publication work succeeds. If a process is killed between renames, the known-good old tree may remain at that named path while the target is absent. The next operation detects the residual and fails closed without deleting it; manual inspection and restoration are required. Shared readers prevent cooperating requests from observing the ordinary two-rename gap.

Plugin deactivation and deletion/uninstall expose `plugin_state_change_preflight($state, $name, $operation)`. The state contains only a literal boolean `allowed` and a bounded safe `message`; malformed output and listener exceptions deny the operation. Single and bulk Plugin Manager operations call the same central plugin functions and cannot bypass this filter.

## Plugin database migrations

Plugin packages may contain a top-level `migrations/` directory. Core accepts only safe regular files named `{exactly four positive digits}-{slug}.sql` or `.php`, orders them numerically, and records each completed file in `plugin_migrations` with its exact plugin name, filename, SHA-256 checksum, and applying plugin version. Applied files must be the complete discovered prefix: a missing, changed, or newly backfilled historical file fails preflight. Gaps are allowed but permanently consume lower sequence positions once a later file is applied. No migration path, callback, SQL, or command is accepted from `plugin.json`.

SQL migrations are split into ordinary statements without PDO multi-statement mode. To make parsing independent of MySQL `sql_mode`, Core lexically rejects raw backslashes and executable/version-comment or optimizer-hint tokens anywhere in the file, including literals and otherwise inert comments. It also rejects `DELIMITER`, every `SET` statement, transaction/XA control, table locks, and `USE`. PHP migrations must return `static function (PDO $pdo): void`. The runner rejects caller-owned transactions and MySQL connections with autocommit disabled. After execution it re-discovers and rehashes the complete migration tree. Immediately before ledger insertion it verifies that no transaction is open and both PDO autocommit and MySQL `@@SESSION.autocommit` remain enabled; leaked state is restored before the file fails. MySQL and MariaDB DDL may commit implicitly, so all migrations must be replay-safe and forward-compatible. A failed file is not tracked, later files do not run, and database effects may survive.

Install, update, and activation run migrations only while Core holds the global exclusive lifecycle lock and the exact plugin lock. Fresh installs are published disabled before migration. Updates validate staged history before publication, retain the exact old file tree during migration, and temporarily disable an enabled plugin when pending migrations exist. Install and update revalidate the complete history after every `install.sh` run. Update reactivation failure restores both the exact old plugin tree and old declared static assets. Any failure after migration starts leaves the plugin inactive even if old files are restored; a successful operation restores its prior enabled state.

Keep-data deletion preserves the ledger because schema/data remain. Complete uninstall first checks for publication recovery artifacts, then requires the plugin to be active and recorded as successfully loaded by `plugin_load_active()` in the current request. This proves that cleanup registered through the existing `plugin_uninstall` hook API is present; a disabled or not-yet-loaded plugin fails before ledger, database, or file mutation with instructions to activate first or keep data. Core then makes the plugin inactive, clears its ledger while both lifecycle locks are held, and executes every registered cleanup hook in isolation. It verifies and, where possible, recovers transaction and MySQL/PDO autocommit state after every callback and again before deletion. A plugin with no cleanup hooks may still complete uninstall. Any callback failure leaves files installed and inactive with history already cleared, so later activation/reinstall reruns schema setup rather than skipping it. Cleanup must therefore be idempotent. After successful cleanup, Core atomically renames the complete executable tree to a same-parent non-discoverable recovery path before recursive deletion. A cleanup failure can therefore leave only an inactive live tree or a named recovery artifact that blocks reinstall, never a discoverable partial tree. For optional MySQL verification against a disposable database, set `JY_TEST_MYSQL_DSN`, `JY_TEST_MYSQL_USER`, and `JY_TEST_MYSQL_PASSWORD`, then run `php tests/plugin_migration_mysql_contract.php`.

## Collection route contract

Collection routes use the active Website Settings at request time. Core never
stores a configured collection prefix in a controller or plugin. For example,
the category route always resolves the current `category_path` setting through
`get_category_routes()` and `get_category_base()`.
Configured collection bases may contain nested segments, such as
`isi/kategori`; Core matches the complete configured base before parsing the
remaining route.

After routing resolves a collection, it stores a normalized context with
`collection_set_route_context()`. Extensions may inspect or adapt this semantic
context through `collection_route_context`; they must not parse `REQUEST_URI`
again. The initial context includes the route name, normalized path, configured
base, slug, page, and query.

Core provides extension points that preserve data ownership:

```php
apply_filters('collection_item', array $item, string $type, array $context): array
apply_filters('collection_rows', array $rows, array $context): array
apply_filters('collection_url', string $url, string $type, array $context): string
apply_filters('collection_query_clauses', array $clauses, array $context): array
```

The filters are presentation and URL extension points. They must not change the
database identity or relationships used by the controller, such as category IDs,
parentage, or post-category membership. URL helpers such as
`get_category_permalink()` and `collection_paginated_url()` read the current
settings and are the required path for Core-rendered category links.

`collection_query_clauses` is used before both the count and row queries. It
accepts only prepared `where` fragments and uniquely named `params`, so an
extension can apply the same visibility policy to pagination and results. Core
owns the base query, joins, ordering, and pagination; extensions must not alter
those concerns through this hook.

`collection_category_breadcrumbs()` builds category breadcrumb records through
the same `collection_item` contract. The Category editor also exposes
`category_editor_after_fields` after source fields have loaded, allowing an
extension to add related controls without changing category CRUD.

Content URL builders finish through `content_permalink`, receiving the generated
URL, the source content row, and its type (`post` or `page`). Extensions may
return an alias URL without changing permalink structures or content identity.

After all Core content routes fail to resolve, extensions may return a redirect
target through `unresolved_content_redirect_url`, which receives the empty
target, normalized request path, and `PDO` connection. A returned root-relative
or absolute URL may include its own query string; Core safely appends the current
request query with `&` rather than adding a second `?`.

## Canonical Content Routes

Articles, pages, and Theme Templates may have an optional nested public path in
`content_routes`, separate from their editor-facing internal slug. Theme
Template editors manage this through **Public path**; assignment-only partials
leave it empty.

- Paths use lowercase ASCII slug segments such as `academic/biomedical`.
- One canonical path is allowed per content record and locale.
- Changing a canonical path retains the previous path as a permanent redirect.
- Internal slug requests redirect to the canonical public path.
- Collection, authentication, plugin, physical, and other reserved routes cannot
  be claimed by content.
- Only published routed Theme Templates appear in the Theme sitemap and Menu
  Manager source list.

Core helpers are defined in `cfg/helpers/content_route_helpers.php`. Use
`content_route_set_canonical()` rather than writing route rows directly so path
history, collision checks, and transaction behavior remain consistent.

## Defaults and Load Default Layout

The `defaults` block pre-fills gadgets when the admin clicks **Load Default Layout**. This is useful for:

- Shipping a theme with a sensible out-of-the-box layout.
- Resetting a layout to the theme author's intent.

Format for a single gadget:

```json
"defaults": {
  "position_slug": {
    "type": "tz_html",
    "title": "",
    "config": { "html": "..." }
  }
}
```

Format for multiple gadgets in the same position:

```json
"defaults": {
  "position_slug": [
    { "type": "tz_html", "title": "", "config": { "html": "..." } },
    { "type": "tz_search", "title": "", "config": {} }
  ]
}
```

Gadgets are created as active rows in `theme_zone_items` scoped to the current theme (`theme_folder`).

## Post-aware gadgets

Three gadgets depend on `$GLOBALS['jy_current_post']` being set:

- `tz_post_author`
- `tz_post_meta`
- `tz_post_contact`

Set the global before rendering single post/page zones:

```php
<?php $GLOBALS['jy_current_post'] = $post; ?>
<?= theme_zone_render_position($pdo, 'single.post', 'after_content') ?>
```

## Custom gadgets

Register custom gadgets with filters so they appear in the admin dropdown and render on the frontend:

```php
add_filter('theme_zone_widget_types', function(array $types): array {
    $types['tz_hello'] = [
        'label' => __('Hello World'),
        'desc'  => __('Simple hello gadget.'),
        'default_config' => ['message' => 'Hello'],
    ];
    return $types;
});

add_filter('theme_zone_render_widget', function(string $html, string $type, array $config, PDO $pdo): string {
    if ($type !== 'tz_hello') return $html;
    $msg = htmlspecialchars((string)($config['message'] ?? 'Hello'), ENT_QUOTES, 'UTF-8');
    return '<p class="tz-hello">' . $msg . '</p>';
}, 10, 5);
```

## Builder integration

Builder plugins can extend Customize without modifying core.

### Register a custom gadget

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

### Render a custom gadget

```php
add_filter('theme_zone_render_widget', function(string $html, string $type, array $config, PDO $pdo): string {
    if ($type !== 'tz_my_block') return $html;
    return '<div class="tz-my-block">' . htmlspecialchars((string)($config['foo'] ?? '')) . '</div>';
}, 10, 5);
```

### Preview zones via PHP helpers

Builder plugins running inside the dashboard should render zones directly with PHP helpers (no public HTTP endpoint):

```php
// Render a whole zone or a single position
$html = theme_zone_render($pdo, 'header');
$html = theme_zone_render_position($pdo, 'single.post', 'after_content');

// Set post context for post-aware gadgets
$GLOBALS['jy_current_post'] = $post;
$html = theme_zone_render_position($pdo, 'single.post', 'after_content');
```

### PHP helpers

```php
// Render a whole zone or a single position
theme_zone_render($pdo, 'header');
theme_zone_render_position($pdo, 'single.post', 'after_content');

// Check if a position has gadgets
theme_zone_has_position($pdo, 'header', 'logo');

// Render a single gadget type by config
theme_zone_render_widget($pdo, 'tz_html', ['html' => '<p>Hello</p>']);
```

## Testing

Run Playwright tests after changing theme structure or `theme.json`:

```bash
cd tests/playwright
npm install
npm run user:create
npx playwright test
```

Key regression areas:

- Customize page loads without errors.
- Drag-and-drop between positions updates ordering.
- Add/configure/delete gadgets for each built-in type.
- **Load Default Layout** creates expected gadgets from `theme.json`.
- Switching themes does not leak gadgets from the previous theme.

## See also

- `AGENTS.md` → Theme Zones section for architecture details.
- `public/views/themes/default/theme.json` for a canonical production example.
- `cfg/helpers/theme_zones.php` for all rendering helpers.
