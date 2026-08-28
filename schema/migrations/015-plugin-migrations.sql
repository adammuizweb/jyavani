CREATE TABLE IF NOT EXISTS `plugin_migrations` (
  `plugin_name` varchar(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `migration` varchar(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `checksum` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `applied_version` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `executed_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`plugin_name`,`migration`),
  KEY `idx_plugin_migrations_executed` (`executed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
