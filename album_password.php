<?php
// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

define('PHPWG_ROOT_PATH','./');
include_once(PHPWG_ROOT_PATH.'include/common.inc.php');

// +-----------------------------------------------------------------------+
// | Check Access and exit when user status is not ok                      |
// +-----------------------------------------------------------------------+
check_status(ACCESS_FREE);

// Validate cat_id parameter
if (!isset($_GET['cat_id']) or !is_numeric($_GET['cat_id']))
{
  redirect(make_index_url());
}

$cat_id = (int)$_GET['cat_id'];
$category = get_cat_info($cat_id);

if (empty($category) or empty($category['password']))
{
  redirect(make_index_url());
}

$redirect_to = isset($_GET['redirect']) ? $_GET['redirect'] : '';

// Handle POST: verify password
if (isset($_POST['album_password']))
{
  $input_password = stripslashes($_POST['album_password']);

  if (password_verify($input_password, $category['password']))
  {
    // Unlock the album in session
    $unlocked = pwg_get_session_var('unlocked_albums', array());
    if (!in_array($cat_id, $unlocked))
    {
      $unlocked[] = $cat_id;
      pwg_set_session_var('unlocked_albums', $unlocked);
    }

    // Redirect to the album or the original page
    if (!empty($_POST['redirect']))
    {
      $redirect_url = stripslashes($_POST['redirect']);
    }
    else
    {
      $redirect_url = make_index_url(array('category' => $category));
    }
    redirect($redirect_url);
  }
  else
  {
    $page['errors'][] = l10n('Wrong password');
  }

  $redirect_to = isset($_POST['redirect']) ? stripslashes($_POST['redirect']) : '';
}

// Template initialization
$title = l10n('Album password');
$page['body_id'] = 'theAlbumPasswordPage';

$template->set_filenames(array('album_password' => 'album_password.tpl'));

$album_name = trigger_change(
  'render_category_name',
  $category['name'],
  'album_password'
);

$template->assign(
  array(
    'ALBUM_NAME' => $album_name,
    'CAT_ID' => $cat_id,
    'U_REDIRECT' => $redirect_to,
    'F_ACTION' => get_root_url().'album_password.php?cat_id='.$cat_id,
  )
);

// Include menubar
$themeconf = $template->get_template_vars('themeconf');
if (!isset($themeconf['hide_menu_on']) or !in_array('theAlbumPasswordPage', $themeconf['hide_menu_on']))
{
  include(PHPWG_ROOT_PATH.'include/menubar.inc.php');
}

include(PHPWG_ROOT_PATH.'include/page_header.php');
flush_page_messages();
$template->pparse('album_password');
include(PHPWG_ROOT_PATH.'include/page_tail.php');
?>
