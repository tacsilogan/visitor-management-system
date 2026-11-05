-- Directions inside a building (INDOOR_DIRECTIONS.md): where each office is, a description
-- for visitors, and the steps from the building door to the office door. Administrators write
-- them in Campus Map > Inside. Safe to run more than once.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `office_directions` (
  `office_code` varchar(16) NOT NULL,
  `building` varchar(60) NOT NULL DEFAULT '',
  `floor` varchar(30) NOT NULL DEFAULT '',
  `room` varchar(30) NOT NULL DEFAULT '',
  `visitor_message` varchar(160) NOT NULL DEFAULT '',
  `updated_by_user_id` int(11) DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`office_code`),
  CONSTRAINT `fk_office_directions_office` FOREIGN KEY (`office_code`) REFERENCES `offices` (`code`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_office_directions_updated_by` FOREIGN KEY (`updated_by_user_id`) REFERENCES `app_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- One row per step, in walking order. photo_path is relative to phone_tracker, like
-- uploads/directions/<random>.jpg, and is empty for a step without a photo. Two departments
-- may share a photo after "Copy from".
CREATE TABLE IF NOT EXISTS `office_direction_steps` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `office_code` varchar(16) NOT NULL,
  `sort_order` tinyint(3) unsigned NOT NULL,
  `step_text` varchar(140) NOT NULL,
  `photo_path` varchar(120) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `idx_direction_steps_office` (`office_code`,`sort_order`),
  KEY `idx_direction_steps_photo` (`photo_path`),
  CONSTRAINT `fk_direction_steps_office` FOREIGN KEY (`office_code`) REFERENCES `offices` (`code`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
