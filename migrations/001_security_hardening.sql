-- Upgrade script for existing installs (run once).
-- Fresh installs use database_schema.sql, which already includes all of this.

ALTER TABLE `api_tokens`
  ADD COLUMN `expires_at` TIMESTAMP NULL DEFAULT NULL AFTER `last_used_at`;

ALTER TABLE `email_queue`
  MODIFY `body_html` MEDIUMTEXT,
  MODIFY `body_text` MEDIUMTEXT,
  ADD COLUMN `locked_by` VARCHAR(32) NULL COMMENT 'Worker run that claimed this row',
  ADD COLUMN `locked_at` DATETIME NULL,
  ADD KEY `idx_queue_ready` (`status`, `send_at`),
  ADD KEY `idx_queue_rate` (`profile_id`, `ip_address`, `submitted_at`);

ALTER TABLE `email_logs`
  ADD KEY `idx_logs_rate` (`profile_id`, `ip_address`, `submitted_at`);

CREATE TABLE `auth_attempts` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `scope` VARCHAR(10) NOT NULL COMMENT 'login or api',
  `ip_address` VARCHAR(45) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_auth_lookup` (`scope`, `ip_address`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- If you still use the shipped default admin password, login is refused until
-- you run:  php bin/set_admin_password.php
