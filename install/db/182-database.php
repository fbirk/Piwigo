<?php
// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

if (!defined('PHPWG_ROOT_PATH'))
{
  die('Hacking attempt!');
}

$upgrade_description = 'add password and share_token columns to categories table';

$query = 'SHOW COLUMNS FROM `'.CATEGORIES_TABLE.'` LIKE "password"';
$result = pwg_query($query);
if (pwg_db_num_rows($result) == 0)
{
  $query = 'ALTER TABLE `'.CATEGORIES_TABLE.'` ADD COLUMN `password` VARCHAR(255) DEFAULT NULL AFTER `status`';
  pwg_query($query);
}

$query = 'SHOW COLUMNS FROM `'.CATEGORIES_TABLE.'` LIKE "share_token"';
$result = pwg_query($query);
if (pwg_db_num_rows($result) == 0)
{
  $query = 'ALTER TABLE `'.CATEGORIES_TABLE.'` ADD COLUMN `share_token` VARCHAR(64) DEFAULT NULL AFTER `password`';
  pwg_query($query);

  $query = 'ALTER TABLE `'.CATEGORIES_TABLE.'` ADD UNIQUE INDEX `idx_share_token` (`share_token`)';
  pwg_query($query);
}

echo "\n".$upgrade_description."\n";

?>
