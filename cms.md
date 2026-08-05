# Theme Authoring Guide for Jyavani CMS Customize

This document is a companion to `AGENTS.md` for theme developers who want to support the visual **Customize** editor (`admin/themes/customize`).

## Quick start

A theme that supports Customize needs three things:

1. A `theme.json` manifest with a `layout` block.
2. Template files that call `theme_zone_render_position()` with fallback HTML.
3. Optionally, `layout.defaults` gadgets that pre-fill the layout when the user clicks **Load Default Layout**.

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
