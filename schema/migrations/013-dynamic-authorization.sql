ALTER TABLE `users`
  ADD COLUMN `is_site_owner` tinyint(1) NOT NULL DEFAULT 0 AFTER `role`;

ALTER TABLE `users`
  ADD COLUMN `site_owner_previous_role` enum('none','author','editor','admin') DEFAULT NULL AFTER `is_site_owner`;

ALTER TABLE `users`
  MODIFY COLUMN `role` enum('none','author','editor','admin') NOT NULL DEFAULT 'none';

ALTER TABLE `users`
  ADD INDEX `idx_users_site_owner_active` (`is_site_owner`,`is_deleted`,`is_locked`,`id`);

CREATE TABLE IF NOT EXISTS `roles` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `slug` varchar(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `name` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `authority_rank` int(11) NOT NULL DEFAULT 0,
  `is_system` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_roles_slug` (`slug`),
  KEY `idx_roles_rank` (`authority_rank`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `permissions` (
  `permission_key` varchar(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `provider` varchar(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `resource` varchar(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `action` varchar(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `label` varchar(191) NOT NULL,
  `supports_scope` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`permission_key`),
  KEY `idx_permissions_provider_active` (`provider`,`is_active`),
  KEY `idx_permissions_resource_action` (`resource`,`action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_roles` (
  `user_id` int(10) unsigned NOT NULL,
  `role_id` int(10) unsigned NOT NULL,
  `assigned_by` int(10) unsigned DEFAULT NULL,
  `assigned_at` datetime NOT NULL DEFAULT current_timestamp(),
  `expires_at` datetime DEFAULT NULL,
  PRIMARY KEY (`user_id`,`role_id`),
  KEY `idx_user_roles_role` (`role_id`),
  KEY `idx_user_roles_expiry` (`expires_at`),
  KEY `idx_user_roles_assigned_by` (`assigned_by`),
  CONSTRAINT `fk_user_roles_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_user_roles_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_user_roles_assigned_by` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `site_owner_role_backups` (
  `user_id` int(10) unsigned NOT NULL,
  `role_id` int(10) unsigned NOT NULL,
  `assigned_by` int(10) unsigned DEFAULT NULL,
  `assigned_at` datetime NOT NULL,
  `expires_at` datetime DEFAULT NULL,
  `backed_up_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`user_id`,`role_id`),
  KEY `idx_site_owner_role_backup_role` (`role_id`),
  KEY `idx_site_owner_role_backup_assigner` (`assigned_by`),
  CONSTRAINT `fk_site_owner_role_backup_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_site_owner_role_backup_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_site_owner_role_backup_assigner` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `role_permissions` (
  `role_id` int(10) unsigned NOT NULL,
  `permission_key` varchar(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `scope` enum('global','own','same_or_lower','any') NOT NULL DEFAULT 'global',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`role_id`,`permission_key`),
  KEY `idx_role_permissions_permission` (`permission_key`),
  CONSTRAINT `fk_role_permissions_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_role_permissions_permission` FOREIGN KEY (`permission_key`) REFERENCES `permissions` (`permission_key`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `authorization_audit_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `actor_user_id` int(10) unsigned DEFAULT NULL,
  `subject_user_id` int(10) unsigned DEFAULT NULL,
  `event_key` varchar(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `resource_type` varchar(100) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `resource_id` varchar(191) DEFAULT NULL,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (`metadata` IS NULL OR json_valid(`metadata`)),
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_authz_audit_actor` (`actor_user_id`,`created_at`),
  KEY `idx_authz_audit_subject` (`subject_user_id`,`created_at`),
  KEY `idx_authz_audit_event` (`event_key`,`created_at`),
  CONSTRAINT `fk_authz_audit_actor` FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_authz_audit_subject` FOREIGN KEY (`subject_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `roles` (`slug`,`name`,`description`,`authority_rank`,`is_system`) VALUES
  ('author','Author','Creates and manages owned content.',10,1),
  ('editor','Editor','Manages editorial resources and owned content.',50,1),
  ('admin','Administrator','Manages the site, users, extensions, and all content.',100,1)
ON DUPLICATE KEY UPDATE
  `name`=VALUES(`name`),
  `description`=VALUES(`description`),
  `authority_rank`=VALUES(`authority_rank`),
  `is_system`=VALUES(`is_system`);

INSERT INTO `permissions` (`permission_key`,`provider`,`resource`,`action`,`label`,`supports_scope`) VALUES
  ('core.dashboard.access','core','dashboard','access','Access dashboard',0),
  ('core.dashboard.stats.read','core','dashboard','read_stats','View dashboard statistics',1),
  ('core.dashboard.layout.manage','core','dashboard','manage_layout','Manage dashboard layout',0),
  ('core.posts.read','core','posts','read','View posts',1),
  ('core.posts.create','core','posts','create','Create posts',0),
  ('core.posts.update','core','posts','update','Update posts',1),
  ('core.posts.trash','core','posts','trash','Move posts to trash',1),
  ('core.posts.restore','core','posts','restore','Restore posts',1),
  ('core.posts.purge','core','posts','purge','Permanently delete posts',1),
  ('core.posts.publish','core','posts','publish','Publish posts',1),
  ('core.posts.change_owner','core','posts','change_owner','Change post owner',1),
  ('core.posts.change_dates','core','posts','change_dates','Change post dates',1),
  ('core.posts.unfiltered_html','core','posts','unfiltered_html','Use unfiltered post HTML',0),
  ('core.pages.read','core','pages','read','View pages',1),
  ('core.pages.create','core','pages','create','Create pages',0),
  ('core.pages.update','core','pages','update','Update pages',1),
  ('core.pages.trash','core','pages','trash','Move pages to trash',1),
  ('core.pages.restore','core','pages','restore','Restore pages',1),
  ('core.pages.purge','core','pages','purge','Permanently delete pages',1),
  ('core.pages.publish','core','pages','publish','Publish pages',1),
  ('core.pages.change_owner','core','pages','change_owner','Change page owner',1),
  ('core.pages.change_dates','core','pages','change_dates','Change page dates',1),
  ('core.pages.unfiltered_html','core','pages','unfiltered_html','Use unfiltered page HTML',0),
  ('core.categories.read','core','categories','read','View categories',1),
  ('core.categories.create','core','categories','create','Create categories',0),
  ('core.categories.update','core','categories','update','Update categories',1),
  ('core.categories.trash','core','categories','trash','Move categories to trash',1),
  ('core.categories.restore','core','categories','restore','Restore categories',1),
  ('core.categories.purge','core','categories','purge','Permanently delete categories',1),
  ('core.media.read','core','media','read','View media',1),
  ('core.media.upload','core','media','upload','Upload media',0),
  ('core.media.update','core','media','update','Update media',1),
  ('core.media.delete','core','media','delete','Delete media',1),
  ('core.files.read','core','files','read','View files',1),
  ('core.files.upload','core','files','upload','Upload files',0),
  ('core.files.update','core','files','update','Update files',1),
  ('core.files.delete','core','files','delete','Delete files',1),
  ('core.menus.manage','core','menus','manage','Manage menus',0),
  ('core.sidebar.manage','core','sidebar','manage','Manage sidebar zones',0),
  ('core.theme_content.read','core','theme_content','read','View theme content',1),
  ('core.theme_content.create','core','theme_content','create','Create theme content',0),
  ('core.theme_content.update','core','theme_content','update','Update theme content',1),
  ('core.theme_content.delete','core','theme_content','delete','Delete theme content',1),
  ('core.themes.manage','core','themes','manage','Manage installed themes',0),
  ('core.shortcodes.read','core','shortcodes','read','View shortcode presets',1),
  ('core.shortcodes.create','core','shortcodes','create','Create shortcode presets',0),
  ('core.shortcodes.update','core','shortcodes','update','Update shortcode presets',1),
  ('core.shortcodes.delete','core','shortcodes','delete','Delete shortcode presets',1),
  ('core.shortcode_layouts.manage','core','shortcode_layouts','manage','Manage shortcode layouts',0),
  ('core.users.read','core','users','read','View users',1),
  ('core.users.create','core','users','create','Create users',0),
  ('core.users.update','core','users','update','Update users',1),
  ('core.users.delete','core','users','delete','Delete users',1),
  ('core.users.restore','core','users','restore','Restore users',0),
  ('core.users.purge','core','users','purge','Permanently delete users',0),
  ('core.users.lock','core','users','lock','Lock and unlock users',1),
  ('core.users.assign_roles','core','users','assign_roles','Assign roles to users',1),
  ('core.roles.manage','core','roles','manage','Manage roles and permissions',0),
  ('core.settings.manage','core','settings','manage','Manage site settings',0),
  ('core.plugins.manage','core','plugins','manage','Manage plugins',0),
  ('core.updates.manage','core','updates','manage','Manage Core updates',0),
  ('core.profile.manage','core','profile','manage','Manage own profile',0),
  ('core.content.preview','core','content','preview','Preview unpublished content',1),
  ('core.private_assets.read','core','private_assets','read','Read private assets',1)
ON DUPLICATE KEY UPDATE
  `provider`=VALUES(`provider`),
  `resource`=VALUES(`resource`),
  `action`=VALUES(`action`),
  `label`=VALUES(`label`),
  `supports_scope`=VALUES(`supports_scope`),
  `is_active`=1;

INSERT INTO `role_permissions` (`role_id`,`permission_key`,`scope`)
SELECT r.id, grants.permission_key, grants.scope
FROM `roles` r
JOIN (
  SELECT 'core.dashboard.access' permission_key, 'global' scope UNION ALL
  SELECT 'core.dashboard.stats.read', 'any' UNION ALL
  SELECT 'core.posts.read', 'own' UNION ALL
  SELECT 'core.posts.create', 'global' UNION ALL
  SELECT 'core.posts.update', 'own' UNION ALL
  SELECT 'core.posts.trash', 'own' UNION ALL
  SELECT 'core.posts.restore', 'own' UNION ALL
  SELECT 'core.posts.purge', 'own' UNION ALL
  SELECT 'core.posts.publish', 'own' UNION ALL
  SELECT 'core.posts.change_dates', 'own' UNION ALL
  SELECT 'core.pages.read', 'own' UNION ALL
  SELECT 'core.pages.create', 'global' UNION ALL
  SELECT 'core.pages.update', 'own' UNION ALL
  SELECT 'core.pages.trash', 'own' UNION ALL
  SELECT 'core.pages.restore', 'own' UNION ALL
  SELECT 'core.pages.purge', 'own' UNION ALL
  SELECT 'core.pages.publish', 'own' UNION ALL
  SELECT 'core.categories.read', 'any' UNION ALL
  SELECT 'core.categories.create', 'global' UNION ALL
  SELECT 'core.categories.update', 'own' UNION ALL
  SELECT 'core.media.read', 'own' UNION ALL
  SELECT 'core.media.upload', 'global' UNION ALL
  SELECT 'core.media.update', 'own' UNION ALL
  SELECT 'core.media.delete', 'own' UNION ALL
  SELECT 'core.files.read', 'own' UNION ALL
  SELECT 'core.files.upload', 'global' UNION ALL
  SELECT 'core.files.update', 'own' UNION ALL
  SELECT 'core.files.delete', 'own' UNION ALL
  SELECT 'core.theme_content.read', 'own' UNION ALL
  SELECT 'core.theme_content.create', 'global' UNION ALL
  SELECT 'core.theme_content.update', 'own' UNION ALL
  SELECT 'core.shortcodes.read', 'own' UNION ALL
  SELECT 'core.shortcodes.create', 'global' UNION ALL
  SELECT 'core.shortcodes.update', 'own' UNION ALL
  SELECT 'core.shortcodes.delete', 'own' UNION ALL
  SELECT 'core.profile.manage', 'global' UNION ALL
  SELECT 'core.content.preview', 'any' UNION ALL
  SELECT 'core.private_assets.read', 'any'
) grants ON r.slug = 'author'
ON DUPLICATE KEY UPDATE `scope`=VALUES(`scope`);

INSERT INTO `role_permissions` (`role_id`,`permission_key`,`scope`)
SELECT editor.id, rp.permission_key, rp.scope
FROM `roles` editor
JOIN `roles` author ON author.slug = 'author'
JOIN `role_permissions` rp ON rp.role_id = author.id
WHERE editor.slug = 'editor'
ON DUPLICATE KEY UPDATE `scope`=VALUES(`scope`);

INSERT INTO `role_permissions` (`role_id`,`permission_key`,`scope`)
SELECT r.id, grants.permission_key, grants.scope
FROM `roles` r
JOIN (
  SELECT 'core.posts.unfiltered_html' permission_key, 'global' scope UNION ALL
  SELECT 'core.pages.unfiltered_html', 'global' UNION ALL
  SELECT 'core.categories.update', 'any' UNION ALL
  SELECT 'core.categories.trash', 'any' UNION ALL
  SELECT 'core.categories.restore', 'any' UNION ALL
  SELECT 'core.categories.purge', 'any' UNION ALL
  SELECT 'core.menus.manage', 'global' UNION ALL
  SELECT 'core.sidebar.manage', 'global' UNION ALL
  SELECT 'core.posts.restore', 'any' UNION ALL
  SELECT 'core.posts.purge', 'any' UNION ALL
  SELECT 'core.pages.restore', 'any' UNION ALL
  SELECT 'core.pages.purge', 'any'
) grants ON r.slug = 'editor'
ON DUPLICATE KEY UPDATE `scope`=VALUES(`scope`);

INSERT INTO `role_permissions` (`role_id`,`permission_key`,`scope`)
SELECT r.id, p.permission_key, IF(p.supports_scope = 1, 'any', 'global')
FROM `roles` r
CROSS JOIN `permissions` p
WHERE r.slug = 'admin' AND p.provider = 'core'
ON DUPLICATE KEY UPDATE `scope`=VALUES(`scope`);

INSERT IGNORE INTO `user_roles` (`user_id`,`role_id`,`assigned_by`)
SELECT u.id, r.id, NULL
FROM `users` u
JOIN `roles` r ON r.slug = u.role;
