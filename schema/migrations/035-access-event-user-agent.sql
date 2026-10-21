ALTER TABLE `access_events`
  ADD COLUMN `user_agent` varchar(1024) DEFAULT NULL AFTER `user_agent_hash`;
