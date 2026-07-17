-- 009-theme-zone-position.sql — Add position column to theme_zone_items
ALTER TABLE `theme_zone_items`
  ADD COLUMN `position` VARCHAR(50) NOT NULL DEFAULT '' AFTER `zone_slug`,
  ADD KEY `idx_zone_position` (`zone_slug`, `position`, `ordering`);
