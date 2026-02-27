# Upgrade Guide: Password-Protected Albums

## 1. Deploy the code

Copy all changed and new files to your Piwigo installation, or pull the `feature/password-only-albums` branch.

## 2. Run the database migration

Execute the following SQL against your Piwigo database. Replace `piwigo_categories` with your actual table name if you use a custom prefix (check `local/config/database.inc.php` for `$prefixeTable`).

```sql
ALTER TABLE piwigo_categories ADD COLUMN `password` VARCHAR(255) DEFAULT NULL AFTER `status`;
ALTER TABLE piwigo_categories ADD COLUMN `share_token` VARCHAR(64) DEFAULT NULL AFTER `password`;
ALTER TABLE piwigo_categories ADD UNIQUE INDEX `idx_share_token` (`share_token`);
```

## 3. Clear the Smarty template cache

Delete everything inside `_data/templates_c/` so the modified templates take effect.

## 4. Clear the browser cache

Hard refresh (Ctrl+Shift+R) in the admin panel to pick up the updated `cat_modify.js`.

## Verify

Go to **Admin > Albums > edit any album**. You should see a "Protect with password" toggle below the "Locked album" switch.
