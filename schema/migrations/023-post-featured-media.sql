ALTER TABLE `posts`
  ADD COLUMN `thumbnail_media_id` int(10) unsigned DEFAULT NULL AFTER `thumbnail`;

ALTER TABLE `posts`
  ADD INDEX `idx_posts_thumbnail_media` (`thumbnail_media_id`);

UPDATE `posts` p
JOIN (
  SELECT BINARY `url` AS `exact_url`, MIN(`id`) AS `media_id`
  FROM `media`
  WHERE `is_deleted` = 0
  GROUP BY BINARY `url`
  HAVING COUNT(*) = 1
) m ON BINARY p.`thumbnail` = m.`exact_url`
SET p.`thumbnail_media_id` = m.`media_id`
WHERE p.`thumbnail_media_id` IS NULL
  AND p.`thumbnail` IS NOT NULL
  AND p.`thumbnail` <> '';

SET @jy_thumbnail_media_fk_exists = (
  SELECT COUNT(*) FROM `information_schema`.`TABLE_CONSTRAINTS`
  WHERE `CONSTRAINT_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'posts' AND `CONSTRAINT_NAME` = 'fk_posts_thumbnail_media'
);
SET @jy_thumbnail_media_sql = IF(
  @jy_thumbnail_media_fk_exists = 0,
  'ALTER TABLE `posts` ADD CONSTRAINT `fk_posts_thumbnail_media` FOREIGN KEY (`thumbnail_media_id`) REFERENCES `media` (`id`) ON DELETE SET NULL ON UPDATE CASCADE',
  'SELECT 1'
);
PREPARE jy_thumbnail_media_stmt FROM @jy_thumbnail_media_sql;
EXECUTE jy_thumbnail_media_stmt;
DEALLOCATE PREPARE jy_thumbnail_media_stmt;
