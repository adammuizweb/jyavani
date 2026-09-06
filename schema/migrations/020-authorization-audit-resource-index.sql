ALTER TABLE `authorization_audit_log`
  ADD KEY `idx_authz_audit_resource` (`resource_type`,`resource_id`(150),`id`);
