-- Authors own the complete lifecycle of categories they create, while
-- cross-owner category mutation remains reserved for higher-authority roles.
INSERT INTO `role_permissions` (`role_id`, `permission_key`, `scope`)
SELECT `id`, 'core.categories.trash', 'own'
FROM `roles`
WHERE `slug` = 'author' AND `is_system` = 1
ON DUPLICATE KEY UPDATE `scope` = VALUES(`scope`);

INSERT INTO `role_permissions` (`role_id`, `permission_key`, `scope`)
SELECT `id`, 'core.categories.restore', 'own'
FROM `roles`
WHERE `slug` = 'author' AND `is_system` = 1
ON DUPLICATE KEY UPDATE `scope` = VALUES(`scope`);
