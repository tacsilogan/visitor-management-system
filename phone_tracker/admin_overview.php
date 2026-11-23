<?php
/*
 * The Admin dashboard's Home, Offices and Campus map checklist (admin.html, admin.js):
 *   right_now     visitors on campus, expected today, requests waiting, visits in 7 days
 *   attention     what needs the administrator, most urgent first (admin_service.php)
 *   offices       each office with its state, staff, waiting requests, answer time, setup
 *   last_days     visits on each of the last 7 days
 *   closures      a campus closure in effect or coming (admin_campus_closure.php)
 *   campus_setup  the campus map checklist
 *   reminders     the automatic reminder settings (admin_settings.php)
 */
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/session_bootstrap.php";
require_once __DIR__ . "/appointment_maintenance.php";
require_once __DIR__ . "/admin_service.php";

auth_json_exception_guard();
auth_require_method("GET");
require_admin_json();
auth_require_schema($conn);
personnel_require_schema($conn);
date_default_timezone_set("Asia/Manila");

refresh_appointment_time_states($conn);
$now = new DateTimeImmutable("now");
$reminders = admin_reminder_settings($conn);
$staff = admin_staff($conn);
$offices = admin_offices($conn, $now, $staff);

auth_json([
    "success" => true,
    "server_time" => $now->format("Y-m-d H:i:s"),
    "right_now" => admin_right_now($conn, $now),
    "attention" => admin_attention($conn, $offices, $staff, $now, $reminders),
    "offices" => $offices,
    "last_days" => admin_last_days($conn, $now, 7),
    "closures" => admin_closures($conn, $now),
    "campus_setup" => admin_campus_setup($conn, $offices),
    "reminders" => $reminders,
    "reminder_targets" => ADMIN_REMINDER_TARGETS,
]);
