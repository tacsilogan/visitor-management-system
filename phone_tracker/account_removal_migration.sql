-- Lets an administrator remove any account, even one with history (AUTH_SECURITY.md, "Removing an
-- account"). The records an account left behind stay and stop pointing at it: a past visit keeps the
-- visitor's name, a suggested time and a pass override keep their details. Safe to run more than once.

SET NAMES utf8mb4;

ALTER TABLE `appointments` MODIFY COLUMN `visitor_user_id` int(11) DEFAULT NULL;
ALTER TABLE `visits` MODIFY COLUMN `visitor_user_id` int(11) DEFAULT NULL;
ALTER TABLE `visitor_consents` MODIFY COLUMN `visitor_user_id` int(11) DEFAULT NULL;
ALTER TABLE `appointment_reschedule_proposals` MODIFY COLUMN `proposed_by_user_id` int(11) DEFAULT NULL;
ALTER TABLE `appointment_qr_overrides` MODIFY COLUMN `authorized_by_user_id` int(11) DEFAULT NULL;

-- Each column's link to the account becomes ON DELETE SET NULL, wherever it still blocks a removal.
-- The link is found by its table and column, so a database whose link has another name is fixed too.
DROP PROCEDURE IF EXISTS `account_removal_relink`;
DELIMITER //
CREATE PROCEDURE `account_removal_relink`(
  IN p_table varchar(64),
  IN p_column varchar(64),
  IN p_constraint varchar(64)
)
BEGIN
  DECLARE v_existing varchar(64) DEFAULT NULL;
  SET v_existing = (
    SELECT k.CONSTRAINT_NAME
    FROM information_schema.KEY_COLUMN_USAGE k
    INNER JOIN information_schema.REFERENTIAL_CONSTRAINTS r
      ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA
     AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
     AND r.TABLE_NAME = k.TABLE_NAME
    WHERE k.CONSTRAINT_SCHEMA = DATABASE()
      AND k.TABLE_NAME = p_table
      AND k.COLUMN_NAME = p_column
      AND k.REFERENCED_TABLE_NAME = 'app_users'
      AND r.DELETE_RULE <> 'SET NULL'
    LIMIT 1
  );
  IF v_existing IS NOT NULL THEN
    SET @account_removal_ddl = CONCAT('ALTER TABLE `', p_table, '` DROP FOREIGN KEY `', v_existing, '`');
    PREPARE account_removal_stmt FROM @account_removal_ddl;
    EXECUTE account_removal_stmt;
    DEALLOCATE PREPARE account_removal_stmt;
    SET @account_removal_ddl = CONCAT(
      'ALTER TABLE `', p_table, '` ADD CONSTRAINT `', p_constraint, '` FOREIGN KEY (`', p_column,
      '`) REFERENCES `app_users` (`id`) ON DELETE SET NULL'
    );
    PREPARE account_removal_stmt FROM @account_removal_ddl;
    EXECUTE account_removal_stmt;
    DEALLOCATE PREPARE account_removal_stmt;
  END IF;
END//
DELIMITER ;

CALL `account_removal_relink`('appointments', 'visitor_user_id', 'fk_appointments_visitor_user');
CALL `account_removal_relink`('visits', 'visitor_user_id', 'fk_visits_visitor');
CALL `account_removal_relink`('visitor_consents', 'visitor_user_id', 'fk_consent_visitor');
CALL `account_removal_relink`('appointment_reschedule_proposals', 'proposed_by_user_id', 'fk_reschedule_proposed_by');
CALL `account_removal_relink`('appointment_qr_overrides', 'authorized_by_user_id', 'fk_override_authorized_by');

DROP PROCEDURE IF EXISTS `account_removal_relink`;
