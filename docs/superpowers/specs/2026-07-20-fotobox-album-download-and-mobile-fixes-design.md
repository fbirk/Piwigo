# Fotobox theme: album ZIP download + mobile/menubar fixes

Date: 2026-07-20
Branch: `feature/fotobox-styling`

## Context

The live site (`fabiansfotoboxverleih.de`) runs the Piwigo `modus` theme, restyled to
look like the Fotobox homepage. `modus` is hard-wired active and cannot be overridden by a
child theme, so the Fotobox styling is applied as **override files** that overlay the
server's base `modus` install (only the override files are tracked in git):

- `themes/modus/template/header.tpl`
- `themes/modus/template/mainpage_categories.tpl`
- `themes/modus/css/fotobox-overrides.css` (loaded last in `header.tpl`, so it wins)

Albums can be password-protected ("password-only albums" feature). Access is gated by
`unlocked_albums` in the session. At bootstrap, `include/common.inc.php` turns that into
`$user['forbidden_categories']` — the same list that hides locked albums from the gallery.
Any new server endpoint that includes `common.inc.php` inherits this gate for free.

`section_init.inc.php` adds `category-<id>` to `$page['body_classes']` and
`category_id` to `$page['body_data']` on album pages, so the current album id is readable
client-side from `document.body` (class `category-<id>`) — no core change needed.

Photos are stored as originals. The `images` table has `path` (relative path to the
original), `file` (filename), and `filesize` (in **KB**, `mediumint unsigned`).

## Goals

Three independent changes to the Fotobox/modus theme:

1. **Album ZIP download** — a button inside an unlocked album that downloads every
   accessible photo (album + sub-albums) as a ZIP of full-resolution originals, after a
   styled confirmation showing the photo count and estimated size.
2. **Mobile fix** — long album names no longer overflow to the right on the start screen.
3. **Remove the "Startseite" / calendar bar** (`#menubar`) entirely.

## Non-goals

- No streaming-ZIP dependency (kept as a noted limitation; see below).
- No admin UI, no per-album on/off toggle — the button appears on every album page the
  user can access.
- No changes to the base `modus` theme on the server beyond the tracked override files.

---

## Change 3 — Remove the `#menubar` bar

The white "Startseite" bar with the calendar is Piwigo's `#menubar` (`Menu` block with the
Home link + calendar block), rendered as `<div id="menubar">…</div><div id="menuSwitcher"></div>`
(see `themes/default/template/menubar.tpl`).

**Implementation:** in `fotobox-overrides.css`:

```css
#menubar, #menuSwitcher { display: none !important; }
```

No template change. Content lives in `#content`, unaffected. Applies to all devices.

---

## Change 2 — Mobile album-name overflow

On the start screen `.fotobox-album__name` does not wrap, so long names (e.g.
`2026_Hochzeit_…`) spill past the right edge on narrow viewports.

**Implementation:** in `fotobox-overrides.css`, adjust the flex children of
`.fotobox-album__link` so the name can shrink and wrap while the count stays intact:

```css
.fotobox-album__name  { flex: 1 1 auto; min-width: 0; overflow-wrap: anywhere; }
.fotobox-album__count { flex: 0 0 auto; white-space: nowrap; }

@media (max-width: 480px) {
  ul.fotobox-albums { padding-left: 1rem; padding-right: 1rem; }
}
```

`min-width: 0` is required so the flex item is allowed to shrink below its content width;
`overflow-wrap: anywhere` breaks long tokens that have no spaces (underscored names).

---

## Change 1 — Album ZIP download

### 1a. Server endpoint: `album_download.php` (new, repo root)

Bootstraps Piwigo like `album_password.php`:

```php
define('PHPWG_ROOT_PATH','./');
include_once(PHPWG_ROOT_PATH.'include/common.inc.php');
check_status(ACCESS_FREE);
```

Reads `cat_id` (int) and `action` (`estimate` | `download`).

**Access guard (both actions):**

- Missing/non-numeric `cat_id` → HTTP 400 (estimate) / redirect to index (download).
- Load `get_cat_info($cat_id)`; if empty → 404 / redirect.
- If **not** admin and `cat_id` is in `explode(',', $user['forbidden_categories'])`
  → HTTP 403 (estimate) / redirect to `album_password.php?cat_id=…` (download).
  This mirrors the view gate exactly: a locked album is forbidden until unlocked.

**Image gathering (shared helper in the endpoint):**

```php
$cat_ids = array_merge(array($cat_id), get_subcat_ids(array($cat_id)));
```

Query `IMAGES_TABLE i` JOIN `IMAGE_CATEGORY_TABLE ic` on `ic.image_id = i.id`,
`ic.category_id IN (<cat_ids>)`, plus the standard visibility condition:

```php
get_sql_condition_FandF(
  array(
    'forbidden_categories' => 'ic.category_id',
    'visible_categories'   => 'ic.category_id',
    'visible_images'       => 'i.id',
  ),
  'AND'
)
```

This automatically drops images that live only in locked sub-albums. Select
`i.id, i.path, i.file, i.filesize` and, for folder naming, the category id per row.
De-duplicate by `i.id` in PHP (an image can appear in several categories) — keep the
first category encountered for its zip folder.

**`action=estimate`** → `Content-Type: application/json`:

```json
{ "count": 142, "bytes": 3650722201, "human": "3,4 GB" }
```

`bytes` = `SUM(filesize) * 1024` over the de-duplicated set. `human` formatted server-side
in German (comma decimal, e.g. `3,4 GB`) via a small `format_bytes()` helper local to the
endpoint. Count is `COUNT(DISTINCT i.id)`.

**`action=download`** → streams a ZIP:

- `set_time_limit(0);` and, best-effort, `@ignore_user_abort(false)`.
- Create a temp file: `tempnam(sys_get_temp_dir(), 'pwgzip_')`.
- `$zip = new ZipArchive(); $zip->open($tmp, ZipArchive::OVERWRITE);`
- For each accessible image, resolve absolute path from `i.path`
  (`PHPWG_ROOT_PATH . path`), skip if the file is missing.
  - Local name inside the zip: `<subfolders>/<file>` where `<subfolders>` is the album
    name path **below** the opened album, derived from the image category's `uppercats`
    names (images directly in the opened album go to the zip root). Sanitize each path
    segment (strip `/`, `\`, control chars).
    - On local-name collision, append ` (2)`, ` (3)`, … before the extension.
  - `$zip->addFile($absPath, $localName);`
    then `$zip->setCompressionName($localName, ZipArchive::CM_STORE);` (no compression —
    JPEGs are already compressed; keeps it fast and makes the size estimate accurate).
- `$zip->close();`
- Send headers: `Content-Type: application/zip`,
  `Content-Disposition: attachment; filename="<sanitized album name>.zip"`,
  `Content-Length: <filesize of temp zip>`, plus no-cache headers.
- Stream the temp file to the client in chunks (`fopen`/`fread` ~8 MB, `echo`, `flush`),
  then `unlink($tmp)` in a `register_shutdown_function` / `finally` so it is removed even
  on early abort.
- If zero accessible images → redirect back to the album with an info message
  (no empty zip).

**Zip filename:** sanitized opened-album name (`$category['name']`), falling back to
`album-<id>` if empty, suffixed `.zip`.

### 1b. Button + confirmation modal (in `themes/modus/template/header.tpl`)

`header.tpl` is already an owned override. Append, after the header `<div>`:

- A small inline `<script>` that runs on `DOMContentLoaded`:
  1. Return unless `document.body.id === 'theCategoryPage'`.
  2. Read the album id from the `category-<id>` class on `document.body`
     (regex `/(?:^|\s)category-(\d+)(?:\s|$)/`). Return if none.
  3. Insert a bronze button **"Album herunterladen"** at the top of `#content`
     (fallback: prepend to `document.body`).
  4. Build a hidden modal (dark-slate panel, cream text) appended to `<body>`.
  5. On button click: `fetch('album_download.php?action=estimate&cat_id='+id)`;
     on success show the modal with
     *"{count} Fotos herunterladen (~{human})?"* and **Herunterladen** / **Abbrechen**.
     On error, fall back to a native `confirm()` with a generic message.
  6. **Herunterladen** → `window.location = 'album_download.php?action=download&cat_id='+id`
     (browser handles the attachment download; the gallery page stays put).
     Disable the button + show a brief "wird vorbereitet…" state.
- All user-facing strings are German literals in the script (site is German-only). No
  language-file entry required, matching how the existing Fotobox overrides hardcode
  German where practical. (If preferred during implementation, they can move to
  `themes/modus/template` via `{'…'|@translate}` — decided at plan time; default: inline.)

### 1c. Modal + button styling (in `fotobox-overrides.css`)

Reuse the existing CSS variables (`--fb-primary-darker`, `--fb-secondary`,
`--fb-highlight`). Add:

- `.fotobox-download-btn` — bronze background / dark text, rounded, hover lighten,
  block on mobile, centered, with top/bottom margin; sits above the thumbnails.
- `.fotobox-modal` (fixed full-screen overlay, dark translucent backdrop, flex-centered,
  `display:none` by default; `.is-open` shows it) and `.fotobox-modal__panel`
  (dark-slate, cream text, max-width ~420px, padding, rounded).
- `.fotobox-modal__actions` with primary (bronze) and secondary (outline) buttons.

---

## Files touched

| File | Change |
|------|--------|
| `album_download.php` | **new** — estimate + download endpoint |
| `themes/modus/template/header.tpl` | append button/modal bootstrap `<script>` |
| `themes/modus/css/fotobox-overrides.css` | menubar hide, mobile name wrap, button + modal styles |

## Testing

No formal test framework exists (per `CLAUDE.md`). Manual verification checklist:

1. **Menubar gone** — album list and an album page show no "Startseite"/calendar bar,
   desktop and mobile.
2. **Mobile names** — on a ~390px viewport, a long album name wraps inside the card and
   the lock icon + count stay aligned; no horizontal overflow.
3. **Download gate** — hitting `album_download.php?action=download&cat_id=<locked>` without
   unlocking redirects to the password prompt; with an unlocked session it downloads.
4. **Estimate** — button shows the modal with a plausible count and human size that
   matches the sum of the album's photo sizes.
5. **ZIP correctness** — downloaded zip opens, contains all accessible originals,
   sub-albums appear as folders, no duplicate-name clobbering, locked sub-albums excluded.
6. **Empty album** — redirects with an info message rather than an empty zip.
7. **Cache** — after deploying template/CSS changes, purge Piwigo cache
   (Admin → Maintenance) so `_data/templates_c` + `_data/combined` are regenerated.

## Known limitations

- The ZIP is built to a temp file and streamed in a single request: needs free disk ≈
  archive size and relies on `set_time_limit(0)` plus adequate PHP/webserver timeouts for
  multi-GB albums. A streaming-ZIP library (e.g. ZipStream) would remove the disk
  requirement but needs a vendored dependency — deliberately out of scope.
- No resumable downloads / range requests.
