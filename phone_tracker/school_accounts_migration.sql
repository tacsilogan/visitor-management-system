-- Mostly undone on 2026-11-10 by visitor_only_migration.sql, which runs after it: visitors are the
-- only people who sign up. What stays is account_requests, which holds a visitor's sign-up until the
-- emailed code is entered (signup_service.php). Kept so new and old databases take the same steps.
--
-- ISATU students and teachers with accounts of their own.
--
-- Students sign up in the app with their @students.isatu.edu.ph email and book any teacher, with no
-- pass, gate scan or location tracking. Teachers ask for an account on the website with their
-- @isatu.edu.ph email, and their department's Dean's Office approves it. Every new app account,
-- visitor or student, first confirms its email with a 6-digit code. Safe to run more than once.

SET NAMES utf8mb4;

ALTER TABLE `app_users`
  MODIFY COLUMN `role` enum('security','visitor','offices','admin','teacher','student') NOT NULL;

-- What a teacher sees about a student who books them. course_code is a course in the department
-- tree; course_name is what the student typed when their course was not listed yet.
CREATE TABLE IF NOT EXISTS `student_profiles` (
  `user_id` int(11) NOT NULL,
  `student_number` varchar(20) NOT NULL,
  `course_code` varchar(16) DEFAULT NULL,
  `course_name` varchar(100) NOT NULL DEFAULT '',
  `year_level` tinyint(3) unsigned NOT NULL,
  `section` varchar(10) NOT NULL DEFAULT '',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`user_id`),
  KEY `idx_student_profiles_course` (`course_code`),
  CONSTRAINT `fk_student_profiles_user` FOREIGN KEY (`user_id`) REFERENCES `app_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- An account that does not exist yet:
--   visitor, student  'unconfirmed' until the emailed code is entered, then 'approved' with user_id set.
--   teacher           'unconfirmed', then 'pending' for the Dean's Office, then 'approved' or 'rejected'.
-- Only an HMAC of the code is kept (key: config/auth_secret.php), never the code itself.
CREATE TABLE IF NOT EXISTS `account_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `account_type` enum('visitor','student','teacher') NOT NULL,
  `status` enum('unconfirmed','pending','approved','rejected','cancelled') NOT NULL DEFAULT 'unconfirmed',
  `email` varchar(190) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `name_title` varchar(16) NOT NULL DEFAULT '',
  `first_name` varchar(60) NOT NULL DEFAULT '',
  `last_name` varchar(60) NOT NULL DEFAULT '',
  `full_name` varchar(100) NOT NULL,
  `contact_number` varchar(32) NOT NULL DEFAULT '',
  `department_code` varchar(16) DEFAULT NULL,
  `course_code` varchar(16) DEFAULT NULL,
  `course_name` varchar(100) NOT NULL DEFAULT '',
  `student_number` varchar(20) NOT NULL DEFAULT '',
  `year_level` tinyint(3) unsigned DEFAULT NULL,
  `section` varchar(10) NOT NULL DEFAULT '',
  `consent_version` varchar(32) NOT NULL DEFAULT '',
  `code_hash` char(64) DEFAULT NULL,
  `code_sent_at` datetime DEFAULT NULL,
  `code_expires_at` datetime DEFAULT NULL,
  `code_attempts` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `codes_sent` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `email_confirmed_at` datetime DEFAULT NULL,
  `decided_by_user_id` int(11) DEFAULT NULL,
  `decided_at` datetime DEFAULT NULL,
  `decision_reason` varchar(255) NOT NULL DEFAULT '',
  `user_id` int(11) DEFAULT NULL,
  `requested_ip` varchar(45) NOT NULL DEFAULT '',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_account_requests_email` (`email`, `status`),
  KEY `idx_account_requests_queue` (`account_type`, `status`, `department_code`),
  KEY `idx_account_requests_user` (`user_id`),
  KEY `idx_account_requests_decider` (`decided_by_user_id`),
  CONSTRAINT `fk_account_requests_user` FOREIGN KEY (`user_id`) REFERENCES `app_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_account_requests_decider` FOREIGN KEY (`decided_by_user_id`) REFERENCES `app_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Who booked: a visitor from outside, or an ISATU student. A student's visit has no pass, no gate
-- scan and no tracking, and is left out of the guard's lists and the visitor numbers.
ALTER TABLE `appointments`
  ADD COLUMN IF NOT EXISTS `visitor_kind` enum('visitor','student') NOT NULL DEFAULT 'visitor' AFTER `visit_type`,
  ADD KEY IF NOT EXISTS `idx_appointments_visitor_kind` (`visitor_kind`, `status`);

-- The office whose Office Personnel approve the teachers of its department: its Dean's Office.
ALTER TABLE `offices`
  ADD COLUMN IF NOT EXISTS `approves_teachers` tinyint(1) NOT NULL DEFAULT 0 AFTER `owner_user_id`;

-- CCI's Dean's Office approves CCI's teachers. A department added later gets its Dean's Office
-- from Add department, already marked.
UPDATE `offices` o
JOIN `offices` d ON d.`code` = o.`parent_code` AND d.`kind` = 'department'
SET o.`approves_teachers` = 1
WHERE o.`code` = 'DEANS' AND o.`kind` = 'office'
  AND NOT EXISTS (
    SELECT 1 FROM (SELECT `parent_code` FROM `offices` WHERE `approves_teachers` = 1) marked
    WHERE marked.`parent_code` = o.`parent_code`
  );
