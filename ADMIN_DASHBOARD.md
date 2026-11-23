# The Admin dashboard on phones, tablets and laptops

Rebuilt on 2026-11-22, after a mockup the user approved: `phone_tracker/admin.html`,
`phone_tracker/admin.js`, and the "Admin dashboard" block at the end of `phone_tracker/style.css`.
It uses the same parts as the Office Personnel and Security pages (`OFFICE_MOBILE.md`), so the
menu, the sheets and the buttons look and behave the same. What it shows comes first by what needs
the administrator, not by which table it comes from.

## One menu

**Home · Offices · People · Reports · Menu** on a phone (a bar at the bottom). On a laptop the blue
side menu also has **Activity** and **Campus map**; on a tablet it is a strip of icons with small
names. Each page has its own address (`#offices`, `#people`, `#activity`, `#reports`, `#campus`,
`#menu`); the old ones (`#users`, `#security`, `#analytics`, `#dashboard`) still open the right
page. Details open as sheets (from the bottom on a phone, from the right on a laptop), and the
phone's Back button closes a sheet first.

## Home

- **One search** for visitors (by name, email or visit code such as V-2026-000123), staff and
  offices (`admin_search.php`).
- **Right now:** on campus, expected today, waiting for an office, visits in the last 7 days
  compared with the 7 before. Each opens a list (`admin_visits.php`); a visit opens its history.
- **Needs your attention**, most urgent first, each with its own buttons: offices without Office
  Personnel, locked sign-ins (Unlock now), an office slow to answer (Remind the office), staff
  without two-step sign-in, temporary passwords not used or expired, offices not on the campus
  map. The top three show; the rest are behind "Show more". The bell opens the whole list.
- **Offices right now**, the **last 7 days** (a chart) and the **latest activity**.

Nothing on the Admin pages shows where a visitor is: only Security has the live map.

## Offices

A card per office: its state (open, on a break, paused, closed now, no staff), its staff, requests
waiting and the oldest, visits today, how long it takes to answer (working hours, last 30 days) and
a **setup bar** of five steps (hours, staff, map pin, walking route, directions inside). Filters:
setup not finished, no staff, requests waiting, archived. An office's sheet has Remind, Continue
setup, Edit (name, where, about; archive), and for an archived office Make it active again or
Delete (only if it was never used).

## People

**Staff** and **Visitors**, with flags on each row (locked, temporary password, 2-step off, turned
off, no name on file) and filters for each. A person's sheet: Unlock now, New password, Edit details
(name, position, email, phone, office), See activity, Reset 2-step, Sign out everywhere, Turn off or
on, Remove. **Add person** asks for the role first; **Several at once** is below.

## Activity, Reports, Campus map, Menu

- **Activity:** the sign-in security numbers and locks, then everyone's activity by day, with
  filters by kind and by person, and Excel or CSV export of what is shown.
- **Reports:** today, this week, this month or this quarter, each compared with the same stretch
  before (`admin_report.php`): visits, average visit, requests answered in time, visitors who didn't
  come; **What stands out** (plain sentences); visits per day with a table view; offices with their
  answer times; why people visit; the busiest hours; the walking routes map. Export: a report to
  print or save as PDF, Excel, or CSV (`admin_analytics.php`, as before).
- **Campus map:** a setup checklist (boundary, gates, office pins, routes, directions inside) with
  a table per office, above the same map editor as before.
- **Menu:** the features below, Account security, Refresh, **Reset visitor data** (moved here from
  the analytics page so it isn't tapped by mistake), Sign out.

## The nine additions

1. **Close the campus to visitors** (Menu, or Offices › Close campus; `admin_campus_closure.php`,
   `campus_closure_migration.sql`). All day for one or more days, or some hours of one day, with a
   reason and a message. Before confirming it shows what it affects. Every office gets an "Office
   closed" change for that time (so bookings and walk-ins are refused), booked visits in that time
   are cancelled and each visitor gets the message in the app, and each office is told once. Home
   and Offices show it with **Change** and **Reopen the campus**; reopening removes the change, and
   cancelled visits stay cancelled. Office staff see it on their Hours page but can't remove it.
2. **Guided office setup** (Add office, or Continue setup on an office): Office → Staff → Map pin →
   Directions inside → Done. Steps can be skipped and finished later. The pin is saved alone
   (`admin_office_pin.php`), so the Campus map's other pins are untouched.
3. **Automatic request reminders** (Menu; `admin_settings.php`,
   `workers/send_request_reminders.php`). Off until turned on. An answer target (2, 4 or 8 hours, or
   1 working day, counted Monday to Friday, 8 AM to 5 PM): the office's staff get one notification
   when a request passes it, and another 1 hour before a request expires. Each reminder is in the
   activity as automatic. With reminders on, Needs your attention shows a slow office only after the
   reminders didn't help. **Remind the office** still works any time (at most every 10 minutes).
4. **Staff reset their own forgotten password** (sign-in page, "Forgot password?";
   `staff_password_reset.php`). Staff with an email on file get a 6-digit code (15 minutes, 5 tries,
   5 codes an hour) and choose a new password; the reply never says whether an account exists.
   Other devices are signed out, trusted browsers are forgotten, so two-step sign-in is still
   asked. Staff without an email still ask an administrator; Menu › Staff who forget their password
   lists them.
5. **Morning summary email** (Menu; `workers/send_admin_digest.php`, the `admin_digest` email).
   Each administrator turns it on for themselves: weekdays at 6, 7 or 8 AM, with what needs them,
   the last working day's numbers and the slowest office. Skipped on days nothing needs them unless
   they ask for it daily. It never includes visitors' contact details or locations. It needs an
   email on the administrator's account and email set up on the server (`DEPLOYMENT.md`).
   `php workers/send_admin_digest.php --now="2026-11-23 07:05"` tries it as if it were that time.
6. **Add several staff at once** (Add person › Several at once): one per line, "name, position,
   office (or Security), email". Each line is checked first (already has an account, unknown
   office, administrators one at a time); then the accounts are made and **password slips** print,
   one per person, with the sign-in address, username and temporary password. A template for Excel
   (CSV) can be filled in and uploaded.
7. **Checks before risky changes:** turning off or removing an office's only Office Personnel warns
   that visitors can't book it and its waiting requests will expire, with **Add someone first**
   (`dialogs.js` now has a third button for this). The only active admin can't be turned off or
   removed (as the server already refused).
8. **Printable report** (Reports › Export): one page with the numbers, what stands out, the chart,
   the offices and the reasons, and Prepared by / Noted by lines. The browser's print window can
   save it as a PDF.
9. **Keyboard shortcuts on a laptop:** / search, N add a person, 1 to 6 the pages, Esc closes a
   panel, ? shows the list.

## Background jobs

`workers/run_scheduled_jobs.php` now also runs the reminders and the morning summary every minute;
each does nothing until turned on, and the summary only between its hour and noon on weekdays.
Settings are kept in `mobile_api_settings` (`admin_reminders`, `admin_digest_<user id>`).
