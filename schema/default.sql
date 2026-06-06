-- Adiwira CMS — Default Schema (MariaDB 10.6+)
-- Dumped from production database 2026-05-25
-- Safe for fresh install: uses CREATE TABLE IF NOT EXISTS + ON DUPLICATE KEY UPDATE

SET FOREIGN_KEY_CHECKS=0;
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ──────────────────────────────────────────────────────────────
-- 1. users
-- ──────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `users` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(255) NOT NULL,
  `username` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `name` varchar(100) DEFAULT NULL,
  `bio` text DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `role` enum('author','editor','admin') NOT NULL DEFAULT 'author',
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `is_locked` tinyint(1) NOT NULL DEFAULT 0,
  `img` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `uq_users_username` (`username`),
  KEY `idx_users_username` (`username`),
  KEY `idx_users_status` (`is_deleted`,`is_locked`,`role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- ──────────────────────────────────────────────────────────────
-- 2. settings
-- ──────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `settings` (
  `key` varchar(64) NOT NULL,
  `value` text DEFAULT NULL,
  `autoload` tinyint(1) NOT NULL DEFAULT 1,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- ──────────────────────────────────────────────────────────────
-- 3. categories
-- ──────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `categories` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(191) NOT NULL,
  `slug` varchar(191) NOT NULL,
  `description` text DEFAULT NULL,
  `parent_id` int(10) unsigned DEFAULT NULL,
  `meta` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`meta`)),
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_categories_slug` (`slug`),
  KEY `idx_parent` (`parent_id`),
  KEY `idx_created_by` (`created_by`),
  CONSTRAINT `fk_categories_created_by_users` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ──────────────────────────────────────────────────────────────
-- 4. posts
-- ──────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `posts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(191) NOT NULL,
  `slug` varchar(191) NOT NULL,
  `content` longtext NOT NULL,
  `type` varchar(50) NOT NULL DEFAULT 'article',
  `meta` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`meta`)),
  `youtube` varchar(255) DEFAULT NULL,
  `thumbnail` varchar(255) DEFAULT NULL,
  `status` enum('draft','published','private') NOT NULL DEFAULT 'draft',
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` datetime DEFAULT NULL,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_type` (`type`),
  KEY `idx_status` (`status`),
  KEY `idx_created_by` (`created_by`),
  FULLTEXT KEY `ft_title_content` (`title`,`content`),
  CONSTRAINT `fk_posts_created_by_users` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ──────────────────────────────────────────────────────────────
-- 5. post_categories
-- ──────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `post_categories` (
  `post_id` int(10) unsigned NOT NULL,
  `category_id` int(10) unsigned NOT NULL,
  `assigned_by` int(10) unsigned DEFAULT NULL,
  `assigned_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`post_id`,`category_id`),
  KEY `idx_category` (`category_id`),
  KEY `idx_assigned_by` (`assigned_by`),
  CONSTRAINT `fk_pc_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_pc_post` FOREIGN KEY (`post_id`) REFERENCES `posts` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ──────────────────────────────────────────────────────────────
-- 6. media
-- ──────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `media` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `url` varchar(512) NOT NULL,
  `filename` varchar(255) DEFAULT NULL,
  `mime` varchar(100) DEFAULT NULL,
  `ext` varchar(10) DEFAULT NULL,
  `size` int(10) unsigned DEFAULT 0,
  `width` int(10) unsigned DEFAULT NULL,
  `height` int(10) unsigned DEFAULT NULL,
  `title` varchar(255) DEFAULT NULL,
  `alt` varchar(255) DEFAULT NULL,
  `caption` text DEFAULT NULL,
  `credit` varchar(255) DEFAULT NULL,
  `visibility` enum('public','private') NOT NULL DEFAULT 'public',
  `storage_disk` enum('public','private') NOT NULL DEFAULT 'public',
  `storage_path` varchar(500) DEFAULT NULL,
  `access_scope` enum('public','editorial','admin') NOT NULL DEFAULT 'public',
  `is_downloadable` tinyint(1) NOT NULL DEFAULT 1,
  `user_id` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `target_url` varchar(2048) DEFAULT NULL,
  `target_attribute` varchar(10) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ──────────────────────────────────────────────────────────────
-- 7. post_media_items
-- ──────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `post_media_items` (
  `post_id` int(10) unsigned NOT NULL,
  `media_id` int(10) unsigned NOT NULL,
  `sort_order` int(10) unsigned NOT NULL DEFAULT 0,
  `caption_override` text DEFAULT NULL,
  `alt_override` varchar(255) DEFAULT NULL,
  `link_url_override` varchar(2048) DEFAULT NULL,
  `link_target_override` enum('_self','_blank','_parent','_top') DEFAULT NULL,
  PRIMARY KEY (`post_id`,`media_id`),
  KEY `idx_post_order` (`post_id`,`sort_order`),
  KEY `idx_media` (`media_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ──────────────────────────────────────────────────────────────
-- 8. file
-- ──────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `file` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `url` varchar(512) NOT NULL,
  `filename` varchar(255) DEFAULT NULL,
  `mime` varchar(100) DEFAULT NULL,
  `ext` varchar(10) DEFAULT NULL,
  `size` int(10) unsigned DEFAULT 0,
  `title` varchar(255) DEFAULT NULL,
  `caption` text DEFAULT NULL,
  `credit` varchar(255) DEFAULT NULL,
  `user_id` int(10) unsigned DEFAULT NULL,
  `media_type` varchar(20) NOT NULL DEFAULT 'file',
  `visibility` enum('public','private') NOT NULL DEFAULT 'public',
  `storage_disk` enum('public','private') NOT NULL DEFAULT 'public',
  `storage_path` varchar(500) DEFAULT NULL,
  `access_scope` enum('public','editorial','admin') NOT NULL DEFAULT 'public',
  `is_downloadable` tinyint(1) NOT NULL DEFAULT 1,
  `duration` int(10) unsigned DEFAULT NULL,
  `thumb_url` varchar(512) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ──────────────────────────────────────────────────────────────
-- 9. themes
-- ──────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `themes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `folder_name` varchar(100) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 0,
  `is_system` tinyint(1) NOT NULL DEFAULT 0,
  `store_url` varchar(255) DEFAULT '',
  `store_slug` varchar(100) DEFAULT '',
  `name` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `version` varchar(50) DEFAULT NULL,
  `author` varchar(150) DEFAULT NULL,
  `screenshot` varchar(255) DEFAULT NULL,
  `manifest_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`manifest_json`)),
  `scanned_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_theme_folder` (`folder_name`),
  KEY `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- ──────────────────────────────────────────────────────────────
-- 10. assignments
-- ──────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `assignments` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `slot_key` varchar(100) NOT NULL,
  `theme_id` int(10) unsigned DEFAULT NULL,
  `theme_file` varchar(255) DEFAULT NULL,
  `custom_post_id` int(10) unsigned DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_assignment_slot` (`slot_key`),
  KEY `idx_theme_id` (`theme_id`),
  KEY `idx_custom_post_id` (`custom_post_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- ──────────────────────────────────────────────────────────────
-- 11. login_attempts
-- ──────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(255) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `attempts` int(11) NOT NULL DEFAULT 1,
  `last_attempt` datetime NOT NULL,
  `blocked_until` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `email` (`email`),
  KEY `ip_address` (`ip_address`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ──────────────────────────────────────────────────────────────
-- 12. menus
-- ──────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `menus` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `slug` varchar(100) NOT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_menu_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ──────────────────────────────────────────────────────────────
-- 13. menu_items
-- ──────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `menu_items` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `menu_id` int(10) unsigned NOT NULL,
  `parent_id` int(10) unsigned DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `type` varchar(50) NOT NULL DEFAULT 'custom',
  `label` varchar(200) NOT NULL,
  `url` varchar(500) DEFAULT NULL,
  `target_id` int(10) unsigned DEFAULT NULL,
  `target_blank` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_menu_id` (`menu_id`),
  KEY `idx_parent_id` (`parent_id`),
  KEY `idx_sort_order` (`menu_id`,`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ──────────────────────────────────────────────────────────────
-- 14. sidebar_zones
-- ──────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `sidebar_zones` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `slug` varchar(100) NOT NULL,
  `description` varchar(255) DEFAULT '',
  `is_primary` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_sidebar_zone_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ──────────────────────────────────────────────────────────────
-- 15. sidebar_zone_items
-- ──────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `sidebar_zone_items` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `zone_id` int(10) unsigned NOT NULL,
  `type` varchar(50) NOT NULL,
  `title` varchar(200) NOT NULL DEFAULT '',
  `config` longtext DEFAULT NULL,
  `ordering` int(11) NOT NULL DEFAULT 0,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_zone_id` (`zone_id`),
  KEY `idx_ordering` (`zone_id`, `ordering`),
  CONSTRAINT `fk_szi_zone` FOREIGN KEY (`zone_id`) REFERENCES `sidebar_zones` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Core settings (installed overrides title/desc/url via installer)
INSERT INTO `settings` (`key`, `value`, `autoload`) VALUES
  ('posts_per_page',   '10',  1),
  ('active_theme',     'default', 1)
ON DUPLICATE KEY UPDATE `value` = VALUES(`value`);

-- Default theme
INSERT INTO `themes` (`id`, `folder_name`, `name`, `version`, `manifest_json`, `is_active`)
VALUES (1, 'default', 'Default Theme', '1.0.0', '{"name":"Default Theme","version":"1.0.0"}', 1)
ON DUPLICATE KEY UPDATE `folder_name` = VALUES(`folder_name`);

-- Default slot assignments
INSERT INTO `assignments` (`slot_key`, `theme_id`, `theme_file`) VALUES
  ('header',      1, 'header.php'),
  ('footer',      1, 'footer.php'),
  ('sidebar',     1, 'sidebar.php'),
  ('main.homepage', 1, 'main/homepage.php')
ON DUPLICATE KEY UPDATE `theme_id` = VALUES(`theme_id`);

-- Default "Primary" menu
INSERT IGNORE INTO `menus` (`id`, `name`, `slug`, `is_default`) VALUES (1, 'Primary', 'primary', 1);

-- Default "Main" sidebar zone
INSERT IGNORE INTO `sidebar_zones` (`name`, `slug`, `description`, `is_primary`) VALUES ('Main Sidebar', 'main', 'Sidebar utama website', 1);

-- Default sidebar zone items — mirrored from old hardcoded fallback
INSERT IGNORE INTO `sidebar_zone_items` (`zone_id`, `type`, `title`, `config`, `ordering`, `active`)
SELECT sz.id, 'search', 'Cari', '{"title":"Cari","placeholder":"Cari artikel..."}', 0, 1
FROM sidebar_zones sz WHERE sz.slug = 'main' AND NOT EXISTS (
  SELECT 1 FROM sidebar_zone_items WHERE zone_id = sz.id AND type = 'search'
);

INSERT IGNORE INTO `sidebar_zone_items` (`zone_id`, `type`, `title`, `config`, `ordering`, `active`)
SELECT sz.id, 'last_posts', 'Artikel Terbaru', '{"title":"Artikel Terbaru","limit":5,"type":"article"}', 1, 1
FROM sidebar_zones sz WHERE sz.slug = 'main' AND NOT EXISTS (
  SELECT 1 FROM sidebar_zone_items WHERE zone_id = sz.id AND type = 'last_posts'
);

INSERT IGNORE INTO `sidebar_zone_items` (`zone_id`, `type`, `title`, `config`, `ordering`, `active`)
SELECT sz.id, 'categories', 'Kategori', '{"title":"Kategori","limit":30,"only_parents":true}', 2, 1
FROM sidebar_zones sz WHERE sz.slug = 'main' AND NOT EXISTS (
  SELECT 1 FROM sidebar_zone_items WHERE zone_id = sz.id AND type = 'categories'
);

-- Default categories
INSERT IGNORE INTO `categories` (`id`, `name`, `slug`, `description`, `created_by`)
VALUES
  (1, 'Blog',        'blog',           'Tulisan dan artikel blog', NULL),
  (2, 'Services',    'services',       'Layanan yang kami tawarkan', NULL),
  (3, 'Web Development', 'web-development', 'Jasa pembuatan dan pengembangan website', NULL),
  (4, 'Tutorial',    'tutorial',       'Tutorial dan panduan', NULL);

-- Web Development is sub-category of Services
UPDATE `categories` SET `parent_id` = 2 WHERE `id` = 3;

-- Default menu items
INSERT IGNORE INTO `menu_items` (`id`, `menu_id`, `parent_id`, `sort_order`, `type`, `label`, `url`, `target_id`, `target_blank`)
VALUES
  (1, 1, NULL, 0, 'custom',   'Home',           '/',    NULL, 0),
  (2, 1, NULL, 1, 'category', 'Blog',           NULL,   1,    0),
  (3, 1, NULL, 2, 'category', 'Services',       NULL,   2,    0),
  (4, 1, 3,     0, 'category', 'Web Development', NULL,  3,    0),
   (5, 1, NULL, 3, 'category', 'Tutorial',       NULL,   4,    0);

-- ~~~~~~~~~~~~~~~~~~~~~~~~~~
-- 16. ui_translations
-- ~~~~~~~~~~~~~~~~~~~~~~~~~~
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

-- ~~~~~~~~~~~~~~~~~~~~~~~~~~
-- 17. schema_migrations
-- ~~~~~~~~~~~~~~~~~~~~~~~~~~
CREATE TABLE IF NOT EXISTS `schema_migrations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) NOT NULL,
  `batch` int(10) unsigned NOT NULL DEFAULT 1,
  `executed_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_migration` (`migration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS=1;
