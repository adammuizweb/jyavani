-- Migration 006: Theme store integration columns
ALTER TABLE `themes` ADD COLUMN `is_system` TINYINT(1) DEFAULT 0;
ALTER TABLE `themes` ADD COLUMN `store_url` VARCHAR(255) DEFAULT '';
ALTER TABLE `themes` ADD COLUMN `store_slug` VARCHAR(100) DEFAULT '';
