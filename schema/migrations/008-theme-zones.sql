-- 008-theme-zones.sql — Theme zone items for header/footer widget-area slots
CREATE TABLE IF NOT EXISTS `theme_zone_items` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `zone_slug` varchar(50) NOT NULL,
  `type` varchar(50) NOT NULL,
  `title` varchar(200) NOT NULL DEFAULT '',
  `config` longtext DEFAULT NULL,
  `ordering` int(11) NOT NULL DEFAULT 0,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_zone_order` (`zone_slug`, `ordering`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
