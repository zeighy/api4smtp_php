-- Upgrade script for installs created before BCC and attachment support.
-- Run once, BEFORE 001_security_hardening.sql.
--
-- Skip this file if your database already has the `email_attachments` table.
-- Fresh installs use database_schema.sql, which already includes all of this.

ALTER TABLE `email_queue`
  ADD COLUMN `bcc_email` TEXT NULL AFTER `cc_email`;

ALTER TABLE `email_logs`
  ADD COLUMN `bcc_email` TEXT NULL AFTER `cc_email`;

CREATE TABLE `email_attachments` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `email_id` VARCHAR(36) NOT NULL,
  `filename` VARCHAR(255) NOT NULL,
  `content_type` VARCHAR(255) NOT NULL,
  `content` LONGBLOB NOT NULL,
  `inline` BOOLEAN NOT NULL DEFAULT FALSE,
  `cid` VARCHAR(255) NULL,
  FOREIGN KEY (`email_id`) REFERENCES `email_queue`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
