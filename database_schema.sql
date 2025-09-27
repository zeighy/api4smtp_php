-- PHP SMTP Mailer: Database Schema
-- This script creates all the necessary tables for the application.

-- Use `utf8mb4` for full Unicode support.
SET NAMES utf8mb4;

-- Table for storing global application settings.
CREATE TABLE `settings` (
  `setting_key` VARCHAR(100) NOT NULL PRIMARY KEY,
  `setting_value` TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default settings. The admin password should be changed immediately.
-- Default password is 'password'. Hash generated with password_hash('password', PASSWORD_DEFAULT).
INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES
('admin_user', 'admin'),
('admin_pass_hash', '$2y$10$DC3A2o65tT2zG81D1x..Q.o2y3.8Xn524.jV8v0R.V5p8dRo4gDve'),
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
  `subject` VARCHAR(255) NOT NULL,
  `body_html` TEXT,
  `body_text` TEXT,
  `status` ENUM('queued', 'processing') DEFAULT 'queued',
  `processing_attempts` INT DEFAULT 0,
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
  `subject` VARCHAR(255) NOT NULL,
  `status` ENUM('sent', 'failed', 'bounced') NOT NULL,
  `status_info` TEXT,
  FOREIGN KEY (`profile_id`) REFERENCES `sending_profiles`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Index for faster log pruning.
CREATE INDEX `idx_sent_at` ON `email_logs`(`sent_at`);

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
