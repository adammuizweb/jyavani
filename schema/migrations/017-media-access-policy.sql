ALTER TABLE `media` ADD COLUMN `visibility` enum('public','private') NOT NULL DEFAULT 'public' AFTER `credit`;
ALTER TABLE `media` ADD COLUMN `storage_disk` enum('public','private') NOT NULL DEFAULT 'public' AFTER `visibility`;
ALTER TABLE `media` ADD COLUMN `storage_path` varchar(500) DEFAULT NULL AFTER `storage_disk`;
ALTER TABLE `media` ADD COLUMN `access_scope` enum('public','editorial','admin') NOT NULL DEFAULT 'public' AFTER `storage_path`;
ALTER TABLE `media` ADD COLUMN `is_downloadable` tinyint(1) NOT NULL DEFAULT 1 AFTER `access_scope`;

ALTER TABLE `file` ADD COLUMN `visibility` enum('public','private') NOT NULL DEFAULT 'public' AFTER `media_type`;
ALTER TABLE `file` ADD COLUMN `storage_disk` enum('public','private') NOT NULL DEFAULT 'public' AFTER `visibility`;
ALTER TABLE `file` ADD COLUMN `storage_path` varchar(500) DEFAULT NULL AFTER `storage_disk`;
ALTER TABLE `file` ADD COLUMN `access_scope` enum('public','editorial','admin') NOT NULL DEFAULT 'public' AFTER `storage_path`;
ALTER TABLE `file` ADD COLUMN `is_downloadable` tinyint(1) NOT NULL DEFAULT 1 AFTER `access_scope`;
