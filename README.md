# Jyavani CMS

[![PHP 8.1+](https://img.shields.io/badge/PHP-8.1%2B-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![MySQL 5.7+](https://img.shields.io/badge/MySQL-5.7%2B-4479A1?logo=mysql&logoColor=white)](https://www.mysql.com/)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)

Jyavani is a lightweight, extensible content management system written in native PHP. It provides an editorial dashboard, flexible content routing, private media, themes, visual layout zones, and removable plugins without requiring a PHP framework, Composer, or a frontend build pipeline.

The current release and platform requirements are the source of truth in [`VERSION`](VERSION) and [`version.json`](version.json).

## Highlights

- Articles, pages, reusable theme templates, drafts, published and private content, hierarchical categories, archives, search, authors, and XML sitemaps.
- Adiwira editorial dashboard with `author`, `editor`, and `admin` roles, configurable login, registration, and admin paths, CSRF protection, session hardening, and login throttling.
- Media and file management with protected storage outside the web root and signed, time-limited access for private PDFs.
- Slot-based themes with fallback resolution, customizer fields, menus, sidebars, widgets, shortcodes, and drag-and-drop Theme Zones.
- Canonical nested content routes, redirect history, configurable collection paths, and custom permalink support.
- UI internationalization for English, Indonesian, and German, with separate dashboard and default-content locale settings.
- Plugin and theme upload, activation, dependency checks, store discovery, update checks, and integrity-aware update flows.
- Optional PWA, offline, web-manifest, and browser-push behavior can be supplied by plugins. These are not core Jyavani CMS features.

## Requirements

- PHP 8.1 or newer with PDO MySQL, `mbstring`, and `zip`/`ZipArchive`.
- MySQL 5.7 or newer, or a compatible MariaDB release, using `utf8mb4`.
- nginx, Apache with `mod_rewrite`, or another server that routes non-static requests to `public/router.php`.
- HTTPS for production deployments.
- Write access for the PHP process to runtime, upload, and update targets. Use owner/group permissions rather than world-writable modes.

Outbound HTTPS access and PHP URL streams are needed for store browsing and remote updates. The `curl` extension is used when available for plugin package downloads. The `pcntl` extension is only needed by one local contract test.

## Installation

For production, start from a tagged release or a clean checkout:

```bash
git clone https://github.com/adammuizweb/jyavani.git
cd jyavani
```

1. Configure the virtual host document root as the repository's `public/` directory. Never expose the repository root, `cfg/`, `dashboard/`, or `private_files/` directly.
2. Configure rewrite/fallback handling. Working nginx and environment examples are documented in [`SERVER_SETUP.md`](SERVER_SETUP.md); Apache rules are included in [`public/.htaccess`](public/.htaccess).
3. Give the PHP process controlled write access to `cfg/`, `cfg/var/`, `private_files/`, and upload destinations. Core files must also be writable if dashboard-managed updates will be used.
4. Open `/pondasi/` and complete the one-time database, site, and administrator setup. The installer creates `cfg/.env`, generates secrets, loads the schema, and seeds dashboard translations.
5. Remove `public/pondasi/` after installation and verify HTTPS/session settings.

Do not commit `cfg/.env`, credentials, generated secrets, private uploads, sessions, or runtime backups. For manual deployments, [`cfg/env-sample`](cfg/env-sample) documents supported settings without usable secrets.

For local evaluation only, PHP's development server can use the same front controller:

```bash
php -S 127.0.0.1:8080 -t public public/router.php
```

Then visit `http://127.0.0.1:8080/pondasi/`.

## Architecture

Jyavani uses plain PHP entrypoints and PDO. A request enters `public/router.php`, loads core configuration and helpers, initializes the active theme and plugins, and dispatches to static controllers in `app/controllers/`. The dashboard PHP remains outside the public web root and is reached only through its configured router path.

```text
app/             Application bootstrap, layout, and frontend controllers
cfg/             Environment loading, database/session setup, and helpers
dashboard/       Adiwira dashboard pages and authentication entrypoints
plugins/         Plugin registry and installed plugin directories
private_files/   Protected media and files outside the web root
public/          The only web root: router, public entrypoints, assets, and themes
schema/          Fresh-install schema, translation seeds, and migrations
tests/           Standalone PHP contract tests and browser checks
tools/           i18n checks, manifest generation, packaging, and permissions tools
```

## Extending Jyavani

### Plugins

A plugin lives in `plugins/{slug}/`, declares metadata and requirements in `plugin.json`, and may provide a `plugin.php` entrypoint. Active plugins can register actions and filters, frontend routes, shortcodes, dashboard pages/navigation, CSS or JavaScript assets, and Theme Zone gadgets. Requirements can constrain Jyavani, PHP, PHP extensions, and other plugins; dependency entrypoints are loaded in dependency order.

The hook API is defined in [`cfg/helpers/hooks.php`](cfg/helpers/hooks.php): `add_action()`, `do_action()`, `add_filter()`, `apply_filters()`, `remove_action()`, and `remove_filter()`. Plugin packages may declare controlled static asset copies. Installation scripts use a fixed `install.sh` convention with bounded runtime and output rather than manifest-provided shell commands.

### Themes

Themes live in `public/views/themes/{folder}/` and are described by `theme.json`. Templates map to slots such as `header`, `footer`, `main.homepage`, `list.post`, and `single.post`; resolution falls back from an assigned theme to the active theme and then the default theme.

Themes may declare:

- Context-aware styles, scripts, core asset dependencies, and preloads.
- Customizer sections and fields stored independently per theme.
- Layout zones, positions, and default gadgets for the visual Customize editor.
- Store metadata for folder-keyed update discovery.

Use `theme_zone_has_position()` and `theme_zone_render_position()` with fallback markup so templates remain usable before a layout is configured. See the [Theme Authoring Guide](cms.md) and the production [`default/theme.json`](public/views/themes/default/theme.json).

## Development And Tests

There is no Composer install or asset compilation step. Run commands from the repository root.

```bash
# Syntax-check tracked PHP files
git ls-files '*.php' -z | xargs -0 -n1 php -l

# Run the standalone PHP contract suite
for test in tests/*_contract.php; do php "$test" || exit 1; done

# Review likely untranslated dashboard strings
php tools/check-dashboard-i18n.php
```

The scripts in `tests/playwright/` are targeted browser diagnostics with deployment-specific URLs, not a packaged test suite. Install Playwright separately and configure their target URL before running them.

When adding dashboard text, use `__()` or `_e()` and update `schema/translations.sql`. Follow the existing strict-types, static-controller, PDO, plain-template, and escaping conventions described in [`AGENTS.md`](AGENTS.md).

## Updates And Releases

Back up the database and site-specific files before every update, test on a staging copy, and keep `cfg/.env`, `cfg/var/`, `private_files/`, uploaded public media, installed plugins, and installed themes out of replacement operations.

The dashboard updater validates manifest paths and SHA-256 hashes before changing files, rejects duplicate or symbolic-link archive entries, creates timestamped backups under `cfg/var/backup-*`, preserves site-owned paths, and attempts rollback if an apply operation fails. These controls complement, rather than replace, an independent deployment backup.

Maintainers should keep `VERSION`, `version.json`, and the generated manifest version aligned, run the contract suite, and build release candidates with:

```bash
php tools/build-package.php /path/to/candidate.zip
```

The builder regenerates `tools/cms-manifest.json`, verifies package hashes and entry counts, and publishes the output atomically. `php tools/generate-manifest.php` only regenerates the manifest. Do not publish from a dirty or unreviewed tree, and verify the final download metadata and checksum before announcing a release.

## Security

Please do not disclose suspected vulnerabilities in a public issue. Report them privately through [GitHub Security Advisories](https://github.com/adammuizweb/jyavani/security/advisories/new) with affected versions, reproduction steps, impact, and any suggested mitigation. General bugs belong in the [issue tracker](https://github.com/adammuizweb/jyavani/issues).

Deploy with HTTPS, `APP_DEBUG=0`, a non-public project root, restrictive file permissions, and unique session/private-file secrets. Review third-party plugin and theme code before installation; extensions execute within the PHP application process.

## Contributing

Issues and pull requests are welcome. Before submitting a change, keep it focused, run relevant contract tests and PHP linting, add or update regression coverage, preserve public extension contracts, and include translations for new dashboard strings. Use the [issue tracker](https://github.com/adammuizweb/jyavani/issues) for proposals and the [pull request page](https://github.com/adammuizweb/jyavani/pulls) for code contributions.

## License

Jyavani CMS is released under the [MIT License](LICENSE). Copyright (c) 2026 Adam Muiz.

## Links

- [Website](https://jyavani.com)
- [Source repository](https://github.com/adammuizweb/jyavani)
- [Releases](https://github.com/adammuizweb/jyavani/releases)
- [Issues](https://github.com/adammuizweb/jyavani/issues)
- [Server setup](SERVER_SETUP.md)
- [Theme authoring](cms.md)
