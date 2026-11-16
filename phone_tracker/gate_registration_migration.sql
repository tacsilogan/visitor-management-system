-- Visitors registered at the gate by Security (GATE_REGISTRATION.md): people who can't use the
-- ISATU Visits app (an iPhone, a basic phone, no data, a dead battery) or don't want location
-- tracking. The guard checks their ID and registers them on the Security dashboard; the visit
-- is checked in at once and never tracked. Also marks a booked visitor checked in without
-- their QR code (found by name or reference on the dashboard).
-- Import after visitor_only_migration.sql. Safe to run again.

ALTER TABLE `appointments`
  ADD COLUMN IF NOT EXISTS `registration_source` enum('app','gate') NOT NULL DEFAULT 'app' AFTER `visit_type`,
  ADD COLUMN IF NOT EXISTS `id_type_shown` varchar(40) NOT NULL DEFAULT '' AFTER `registration_source`,
  ADD COLUMN IF NOT EXISTS `gate_consent_at` datetime DEFAULT NULL AFTER `id_type_shown`,
  ADD COLUMN IF NOT EXISTS `checked_in_without_qr` tinyint(1) NOT NULL DEFAULT 0 AFTER `checked_in_by_user_id`;
