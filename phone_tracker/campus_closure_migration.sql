-- Closing the whole campus to visitors for a day or a few hours (a class suspension, a holiday,
-- bad weather), from the Admin dashboard (admin_campus_closure.php). The closure itself is kept
-- here; every active office also gets an "Office closed" row in office_availability_exceptions
-- that points back to it, so every booking and walk-in check already refuses that time, and the
-- office staff see it on their Hours page. Reopening the campus removes those rows.
-- Import after visitor_rights_migration.sql. Safe to run again.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `campus_closures` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `starts_at` datetime NOT NULL,
  `ends_at` datetime NOT NULL,
  `all_day` tinyint(1) NOT NULL DEFAULT 1,
  `reason` varchar(120) NOT NULL DEFAULT '',
  `message` varchar(500) NOT NULL DEFAULT '',
  `cancelled_visits` int(11) NOT NULL DEFAULT 0,
  `created_by_user_id` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `reopened_at` datetime DEFAULT NULL,
  `reopened_by_user_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_campus_closure_when` (`reopened_at`, `ends_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE `office_availability_exceptions`
  ADD COLUMN IF NOT EXISTS `campus_closure_id` int(11) DEFAULT NULL AFTER `reason`,
  ADD KEY IF NOT EXISTS `idx_availability_exception_closure` (`campus_closure_id`);
