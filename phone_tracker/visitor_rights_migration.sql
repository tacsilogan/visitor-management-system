-- A visitor's rights over their own account (AUTH_SECURITY.md, "Visitors' own accounts"):
-- which version of the Terms and Privacy Notice they last agreed to, and when. The app also
-- remembers this on the phone; this is the record on ISATU's side (Data Privacy Act: consent
-- evidenced in writing or electronically). Visitors can also delete their own account in the
-- app (api/v1/account_delete.php); that needs no new columns.
-- Import after gate_registration_migration.sql. Safe to run again.

ALTER TABLE `app_users`
  ADD COLUMN IF NOT EXISTS `notice_version` varchar(40) NOT NULL DEFAULT '' AFTER `contact_number`,
  ADD COLUMN IF NOT EXISTS `notice_agreed_at` datetime DEFAULT NULL AFTER `notice_version`;

-- Accounts made before this: the version they agreed to when they signed up.
UPDATE `app_users` u
  INNER JOIN `account_requests` r ON r.`user_id` = u.`id`
  SET u.`notice_version` = r.`consent_version`, u.`notice_agreed_at` = r.`created_at`
  WHERE u.`role` = 'visitor' AND u.`notice_version` = '' AND r.`consent_version` <> '';
