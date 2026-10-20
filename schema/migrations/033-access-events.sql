CREATE TABLE IF NOT EXISTS `access_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `event_id` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `request_id` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `surface` varchar(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `event_key` varchar(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `actor_user_id` int(10) unsigned DEFAULT NULL,
  `actor_label` varchar(191) NOT NULL,
  `actor_email` varchar(255) DEFAULT NULL,
  `route` varchar(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `request_path` varchar(1024) NOT NULL,
  `page_title` varchar(255) DEFAULT NULL,
  `resource_type` varchar(100) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `resource_id` varchar(191) DEFAULT NULL,
  `resource_label` varchar(255) DEFAULT NULL,
  `response_status` smallint(5) unsigned NOT NULL,
  `ip_address` varbinary(16) DEFAULT NULL,
  `user_agent_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `occurred_at_utc` datetime(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_access_events_event` (`event_id`),
  KEY `idx_access_events_occurred` (`occurred_at_utc`,`id`),
  KEY `idx_access_events_actor` (`actor_user_id`,`occurred_at_utc`,`id`),
  KEY `idx_access_events_surface_route` (`surface`,`route`,`id`),
  CONSTRAINT `fk_access_events_actor` FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `permissions`
  (`permission_key`,`provider`,`resource`,`action`,`label`,`supports_scope`,`is_delegable`,`is_active`)
VALUES
  ('core.audit.manage','core','audit','manage','Manage audit logging',0,0,1)
ON DUPLICATE KEY UPDATE
  `provider`=VALUES(`provider`),
  `resource`=VALUES(`resource`),
  `action`=VALUES(`action`),
  `label`=VALUES(`label`),
  `supports_scope`=VALUES(`supports_scope`),
  `is_delegable`=VALUES(`is_delegable`),
  `is_active`=1;

INSERT INTO `role_permissions` (`role_id`,`permission_key`,`scope`)
SELECT r.id, 'core.audit.manage', 'global'
FROM `roles` r
WHERE r.slug = 'admin'
ON DUPLICATE KEY UPDATE `scope`=VALUES(`scope`);

INSERT IGNORE INTO `settings` (`key`,`value`,`autoload`) VALUES
  ('audit_dashboard_navigation_enabled','0',1),
  ('audit_public_page_views_enabled','0',1),
  ('audit_access_retention_days','90',1),
  ('audit_access_last_pruned_utc','1970-01-01 00:00:00',1);
