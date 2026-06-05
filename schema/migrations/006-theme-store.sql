-- Migration 006: Theme store integration columns
ALTER TABLE `themes`
  ADD COLUMN IF NOT EXISTS `is_system` TINYINT(1) DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `store_url` VARCHAR(255) DEFAULT '',
  ADD COLUMN IF NOT EXISTS `store_slug` VARCHAR(100) DEFAULT '';
