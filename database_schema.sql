-- PHP SMTP Mailer: Database Schema
-- This script creates all the necessary tables for the application.

-- Use `utf8mb4` for full Unicode support.
SET NAMES utf8mb4;

-- Table for storing global application settings.
CREATE TABLE `settings` (
  `setting_key` VARCHAR(100) NOT NULL PRIMARY KEY,
  `setting_value` TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default settings. No admin password is shipped: set one with
--   php bin/set_admin_password.php
-- before the first login (an empty hash makes login impossible).
INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES
('admin_user', 'admin'),
('admin_pass_hash', ''),
('log_retention_days', '30');

-- Table for SMTP sending profiles.
CREATE TABLE `sending_profiles` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `profile_name` VARCHAR(255) NOT NULL,
  `from_name` VARCHAR(255) NOT NULL,
  `from_email` VARCHAR(255) NOT NULL,
  `smtp_host` VARCHAR(255) NOT NULL,
  `smtp_port` INT NOT NULL,
  `smtp_user` VARCHAR(255) NOT NULL,
  `smtp_pass` VARCHAR(255) NOT NULL, -- This will be encrypted in the application layer.
  `smtp_encryption` VARCHAR(10) NOT NULL COMMENT 'e.g., tls, ssl, none',
  `rate_limit_count` INT DEFAULT 100,
  `rate_limit_interval` INT DEFAULT 60 COMMENT 'Interval in minutes',
  `rate_limit_strategy` ENUM('DELAY', 'REJECT') DEFAULT 'REJECT',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table for API authentication tokens.
CREATE TABLE `api_tokens` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `profile_id` INT NOT NULL,
  `token_hash` VARCHAR(255) NOT NULL,
  `token_prefix` VARCHAR(10) NOT NULL UNIQUE,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `last_used_at` TIMESTAMP NULL,
  `expires_at` TIMESTAMP NULL DEFAULT NULL,
  FOREIGN KEY (`profile_id`) REFERENCES `sending_profiles`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table for emails waiting to be sent.
CREATE TABLE `email_queue` (
  `id` VARCHAR(36) NOT NULL PRIMARY KEY COMMENT 'UUID for the email',
  `profile_id` INT NOT NULL,
  `ip_address` VARCHAR(45) NOT NULL,
  `submitted_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `send_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `recipient_email` VARCHAR(255) NOT NULL,
  `cc_email` VARCHAR(255) NULL,
  `bcc_email` TEXT NULL,
  `subject` VARCHAR(255) NOT NULL,
  `body_html` MEDIUMTEXT,
  `body_text` MEDIUMTEXT,
  `status` ENUM('queued', 'processing') DEFAULT 'queued',
  `processing_attempts` INT DEFAULT 0,
  `locked_by` VARCHAR(32) NULL COMMENT 'Worker run that claimed this row',
  `locked_at` DATETIME NULL,
  KEY `idx_queue_ready` (`status`, `send_at`),
  KEY `idx_queue_rate` (`profile_id`, `ip_address`, `submitted_at`),
  FOREIGN KEY (`profile_id`) REFERENCES `sending_profiles`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table for historical email logs.
CREATE TABLE `email_logs` (
  `id` VARCHAR(36) NOT NULL PRIMARY KEY COMMENT 'UUID from email_queue',
  `profile_id` INT NOT NULL,
  `ip_address` VARCHAR(45) NOT NULL,
  `submitted_at` TIMESTAMP NOT NULL,
  `sent_at` TIMESTAMP NULL,
  `recipient_email` VARCHAR(255) NOT NULL,
  `cc_email` VARCHAR(255) NULL,
  `bcc_email` TEXT NULL,
  `subject` VARCHAR(255) NOT NULL,
  `status` ENUM('sent', 'failed', 'bounced') NOT NULL,
  `status_info` TEXT,
  `debug_info` TEXT,
  KEY `idx_logs_rate` (`profile_id`, `ip_address`, `submitted_at`),
  FOREIGN KEY (`profile_id`) REFERENCES `sending_profiles`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Index for faster log pruning.
CREATE INDEX `idx_sent_at` ON `email_logs`(`sent_at`);

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

-- Table to track API requests for rate limiting.
-- This table will store IPs that have been temporarily blocked by the rate limiter.
CREATE TABLE `rate_limit_tracker` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ip_address` varchar(45) NOT NULL,
  `profile_id` int(11) NOT NULL,
  `blocked_until` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ip_address` (`ip_address`),
  KEY `profile_id` (`profile_id`),
  CONSTRAINT `rate_limit_tracker_ibfk_1` FOREIGN KEY (`profile_id`) REFERENCES `sending_profiles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Failed admin-login / API-token attempts, used for brute-force throttling.
CREATE TABLE `auth_attempts` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `scope` VARCHAR(10) NOT NULL COMMENT 'login or api',
  `ip_address` VARCHAR(45) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_auth_lookup` (`scope`, `ip_address`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
