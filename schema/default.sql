-- Adiwira CMS — Default Schema (MariaDB 10.6+)
-- Dumped from production database 2026-05-25
-- Safe for fresh install: uses CREATE TABLE IF NOT EXISTS + ON DUPLICATE KEY UPDATE

SET FOREIGN_KEY_CHECKS=0;
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ──────────────────────────────────────────────────────────────
-- 1. users
-- ──────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `users` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(255) NOT NULL,
  `username` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `name` varchar(100) DEFAULT NULL,
  `bio` text DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `role` enum('none','author','editor','admin') NOT NULL DEFAULT 'none',
  `is_site_owner` tinyint(1) NOT NULL DEFAULT 0,
  `site_owner_previous_role` enum('none','author','editor','admin') DEFAULT NULL,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `is_locked` tinyint(1) NOT NULL DEFAULT 0,
  `img` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `uq_users_username` (`username`),
  KEY `idx_users_username` (`username`),
  KEY `idx_users_status` (`is_deleted`,`is_locked`,`role`),
  KEY `idx_users_site_owner_active` (`is_site_owner`,`is_deleted`,`is_locked`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- ──────────────────────────────────────────────────────────────
-- 1b. dynamic authorization
-- ──────────────────────────────────────────────────────────────
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
  `is_delegable` tinyint(1) NOT NULL DEFAULT 1,
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

-- ──────────────────────────────────────────────────────────────
-- 2. settings
-- ──────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `settings` (
  `key` varchar(64) NOT NULL,
  `value` text DEFAULT NULL,
  `autoload` tinyint(1) NOT NULL DEFAULT 1,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- ──────────────────────────────────────────────────────────────
-- 3. categories
-- ──────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `categories` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(191) NOT NULL,
  `slug` varchar(191) NOT NULL,
  `description` text DEFAULT NULL,
  `parent_id` int(10) unsigned DEFAULT NULL,
  `meta` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`meta`)),
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_categories_slug` (`slug`),
  KEY `idx_parent` (`parent_id`),
  KEY `idx_created_by` (`created_by`),
  CONSTRAINT `fk_categories_created_by_users` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ──────────────────────────────────────────────────────────────
-- 4. posts
-- ──────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `posts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(191) NOT NULL,
  `slug` varchar(191) NOT NULL,
  `content` longtext NOT NULL,
  `type` varchar(50) NOT NULL DEFAULT 'article',
  `meta` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`meta`)),
  `youtube` varchar(255) DEFAULT NULL,
  `thumbnail` varchar(255) DEFAULT NULL,
  `status` enum('draft','published','private') NOT NULL DEFAULT 'draft',
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `deleted_at` datetime DEFAULT NULL,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_type` (`type`),
  KEY `idx_status` (`status`),
  KEY `idx_created_by` (`created_by`),
  FULLTEXT KEY `ft_title_content` (`title`,`content`),
  CONSTRAINT `fk_posts_created_by_users` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ──────────────────────────────────────────────────────────────
-- 4b. content_routes
-- ──────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `content_routes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `post_id` int(10) unsigned NOT NULL,
  `locale` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `path` varchar(512) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `is_canonical` tinyint(1) NOT NULL DEFAULT 0,
  `canonical_slot` tinyint(3) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_content_routes_locale_path` (`locale`,`path`),
  UNIQUE KEY `uq_content_routes_post_locale_canonical` (`post_id`,`locale`,`canonical_slot`),
  KEY `idx_content_routes_post_locale` (`post_id`,`locale`),
  KEY `idx_content_routes_canonical_lookup` (`post_id`,`locale`,`is_canonical`),
  CONSTRAINT `chk_content_routes_canonical` CHECK (
    (`is_canonical` = 1 AND `canonical_slot` = 1)
    OR (`is_canonical` = 0 AND `canonical_slot` IS NULL)
  ),
  CONSTRAINT `fk_content_routes_post` FOREIGN KEY (`post_id`) REFERENCES `posts` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ──────────────────────────────────────────────────────────────
-- 5. post_categories
-- ──────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `post_categories` (
  `post_id` int(10) unsigned NOT NULL,
  `category_id` int(10) unsigned NOT NULL,
  `assigned_by` int(10) unsigned DEFAULT NULL,
  `assigned_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`post_id`,`category_id`),
  KEY `idx_category` (`category_id`),
  KEY `idx_assigned_by` (`assigned_by`),
  CONSTRAINT `fk_pc_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_pc_post` FOREIGN KEY (`post_id`) REFERENCES `posts` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ──────────────────────────────────────────────────────────────
-- 6. media
-- ──────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `media` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `url` varchar(512) NOT NULL,
  `filename` varchar(255) DEFAULT NULL,
  `mime` varchar(100) DEFAULT NULL,
  `ext` varchar(10) DEFAULT NULL,
  `size` int(10) unsigned DEFAULT 0,
  `width` int(10) unsigned DEFAULT NULL,
  `height` int(10) unsigned DEFAULT NULL,
  `title` varchar(255) DEFAULT NULL,
  `alt` varchar(255) DEFAULT NULL,
  `caption` text DEFAULT NULL,
  `credit` varchar(255) DEFAULT NULL,
  `visibility` enum('public','private') NOT NULL DEFAULT 'public',
  `storage_disk` enum('public','private') NOT NULL DEFAULT 'public',
  `storage_path` varchar(500) DEFAULT NULL,
  `access_scope` enum('public','editorial','admin') NOT NULL DEFAULT 'public',
  `is_downloadable` tinyint(1) NOT NULL DEFAULT 1,
  `user_id` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `target_url` varchar(2048) DEFAULT NULL,
  `target_attribute` varchar(10) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ──────────────────────────────────────────────────────────────
-- 7. post_media_items
-- ──────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `post_media_items` (
  `post_id` int(10) unsigned NOT NULL,
  `media_id` int(10) unsigned NOT NULL,
  `sort_order` int(10) unsigned NOT NULL DEFAULT 0,
  `caption_override` text DEFAULT NULL,
  `alt_override` varchar(255) DEFAULT NULL,
  `link_url_override` varchar(2048) DEFAULT NULL,
  `link_target_override` enum('_self','_blank','_parent','_top') DEFAULT NULL,
  PRIMARY KEY (`post_id`,`media_id`),
  KEY `idx_post_order` (`post_id`,`sort_order`),
  KEY `idx_media` (`media_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ──────────────────────────────────────────────────────────────
-- 8. file
-- ──────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `file` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `url` varchar(512) NOT NULL,
  `filename` varchar(255) DEFAULT NULL,
  `mime` varchar(100) DEFAULT NULL,
  `ext` varchar(10) DEFAULT NULL,
  `size` int(10) unsigned DEFAULT 0,
  `title` varchar(255) DEFAULT NULL,
  `caption` text DEFAULT NULL,
  `credit` varchar(255) DEFAULT NULL,
  `user_id` int(10) unsigned DEFAULT NULL,
  `media_type` varchar(20) NOT NULL DEFAULT 'file',
  `visibility` enum('public','private') NOT NULL DEFAULT 'public',
  `storage_disk` enum('public','private') NOT NULL DEFAULT 'public',
  `storage_path` varchar(500) DEFAULT NULL,
  `access_scope` enum('public','editorial','admin') NOT NULL DEFAULT 'public',
  `is_downloadable` tinyint(1) NOT NULL DEFAULT 1,
  `duration` int(10) unsigned DEFAULT NULL,
  `thumb_url` varchar(512) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ──────────────────────────────────────────────────────────────
-- 9. themes
-- ──────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `themes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `folder_name` varchar(100) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 0,
  `is_system` tinyint(1) NOT NULL DEFAULT 0,
  `store_url` varchar(255) DEFAULT '',
  `store_slug` varchar(100) DEFAULT '',
  `name` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `version` varchar(50) DEFAULT NULL,
  `author` varchar(150) DEFAULT NULL,
  `screenshot` varchar(255) DEFAULT NULL,
  `manifest_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`manifest_json`)),
  `scanned_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_theme_folder` (`folder_name`),
  KEY `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- ──────────────────────────────────────────────────────────────
-- 10. assignments
-- ──────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `assignments` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `slot_key` varchar(100) NOT NULL,
  `slot_owner` varchar(100) DEFAULT NULL,
  `theme_id` int(10) unsigned DEFAULT NULL,
  `theme_file` varchar(255) DEFAULT NULL,
  `custom_post_id` int(10) unsigned DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_assignment_slot` (`slot_key`),
  KEY `idx_theme_id` (`theme_id`),
  KEY `idx_custom_post_id` (`custom_post_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- ──────────────────────────────────────────────────────────────
-- 11. login_attempts
-- ──────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(255) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `attempts` int(11) NOT NULL DEFAULT 1,
  `last_attempt` datetime NOT NULL,
  `blocked_until` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `email` (`email`),
  KEY `ip_address` (`ip_address`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ──────────────────────────────────────────────────────────────
-- 12. menus
-- ──────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `menus` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `slug` varchar(100) NOT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_menu_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ──────────────────────────────────────────────────────────────
-- 13. menu_items
-- ──────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `menu_items` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `menu_id` int(10) unsigned NOT NULL,
  `parent_id` int(10) unsigned DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `type` varchar(50) NOT NULL DEFAULT 'custom',
  `label` varchar(200) NOT NULL,
  `url` varchar(500) DEFAULT NULL,
  `target_id` int(10) unsigned DEFAULT NULL,
  `target_blank` tinyint(1) NOT NULL DEFAULT 0,
  `hidden` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_menu_id` (`menu_id`),
  KEY `idx_parent_id` (`parent_id`),
  KEY `idx_sort_order` (`menu_id`,`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ──────────────────────────────────────────────────────────────
-- 14. sidebar_zones
-- ──────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `sidebar_zones` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `slug` varchar(100) NOT NULL,
  `description` varchar(255) DEFAULT '',
  `is_primary` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_sidebar_zone_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ──────────────────────────────────────────────────────────────
-- 15. sidebar_zone_items
-- ──────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `sidebar_zone_items` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `zone_id` int(10) unsigned NOT NULL,
  `type` varchar(50) NOT NULL,
  `title` varchar(200) NOT NULL DEFAULT '',
  `config` longtext DEFAULT NULL,
  `ordering` int(11) NOT NULL DEFAULT 0,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_zone_id` (`zone_id`),
  KEY `idx_ordering` (`zone_id`, `ordering`),
  CONSTRAINT `fk_szi_zone` FOREIGN KEY (`zone_id`) REFERENCES `sidebar_zones` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ~~~~~~~~~~~~~~~~~~~~~~~~~~
-- 15b. theme_zone_items
-- ~~~~~~~~~~~~~~~~~~~~~~~~~~
CREATE TABLE IF NOT EXISTS `theme_zone_items` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `theme_folder` varchar(100) NOT NULL DEFAULT '',
  `zone_slug` varchar(50) NOT NULL,
  `position` varchar(50) NOT NULL DEFAULT '',
  `type` varchar(50) NOT NULL,
  `title` varchar(200) NOT NULL DEFAULT '',
  `config` longtext DEFAULT NULL,
  `ordering` int(11) NOT NULL DEFAULT 0,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_zone_order` (`zone_slug`, `ordering`),
  KEY `idx_zone_position` (`zone_slug`, `position`, `ordering`),
  KEY `idx_theme_zone` (`theme_folder`, `zone_slug`, `position`, `ordering`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default theme zone items (header, footer, single.post, list.post)
INSERT INTO `theme_zone_items` (`theme_folder`, `zone_slug`, `position`, `type`, `title`, `config`, `ordering`, `active`) VALUES
  ('default', 'footer', 'about', 'tz_html', 'Site Logo', '{"title": "", "html": "<a href=\\"/\\" class=\\"brand onload wave-span\\" data-anim-trigger=\\"load\\" data-wave-target=\\".jyavani-logo\\" data-wave-step=\\"28\\">\\n  <img src=\\"/static/img/jyavani.svg\\" alt=\\"Jyavani\\" class=\\"flip-logo onload\\" data-anim-trigger=\\"load\\" data-fl-duration=\\"900\\" data-fl-delay=\\"120\\">\\n  <span class=\\"jyavani-logo\\" aria-label=\\"Jyavani\\">\\n    <span class=\\"letter accent\\" data-word=\\"Just\\">J</span>\\n    <span class=\\"letter base\\" data-word=\\"Your\\">y</span>\\n    <span class=\\"letter accent\\" data-word=\\"Visiting\\">v</span>\\n    <span class=\\"letter base\\" data-word=\\"Always\\">a</span>\\n    <span class=\\"letter base\\" data-word=\\"Nice\\">n</span>\\n    <span class=\\"letter base\\" data-word=\\"Inspire\\">i</span>\\n  </span>\\n</a>\\n", "_title_tag": "div", "_align_title": "left", "_align_content": "left"}', 0, 1),
  ('default', 'footer', 'about', 'tz_html', 'About', '{"title": "About", "html": "<p style=\\"margin:.35rem 0 0;font-size:.85rem;opacity:.75\\">Jyavani CMS \\u2014 native PHP CMS. Fast, lightweight, easy to customize.</p>", "_title_tag": "div", "_align_title": "left", "_align_content": "left"}', 1, 1),
  ('default', 'footer', 'copyright', 'tz_html', '', '{"title":"","_title_tag":"div","_align_title":"left","_align_content":"center","html":"<p style=\\"margin:0;font-size:.85rem;opacity:.75\\">&copy; 2026 Jyavani. Released under MIT License.</p>"}', 0, 1),
  ('default', 'footer', 'pages', 'tz_pages', 'Pages', '{"title":"Pages","_title_tag":"div","_align_title":"left","_align_content":"left","pages":[],"list_class":"tz-pages"}', 0, 1),
  ('default', 'footer', 'social', 'tz_social', 'Social Media', '{"title": "Social Media", "enabled": ["x", "github", "instagram"], "links": [], "_title_tag": "div", "_align_title": "left", "_align_content": "left"}', 0, 1),
  ('default', 'header', 'controls', 'tz_html', 'Controller', '{"title": "", "html": "<select id=\\"themeSelect\\" class=\\"ctrl-item blur-in onload\\" data-anime-trigger=\\"load\\" data-duration=\\"1700\\" data-delay=\\"760\\">\\n  <option value=\\"light\\">Light</option>\\n  <option value=\\"dark\\">Dark</option>\\n</select>\\n<form method=\\"get\\" action=\\"/\\">\\n  <input type=\\"search\\" name=\\"s\\" class=\\"ctrl-item pop\\" data-anime-trigger=\\"load\\" data-duration=\\"1000\\" data-delay=\\"1000\\" placeholder=\\"Search...\\" style=\\"width:120px;max-width:140px;\\">\\n</form>", "_title_tag": "div", "_align_title": "left", "_align_content": "left"}', 1, 1),
  ('default', 'header', 'logo', 'tz_html', 'Logo Jyavani', '{"title": "", "html": "<a href=\\"/\\" class=\\"brand onload wave-span\\" data-anim-trigger=\\"load\\" data-wave-target=\\".jyavani-logo\\" data-wave-step=\\"28\\">\\n  <img src=\\"/static/img/jyavani.svg\\" alt=\\"Jyavani\\" class=\\"flip-logo onload\\" data-anim-trigger=\\"load\\" data-fl-duration=\\"900\\" data-fl-delay=\\"120\\">\\n  <span class=\\"jyavani-logo\\" aria-label=\\"Jyavani\\">\\n    <span class=\\"letter accent\\" data-word=\\"Just\\">J</span>\\n    <span class=\\"letter base\\" data-word=\\"Your\\">y</span>\\n    <span class=\\"letter accent\\" data-word=\\"Visiting\\">v</span>\\n    <span class=\\"letter base\\" data-word=\\"Always\\">a</span>\\n    <span class=\\"letter base\\" data-word=\\"Nice\\">n</span>\\n    <span class=\\"letter base\\" data-word=\\"Inspire\\">i</span>\\n  </span>\\n</a>", "_title_tag": "div", "_align_title": "left", "_align_content": "left"}', 1, 1),
  ('default', 'header', 'nav', 'tz_nav_menu', 'Main Menu', '{"menu": "primary", "menu_class": "menu expand-center-safe moving-line onload", "depth": 0, "ul_attr": "data-anime-trigger=\\"load\\" data-duration=\\"2500\\" data-delay=\\"360\\" data-ml-duration=\\"1000\\" data-ml-delay=\\"520\\"", "_title_tag": "div", "_align_title": "left", "_align_content": "left"}', 1, 1),

  ('default', 'single.post', 'after_content', 'tz_post_meta', 'Post Meta', '{"title":"Post Meta","_title_tag":"div","_align_title":"left","_align_content":"left","show_date":true,"show_updated":false,"show_read_time":true}', 0, 0),
  ('default', 'single.post', 'after_content', 'tz_post_author', 'Author Box', '{"title":"Author Box","_title_tag":"div","_align_title":"left","_align_content":"left","show_avatar":true}', 1, 0);

-- Core settings (installed overrides title/desc/url via installer)
INSERT INTO `settings` (`key`, `value`, `autoload`) VALUES
  ('posts_per_page',   '10',  1),
  ('active_theme',     'default', 1)
ON DUPLICATE KEY UPDATE `value` = VALUES(`value`);

-- Default theme
INSERT INTO `themes` (`id`, `folder_name`, `name`, `version`, `manifest_json`, `is_active`)
VALUES (1, 'default', 'Default Theme', '1.0.0', '{"name":"Default Theme","version":"1.0.0"}', 1)
ON DUPLICATE KEY UPDATE `folder_name` = VALUES(`folder_name`);

-- Default slot assignments
INSERT INTO `assignments` (`slot_key`, `theme_id`, `theme_file`) VALUES
  ('header',      1, 'header.php'),
  ('footer',      1, 'footer.php'),
  ('sidebar',     1, 'sidebar.php'),
  ('main.homepage', 1, 'main/homepage.php')
ON DUPLICATE KEY UPDATE `theme_id` = VALUES(`theme_id`);

-- Default "Main" menu
INSERT IGNORE INTO `menus` (`id`, `name`, `slug`, `is_default`) VALUES (3, 'Main', 'main', 1);

-- Default menu items
INSERT IGNORE INTO `menu_items` (`id`, `menu_id`, `parent_id`, `sort_order`, `type`, `label`, `url`, `target_id`, `target_blank`)
VALUES
  (9, 3, NULL, 0, 'custom',   'Home',      '/',  NULL, 0),
  (14, 3, NULL, 1, 'category', 'Panduan',   '', 1, 0),
  (15, 3, NULL, 2, 'category', 'Sistem',    '', 4, 0),
  (16, 3, 15,   0, 'category', 'Keamanan',  '', 2, 0),
  (17, 3, NULL, 3, 'category', 'Pengembangan', '', 3, 0);

-- Default categories
INSERT IGNORE INTO `categories` (`id`, `name`, `slug`, `description`, `created_by`)
VALUES
  (1, 'Panduan',      'panduan',      'Artikel panduan langkah demi langkah menggunakan Jyavani CMS', 1),
  (2, 'Keamanan',     'keamanan',     'Artikel tentang keamanan, privasi, proteksi data, dan akses kontrol', 1),
  (3, 'Pengembangan', 'pengembangan', 'Artikel tentang theme, plugin, widget, shortcodes, dan pengembangan fitur CMS', 1),
  (4, 'Sistem',       'sistem',       'Artikel tentang administrasi sistem, maintenance, update, dan manajemen user', 1);

-- Default "Sidebar Alt" sidebar zone
INSERT IGNORE INTO `sidebar_zones` (`id`, `name`, `slug`, `description`, `is_primary`) VALUES (3, 'Sidebar Alt', 'altsid', '', 1);

-- Default sidebar zone items
INSERT IGNORE INTO `sidebar_zone_items` (`zone_id`, `type`, `title`, `config`, `ordering`, `active`)
VALUES
  (3, 'last_posts', 'Artikel Terbaru', '{"title":"Artikel Terbaru","limit":5,"type":"article"}', 1, 1),
  (3, 'search', 'Cari', '{"title":"Cari","placeholder":"Cari artikel..."}', 0, 1);
-- 16. ui_translations
-- ~~~~~~~~~~~~~~~~~~~~~~~~~~
CREATE TABLE IF NOT EXISTS `ui_translations` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `scope` VARCHAR(50) NOT NULL DEFAULT 'default',
  `source` TEXT NOT NULL COMMENT 'English source text',
  `value` TEXT NOT NULL COMMENT 'Translated text',
  `locale` CHAR(5) NOT NULL DEFAULT 'en',
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `source_lookup` (`scope`, `source`(191), `locale`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ~~~~~~~~~~~~~~~~~~~~~~~~~~
-- 17. schema_migrations
-- ~~~~~~~~~~~~~~~~~~~~~~~~~~
CREATE TABLE IF NOT EXISTS `schema_migrations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) NOT NULL,
  `batch` int(10) unsigned NOT NULL DEFAULT 1,
  `executed_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_migration` (`migration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ~~~~~~~~~~~~~~~~~~~~~~~~~~
-- 18. plugin_migrations
-- ~~~~~~~~~~~~~~~~~~~~~~~~~~
CREATE TABLE IF NOT EXISTS `plugin_migrations` (
  `plugin_name` varchar(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `migration` varchar(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `checksum` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `applied_version` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `executed_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`plugin_name`,`migration`),
  KEY `idx_plugin_migrations_executed` (`executed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS=1;
