-- Consolidated Migration: Private file & media support
-- Replaces migration_002, migration_003, and migration_004
--
-- Usage:
--   For new installs: run the full script.
--   For existing installs that already ran 002-004:
--     Skip the ALTER TABLE ADD COLUMN statements if columns already exist.
--     The UPDATE statements safely normalize old 'employee'/'both' values.

-- ======= FILE TABLE =======
ALTER TABLE `file`
  ADD COLUMN `visibility` ENUM('public','private') NOT NULL DEFAULT 'public' AFTER `media_type`,
  ADD COLUMN `storage_disk` ENUM('public','private') NOT NULL DEFAULT 'public' AFTER `visibility`,
  ADD COLUMN `storage_path` VARCHAR(500) NULL AFTER `storage_disk`,
  ADD COLUMN `access_scope` ENUM('public','editorial','admin') NOT NULL DEFAULT 'public' AFTER `storage_path`,
  ADD COLUMN `is_downloadable` TINYINT(1) NOT NULL DEFAULT 1 AFTER `access_scope`;

UPDATE `file` SET `access_scope` = 'editorial' WHERE `access_scope` IN ('employee', 'both');

-- ======= MEDIA TABLE =======
ALTER TABLE `media`
  ADD COLUMN `visibility` ENUM('public','private') NOT NULL DEFAULT 'public' AFTER `credit`,
  ADD COLUMN `storage_disk` ENUM('public','private') NOT NULL DEFAULT 'public' AFTER `visibility`,
  ADD COLUMN `storage_path` VARCHAR(500) NULL AFTER `storage_disk`,
  ADD COLUMN `access_scope` ENUM('public','editorial','admin') NOT NULL DEFAULT 'public' AFTER `storage_path`,
  ADD COLUMN `is_downloadable` TINYINT(1) NOT NULL DEFAULT 1 AFTER `access_scope`;

UPDATE `media` SET `access_scope` = 'editorial' WHERE `access_scope` IN ('employee', 'both');
