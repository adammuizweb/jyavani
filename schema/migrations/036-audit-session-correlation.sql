ALTER TABLE `audit_events`
  ADD COLUMN `session_correlation_id` char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL AFTER `request_id`,
  ADD KEY `idx_audit_events_session` (`session_correlation_id`,`id`);

ALTER TABLE `access_events`
  ADD COLUMN `session_correlation_id` char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL AFTER `request_id`,
  ADD KEY `idx_access_events_session` (`session_correlation_id`,`id`);
