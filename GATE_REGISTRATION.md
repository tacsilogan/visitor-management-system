# Register at the gate, check in without QR, and the Security dashboard

## Why

The ISATU Visits app (Android 8.0 and up) is the main way into the system. Some visitors
can't or won't use it: iPhone users, people with a basic phone, no mobile data or a dead
battery, people who refuse location tracking, and people who won't install an app for a
short errand. Before this change the guard had no way to record them, so the records, the
"inside campus" count and the reports missed them. Location tracking is also meant to be
consented to freely (Data Privacy Act of 2012); a way in without tracking keeps it a choice.

## Register at the gate

Security (or an Admin) opens **Register at the gate** on the Security dashboard, or on the
Scan visitor pass page ("Visitor has no QR code?").

1. The guard checks the visitor's ID.
2. They enter the full name, the type of ID shown (the type only, never the number), an
   optional contact number, the office, the purpose (the same list as the app) and an
   optional concern.
3. They read the notice to the visitor (`GATE_NOTICE_TEXT` in `gate_service.php`) and tick
   "The visitor agreed".
4. **Register and check in** saves the visit already checked in. The guard gives the visitor
   their reference (V-2026-000124).

Rules (`gate_register_visitor()`):

- The office must have Office Personnel and be taking walk-ins now: the same rule as an app
  walk-in (`office_walk_in_block_reason`), so not during a break, a closure, or outside
  visitor hours, except within 30 minutes of opening again.
- The visit is a walk-in with `registration_source = 'gate'`, no visitor account and no
  tracking. Its booked time is 4 hours from check-in, as for an app walk-in after check-in,
  so "Overstay" works the same way.
- The office gets the usual "Walk-in visitor arriving" notice. The activity log records it as
  a check-in by that guard ("Registered … at the gate after an ID check"), with the notice
  version, and never the contact number.
- The visit ends when the guard taps **End visit** (there is no pass to scan out), when the
  office marks it done, or automatically at the end of the day. It is deleted after a year
  with every other visit.

## Check in without QR

For a booked visitor who can't show their pass (dead phone, cracked screen): the guard finds
the booking with the dashboard's search (name or reference) or in **Expected today**, checks
the ID, and taps **Check in without QR**. The dashboard sends `{"appointment_id": n}` to
`scan_appointment.php`, which uses that booking's own pass, so every scan rule applies: the
time window, time overrides (the same recorded exception as on the scan page) and multi-office
visits. The visit is marked `checked_in_without_qr`, and its history says the ID was checked.

## The Security dashboard

What a guard does most, or must act on now, comes first and biggest:

1. **Scan visitor pass** and **Register at the gate** at the top.
2. **Find a visitor** by name or reference, including today's bookings (`/` jumps to it).
3. **Inside campus now**: the headcount, with how many are on the live map, waiting for GPS,
   have lost signal, are outside the boundary, or are not tracked.
4. **Needs attention**: overstays (30 minutes past the booked time), visitors outside the
   boundary, and lost signal (no position for over 3 minutes), with Monitor and End visit.
5. Today's numbers, smaller.
6. Tabs: **Inside now**, **Expected today**, **Left today**, and **All records** (the earlier
   table with its status filter).

`security_dashboard.php` returns `inside`, `expected` and `left` with each visitor's location
state (`monitor_visitors()`), without exact positions, which stay on the live map. A gate
visitor's location state is `not_tracked`.

## Files

- `gate_registration_migration.sql`: `registration_source`, `id_type_shown`, `gate_consent_at`,
  `checked_in_without_qr` on `appointments`.
- `gate_service.php`, `gate_options.php`, `gate_register.php`, `gate_register.js` (the form,
  shared by both pages).
- `scan_appointment.php` (check in without QR), `security_dashboard.php`, `dashboard.html`,
  `dashboard.js`, `security_scan.html`, `security_scan.js`, `monitoring_service.php`.
- Office detail (`office_appointment.php`, `offices.js`), activity log (`activity_service.php`),
  Admin analytics and its Excel export (`admin_analytics.php`, `analytics_export.php`).
- `tools/gate_registration_test.php`, run by the GitHub "Server image" check.
