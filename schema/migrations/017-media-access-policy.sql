ALTER TABLE `media`
  ADD COLUMN IF NOT EXISTS `visibility` enum('public','private') NOT NULL DEFAULT 'public' AFTER `credit`,
  ADD COLUMN IF NOT EXISTS `storage_disk` enum('public','private') NOT NULL DEFAULT 'public' AFTER `visibility`,
  ADD COLUMN IF NOT EXISTS `storage_path` varchar(500) DEFAULT NULL AFTER `storage_disk`,
  ADD COLUMN IF NOT EXISTS `access_scope` enum('public','editorial','admin') NOT NULL DEFAULT 'public' AFTER `storage_path`,
  ADD COLUMN IF NOT EXISTS `is_downloadable` tinyint(1) NOT NULL DEFAULT 1 AFTER `access_scope`;

ALTER TABLE `file`
  ADD COLUMN IF NOT EXISTS `visibility` enum('public','private') NOT NULL DEFAULT 'public' AFTER `media_type`,
  ADD COLUMN IF NOT EXISTS `storage_disk` enum('public','private') NOT NULL DEFAULT 'public' AFTER `visibility`,
  ADD COLUMN IF NOT EXISTS `storage_path` varchar(500) DEFAULT NULL AFTER `storage_disk`,
  ADD COLUMN IF NOT EXISTS `access_scope` enum('public','editorial','admin') NOT NULL DEFAULT 'public' AFTER `storage_path`,
  ADD COLUMN IF NOT EXISTS `is_downloadable` tinyint(1) NOT NULL DEFAULT 1 AFTER `access_scope`;
