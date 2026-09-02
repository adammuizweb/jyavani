SET @jy_updated_by_column_exists = (
  SELECT COUNT(*) FROM `information_schema`.`COLUMNS`
  WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'posts' AND `COLUMN_NAME` = 'updated_by'
);
SET @jy_updated_by_sql = IF(
  @jy_updated_by_column_exists = 0,
  'ALTER TABLE `posts` ADD COLUMN `updated_by` int(10) unsigned DEFAULT NULL AFTER `created_by`',
  'SELECT 1'
);
PREPARE jy_updated_by_stmt FROM @jy_updated_by_sql;
EXECUTE jy_updated_by_stmt;
DEALLOCATE PREPARE jy_updated_by_stmt;

SET @jy_updated_by_index_exists = (
  SELECT COUNT(*) FROM `information_schema`.`STATISTICS`
  WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'posts' AND `INDEX_NAME` = 'idx_posts_updated_by'
);
SET @jy_updated_by_sql = IF(
  @jy_updated_by_index_exists = 0,
  'ALTER TABLE `posts` ADD KEY `idx_posts_updated_by` (`updated_by`)',
  'SELECT 1'
);
PREPARE jy_updated_by_stmt FROM @jy_updated_by_sql;
EXECUTE jy_updated_by_stmt;
DEALLOCATE PREPARE jy_updated_by_stmt;

SET @jy_updated_by_fk_exists = (
  SELECT COUNT(*) FROM `information_schema`.`TABLE_CONSTRAINTS`
  WHERE `CONSTRAINT_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'posts' AND `CONSTRAINT_NAME` = 'fk_posts_updated_by_users'
);
SET @jy_updated_by_sql = IF(
  @jy_updated_by_fk_exists = 0,
  'ALTER TABLE `posts` ADD CONSTRAINT `fk_posts_updated_by_users` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE',
  'SELECT 1'
);
PREPARE jy_updated_by_stmt FROM @jy_updated_by_sql;
EXECUTE jy_updated_by_stmt;
DEALLOCATE PREPARE jy_updated_by_stmt;

SET @jy_updated_by_backfill_done = (
  SELECT COUNT(*) FROM `settings` WHERE `key` = 'core_posts_updated_by_backfilled'
);
UPDATE `posts`
SET `updated_by` = `created_by`
WHERE `updated_by` IS NULL AND @jy_updated_by_backfill_done = 0;
INSERT IGNORE INTO `settings` (`key`, `value`) VALUES ('core_posts_updated_by_backfilled', '1');

INSERT INTO `permissions` (`permission_key`,`provider`,`resource`,`action`,`label`,`supports_scope`) VALUES
  ('core.theme_content.restore','core','theme_content','restore','Restore theme content',1),
  ('core.theme_content.purge','core','theme_content','purge','Permanently delete theme content',1)
ON DUPLICATE KEY UPDATE
  `provider`=VALUES(`provider`),
  `resource`=VALUES(`resource`),
  `action`=VALUES(`action`),
  `label`=VALUES(`label`),
  `supports_scope`=VALUES(`supports_scope`),
  `is_active`=1;

INSERT INTO `role_permissions` (`role_id`,`permission_key`,`scope`)
SELECT r.id, p.permission_key, 'any'
FROM `roles` r
JOIN `permissions` p ON p.permission_key IN (
  'core.posts.read',
  'core.pages.read',
  'core.categories.read',
  'core.theme_content.read'
)
WHERE r.is_system = 1 AND r.slug IN ('author','editor')
ON DUPLICATE KEY UPDATE `scope`=VALUES(`scope`);

INSERT INTO `role_permissions` (`role_id`,`permission_key`,`scope`)
SELECT r.id, p.permission_key, 'own'
FROM `roles` r
JOIN `permissions` p ON p.permission_key IN (
  'core.posts.update', 'core.posts.trash', 'core.posts.restore', 'core.posts.purge',
  'core.posts.publish', 'core.posts.change_dates',
  'core.pages.update', 'core.pages.trash', 'core.pages.restore', 'core.pages.purge',
  'core.pages.publish', 'core.pages.change_dates',
  'core.categories.update', 'core.categories.trash', 'core.categories.restore', 'core.categories.purge',
  'core.theme_content.update', 'core.theme_content.delete',
  'core.theme_content.restore', 'core.theme_content.purge'
)
WHERE r.is_system = 1 AND r.slug = 'editor'
ON DUPLICATE KEY UPDATE `scope`=VALUES(`scope`);

INSERT INTO `role_permissions` (`role_id`,`permission_key`,`scope`)
SELECT r.id, p.permission_key, 'own'
FROM `roles` r
JOIN `permissions` p ON p.permission_key IN ('core.theme_content.delete','core.theme_content.restore')
WHERE r.is_system = 1 AND r.slug = 'author'
ON DUPLICATE KEY UPDATE `scope`=VALUES(`scope`);

INSERT INTO `role_permissions` (`role_id`,`permission_key`,`scope`)
SELECT r.id, p.permission_key, 'any'
FROM `roles` r
JOIN `permissions` p ON p.permission_key IN ('core.theme_content.restore','core.theme_content.purge')
WHERE r.is_system = 1 AND r.slug = 'admin'
ON DUPLICATE KEY UPDATE `scope`=VALUES(`scope`);
