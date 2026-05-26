-- Migration 005: Sidebar Zone System (sidebar_zones + sidebar_zone_items tables)
-- Multi-zone sidebar management with primary zone selection.

-- ======= 1. Create sidebar_zones table =======
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

-- ======= 2. Create sidebar_zone_items table =======
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

-- ======= 3. Insert default "Main" sidebar zone =======
INSERT IGNORE INTO `sidebar_zones` (`name`, `slug`, `description`, `is_primary`) VALUES ('Main Sidebar', 'main', 'Sidebar utama website', 1);

-- ======= 4. Insert default items for Main zone =======
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
