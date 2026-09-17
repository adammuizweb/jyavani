ALTER TABLE `posts`
  ADD COLUMN `publish_at_utc` datetime DEFAULT NULL AFTER `status_revision`;

ALTER TABLE `posts`
  ADD KEY `idx_posts_scheduled_publish` (`status`, `is_deleted`, `publish_at_utc`, `type`);
