ALTER TABLE `media` ADD COLUMN `is_deleted` tinyint(1) NOT NULL DEFAULT 0 AFTER `updated_at`;
ALTER TABLE `media` ADD COLUMN `deleted_at` datetime DEFAULT NULL AFTER `is_deleted`;
ALTER TABLE `media` ADD COLUMN `quarantine_path` varchar(600) DEFAULT NULL AFTER `deleted_at`;
ALTER TABLE `media` ADD KEY `idx_media_deleted_owner` (`is_deleted`,`user_id`,`id`);
ALTER TABLE `media` ADD KEY `idx_media_deleted_at` (`is_deleted`,`deleted_at`,`id`);
ALTER TABLE `media` ADD KEY `idx_media_storage_identity` (`storage_disk`,`storage_path`);

ALTER TABLE `file` ADD COLUMN `is_deleted` tinyint(1) NOT NULL DEFAULT 0 AFTER `updated_at`;
ALTER TABLE `file` ADD COLUMN `deleted_at` datetime DEFAULT NULL AFTER `is_deleted`;
ALTER TABLE `file` ADD COLUMN `quarantine_path` varchar(600) DEFAULT NULL AFTER `deleted_at`;
ALTER TABLE `file` ADD KEY `idx_file_deleted_owner` (`is_deleted`,`user_id`,`id`);
ALTER TABLE `file` ADD KEY `idx_file_deleted_at` (`is_deleted`,`deleted_at`,`id`);
ALTER TABLE `file` ADD KEY `idx_file_storage_identity` (`storage_disk`,`storage_path`);

UPDATE `media`
SET `storage_disk` = 'public',
    `storage_path` = SUBSTRING(`url`, LENGTH('/static/img/') + 1)
WHERE `storage_path` IS NULL
  AND `url` LIKE '/static/img/%'
  AND SUBSTRING(`url`, LENGTH('/static/img/') + 1) REGEXP '^[0-9]{4}/[0-9]{2}/[^/]+$';

UPDATE `file`
SET `storage_disk` = 'public',
    `storage_path` = SUBSTRING(`url`, LENGTH('/static/files/') + 1)
WHERE `storage_path` IS NULL
  AND `url` LIKE '/static/files/%'
  AND SUBSTRING(`url`, LENGTH('/static/files/') + 1) REGEXP '^[0-9]{4}/[0-9]{2}/[^/]+$';

INSERT INTO `permissions` (`permission_key`,`provider`,`resource`,`action`,`label`,`supports_scope`) VALUES
  ('core.media.delete','core','media','trash','Move media to trash',1),
  ('core.media.restore','core','media','restore','Restore media',1),
  ('core.media.purge','core','media','purge','Permanently delete media',1),
  ('core.files.delete','core','files','trash','Move files to trash',1),
  ('core.files.restore','core','files','restore','Restore files',1),
  ('core.files.purge','core','files','purge','Permanently delete files',1)
ON DUPLICATE KEY UPDATE
  `provider`=VALUES(`provider`),
  `resource`=VALUES(`resource`),
  `action`=VALUES(`action`),
  `label`=VALUES(`label`),
  `supports_scope`=VALUES(`supports_scope`),
  `is_active`=1;

INSERT INTO `role_permissions` (`role_id`,`permission_key`,`scope`)
SELECT r.id, p.permission_key, 'own'
FROM `roles` r
JOIN `permissions` p ON p.permission_key IN ('core.media.restore','core.files.restore')
WHERE r.is_system = 1 AND r.slug IN ('author','editor')
ON DUPLICATE KEY UPDATE `scope`=VALUES(`scope`);

INSERT INTO `role_permissions` (`role_id`,`permission_key`,`scope`)
SELECT r.id, p.permission_key, 'own'
FROM `roles` r
JOIN `permissions` p ON p.permission_key IN ('core.media.purge','core.files.purge')
WHERE r.is_system = 1 AND r.slug = 'editor'
ON DUPLICATE KEY UPDATE `scope`=VALUES(`scope`);

INSERT INTO `role_permissions` (`role_id`,`permission_key`,`scope`)
SELECT r.id, p.permission_key, 'any'
FROM `roles` r
JOIN `permissions` p ON p.permission_key IN (
  'core.media.restore','core.media.purge',
  'core.files.restore','core.files.purge'
)
WHERE r.is_system = 1 AND r.slug = 'admin'
ON DUPLICATE KEY UPDATE `scope`=VALUES(`scope`);
