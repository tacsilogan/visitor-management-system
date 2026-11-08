-- "Forgot password?" in the app emails a 6-digit code, as signing up does (PHASE4_MOBILE_API.md).
-- A code takes 5 wrong tries, then stops working: this counts them. Safe to run more than once.

SET NAMES utf8mb4;

ALTER TABLE `account_action_tokens`
  ADD COLUMN IF NOT EXISTS `failed_attempts` tinyint(3) unsigned NOT NULL DEFAULT 0 AFTER `consumed_at`;
