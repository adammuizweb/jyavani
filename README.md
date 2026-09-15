# Jyavani CMS

[![PHP 8.1+](https://img.shields.io/badge/PHP-8.1%2B-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![MySQL 5.7+](https://img.shields.io/badge/MySQL-5.7%2B-4479A1?logo=mysql&logoColor=white)](https://www.mysql.com/)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)

Jyavani is a lightweight, extensible content management system written in native PHP. It provides an editorial dashboard, flexible content routing, private media, themes, visual layout zones, and removable plugins without requiring a PHP framework, Composer, or a frontend build pipeline.

The current release and platform requirements are the source of truth in [`VERSION`](VERSION) and [`version.json`](version.json).

## Highlights

- Articles, pages, reusable theme templates, drafts, published and private content, hierarchical categories, archives, search, authors, and XML sitemaps.
- Adiwira editorial dashboard with database-backed roles, action-specific permissions and scopes, a separately protected Site Owner identity, configurable authentication/admin paths, CSRF protection, session hardening, and login throttling. The legacy `author`, `editor`, and `admin` values remain compatibility identities rather than the authorization policy.
- Media and file management with protected storage outside the web root and signed, time-limited access for private PDFs.
- Slot-based themes with fallback resolution, customizer fields, menus, sidebars, widgets, shortcodes, and drag-and-drop Theme Zones.
- Canonical nested content routes, redirect history, configurable collection paths, and custom permalink support.
- UI internationalization for English, Indonesian, and German, with separate dashboard and default-content locale settings.
- Plugin and theme upload, activation, dependency checks, store discovery, update checks, and integrity-aware update flows.
- Site Health verifies Store plugin and theme package files against canonical exact-version HTTPS release manifests. A deployment may optionally trust otherwise local extensions through one environment-pinned signed manifest.
- Optional PWA, offline, web-manifest, and browser-push behavior can be supplied by plugins. These are not core Jyavani CMS features.

## Requirements

- PHP 8.1 or newer with PDO MySQL, `mbstring`, and `zip`/`ZipArchive`.
- MySQL 5.7 or newer, or a compatible MariaDB release, using `utf8mb4`.
- nginx, Apache with `mod_rewrite`, or another server that routes non-static requests to `public/router.php`.
- HTTPS for production deployments.
- Write access for the PHP process to runtime, upload, and update targets. Use owner/group permissions rather than world-writable modes.

Outbound HTTPS access is needed for Store browsing, release-manifest verification, and remote updates. The `curl` extension is used when available, with the bounded PHP stream transport as fallback. The `pcntl` extension is only needed by one local contract test.

Site Health trusts canonical Store release hashes only for the exact installed version. As an optional deployment-owned alternative for extensions without canonical Store metadata, `SITE_HEALTH_DEPLOYMENT_MANIFEST_PATH` may name one absolute private envelope path, `SITE_HEALTH_DEPLOYMENT_PUBLIC_KEY` may pin its strict-base64 raw 32-byte Ed25519 public key, and `SITE_HEALTH_DEPLOYMENT_SOURCE_REVISION` must identify the exact deployed 40-lowercase-hex revision. Configure all three or none. The private key never belongs on the CMS host. The schema-1 envelope signs canonical payload bytes containing that source revision and sorted plugin/theme file maps; mismatched revisions, invalid configuration, signatures, schemas, paths, or unavailable sodium support remain `unverified`. Canonical Store metadata always retains precedence, including when its slug, version, or release baseline is invalid.

Extension release identity is file-only: every regular package file must appear in the manifest, but ordinary empty directories are ignored because the release contract has no directory map. Updater-preserved `.store.json` is outside package identity and is ignored only when it is a bounded, readable regular file containing a JSON object; it never establishes trust. Unsafe or invalid metadata prevents a clean result. `.git` is always prohibited from a clean Store tree, including an empty `.git` directory.

Plugins may declare up to eight runtime-generated public image directories under their own `static/plugins/{folder}/` namespace. Those generated JPEG, PNG, GIF, WebP, or AVIF files are bounded and verified by regular-file, executable-name, extension, size, and MIME rules rather than immutable release hashes. The declaration never relaxes exact verification for packaged plugin code or copied static assets.

For a Site Owner with settings access, opening the dashboard asynchronously refreshes a missing, different-Core-version, or older-than-one-hour Site Health report. The browser trigger is throttled, while the server rechecks freshness under the nonblocking scan lock before doing work. Manual full scans remain available for immediate verification.

The Site Health dashboard widget is required for authorized Site Owners. It is included in new and migrated layouts, cannot be hidden by the widget arranger, and labels non-clean Core counts as integrity findings rather than vulnerabilities.

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

Non-production environments can enable Core's pre-bootstrap access gate with `DEV_LOCK_ENABLED=1` and an environment-owned `DEV_LOCK_PASSWORD_HASH`. Generate the hash offline with PHP's `password_hash()` and keep both the password and hash out of Git. Locked requests return HTTP 503 with no-store and `noindex` directives before database or plugin bootstrap; production should leave the feature disabled.

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
tests/           Standalone PHP contract tests
tools/           i18n checks, manifest generation, packaging, and permissions tools
```

## Extending Jyavani

### Plugins

A plugin lives in `plugins/{slug}/`, declares metadata and requirements in `plugin.json`, and may provide a `plugin.php` entrypoint. Active plugins can register actions and filters, frontend routes, shortcodes, dashboard pages/navigation, CSS or JavaScript assets, and Theme Zone gadgets. Requirements can constrain Jyavani, PHP, PHP extensions, and other plugins; dependency entrypoints are loaded in dependency order. Plugins may declare owned `permissions[]` and bind an admin page to an unscoped declared `permission`; `roles` on that page only seed compatibility defaults. A page may instead require `site_owner`, but cannot combine both guards.

Frontend routes use `register_frontend_route($path, $handler, $options)`. Register routes directly while `plugin.php` loads or from `plugins_loaded`; Core seals the registry afterward so frontend dispatch and dashboard content-route validation share the same definitions. Existing two-argument calls register a prefix route for every HTTP method. Optional `match` (`prefix` or `exact`), `methods`, and integer `priority` keys support exact root endpoints, method constraints, and deterministic ordering. Site routes and the Core service worker retain precedence; an exact root plugin route intentionally replaces the Core homepage, while other managed Core routes retain their existing precedence. Repeating an identical registration is safe, but a later conflicting registration no longer replaces the first route and is rejected with a diagnostic.

The hook API is defined in [`cfg/helpers/hooks.php`](cfg/helpers/hooks.php): `add_action()`, `do_action()`, `add_filter()`, `apply_filters()`, `remove_action()`, and `remove_filter()`. Plugin packages may declare controlled static asset copies. Installation scripts use a fixed `install.sh` convention with bounded runtime and output rather than manifest-provided shell commands.

Store plugins may ship append-only database migrations in `migrations/`, using exactly four positive digits such as `0001-create-tables.sql` or `0002-seed.php`. New files must sort after every applied sequence; skipped numbers cannot be backfilled later. SQL files contain ordinary semicolon-terminated statements; PHP files must return a `Closure` that accepts `PDO`. Core discovers this fixed directory automatically during install, update, and activation. Migration commands and paths are never read from `plugin.json`.

Applied filenames, content, and original SHA-256 ledger values are immutable. Never edit, rename, or remove an applied migration. Preflight accepts exact bytes or an otherwise identical whole-file LF/CRLF checkout conversion; mixed line endings, lone carriage returns, and every content change fail closed without rewriting the ledger. SQL migration files reject raw backslashes and executable-comment tokens even when they occur inside literals or ordinary comments, plus `DELIMITER`, `SET`, transaction/XA control, table locks, and `USE`; use PHP migrations when carefully bounded dynamic behavior is unavoidable. Core rehashes the complete migration tree after each file executes and before ledger insertion. MySQL and MariaDB DDL can commit implicitly, so migrations must be idempotent, forward-only, and compatible with the previous plugin version. Core revalidates history after `install.sh`. On failure Core stops immediately and keeps the plugin inactive, but database changes may remain.

Keep-data uninstall retains migration history. Complete uninstall is available only while the plugin is active and its entrypoint has loaded successfully in the current request, ensuring its existing `plugin_uninstall` hooks are registered. Disabled plugins must be activated first or removed with data retained. Recovery artifacts block the operation before ledger clearing. Core marks the plugin inactive under lock, clears history, then verifies and recovers transaction/autocommit state after every isolated cleanup hook. Listener errors leave the plugin installed and inactive with history cleared, ensuring activation or reinstall cannot silently skip schema setup. Final file deletion first moves the complete tree to a same-parent non-discoverable recovery path, so partial recursive cleanup cannot expose a broken plugin. An optional disposable-MySQL contract can be run with `JY_TEST_MYSQL_DSN`, `JY_TEST_MYSQL_USER`, and `JY_TEST_MYSQL_PASSWORD`.

### Mail API

Feature plugins send email through Core rather than depending on an SMTP plugin:

```php
$result = jy_mail_send($pdo, [
    'to' => ['person@example.com'],
    'subject' => 'Notification',
    'body' => 'The operation completed.',
    'content_type' => 'text/plain',
]);
```

Core validates structured recipients and sender identity, rejects caller-provided raw headers, selects the configured transport, and returns a redacted result. The built-in `native` transport delegates to PHP `mail()` and the hosting mail infrastructure. An SMTP or provider plugin can register an optional transport with `jy_mail_register_transport()`; callers continue using `jy_mail_send()` and do not depend on that plugin directly. Outgoing identity, transport selection, redacted logging, and test delivery are managed under **Settings > Email** by the Site Owner.

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
