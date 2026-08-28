ALTER TABLE `assignments`
  ADD COLUMN `slot_owner` varchar(100) DEFAULT NULL AFTER `slot_key`;
