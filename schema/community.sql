-- ──────────────────────────────────────────────────────────────
-- Community & Plugin Marketplace (jyavani.com only)
-- NOT part of CMS installation. Run separately on the
-- jyavani.com community site.
-- ──────────────────────────────────────────────────────────────

SET FOREIGN_KEY_CHECKS=0;

-- 1. members — community members (separate from admin users)
CREATE TABLE IF NOT EXISTS `members` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(100) NOT NULL,
  `email` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL,
  `display_name` varchar(100) DEFAULT NULL,
  `bio` text DEFAULT NULL,
  `avatar` varchar(255) DEFAULT NULL,
  `website` varchar(255) DEFAULT NULL,
  `location` varchar(100) DEFAULT NULL,
  `registered_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_login` datetime DEFAULT NULL,
  `is_verified` tinyint(1) NOT NULL DEFAULT 0,
  `is_banned` tinyint(1) NOT NULL DEFAULT 0,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_members_username` (`username`),
  UNIQUE KEY `uq_members_email` (`email`),
  KEY `idx_members_status` (`is_banned`,`is_deleted`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. plugin_categories — marketplace taxonomy
CREATE TABLE IF NOT EXISTS `plugin_categories` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `slug` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `icon` varchar(50) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_visible` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_plugincat_slug` (`slug`),
  KEY `idx_plugincat_visible` (`is_visible`,`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. plugins — marketplace listings
CREATE TABLE IF NOT EXISTS `plugins` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `member_id` int(10) unsigned NOT NULL,
  `name` varchar(100) NOT NULL,
  `slug` varchar(100) NOT NULL,
  `title` varchar(200) NOT NULL,
  `description` longtext DEFAULT NULL,
  `excerpt` varchar(300) DEFAULT NULL,
  `icon` varchar(255) DEFAULT NULL,
  `banner` varchar(255) DEFAULT NULL,
  `status` enum('draft','pending','approved','rejected','archived') NOT NULL DEFAULT 'draft',
  `php_required` varchar(20) DEFAULT '8.1',
  `tested_up_to` varchar(20) DEFAULT NULL,
  `homepage` varchar(255) DEFAULT NULL,
  `docs_url` varchar(255) DEFAULT NULL,
  `github_url` varchar(255) DEFAULT NULL,
  `total_downloads` int(10) unsigned NOT NULL DEFAULT 0,
  `total_ratings` int(10) unsigned NOT NULL DEFAULT 0,
  `avg_rating` decimal(2,1) NOT NULL DEFAULT 0.0,
  `category_id` int(10) unsigned DEFAULT NULL,
  `submission_notes` text DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `reviewed_by` int(10) unsigned DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_plugins_slug` (`slug`),
  UNIQUE KEY `uq_plugins_name` (`name`),
  KEY `idx_plugins_member` (`member_id`),
  KEY `idx_plugins_status` (`status`),
  KEY `idx_plugins_category` (`category_id`),
  KEY `idx_plugins_rating` (`avg_rating`),
  CONSTRAINT `fk_plugins_member` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_plugins_category` FOREIGN KEY (`category_id`) REFERENCES `plugin_categories` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. plugin_versions — release history
CREATE TABLE IF NOT EXISTS `plugin_versions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `plugin_id` int(10) unsigned NOT NULL,
  `version` varchar(20) NOT NULL,
  `changelog` text DEFAULT NULL,
  `zip_file` varchar(255) NOT NULL,
  `zip_size` int(10) unsigned NOT NULL DEFAULT 0,
  `php_required` varchar(20) DEFAULT NULL,
  `checksum` varchar(64) DEFAULT NULL,
  `is_current` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_pv_plugin` (`plugin_id`),
  KEY `idx_pv_current` (`plugin_id`,`is_current`),
  CONSTRAINT `fk_pv_plugin` FOREIGN KEY (`plugin_id`) REFERENCES `plugins` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. plugin_reviews — ratings & reviews
CREATE TABLE IF NOT EXISTS `plugin_reviews` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `plugin_id` int(10) unsigned NOT NULL,
  `member_id` int(10) unsigned NOT NULL,
  `version` varchar(20) DEFAULT NULL,
  `rating` tinyint(3) unsigned NOT NULL CHECK (`rating` >= 1 AND `rating` <= 5),
  `title` varchar(200) DEFAULT NULL,
  `content` text DEFAULT NULL,
  `status` enum('pending','approved','spam') NOT NULL DEFAULT 'approved',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pr_member_plugin` (`plugin_id`,`member_id`),
  KEY `idx_pr_plugin` (`plugin_id`),
  KEY `idx_pr_member` (`member_id`),
  KEY `idx_pr_rating` (`rating`),
  CONSTRAINT `fk_pr_plugin` FOREIGN KEY (`plugin_id`) REFERENCES `plugins` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_pr_member` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default plugin categories
INSERT IGNORE INTO `plugin_categories` (`id`, `name`, `slug`, `description`, `sort_order`, `is_visible`) VALUES
  (1, 'Admin Tools',  'admin-tools',  'Plugins for the admin dashboard',  0, 1),
  (2, 'Frontend',     'frontend',     'Templates, widgets, layout plugins', 1, 1),
  (3, 'Media',        'media',        'Image, video, file handling',       2, 1),
  (4, 'SEO',          'seo',          'Search engine optimization',        3, 1),
  (5, 'Security',     'security',     'Auth, firewall, captcha',           4, 1),
  (6, 'Integration',  'integration',  'Third-party API connectors',        5, 1);

SET FOREIGN_KEY_CHECKS=1;
