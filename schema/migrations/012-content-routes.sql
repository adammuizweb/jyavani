-- 012-content-routes.sql - Canonical and redirect paths for routable content
CREATE TABLE IF NOT EXISTS `content_routes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `post_id` int(10) unsigned NOT NULL,
  `locale` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `path` varchar(512) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `is_canonical` tinyint(1) NOT NULL DEFAULT 0,
  `canonical_slot` tinyint(3) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_content_routes_locale_path` (`locale`,`path`),
  UNIQUE KEY `uq_content_routes_post_locale_canonical` (`post_id`,`locale`,`canonical_slot`),
  KEY `idx_content_routes_post_locale` (`post_id`,`locale`),
  KEY `idx_content_routes_canonical_lookup` (`post_id`,`locale`,`is_canonical`),
  CONSTRAINT `chk_content_routes_canonical` CHECK (
    (`is_canonical` = 1 AND `canonical_slot` = 1)
    OR (`is_canonical` = 0 AND `canonical_slot` IS NULL)
  ),
  CONSTRAINT `fk_content_routes_post` FOREIGN KEY (`post_id`) REFERENCES `posts` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
