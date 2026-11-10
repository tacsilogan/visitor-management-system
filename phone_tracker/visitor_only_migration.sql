-- Visitors only (2026-11-10, after the capstone adviser's review). The visitor is the only person who
-- uses the app, and each office simply has its own Office Personnel: there are no ISATU student or
-- teacher accounts, no visits to a single teacher, and no Dean's Office approvals. The offices are one
-- plain list again, with no departments or courses above them.
--
-- This removes what teacher_visits_migration.sql and school_accounts_migration.sql added. Those two
-- still run first on a new database, so a new database and an updated one end up the same.
--
-- On a database that has them, it also:
--   - deletes the student and teacher accounts, every visit a student booked and every visit to a
--     teacher, and what was recorded about them (a visitor whose visit to a teacher was still to come
--     gets a notification saying so);
--   - gives each office that used its department's map pin or inside directions a copy of its own;
--   - turns the department (CCI), its courses (IT, CS, IS) and its offices into plain offices.
-- Safe to run more than once.

SET NAMES utf8mb4 COLLATE utf8mb4_general_ci;

DROP PROCEDURE IF EXISTS `visitor_only_cleanup`;
DELIMITER //
CREATE PROCEDURE `visitor_only_cleanup`()
BEGIN
  DECLARE has_tree INT DEFAULT 0;
  DECLARE has_visitor_kind INT DEFAULT 0;
  DECLARE has_account_type INT DEFAULT 0;

  SELECT COUNT(*) INTO has_tree FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'offices' AND COLUMN_NAME = 'parent_code';
  SELECT COUNT(*) INTO has_visitor_kind FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'appointments' AND COLUMN_NAME = 'visitor_kind';
  SELECT COUNT(*) INTO has_account_type FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'account_requests' AND COLUMN_NAME = 'account_type';

  -- Who goes: student and teacher accounts, and each teacher's own calendar row in offices.
  DROP TEMPORARY TABLE IF EXISTS vo_people;
  CREATE TEMPORARY TABLE vo_people (id INT NOT NULL PRIMARY KEY) ENGINE=InnoDB;
  INSERT INTO vo_people (id) SELECT id FROM app_users WHERE role IN ('student', 'teacher');

  DROP TEMPORARY TABLE IF EXISTS vo_calendars;
  CREATE TEMPORARY TABLE vo_calendars (code VARCHAR(32) NOT NULL PRIMARY KEY, name VARCHAR(150) NOT NULL DEFAULT '')
    ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
  IF has_tree > 0 THEN
    INSERT INTO vo_calendars (code, name) SELECT code, name FROM offices WHERE kind = 'teacher';
  END IF;

  -- Their visits: booked by a student, or to a teacher.
  DROP TEMPORARY TABLE IF EXISTS vo_visits;
  CREATE TEMPORARY TABLE vo_visits (id INT NOT NULL PRIMARY KEY) ENGINE=InnoDB;
  INSERT IGNORE INTO vo_visits (id)
    SELECT id FROM appointments WHERE office_code IN (SELECT code FROM vo_calendars);
  INSERT IGNORE INTO vo_visits (id)
    SELECT id FROM appointments WHERE visitor_user_id IN (SELECT id FROM vo_people);
  IF has_visitor_kind > 0 THEN
    INSERT IGNORE INTO vo_visits (id) SELECT id FROM appointments WHERE visitor_kind = 'student';
  END IF;

  -- A visitor still expecting to see a teacher is told before the visit goes.
  INSERT INTO app_notifications (recipient_user_id, appointment_id, notification_type, title, message)
  SELECT a.visitor_user_id, NULL, 'appointment.cancelled', 'Visit cancelled',
         LEFT(CONCAT('Your visit with ', c.name, ' on ', DATE_FORMAT(a.scheduled_start_at, '%b %e at %l:%i %p'),
              ' was cancelled. Visits are now booked by office, so please book the office instead.'), 1000)
  FROM appointments a
  JOIN vo_calendars c ON c.code = a.office_code
  JOIN app_users u ON u.id = a.visitor_user_id AND u.role = 'visitor'
  WHERE a.status IN ('pending_approval', 'approved', 'reschedule_proposed', 'checked_in')
    AND a.scheduled_end_at > NOW();

  -- The database removes a visit's history, notifications, consent and tracking with it, but would
  -- only unlink the activity records about it, so those go first.
  DELETE FROM audit_logs WHERE appointment_id IN (SELECT id FROM vo_visits);
  DELETE FROM appointments WHERE id IN (SELECT id FROM vo_visits);

  -- Each teacher's calendar: hours, time off and settings, then the row itself (its breaks,
  -- directions and routes go with it).
  DELETE FROM office_availability_rules WHERE office_code IN (SELECT code FROM vo_calendars);
  DELETE FROM office_availability_exceptions WHERE office_code IN (SELECT code FROM vo_calendars);
  DELETE FROM office_availability_settings WHERE office_code IN (SELECT code FROM vo_calendars);
  DELETE FROM campus_places WHERE office_code IN (SELECT code FROM vo_calendars);
  DELETE FROM offices WHERE code IN (SELECT code FROM vo_calendars);

  -- What the students and teachers did, their emails and sign-ups, the teacher alerts and the Dean's
  -- Office's requests, then the accounts (their sign-ins and phones go with them).
  DELETE FROM audit_logs WHERE actor_user_id IN (SELECT id FROM vo_people);
  DELETE FROM audit_logs WHERE action LIKE 'teacher.%' OR action LIKE 'teacher\_request.%';
  -- What was recorded about student and teacher accounts removed earlier, by an administrator or at sign-up.
  DELETE FROM audit_logs
   WHERE details_json LIKE '%"role":"student"%' OR details_json LIKE '%"role":"teacher"%'
      OR details_json LIKE '%"account_type":"student"%' OR details_json LIKE '%"account_type":"teacher"%';
  DELETE FROM outbound_emails WHERE user_id IN (SELECT id FROM vo_people);
  DELETE FROM outbound_emails WHERE template_key LIKE 'teacher%';
  IF has_account_type > 0 THEN
    DELETE e FROM outbound_emails e
      JOIN account_requests r ON r.email = e.recipient_email AND r.account_type IN ('student', 'teacher')
     WHERE e.user_id IS NULL AND e.template_key = 'account_code';
    DELETE FROM account_requests WHERE account_type <> 'visitor';
  END IF;
  DELETE FROM account_requests WHERE user_id IN (SELECT id FROM vo_people);
  DELETE FROM account_requests WHERE status NOT IN ('unconfirmed', 'approved');
  DELETE FROM app_notifications WHERE notification_type = 'teacher_request';
  DELETE FROM app_users WHERE id IN (SELECT id FROM vo_people);

  -- Map pins and inside directions used to be looked up from an office to its department. Each
  -- office that had none of its own gets a copy of its department's, so visitors still find it.
  IF has_tree > 0 THEN
    INSERT INTO campus_places (place_type, office_code, name, latitude, longitude, accuracy_meters, placed_by, sort_order, updated_by_user_id)
    SELECT 'office', o.code, o.name, p.latitude, p.longitude, p.accuracy_meters, p.placed_by, p.sort_order, p.updated_by_user_id
    FROM offices o
    JOIN (SELECT office_code, MIN(id) AS id FROM campus_places WHERE place_type = 'office' GROUP BY office_code) pin1
      ON pin1.office_code = o.parent_code
    JOIN campus_places p ON p.id = pin1.id
    WHERE o.parent_code IS NOT NULL
      AND NOT EXISTS (SELECT 1 FROM campus_places x WHERE x.place_type = 'office' AND x.office_code = o.code);

    -- The steps first, while the office still has no directions of its own.
    INSERT INTO office_direction_steps (office_code, sort_order, step_text, photo_path)
    SELECT o.code, s.sort_order, s.step_text, s.photo_path
    FROM offices o
    JOIN office_direction_steps s ON s.office_code = o.parent_code
    WHERE o.parent_code IS NOT NULL
      AND NOT EXISTS (SELECT 1 FROM office_directions d WHERE d.office_code = o.code)
      AND NOT EXISTS (SELECT 1 FROM office_direction_steps x WHERE x.office_code = o.code);
    INSERT INTO office_directions (office_code, building, floor, room, visitor_message, updated_by_user_id)
    SELECT o.code, d.building, d.floor, d.room, d.visitor_message, d.updated_by_user_id
    FROM offices o
    JOIN office_directions d ON d.office_code = o.parent_code
    WHERE o.parent_code IS NOT NULL
      AND NOT EXISTS (SELECT 1 FROM office_directions x WHERE x.office_code = o.code);
  END IF;

  DROP TEMPORARY TABLE IF EXISTS vo_people;
  DROP TEMPORARY TABLE IF EXISTS vo_calendars;
  DROP TEMPORARY TABLE IF EXISTS vo_visits;
END//
DELIMITER ;

CALL `visitor_only_cleanup`();
DROP PROCEDURE IF EXISTS `visitor_only_cleanup`;

-- The phone alerts' signing keys (web push), made for teachers.
DELETE FROM `mobile_api_settings` WHERE `setting_key` IN ('web_push_public_key', 'web_push_private_key');

DROP TABLE IF EXISTS `web_push_subscriptions`;
DROP TABLE IF EXISTS `student_profiles`;

ALTER TABLE `app_notifications`
  DROP COLUMN IF EXISTS `web_pushed_at`;

ALTER TABLE `appointments`
  DROP INDEX IF EXISTS `idx_appointments_visitor_kind`,
  DROP INDEX IF EXISTS `idx_appointments_answer_by`;
ALTER TABLE `appointments`
  DROP COLUMN IF EXISTS `visitor_kind`,
  DROP COLUMN IF EXISTS `answer_by_at`,
  DROP COLUMN IF EXISTS `answer_reminded_at`,
  DROP COLUMN IF EXISTS `meeting_done_at`;

ALTER TABLE `office_availability_settings`
  DROP COLUMN IF EXISTS `auto_accept`,
  DROP COLUMN IF EXISTS `accept_walk_ins`,
  DROP COLUMN IF EXISTS `email_alerts`;

-- The office tree: departments, courses and teacher calendars.
ALTER TABLE `offices`
  DROP FOREIGN KEY IF EXISTS `fk_offices_parent`,
  DROP FOREIGN KEY IF EXISTS `fk_offices_owner`;
ALTER TABLE `offices`
  DROP INDEX IF EXISTS `idx_offices_kind`,
  DROP INDEX IF EXISTS `idx_offices_parent`,
  DROP INDEX IF EXISTS `uq_offices_owner`;
ALTER TABLE `offices`
  DROP COLUMN IF EXISTS `approves_teachers`,
  DROP COLUMN IF EXISTS `owner_user_id`,
  DROP COLUMN IF EXISTS `parent_code`,
  DROP COLUMN IF EXISTS `kind`;

ALTER TABLE `app_users`
  DROP COLUMN IF EXISTS `name_title`,
  MODIFY COLUMN `role` enum('security','visitor','offices','admin') NOT NULL;

-- A sign-up waiting for its emailed code (signup_service.php): a visitor's only.
ALTER TABLE `account_requests`
  DROP FOREIGN KEY IF EXISTS `fk_account_requests_decider`;
ALTER TABLE `account_requests`
  DROP INDEX IF EXISTS `idx_account_requests_queue`,
  DROP INDEX IF EXISTS `idx_account_requests_decider`;
ALTER TABLE `account_requests`
  DROP COLUMN IF EXISTS `account_type`,
  DROP COLUMN IF EXISTS `name_title`,
  DROP COLUMN IF EXISTS `first_name`,
  DROP COLUMN IF EXISTS `last_name`,
  DROP COLUMN IF EXISTS `department_code`,
  DROP COLUMN IF EXISTS `course_code`,
  DROP COLUMN IF EXISTS `course_name`,
  DROP COLUMN IF EXISTS `student_number`,
  DROP COLUMN IF EXISTS `year_level`,
  DROP COLUMN IF EXISTS `section`,
  DROP COLUMN IF EXISTS `decided_by_user_id`,
  DROP COLUMN IF EXISTS `decided_at`,
  DROP COLUMN IF EXISTS `decision_reason`,
  MODIFY COLUMN `status` enum('unconfirmed','approved') NOT NULL DEFAULT 'unconfirmed';
