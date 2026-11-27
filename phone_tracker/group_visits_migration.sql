-- Visits with other people, the ID check at the gate, and the visitor app on the website
-- (GROUP_VISITS.md):
--   * visit_people: everyone who comes with a visitor, by name, with whether they are 18 or
--     older. The guard checks each adult's ID at check-in and records only its type.
--   * appointments.no_id_shown, no_id_reason: the guard let the visitor in without an ID, and why.
--   * app_releases, app_release_downloads: the visitor app versions the Admin publishes on the
--     website, and when each was downloaded (no IP address or other detail is kept).
-- Import after gate_registration_migration.sql and campus_closure_migration.sql. Safe to run again.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `visit_people` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `appointment_id` int(11) DEFAULT NULL COMMENT 'The pass of a visit to one office',
  `visit_id` int(11) DEFAULT NULL COMMENT 'The pass of a visit to several offices',
  `full_name` varchar(150) NOT NULL,
  `booked_name` varchar(150) DEFAULT NULL COMMENT 'The name as booked, when the guard fixed it to match the ID',
  `is_adult` tinyint(1) NOT NULL DEFAULT 1 COMMENT '18 or older',
  `added_at_gate` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Not on the booking: the guard added them',
  `gate_status` enum('expected','in','absent','refused') NOT NULL DEFAULT 'expected',
  `id_type_shown` varchar(40) NOT NULL DEFAULT '' COMMENT 'Only the type of ID, never its number',
  `no_id_reason` varchar(150) NOT NULL DEFAULT '',
  `checked_in_at` datetime DEFAULT NULL,
  `checked_in_by_user_id` int(11) DEFAULT NULL,
  `left_at` datetime DEFAULT NULL COMMENT 'Set only when they left before the rest of the group',
  `left_by_user_id` int(11) DEFAULT NULL,
  `sort_order` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_visit_people_appointment` (`appointment_id`,`sort_order`),
  KEY `idx_visit_people_visit` (`visit_id`,`sort_order`),
  KEY `idx_visit_people_name` (`full_name`),
  CONSTRAINT `fk_visit_people_appointment` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_visit_people_visit` FOREIGN KEY (`visit_id`) REFERENCES `visits` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_visit_people_checked_in_by` FOREIGN KEY (`checked_in_by_user_id`) REFERENCES `app_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_visit_people_left_by` FOREIGN KEY (`left_by_user_id`) REFERENCES `app_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE `appointments`
  ADD COLUMN IF NOT EXISTS `no_id_shown` tinyint(1) NOT NULL DEFAULT 0 AFTER `id_type_shown`,
  ADD COLUMN IF NOT EXISTS `no_id_reason` varchar(150) NOT NULL DEFAULT '' AFTER `no_id_shown`;

CREATE TABLE IF NOT EXISTS `app_releases` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `version_name` varchar(32) NOT NULL,
  `version_code` int(11) NOT NULL,
  `package_name` varchar(100) NOT NULL,
  `file_name` varchar(100) NOT NULL COMMENT 'Stored under the private data folder, never in the web folder',
  `file_size` bigint(20) NOT NULL,
  `file_sha256` char(64) NOT NULL,
  `signing_sha256` char(64) NOT NULL COMMENT 'Fingerprint of the certificate the app is signed with',
  `whats_new` varchar(160) NOT NULL DEFAULT '',
  `status` enum('checked','current','previous','retired') NOT NULL DEFAULT 'checked',
  `uploaded_by_user_id` int(11) DEFAULT NULL,
  `published_by_user_id` int(11) DEFAULT NULL,
  `uploaded_at` datetime NOT NULL DEFAULT current_timestamp(),
  `published_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_app_releases_status` (`status`,`version_code`),
  CONSTRAINT `fk_app_releases_uploaded_by` FOREIGN KEY (`uploaded_by_user_id`) REFERENCES `app_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_app_releases_published_by` FOREIGN KEY (`published_by_user_id`) REFERENCES `app_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `app_release_downloads` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `release_id` int(11) NOT NULL,
  `downloaded_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_app_release_downloads_when` (`downloaded_at`),
  KEY `idx_app_release_downloads_release` (`release_id`),
  CONSTRAINT `fk_app_release_downloads_release` FOREIGN KEY (`release_id`) REFERENCES `app_releases` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
