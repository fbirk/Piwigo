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

/**
 * Turn an arbitrary string into a safe path segment for a zip entry.
 *
 * @param string $name
 * @return string
 */
function fb_dl_sanitize_segment($name)
{
  $name = str_replace(array('/', '\\', '"'), array('-', '-', ''), $name);
  $name = preg_replace('/[\x00-\x1F\x7F]/', '', $name); // control chars
  $name = trim($name);
  if ($name === '' or $name === '.' or $name === '..')
  {
    return '_';
  }
  return $name;
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
 * Fetch id => name (for the full ancestor set) and id => uppercats (for the
 * image-bearing categories) so we can build zip sub-folders. Names cover every
 * ancestor — including container-only sub-albums that hold no images directly —
 * so each level contributes its folder segment.
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

  // uppercats of the image-bearing categories (keyed as fb_dl_local_name expects)
  $query = '
SELECT id, uppercats
  FROM '.CATEGORIES_TABLE.'
  WHERE id IN ('.implode(',', array_map('intval', array_keys($ids))).')
;';
  $rows = query2array($query, 'id');

  $uppercats = array();
  $all_ids = array();
  foreach ($rows as $id => $row)
  {
    $uppercats[(int)$id] = $row['uppercats'];
    foreach (explode(',', $row['uppercats']) as $anc)
    {
      $all_ids[(int)$anc] = true;
    }
  }

  // names for the full ancestor set (container-only sub-albums included)
  $names = array();
  if (!empty($all_ids))
  {
    $nquery = '
SELECT id, name
  FROM '.CATEGORIES_TABLE.'
  WHERE id IN ('.implode(',', array_map('intval', array_keys($all_ids))).')
;';
    $nrows = query2array($nquery, 'id');
    foreach ($nrows as $id => $row)
    {
      $names[(int)$id] = $row['name'];
    }
  }

  return array($names, $uppercats);
}

/**
 * Human-readable byte size, German formatting (comma decimal separator).
 *
 * @param int|float $bytes
 * @return string e.g. "3,4 GB", "812 MB", "0 B"
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
