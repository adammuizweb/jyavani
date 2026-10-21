INSERT IGNORE INTO `settings` (`key`,`value`,`autoload`) VALUES
  ('audit_dashboard_actions_enabled','1',1);

DELETE FROM `settings`
WHERE `key` = 'audit_public_page_views_enabled';
