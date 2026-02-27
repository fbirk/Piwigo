# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Piwigo is an open-source PHP photo gallery application (v17.0.0beta1). No framework — custom procedural PHP with selective OOP. No build system, no composer, no package manager.

**Requirements**: PHP 7.4+, MySQL 5+ / MariaDB, Apache or nginx, ImageMagick or PHP GD.

## Testing

There is no formal test framework (no PHPUnit, no CI/CD). The only test script is:
```bash
php tools/test_piwigo.php --url "localhost/piwigo" --db_user "root" --db_password "pass" --db_name "testdb" --file "picture.png"
```
This creates a test database, installs Piwigo, and runs basic functional tests (login, album creation, upload).

## Contributing Conventions

- Branch naming: `issue-<id>-<short-summary>`
- Commit messages: prefix with `issue #<id>` or `fixes #<id>`
- PRs go to `master` branch

## Architecture

### Request Flow

Every page controller (e.g. `index.php`, `picture.php`, `admin.php`) includes `include/common.inc.php` which:
1. Sanitizes `$_GET`, `$_POST`, `$_COOKIE`
2. Loads config (`include/config_default.inc.php` → `local/config/config.inc.php`)
3. Connects to DB via `local/config/database.inc.php`
4. Loads DB-stored config with `load_conf_from_db()`
5. Starts session, resolves user
6. Loads plugins and fires `trigger_notify('init')`

URL routing is handled by `include/section_init.inc.php` which parses `PATH_INFO` into `$page['section']`, `$page['category']`, `$page['image_id']`, etc.

### Key Entry Points

| File | Purpose |
|------|---------|
| `index.php` | Gallery homepage / category browsing |
| `picture.php` | Single photo view |
| `admin.php` | Admin panel entry (routes to `admin/` pages) |
| `ws.php` | Web services API |
| `action.php` | File serving (images, derivatives) |
| `identification.php` | Login/logout |
| `i.php` | On-the-fly image derivative generation |

### Global State

The codebase relies heavily on global variables initialized in `common.inc.php`:

- **`$conf`** — All configuration (file-based + DB-based merged)
- **`$user`** — Current authenticated user (id, username, theme, language, permissions)
- **`$page`** — Page context (section, category, errors/infos/warnings arrays, body_classes)
- **`$lang`** — Localization strings
- **`$filter`** — Image filtering context

### Configuration System

Two-tier: file-based defaults in `include/config_default.inc.php` (overridden by `local/config/config.inc.php`), plus DB-stored settings in `pwg_config` table (loaded by `load_conf_from_db()`, updated with `conf_update_param()`).

### Database Layer

Direct SQL via `include/dblayer/functions_mysqli.inc.php`. No ORM, no prepared statements.

```php
$result = pwg_query("SELECT * FROM ".IMAGES_TABLE." WHERE id = ".(int)$id);
$row = pwg_db_fetch_assoc($result);
```

Key functions: `pwg_query()`, `pwg_db_fetch_assoc()`, `pwg_db_fetch_row()`, `pwg_db_num_rows()`, `pwg_db_insert_id()`, `pwg_db_real_escape_string()`.

Table names are constants defined in `include/constants.php` (e.g. `IMAGES_TABLE`, `CATEGORIES_TABLE`, `USERS_TABLE`). They use the `$prefixeTable` prefix.

### Plugin System

Event-driven architecture in `include/functions_plugins.inc.php`:
- `add_event_handler($event, $func, $priority, $include_path)` — Register handler
- `trigger_notify($event)` — Fire event, no return value
- `trigger_change($event, $data)` — Fire event, data flows through handlers and is returned

Plugin lifecycle via `PluginMaintain` class: `install()`, `activate()`, `deactivate()`, `uninstall()`, `update()`.

### Web Services API

Methods registered in `ws.php` and implemented in `include/ws_functions/`. Registration pattern:
```php
$service->addMethod(
  'pwg.images.getInfo',
  'ws_images_getInfo',
  array('image_id' => array('type' => WS_TYPE_ID)),
  'Returns info for an image',
  $ws_functions_root.'pwg.images.php',
  array('admin_only' => false)
);
```

Parameter types: `WS_TYPE_BOOL`, `WS_TYPE_INT`, `WS_TYPE_FLOAT`, `WS_TYPE_ID`, `WS_TYPE_POSITIVE`, `WS_TYPE_NOTNULL`. Flags: `WS_PARAM_OPTIONAL`, `WS_PARAM_ACCEPT_ARRAY`, `WS_PARAM_FORCE_ARRAY`.

Response formats: JSON (default), REST, PHP serialized, XML-RPC. Core classes: `PwgServer`, `PwgError`, `PwgNamedArray`, `PwgNamedStruct`.

### Template System

Smarty-based, wrapped in `include/template.class.php`. Frontend themes in `themes/`, admin theme in `admin/themes/`. Default theme: `modus`.

### Localization

```php
load_language('common.lang');        // Load language file
l10n('key')                          // Translate string
l10n_dec('singular', 'plural', $n)   // Plural translation
```

Language files in `language/<locale>/` (e.g. `en_UK`, `fr_FR`). 40+ languages supported.

### Access Control

```php
check_status(ACCESS_ADMINISTRATOR);  // Require admin access
is_admin();                          // Check if admin
is_a_guest();                        // Check if guest
```

Levels: `ACCESS_FREE` (0) → `ACCESS_GUEST` (1) → `ACCESS_CLASSIC` (2) → `ACCESS_ADMINISTRATOR` (3) → `ACCESS_WEBMASTER` (4) → `ACCESS_CLOSED` (5).

### Authentication

Resolved in `include/user.inc.php`. Methods: session cookie (`$_SESSION['pwg_uid']`), auto-login (persistent cookie), Apache auth (`REMOTE_USER`), auth key (URL param `?auth=` or header `X-Piwigo-API`).
