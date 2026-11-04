-- Named breaks in an office's weekly visitor hours: a lunch break, a flag ceremony, a staff
-- meeting. The bookable time itself stays in office_availability_rules: a day with a lunch
-- break is saved there as two windows (8:00-12:00 and 1:00-5:00), so every booking check
-- already skips the break. This table keeps each break's name and span for the office
-- dashboard and the visitor app. Safe to run more than once.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `office_availability_breaks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `office_code` varchar(16) NOT NULL,
  `day_of_week` tinyint(3) unsigned NOT NULL COMMENT '1=Monday through 7=Sunday',
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `label` varchar(60) NOT NULL DEFAULT 'Break',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_availability_break` (`office_code`,`day_of_week`,`start_time`),
  KEY `idx_availability_break_lookup` (`office_code`,`day_of_week`),
  CONSTRAINT `fk_availability_breaks_office` FOREIGN KEY (`office_code`) REFERENCES `offices` (`code`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
