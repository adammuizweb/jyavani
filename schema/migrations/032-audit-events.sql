CREATE TABLE IF NOT EXISTS `audit_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `event_id` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `request_id` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `operation_id` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `provider` varchar(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `event_key` varchar(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `actor_user_id` int(10) unsigned DEFAULT NULL,
  `actor_label` varchar(191) NOT NULL,
  `actor_email` varchar(255) DEFAULT NULL,
  `resource_type` varchar(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `resource_id` varchar(191) NOT NULL,
  `resource_label` varchar(255) DEFAULT NULL,
  `source` varchar(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `route` varchar(191) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `request_method` varchar(10) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `request_path` varchar(1024) DEFAULT NULL,
  `ip_address` varbinary(16) DEFAULT NULL,
  `user_agent_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `changes` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (`changes` IS NULL OR json_valid(`changes`)),
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (`metadata` IS NULL OR json_valid(`metadata`)),
  `authorization_audit_id` bigint(20) unsigned DEFAULT NULL,
  `occurred_at_utc` datetime(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_audit_events_event` (`event_id`),
  UNIQUE KEY `uq_audit_events_authorization` (`authorization_audit_id`),
  KEY `idx_audit_events_occurred` (`occurred_at_utc`,`id`),
  KEY `idx_audit_events_actor` (`actor_user_id`,`occurred_at_utc`,`id`),
  KEY `idx_audit_events_provider_event` (`provider`,`event_key`,`id`),
  KEY `idx_audit_events_resource` (`resource_type`,`resource_id`(150),`id`),
  KEY `idx_audit_events_operation` (`operation_id`,`id`),
  CONSTRAINT `fk_audit_events_actor` FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_audit_events_authorization` FOREIGN KEY (`authorization_audit_id`) REFERENCES `authorization_audit_log` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `permissions`
  (`permission_key`,`provider`,`resource`,`action`,`label`,`supports_scope`,`is_delegable`,`is_active`)
VALUES
  ('core.audit.read','core','audit','read','View audit log',0,0,1)
ON DUPLICATE KEY UPDATE
  `provider`=VALUES(`provider`),
  `resource`=VALUES(`resource`),
  `action`=VALUES(`action`),
  `label`=VALUES(`label`),
  `supports_scope`=VALUES(`supports_scope`),
  `is_delegable`=VALUES(`is_delegable`),
  `is_active`=1;

INSERT INTO `role_permissions` (`role_id`,`permission_key`,`scope`)
SELECT r.id, 'core.audit.read', 'global'
FROM `roles` r
WHERE r.slug = 'admin'
ON DUPLICATE KEY UPDATE `scope`=VALUES(`scope`);
