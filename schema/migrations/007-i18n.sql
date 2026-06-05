-- Migration 007: UI Translation table
CREATE TABLE IF NOT EXISTS `ui_translations` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `scope` VARCHAR(50) NOT NULL DEFAULT 'default',
  `source` TEXT NOT NULL COMMENT 'English source text',
  `value` TEXT NOT NULL COMMENT 'Translated text',
  `locale` CHAR(5) NOT NULL DEFAULT 'en',
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `source_lookup` (`scope`, `source`(191), `locale`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
