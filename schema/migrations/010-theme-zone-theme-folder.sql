-- 010-theme-zone-theme-folder.sql — Per-theme scoping for theme zone items
ALTER TABLE `theme_zone_items`
  ADD COLUMN `theme_folder` VARCHAR(100) NOT NULL DEFAULT '' AFTER `zone_slug`;

ALTER TABLE `theme_zone_items`
  ADD KEY `idx_theme_zone` (`theme_folder`, `zone_slug`, `position`, `ordering`);

UPDATE `theme_zone_items` t
  JOIN `settings` s ON s.`key` = 'active_theme'
  SET t.`theme_folder` = s.`value`
  WHERE t.`theme_folder` = '';
