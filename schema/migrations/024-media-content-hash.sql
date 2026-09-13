ALTER TABLE `media`
  ADD COLUMN `content_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL AFTER `height`;

ALTER TABLE `media`
  ADD INDEX `idx_media_content_hash` (`content_hash`,`is_deleted`);
