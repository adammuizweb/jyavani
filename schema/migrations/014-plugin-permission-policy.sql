-- MySQL 5.7 and MariaDB-compatible idempotent column addition.
SET @jy_permission_policy_column_exists = (
  SELECT COUNT(*)
  FROM `information_schema`.`COLUMNS`
  WHERE `TABLE_SCHEMA` = DATABASE()
    AND `TABLE_NAME` = 'permissions'
    AND `COLUMN_NAME` = 'is_delegable'
);
SET @jy_permission_policy_sql = IF(
  @jy_permission_policy_column_exists = 0,
  'ALTER TABLE `permissions` ADD COLUMN `is_delegable` tinyint(1) NOT NULL DEFAULT 1 AFTER `supports_scope`',
  'SELECT 1'
);
PREPARE jy_permission_policy_stmt FROM @jy_permission_policy_sql;
EXECUTE jy_permission_policy_stmt;
DEALLOCATE PREPARE jy_permission_policy_stmt;
