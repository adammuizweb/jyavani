UPDATE `login_attempts` AS survivor
INNER JOIN (
  SELECT *
  FROM (
    SELECT
      MIN(`id`) AS `survivor_id`,
      `email`,
      `ip_address`,
      LEAST(2147483647, SUM(`attempts`)) AS `attempts`,
      MAX(`last_attempt`) AS `last_attempt`,
      MAX(`blocked_until`) AS `blocked_until`
    FROM `login_attempts`
    GROUP BY `email`, `ip_address`
    HAVING COUNT(*) > 1
  ) AS grouped_attempts
) AS merged
  ON merged.`survivor_id` = survivor.`id`
INNER JOIN `login_attempts` AS duplicate_attempt
  ON duplicate_attempt.`email` = merged.`email`
 AND duplicate_attempt.`ip_address` = merged.`ip_address`
 AND duplicate_attempt.`id` <> merged.`survivor_id`
SET
  survivor.`attempts` = merged.`attempts`,
  survivor.`last_attempt` = merged.`last_attempt`,
  survivor.`blocked_until` = merged.`blocked_until`,
  duplicate_attempt.`attempts` = 0,
  duplicate_attempt.`blocked_until` = NULL;

DELETE duplicate_attempt
FROM `login_attempts` AS duplicate_attempt
INNER JOIN `login_attempts` AS survivor
  ON survivor.`email` = duplicate_attempt.`email`
 AND survivor.`ip_address` = duplicate_attempt.`ip_address`
 AND survivor.`id` < duplicate_attempt.`id`;

ALTER TABLE `login_attempts`
  ADD UNIQUE KEY `uq_login_attempt_email_ip` (`email`,`ip_address`);
