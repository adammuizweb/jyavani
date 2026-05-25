-- Migration 004: Dynamic Menu System (menus + menu_items tables)
-- WordPress-like menu management with nested items, multiple menus, default menu support.

-- ======= 1. Create menus table =======
CREATE TABLE IF NOT EXISTS `menus` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `slug` VARCHAR(100) NOT NULL,
  `is_default` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_menu_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ======= 2. Create menu_items table =======
CREATE TABLE IF NOT EXISTS `menu_items` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `menu_id` INT UNSIGNED NOT NULL,
  `parent_id` INT UNSIGNED DEFAULT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `type` VARCHAR(50) NOT NULL DEFAULT 'custom',
  `label` VARCHAR(200) NOT NULL,
  `url` VARCHAR(500) DEFAULT NULL,
  `target_id` INT UNSIGNED DEFAULT NULL,
  `target_blank` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_menu_id` (`menu_id`),
  KEY `idx_parent_id` (`parent_id`),
  KEY `idx_sort_order` (`menu_id`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ======= 3. Insert default "Primary" menu =======
INSERT IGNORE INTO `menus` (`name`, `slug`, `is_default`) VALUES ('Primary', 'primary', 1);
