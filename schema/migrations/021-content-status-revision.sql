ALTER TABLE `posts`
  ADD COLUMN `status_revision` bigint(20) unsigned NOT NULL DEFAULT 0 AFTER `status`;
