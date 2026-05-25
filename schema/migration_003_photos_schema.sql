-- Migration 003: Add photo schema support (sort_order on posts, post_media_items table)
-- Also adds 'photo' to posts.type ENUM if needed.
-- 
-- WARNING: The IF-based approach requires MySQL's prepared statement workaround.
-- If you get a syntax error, run the ALTER TABLE and CREATE TABLE lines directly.

-- ======= 1. Add 'photo' to posts.type ENUM =======
SELECT COUNT(*) INTO @exists FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'posts' AND COLUMN_NAME = 'type'
    AND (COLUMN_TYPE LIKE '%photo%' OR COLUMN_TYPE LIKE '%varchar%' OR COLUMN_TYPE LIKE '%text%');

SET @sql = IF(@exists = 0,
  'ALTER TABLE `posts` MODIFY COLUMN `type` VARCHAR(50) NOT NULL DEFAULT ''article''',
  'SELECT 1 AS already_exists');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DROP PREPARE stmt;

-- ======= 2. Add sort_order column to posts (if missing) =======
SELECT COUNT(*) INTO @exists FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'posts' AND COLUMN_NAME = 'sort_order';

SET @sql = IF(@exists = 0,
  'ALTER TABLE `posts` ADD COLUMN `sort_order` INT NOT NULL DEFAULT 0 AFTER `updated_at`',
  'SELECT 1 AS already_exists');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DROP PREPARE stmt;

-- ======= 3. Create post_media_items table (if missing) =======
SELECT COUNT(*) INTO @exists FROM INFORMATION_SCHEMA.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'post_media_items';

SET @sql = IF(@exists = 0,
  'CREATE TABLE `post_media_items` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `post_id` INT UNSIGNED NOT NULL,
    `media_id` INT UNSIGNED NOT NULL,
    `sort_order` INT NOT NULL DEFAULT 0,
    `caption_override` VARCHAR(500) DEFAULT NULL,
    `alt_override` VARCHAR(500) DEFAULT NULL,
    `link_url_override` VARCHAR(1000) DEFAULT NULL,
    `link_target_override` VARCHAR(10) DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_post_media` (`post_id`, `media_id`),
    KEY `idx_post_id` (`post_id`),
    KEY `idx_media_id` (`media_id`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
  'SELECT 1 AS already_exists');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DROP PREPARE stmt;
