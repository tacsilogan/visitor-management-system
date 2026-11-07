-- Visits to a specific teacher (TEACHER_VISITS.md).
--
-- The offices table becomes a tree. A department (CCI) holds offices (Dean's Office, Tech Support)
-- and courses (IT, CS, IS), and a course holds teachers. A teacher is a calendar row of their own,
-- so weekly hours, approvals and "only your own visitors" work as they do for an office.
-- Old appointments keep working because no code changes. Safe to run more than once.

SET NAMES utf8mb4;

ALTER TABLE `offices`
  ADD COLUMN IF NOT EXISTS `kind` enum('department','office','course','teacher') NOT NULL DEFAULT 'office' AFTER `code`,
  ADD COLUMN IF NOT EXISTS `parent_code` varchar(16) DEFAULT NULL AFTER `kind`,
  ADD COLUMN IF NOT EXISTS `owner_user_id` int(11) DEFAULT NULL AFTER `parent_code`,
  ADD KEY IF NOT EXISTS `idx_offices_kind` (`kind`, `is_active`),
  ADD KEY IF NOT EXISTS `idx_offices_parent` (`parent_code`),
  ADD UNIQUE KEY IF NOT EXISTS `uq_offices_owner` (`owner_user_id`);

ALTER TABLE `app_users`
  MODIFY COLUMN `role` enum('security','visitor','offices','admin','teacher') NOT NULL,
  ADD COLUMN IF NOT EXISTS `name_title` varchar(16) NOT NULL DEFAULT '' AFTER `display_name`;

-- auto_accept: requests during the teacher's hours are accepted right away. accept_walk_ins: visitors
-- may ask to see the teacher now; the teacher still answers each one. email_alerts: new requests are
-- also sent by email. All three are only read for teachers.
ALTER TABLE `office_availability_settings`
  ADD COLUMN IF NOT EXISTS `auto_accept` tinyint(1) NOT NULL DEFAULT 0 AFTER `maximum_visitors_per_slot`,
  ADD COLUMN IF NOT EXISTS `accept_walk_ins` tinyint(1) NOT NULL DEFAULT 1 AFTER `auto_accept`,
  ADD COLUMN IF NOT EXISTS `email_alerts` tinyint(1) NOT NULL DEFAULT 1 AFTER `accept_walk_ins`;

-- answer_by_at: a walk-in request to a teacher closes at this time with no answer.
-- answer_reminded_at: when the teacher and visitor were told a booked request still has no answer.
-- meeting_done_at: the teacher finished the meeting. The visitor is still on campus until the gate.
ALTER TABLE `appointments`
  ADD COLUMN IF NOT EXISTS `answer_by_at` datetime DEFAULT NULL AFTER `status_updated_at`,
  ADD COLUMN IF NOT EXISTS `answer_reminded_at` datetime DEFAULT NULL AFTER `answer_by_at`,
  ADD COLUMN IF NOT EXISTS `meeting_done_at` datetime DEFAULT NULL AFTER `completed_by_user_id`,
  ADD KEY IF NOT EXISTS `idx_appointments_answer_by` (`status`, `answer_by_at`);

-- A teacher's phone, for alerts while the dashboard is closed (web push).
CREATE TABLE IF NOT EXISTS `web_push_subscriptions` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `endpoint` varchar(1024) NOT NULL,
  `endpoint_hash` char(64) NOT NULL,
  `device_label` varchar(100) NOT NULL DEFAULT '',
  `failure_count` smallint(5) unsigned NOT NULL DEFAULT 0,
  `last_sent_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_web_push_endpoint` (`endpoint_hash`),
  KEY `idx_web_push_user` (`user_id`),
  CONSTRAINT `fk_web_push_user` FOREIGN KEY (`user_id`) REFERENCES `app_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE `app_notifications`
  ADD COLUMN IF NOT EXISTS `web_pushed_at` datetime DEFAULT NULL;

-- Add the two foreign keys on offices only when they do not already exist.
DROP PROCEDURE IF EXISTS `teacher_visits_add_fk`;
DELIMITER //
CREATE PROCEDURE `teacher_visits_add_fk`(
  IN p_constraint_name varchar(64),
  IN p_ddl text
)
BEGIN
  IF NOT EXISTS (
    SELECT 1
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'offices'
      AND CONSTRAINT_NAME = p_constraint_name
  ) THEN
    SET @teacher_visits_ddl = p_ddl;
    PREPARE teacher_visits_stmt FROM @teacher_visits_ddl;
    EXECUTE teacher_visits_stmt;
    DEALLOCATE PREPARE teacher_visits_stmt;
  END IF;
END//
DELIMITER ;

CALL `teacher_visits_add_fk`('fk_offices_parent',
  'ALTER TABLE `offices` ADD CONSTRAINT `fk_offices_parent` FOREIGN KEY (`parent_code`) REFERENCES `offices` (`code`) ON DELETE SET NULL ON UPDATE CASCADE');
CALL `teacher_visits_add_fk`('fk_offices_owner',
  'ALTER TABLE `offices` ADD CONSTRAINT `fk_offices_owner` FOREIGN KEY (`owner_user_id`) REFERENCES `app_users` (`id`) ON DELETE SET NULL');

DROP PROCEDURE IF EXISTS `teacher_visits_add_fk`;

-- CCI, the department. Appointments from before the department-level catalog may already carry
-- this code; they now read this name instead of the old "(legacy)" label.
INSERT INTO `offices` (`code`, `kind`, `name`, `sort_order`)
SELECT 'CCI', 'department', 'College of Computing and Informatics', 0
WHERE NOT EXISTS (SELECT 1 FROM `offices` WHERE `code` = 'CCI' OR `name` = 'College of Computing and Informatics');

SET @cci_code := (
  SELECT `code` FROM `offices`
  WHERE `code` = 'CCI' OR `name` = 'College of Computing and Informatics'
  ORDER BY `code` = 'CCI' DESC LIMIT 1
);
UPDATE `offices` SET `kind` = 'department', `parent_code` = NULL WHERE `code` = @cci_code;

-- IT, CS and IS are CCI's courses. The Dean's Office and Tech Support are CCI's offices. Their codes
-- stay, so old visits, staff, hours, pins, routes and directions stay attached to them.
UPDATE `offices` SET `kind` = 'course', `parent_code` = @cci_code WHERE `code` IN ('IT', 'IS', 'CS');
UPDATE `offices` SET `kind` = 'office', `parent_code` = @cci_code WHERE `code` IN ('DEANS', 'TECH_SUPPORT');

-- Only the original names change, so a name an administrator already edited stays.
UPDATE `offices` SET `name` = 'CCI IT Office' WHERE `code` = 'IT' AND `name` = 'IT Department';
UPDATE `offices` SET `name` = 'CCI IS Office' WHERE `code` = 'IS' AND `name` = 'IS Department';
UPDATE `offices` SET `name` = 'CCI CS Office' WHERE `code` = 'CS' AND `name` = 'CS Department';
UPDATE `offices` SET `name` = 'CCI Dean''s Office' WHERE `code` = 'DEANS' AND `name` = 'Dean''s Office';
UPDATE `offices` SET `name` = 'CCI Tech Support' WHERE `code` = 'TECH_SUPPORT' AND `name` = 'Tech Support';
