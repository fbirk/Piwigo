# Fotobox Album ZIP Download + Mobile/Menubar Fixes — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a "download whole album as ZIP" button (with a styled size-confirmation modal) inside unlocked Fotobox albums, and fix two theme issues — mobile album-name overflow and the unwanted `#menubar` "Startseite"/calendar bar.

**Architecture:** A new PHP endpoint `album_download.php` bootstraps Piwigo via `common.inc.php` (inheriting the session, `$user`, and the password gate encoded in `$user['forbidden_categories']`). It serves two actions: a JSON size estimate and a streamed `ZipArchive` of full-resolution originals. The button, modal, and its wiring are injected client-side from the theme's owned `header.tpl` override (reading the current album id from the `category-<id>` class Piwigo puts on `<body>`), styled in the existing `fotobox-overrides.css`. The two theme fixes are pure CSS additions.

**Tech Stack:** PHP 7.4+ (procedural Piwigo core, `ZipArchive`), Smarty templates, vanilla JS (`fetch`), CSS. No build system, no package manager, no test framework.

## Global Constraints

- Live site runs the **`modus`** theme; only the override files under `themes/modus/` are tracked in git. Do **not** edit the base modus theme (it lives only on the server). Edit only: `themes/modus/template/header.tpl`, `themes/modus/css/fotobox-overrides.css`, and the new `album_download.php`.
- **No test framework exists.** Verification is `php -l` syntax checks plus manual/`curl` browser checks. Do not add PHPUnit or a test harness.
- All `*.php` and `*.tpl` files must be saved **UTF-8**; new PHP files follow the existing Piwigo header/license comment style (see `album_password.php`).
- After any template/CSS change on the live server, the Piwigo cache must be purged (Admin → Maintenance) — note in verification, not something the plan automates.
- DB `filesize` is in **KB** (`mediumint unsigned`); bytes = `SUM(filesize) * 1024`.
- Image original path = `PHPWG_ROOT_PATH . $image['path']` (path is stored relative to the Piwigo root).
- User-facing strings are **German literals** (site is German-only), matching existing overrides.
- Access rule: a request is allowed only if the user is admin **or** `cat_id` is **not** in `explode(',', $user['forbidden_categories'])`. This mirrors the view gate exactly.
- Commit frequently, one task per commit. Commit message style: `feat: …` / `fix: …`, with trailer `Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>`.

## File Structure

| File | Responsibility |
|------|----------------|
| `album_download.php` (new) | Bootstrap Piwigo; access guard; gather accessible images for album+subalbums; serve `action=estimate` (JSON) and `action=download` (streamed ZIP of originals). Self-contained — all helpers local to this file. |
| `themes/modus/template/header.tpl` (modify) | Append a `<script>` that, on album pages, injects the download button + confirmation modal and wires them to `album_download.php`. |
| `themes/modus/css/fotobox-overrides.css` (modify) | Add: menubar hide (change 3); album-name wrap (change 2); download button + modal styling (change 1). |

Reference (read-only, do not edit): `album_password.php` (bootstrap pattern, session gate), `include/functions_category.inc.php` (`get_cat_info`, `get_subcat_ids`), `include/functions_user.inc.php` (`get_sql_condition_FandF`).

Task order: pure-CSS fixes first (Tasks 1–2, lowest risk, independently shippable), then the endpoint (Task 3), then the estimate action (Task 4 folds into 3's file), then the front-end button/modal (Task 5), then styling for it (Task 6). Tasks 1 and 2 are fully independent of the rest.

---

### Task 1: Remove the `#menubar` "Startseite"/calendar bar (change 3)

**Files:**
- Modify: `themes/modus/css/fotobox-overrides.css` (append)

**Interfaces:**
- Consumes: nothing.
- Produces: nothing consumed by later tasks.

- [ ] **Step 1: Append the menubar-hide rule**

At the end of `themes/modus/css/fotobox-overrides.css`, add:

```css
/* --- Fotobox: remove the "Startseite" / calendar menubar entirely --- */
#menubar,
#menuSwitcher {
  display: none !important;
}
```

- [ ] **Step 2: Verify no CSS syntax breakage**

Run (Git Bash):
```bash
grep -c "}" themes/modus/css/fotobox-overrides.css
```
Expected: a number ≥ the brace count before the edit (sanity that braces were added in balanced pairs). Visually confirm the new block has matched `{ }`.

- [ ] **Step 3: Manual browser check (documented, run against a deploy)**

Load the album-list page and an opened album. Expected: no white "Startseite" bar and no calendar block anywhere; the album list / thumbnails render normally in `#content`. (Requires cache purge after upload.)

- [ ] **Step 4: Commit**

```bash
git add themes/modus/css/fotobox-overrides.css
git commit -m "fix: remove the Startseite/calendar menubar from the fotobox theme

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 2: Fix mobile album-name overflow (change 2)

**Files:**
- Modify: `themes/modus/css/fotobox-overrides.css` (append)

**Interfaces:**
- Consumes: existing classes `.fotobox-album__link`, `.fotobox-album__name`, `.fotobox-album__count`, `ul.fotobox-albums` (defined earlier in the same file).
- Produces: nothing consumed by later tasks.

- [ ] **Step 1: Append the wrap rules**

At the end of `themes/modus/css/fotobox-overrides.css`, add:

```css
/* --- Fotobox: keep long album names from overflowing on mobile --- */
.fotobox-album__name {
  flex: 1 1 auto;
  min-width: 0;            /* allow the flex item to shrink below content width */
  overflow-wrap: anywhere; /* break long underscored names with no spaces */
}
.fotobox-album__count {
  flex: 0 0 auto;
  white-space: nowrap;
}
@media (max-width: 480px) {
  ul.fotobox-albums {
    padding-left: 1rem;
    padding-right: 1rem;
  }
}
```

- [ ] **Step 2: Verify brace balance**

Run:
```bash
awk '{o+=gsub(/{/,"{"); c+=gsub(/}/,"}")} END{print o, c}' themes/modus/css/fotobox-overrides.css
```
Expected: the two numbers are equal (open braces == close braces).

- [ ] **Step 3: Manual browser check (documented, run against a deploy)**

Emulate a 390px-wide viewport (iPhone 12 Pro). Open the start screen with a long album name (e.g. `2026_Hochzeit_…`). Expected: the name wraps onto multiple lines inside the card; the lock icon stays at the left; the photo count stays at the right on its own; no horizontal page overflow / scrollbar.

- [ ] **Step 4: Commit**

```bash
git add themes/modus/css/fotobox-overrides.css
git commit -m "fix: wrap long album names on mobile in the fotobox theme

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 3: `album_download.php` — bootstrap, access guard, image gathering, ZIP download

**Files:**
- Create: `album_download.php`

**Interfaces:**
- Consumes (from Piwigo core): `common.inc.php` globals `$user`, `$conf`; `check_status(ACCESS_FREE)`, `get_cat_info($id)`, `get_subcat_ids(array $ids)`, `get_sql_condition_FandF(array $fields, string $prefix)`, `is_admin()`, `pwg_query()`, `pwg_db_fetch_assoc()`, `query2array()`, `redirect($url)`, `make_index_url()`, `get_root_url()`, `urlencode()`. Constants `IMAGES_TABLE`, `IMAGE_CATEGORY_TABLE`, `CATEGORIES_TABLE`, `PHPWG_ROOT_PATH`.
- Produces (for Task 5, the front-end): URL contract
  `album_download.php?action=download&cat_id=<int>` → streams `application/zip`;
  `album_download.php?action=estimate&cat_id=<int>` → JSON (implemented in Task 4).
  Internal functions defined here and reused by Task 4: `fb_dl_require_access(int $cat_id): array` (returns the category row or exits), `fb_dl_collect_images(int $cat_id): array` (returns de-duplicated list of `['id','path','file','filesize','category_id']`).

- [ ] **Step 1: Create the file with the bootstrap + access guard + image collector**

Create `album_download.php` (UTF-8) with exactly this content:

```php
<?php
// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+
//
// Fotobox: download a whole album (originals of the album + all accessible
// sub-albums) as a ZIP, with a JSON size-estimate action for the confirm modal.

define('PHPWG_ROOT_PATH', './');
include_once(PHPWG_ROOT_PATH.'include/common.inc.php');

// Anyone may reach this page; per-album access is enforced below via
// $user['forbidden_categories'] (which already encodes the unlocked_albums gate).
check_status(ACCESS_FREE);

/**
 * Validate cat_id and enforce the album password gate.
 * On failure this function ends the request (403/redirect) and never returns.
 *
 * @param int $cat_id
 * @param string $mode 'estimate' (emit HTTP status) or 'download' (redirect)
 * @return array the category row from get_cat_info()
 */
function fb_dl_require_access($cat_id, $mode)
{
  global $user;

  $category = ($cat_id > 0) ? get_cat_info($cat_id) : null;

  if (empty($category))
  {
    if ($mode == 'estimate')
    {
      header('HTTP/1.1 404 Not Found');
      header('Content-Type: application/json; charset=utf-8');
      echo json_encode(array('error' => 'not_found'));
    }
    else
    {
      redirect(make_index_url());
    }
    exit();
  }

  $forbidden = empty($user['forbidden_categories'])
    ? array()
    : explode(',', $user['forbidden_categories']);

  if (!is_admin() and in_array((string)$cat_id, $forbidden, false))
  {
    if ($mode == 'estimate')
    {
      header('HTTP/1.1 403 Forbidden');
      header('Content-Type: application/json; charset=utf-8');
      echo json_encode(array('error' => 'forbidden'));
      exit();
    }
    // For a download, send the visitor to the password prompt for this album.
    redirect(
      get_root_url().'album_password.php?cat_id='.$cat_id
      .'&redirect='.urlencode(make_index_url(array('category' => $category)))
    );
  }

  return $category;
}

/**
 * Collect every accessible image in $cat_id and its sub-albums.
 * De-duplicated by image id (an image may live in several categories);
 * the first category seen supplies the zip sub-folder.
 *
 * @param int $cat_id
 * @return array list of ['id','path','file','filesize','category_id']
 */
function fb_dl_collect_images($cat_id)
{
  $cat_ids = array_unique(
    array_merge(array($cat_id), get_subcat_ids(array($cat_id)))
  );

  $ff = get_sql_condition_FandF(
    array(
      'forbidden_categories' => 'ic.category_id',
      'visible_categories'   => 'ic.category_id',
      'visible_images'       => 'i.id',
    ),
    'AND'
  );

  $query = '
SELECT i.id, i.path, i.file, i.filesize, ic.category_id
  FROM '.IMAGES_TABLE.' AS i
  INNER JOIN '.IMAGE_CATEGORY_TABLE.' AS ic ON ic.image_id = i.id
  WHERE ic.category_id IN ('.implode(',', array_map('intval', $cat_ids)).')
    '.$ff.'
  ORDER BY ic.category_id, i.id
;';
  $result = pwg_query($query);

  $images = array();
  while ($row = pwg_db_fetch_assoc($result))
  {
    if (isset($images[$row['id']]))
    {
      continue; // keep the first category for this image
    }
    $images[$row['id']] = array(
      'id'          => (int)$row['id'],
      'path'        => $row['path'],
      'file'        => $row['file'],
      'filesize'    => (int)$row['filesize'], // KB
      'category_id' => (int)$row['category_id'],
    );
  }

  return array_values($images);
}
```

- [ ] **Step 2: Add the download-path helpers and the action dispatcher**

Append to `album_download.php` (before the closing `?>` — add the closing tag at the very end):

```php
/**
 * Turn an arbitrary string into a safe path segment for a zip entry.
 *
 * @param string $name
 * @return string
 */
function fb_dl_sanitize_segment($name)
{
  $name = str_replace(array('/', '\\'), '-', $name);
  $name = preg_replace('/[\x00-\x1F\x7F]/', '', $name); // control chars
  $name = trim($name);
  return $name === '' ? '_' : $name;
}

/**
 * Build the zip entry path for an image: the category-name path *below* the
 * opened album, plus the file name. Images directly in the opened album go to
 * the zip root. Collisions get a " (2)", " (3)", … suffix before the extension.
 *
 * @param array $image  one entry from fb_dl_collect_images()
 * @param int   $root_cat_id  the opened album id
 * @param array $cat_names  map of category id => name
 * @param array $cat_uppercats  map of category id => "1,4,9" uppercats string
 * @param array $used  reference to a set of already-used local names
 * @return string
 */
function fb_dl_local_name($image, $root_cat_id, $cat_names, $cat_uppercats, &$used)
{
  $segments = array();
  $upper = isset($cat_uppercats[$image['category_id']])
    ? explode(',', $cat_uppercats[$image['category_id']])
    : array();

  $below_root = false;
  foreach ($upper as $uid)
  {
    $uid = (int)$uid;
    if ($uid == $root_cat_id)
    {
      $below_root = true;
      continue; // the opened album itself is the zip root
    }
    if ($below_root and isset($cat_names[$uid]))
    {
      $segments[] = fb_dl_sanitize_segment($cat_names[$uid]);
    }
  }

  $file = fb_dl_sanitize_segment($image['file']);
  $segments[] = $file;
  $local = implode('/', $segments);

  // De-collide.
  if (isset($used[$local]))
  {
    $dot = strrpos($file, '.');
    $base = ($dot === false) ? $file : substr($file, 0, $dot);
    $ext  = ($dot === false) ? ''   : substr($file, $dot);
    $n = 2;
    do
    {
      $segments[count($segments) - 1] = $base.' ('.$n.')'.$ext;
      $local = implode('/', $segments);
      $n++;
    } while (isset($used[$local]));
  }
  $used[$local] = true;
  return $local;
}

/**
 * Fetch id => name and id => uppercats for the categories referenced by the
 * collected images, so we can build zip sub-folders.
 *
 * @param array $images
 * @return array [ $names, $uppercats ]
 */
function fb_dl_category_maps($images)
{
  $ids = array();
  foreach ($images as $img)
  {
    $ids[$img['category_id']] = true;
  }
  if (empty($ids))
  {
    return array(array(), array());
  }
  $query = '
SELECT id, name, uppercats
  FROM '.CATEGORIES_TABLE.'
  WHERE id IN ('.implode(',', array_map('intval', array_keys($ids))).')
;';
  $rows = query2array($query, 'id');
  $names = array();
  $uppercats = array();
  foreach ($rows as $id => $row)
  {
    $names[(int)$id] = $row['name'];
    $uppercats[(int)$id] = $row['uppercats'];
  }
  return array($names, $uppercats);
}

// ---- Action dispatch -------------------------------------------------------

$cat_id = (isset($_GET['cat_id']) and is_numeric($_GET['cat_id'])) ? (int)$_GET['cat_id'] : 0;
$action = isset($_GET['action']) ? $_GET['action'] : 'download';

if ($action == 'estimate')
{
  // implemented in Task 4
  fb_dl_action_estimate($cat_id);
  exit();
}

// --- action=download --------------------------------------------------------
$category = fb_dl_require_access($cat_id, 'download');
$images = fb_dl_collect_images($cat_id);

if (empty($images))
{
  $_SESSION['page_infos'][] = 'Dieses Album enthält keine herunterladbaren Fotos.';
  redirect(make_index_url(array('category' => $category)));
}

list($cat_names, $cat_uppercats) = fb_dl_category_maps($images);

@set_time_limit(0);

$tmp = tempnam(sys_get_temp_dir(), 'pwgzip_');
register_shutdown_function(function () use ($tmp) {
  if (is_file($tmp)) { @unlink($tmp); }
});

$zip = new ZipArchive();
if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true)
{
  header('HTTP/1.1 500 Internal Server Error');
  echo 'ZIP konnte nicht erstellt werden.';
  exit();
}

$used = array();
$added = 0;
foreach ($images as $img)
{
  $abs = PHPWG_ROOT_PATH.$img['path'];
  if (!is_file($abs))
  {
    continue;
  }
  $local = fb_dl_local_name($img, $cat_id, $cat_names, $cat_uppercats, $used);
  if ($zip->addFile($abs, $local))
  {
    // Store without compression: JPEGs don't shrink, keeps it fast and the
    // size estimate accurate.
    $zip->setCompressionName($local, ZipArchive::CM_STORE);
    $added++;
  }
}
$zip->close();

if ($added == 0)
{
  $_SESSION['page_infos'][] = 'Die Originaldateien dieses Albums wurden nicht gefunden.';
  redirect(make_index_url(array('category' => $category)));
}

$zip_basename = fb_dl_sanitize_segment($category['name']);
if ($zip_basename === '' or $zip_basename === '_')
{
  $zip_basename = 'album-'.$cat_id;
}

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="'.$zip_basename.'.zip"');
header('Content-Length: '.filesize($tmp));
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');

$fh = fopen($tmp, 'rb');
if ($fh !== false)
{
  while (!feof($fh))
  {
    echo fread($fh, 8 * 1024 * 1024);
    flush();
  }
  fclose($fh);
}
exit();
?>
```

Note: `fb_dl_action_estimate()` is called here but defined in Task 4. Because it is only *invoked* on the `estimate` branch (not the `download` branch tested in this task), Task 3 can be syntax-checked and download-tested on its own; the estimate branch will fatal until Task 4 lands. Implement Task 4 immediately after.

- [ ] **Step 3: PHP syntax check**

Run:
```bash
php -l album_download.php
```
Expected: `No syntax errors detected in album_download.php`. (If `php` is not on PATH, use the local PHP binary; the check must pass before continuing.)

- [ ] **Step 4: Commit**

```bash
git add album_download.php
git commit -m "feat: album_download.php endpoint — access guard + ZIP download of originals

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 4: `album_download.php` — `action=estimate` (JSON size + count)

**Files:**
- Modify: `album_download.php` (add `fb_dl_action_estimate()` and a byte formatter)

**Interfaces:**
- Consumes: `fb_dl_require_access()`, `fb_dl_collect_images()` (Task 3).
- Produces (for Task 5): JSON response shape
  `{ "count": <int>, "bytes": <int>, "human": "<string, e.g. 3,4 GB>" }` on success;
  HTTP 403/404 with `{ "error": "forbidden"|"not_found" }` on gate failure.

- [ ] **Step 1: Add the estimate action + formatter**

In `album_download.php`, insert these two functions immediately **before** the `// ---- Action dispatch ----` comment block:

```php
/**
 * Human-readable byte size, German formatting (comma decimal separator).
 *
 * @param int|float $bytes
 * @return string e.g. "3,4 GB", "812 MB", "0 KB"
 */
function fb_dl_format_bytes($bytes)
{
  $units = array('B', 'KB', 'MB', 'GB', 'TB');
  $i = 0;
  $b = (float)$bytes;
  while ($b >= 1024 and $i < count($units) - 1)
  {
    $b /= 1024;
    $i++;
  }
  // one decimal for GB/TB, none below that
  $decimals = ($i >= 3) ? 1 : 0;
  $formatted = number_format($b, $decimals, ',', '.');
  return $formatted.' '.$units[$i];
}

/**
 * Emit the JSON estimate for the confirm modal. Ends the request.
 *
 * @param int $cat_id
 * @return void
 */
function fb_dl_action_estimate($cat_id)
{
  $category = fb_dl_require_access($cat_id, 'estimate'); // exits on failure
  unset($category); // not needed further

  $images = fb_dl_collect_images($cat_id);

  $count = count($images);
  $kb = 0;
  foreach ($images as $img)
  {
    $kb += $img['filesize']; // KB
  }
  $bytes = $kb * 1024;

  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-cache, no-store, must-revalidate');
  echo json_encode(array(
    'count' => $count,
    'bytes' => $bytes,
    'human' => fb_dl_format_bytes($bytes),
  ));
}
```

- [ ] **Step 2: PHP syntax check**

Run:
```bash
php -l album_download.php
```
Expected: `No syntax errors detected in album_download.php`.

- [ ] **Step 3: Behavioural check of the formatter (throwaway, do not commit)**

Run:
```bash
php -r 'require "album_download.php";' 2>/dev/null; echo "---"; \
php -r 'function number_format_test(){}; $u=array("B","KB","MB","GB","TB"); function f($bytes){$units=array("B","KB","MB","GB","TB");$i=0;$b=(float)$bytes;while($b>=1024&&$i<count($units)-1){$b/=1024;$i++;}$d=($i>=3)?1:0;return number_format($b,$d,",",".")." ".$units[$i];} echo f(3650722201),"|",f(851443712),"|",f(0),"\n";'
```
Expected second line: `3,4 GB|812 MB|0 B`. (The first `require` line will error because Piwigo isn't bootstrapped from the CLI — that's expected and ignored; it only confirms the file parses. The inline copy verifies the formatting logic.)

- [ ] **Step 4: Manual endpoint check (documented, run against a deploy with a logged-in/unlocked session)**

- `curl` (with the session cookie) `…/album_download.php?action=estimate&cat_id=<unlocked album>` → JSON with a plausible `count` and `human`.
- Same for a **locked** album without unlocking → HTTP 403 and `{"error":"forbidden"}`.

- [ ] **Step 5: Commit**

```bash
git add album_download.php
git commit -m "feat: album_download.php estimate action — JSON count + human size

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 5: Download button + confirmation modal wiring (`header.tpl`)

**Files:**
- Modify: `themes/modus/template/header.tpl` (append after the existing `#theHeader` div, line ~71)

**Interfaces:**
- Consumes: `$ROOT_URL` (Smarty var, already used in this file) for the endpoint base; the `category-<id>` class Piwigo sets on `<body>`; the `album_download.php` URL contract (Tasks 3–4).
- Produces: DOM elements `.fotobox-download-btn`, `.fotobox-modal` (+ `.fotobox-modal__panel`, `.fotobox-modal__actions`) styled in Task 6.

- [ ] **Step 1: Append the button/modal bootstrap script**

At the **end** of `themes/modus/template/header.tpl` (after the `<div id="theHeader" …></div>` line), append:

```smarty
{* fotobox: album ZIP-download button + confirmation modal (album pages only) *}
<script>
(function () {
  function ready(fn) {
    if (document.readyState !== 'loading') { fn(); }
    else { document.addEventListener('DOMContentLoaded', fn); }
  }
  ready(function () {
    var body = document.body;
    if (!body || body.id !== 'theCategoryPage') { return; }

    var m = /(?:^|\s)category-(\d+)(?:\s|$)/.exec(body.className || '');
    if (!m) { return; }
    var catId = m[1];
    var base = '{$ROOT_URL}album_download.php';

    // --- button ---
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'fotobox-download-btn';
    btn.textContent = 'Album herunterladen';

    var content = document.getElementById('content') || body;
    if (content.firstChild) { content.insertBefore(btn, content.firstChild); }
    else { content.appendChild(btn); }

    // --- modal ---
    var overlay = document.createElement('div');
    overlay.className = 'fotobox-modal';
    overlay.innerHTML =
      '<div class="fotobox-modal__panel" role="dialog" aria-modal="true">' +
        '<p class="fotobox-modal__text"></p>' +
        '<div class="fotobox-modal__actions">' +
          '<button type="button" class="fotobox-modal__cancel">Abbrechen</button>' +
          '<button type="button" class="fotobox-modal__confirm">Herunterladen</button>' +
        '</div>' +
      '</div>';
    body.appendChild(overlay);

    var textEl = overlay.querySelector('.fotobox-modal__text');
    var cancelEl = overlay.querySelector('.fotobox-modal__cancel');
    var confirmEl = overlay.querySelector('.fotobox-modal__confirm');

    function closeModal() { overlay.classList.remove('is-open'); }
    function startDownload() {
      closeModal();
      window.location = base + '?action=download&cat_id=' + catId;
    }

    cancelEl.addEventListener('click', closeModal);
    overlay.addEventListener('click', function (e) {
      if (e.target === overlay) { closeModal(); }
    });
    confirmEl.addEventListener('click', startDownload);

    btn.addEventListener('click', function () {
      btn.disabled = true;
      var original = btn.textContent;
      btn.textContent = 'wird berechnet…';
      fetch(base + '?action=estimate&cat_id=' + catId, { credentials: 'same-origin' })
        .then(function (r) {
          if (!r.ok) { throw new Error('estimate failed'); }
          return r.json();
        })
        .then(function (data) {
          btn.disabled = false;
          btn.textContent = original;
          textEl.textContent = data.count + ' Fotos herunterladen (~' + data.human + ')?';
          overlay.classList.add('is-open');
        })
        .catch(function () {
          btn.disabled = false;
          btn.textContent = original;
          if (window.confirm('Ganzes Album herunterladen?')) { startDownload(); }
        });
    });
  });
})();
</script>
```

- [ ] **Step 2: Verify the template has no obviously broken Smarty/HTML**

Run:
```bash
grep -c "fotobox-download-btn\|fotobox-modal" themes/modus/template/header.tpl
```
Expected: ≥ 5 (button class + several modal references present). Visually confirm the `<script>` block is the last thing in the file and `{$ROOT_URL}` appears exactly where the endpoint base is built.

- [ ] **Step 3: Manual browser check (documented, run against a deploy)**

Open an **unlocked** album. Expected: an "Album herunterladen" button appears above the thumbnails. Click it → button briefly shows "wird berechnet…", then a modal appears reading e.g. *"142 Fotos herunterladen (~3,4 GB)?"* with **Herunterladen** / **Abbrechen**. Cancel closes it; clicking the backdrop closes it; **Herunterladen** starts a `.zip` download and the gallery page stays put. On the album-list page (body id not `theCategoryPage`) no button appears.

- [ ] **Step 4: Commit**

```bash
git add themes/modus/template/header.tpl
git commit -m "feat: album download button + confirm modal wiring in modus header

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 6: Style the download button + modal (`fotobox-overrides.css`)

**Files:**
- Modify: `themes/modus/css/fotobox-overrides.css` (append)

**Interfaces:**
- Consumes: CSS vars `--fb-primary-darker`, `--fb-secondary`, `--fb-highlight`, `--fb-bg-dark` (defined at the top of the file); DOM produced by Task 5.
- Produces: nothing consumed later.

- [ ] **Step 1: Append the button + modal styles**

At the end of `themes/modus/css/fotobox-overrides.css`, add:

```css
/* --- Fotobox: album download button --- */
.fotobox-download-btn {
  display: block;
  margin: 1.5rem auto 0.5rem;
  padding: 0.7rem 1.4rem;
  border: 0;
  border-radius: 6px;
  background-color: var(--fb-highlight);
  color: var(--fb-primary-darker);
  font-size: 1rem;
  font-weight: 600;
  cursor: pointer;
  transition: filter 150ms ease, opacity 150ms ease;
}
.fotobox-download-btn:hover { filter: brightness(1.08); }
.fotobox-download-btn:disabled { opacity: 0.6; cursor: default; }

/* --- Fotobox: confirmation modal --- */
.fotobox-modal {
  display: none;
  position: fixed;
  inset: 0;
  z-index: 1000;
  background-color: rgba(0, 0, 0, 0.6);
  align-items: center;
  justify-content: center;
  padding: 1rem;
}
.fotobox-modal.is-open { display: flex; }
.fotobox-modal__panel {
  background-color: var(--fb-primary-darker);
  color: var(--fb-secondary);
  max-width: 420px;
  width: 100%;
  padding: 1.5rem;
  border-radius: 8px;
  box-shadow: 0 10px 40px rgba(0, 0, 0, 0.5);
  text-align: center;
}
.fotobox-modal__text {
  margin: 0 0 1.25rem;
  font-size: 1.1rem;
  line-height: 1.4;
}
.fotobox-modal__actions {
  display: flex;
  gap: 0.75rem;
  justify-content: center;
  flex-wrap: wrap;
}
.fotobox-modal__actions button {
  padding: 0.6rem 1.2rem;
  border-radius: 6px;
  font-size: 1rem;
  font-weight: 600;
  cursor: pointer;
}
.fotobox-modal__confirm {
  border: 0;
  background-color: var(--fb-highlight);
  color: var(--fb-primary-darker);
}
.fotobox-modal__confirm:hover { filter: brightness(1.08); }
.fotobox-modal__cancel {
  background-color: transparent;
  color: var(--fb-secondary);
  border: 1px solid var(--fb-secondary);
}
.fotobox-modal__cancel:hover { color: var(--fb-highlight); border-color: var(--fb-highlight); }
```

- [ ] **Step 2: Verify brace balance**

Run:
```bash
awk '{o+=gsub(/{/,"{"); c+=gsub(/}/,"}")} END{print o, c}' themes/modus/css/fotobox-overrides.css
```
Expected: the two numbers are equal.

- [ ] **Step 3: Manual browser check (documented, run against a deploy)**

Reload an unlocked album (after cache purge). Expected: the button is bronze on dark and centered above the thumbnails; the modal panel is dark-slate with cream text, the confirm button bronze and the cancel button outlined; layout holds at 390px width (buttons wrap if needed).

- [ ] **Step 4: Commit**

```bash
git add themes/modus/css/fotobox-overrides.css
git commit -m "feat: style the fotobox album download button and confirm modal

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Final Verification (whole feature, against a deploy)

Run after all tasks, on the live/staging server, **after purging the Piwigo cache** (Admin → Maintenance):

1. **Menubar gone** (Task 1) — no "Startseite"/calendar bar on album-list or album pages, desktop + mobile.
2. **Mobile names** (Task 2) — 390px viewport: long album names wrap; lock + count aligned; no horizontal overflow.
3. **Button visibility** (Task 5) — button shows only inside an album page, only when unlocked/accessible.
4. **Estimate** (Task 4) — modal shows a count and human size matching the sum of the album's photo sizes.
5. **Download gate** (Task 3) — `action=download` on a locked album (not unlocked) redirects to the password prompt; unlocked session downloads.
6. **ZIP correctness** (Task 3) — the zip opens; contains all accessible originals; sub-albums appear as folders; duplicate filenames are suffixed, not clobbered; images that live only in locked sub-albums are absent.
7. **Empty album** (Task 3) — an album with no accessible originals redirects back with the German info message, no empty zip.
8. **Large album sanity** — on the biggest real album, the download completes (watch for PHP/webserver timeout; this is the documented single-request limitation).

## Notes / Known Limitations (from the spec)

- ZIP is built to a temp file and streamed in one request: needs free disk ≈ archive size and adequate PHP/webserver timeouts for multi-GB albums. Streaming-ZIP (no temp file) is deliberately out of scope.
- No resumable/range downloads.
- German strings are inline literals in the header script (site is German-only), matching existing overrides.
