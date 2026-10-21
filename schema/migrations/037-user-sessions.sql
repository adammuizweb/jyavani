CREATE TABLE IF NOT EXISTS `user_sessions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `session_key_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `created_at_utc` datetime(6) NOT NULL,
  `last_seen_at_utc` datetime(6) NOT NULL,
  `expires_at_utc` datetime(6) NOT NULL,
  `revoked_at_utc` datetime(6) DEFAULT NULL,
  `revoked_by_user_id` int(10) unsigned DEFAULT NULL,
  `revoke_reason` varchar(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `ip_address` varbinary(16) DEFAULT NULL,
  `user_agent_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `user_agent` varchar(1024) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_sessions_key` (`session_key_hash`),
  KEY `idx_user_sessions_user_active` (`user_id`,`revoked_at_utc`,`expires_at_utc`),
  KEY `idx_user_sessions_retention` (`revoked_at_utc`,`expires_at_utc`,`id`),
  KEY `idx_user_sessions_revoker` (`revoked_by_user_id`),
  CONSTRAINT `fk_user_sessions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_user_sessions_revoker` FOREIGN KEY (`revoked_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `permissions`
  (`permission_key`,`provider`,`resource`,`action`,`label`,`supports_scope`,`is_delegable`,`is_active`)
VALUES
  ('core.sessions.manage','core','sessions','manage','Manage login sessions',1,1,1)
ON DUPLICATE KEY UPDATE
  `provider`=VALUES(`provider`),
  `resource`=VALUES(`resource`),
  `action`=VALUES(`action`),
  `label`=VALUES(`label`),
  `supports_scope`=VALUES(`supports_scope`),
  `is_delegable`=VALUES(`is_delegable`),
  `is_active`=1;

INSERT INTO `role_permissions` (`role_id`,`permission_key`,`scope`)
SELECT r.id, 'core.sessions.manage', IF(r.slug = 'admin', 'any', 'own')
FROM `roles` r
WHERE r.slug IN ('author','editor','admin')
ON DUPLICATE KEY UPDATE `scope`=VALUES(`scope`);
