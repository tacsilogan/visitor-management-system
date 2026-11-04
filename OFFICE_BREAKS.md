# Office breaks and pausing visits

Office personnel can add breaks to their weekly visitor hours (a 12–1 PM lunch break, a
Monday flag ceremony) and pause new visits for an unplanned break. Visitors can't book or
walk in during either.

## On the office dashboard (Availability)

- **Breaks per day:** "+ Add break" under an open day; up to 4 a day, each with a name
  (Lunch break, Flag ceremony, Staff meeting, or anything else). A break must be inside the
  day's hours and must not overlap another one.
- **Add a 12–1 PM lunch break to every open day** and **Copy to other open days** set up a
  whole week in a few clicks.
- Each open day shows how many **slots** it has, a **timeline** (green is bookable, striped
  is a break, a line where each slot starts), and a note when time is too short for a slot
  ("30 min before Lunch break").
- **What visitors can book** lists the exact times the visitor app shows for a chosen day.
- **Saving checks booked visits.** If new hours or breaks leave visits outside the schedule,
  they are listed first, each with **Open** to suggest another time. The office saves anyway
  or goes back. Nothing changes for those visitors until the office acts.
- Unsaved edits are marked, and the browser asks before leaving the page with them.
- The **status chip** says where the office stands now and refreshes every minute: "Open now ·
  Lunch break at 12:00 PM", "Lunch break · back at 1:00 PM", "Closed now · opens tomorrow at
  8:00 AM", "Paused · Staff stepped out · until 2:30 PM".
- **Pause visits** (15 minutes up to the rest of today) covers an unplanned break; **Resume
  now** ends it early. A pause is saved as an "Office unavailable" temporary change, so it
  also appears in that list.

## How it is stored

A day with breaks is saved in `office_availability_rules` as several bookable windows: 8:00
to 5:00 with lunch at 12:00 becomes 8:00–12:00 and 1:00–5:00. So everything that already
checks availability skips breaks with no change of its own: `office_availability_check()`,
the app's time slots, office reschedule proposals, and the web booking page. The table
`office_availability_breaks` (`phone_tracker/office_breaks_migration.sql`) keeps each break's
name and span for display. Before that migration runs, breaks still work but are all called
"Break". The server applies the migration by itself on its next start (`tools/setup_database.php`).

The rules live in `phone_tracker/office_availability_service.php`: `office_normalize_week()`
(checks a week and works out its windows), `office_week_for_editing()`,
`office_open_state_at()`, `office_schedule_conflicts()`, and
`office_walk_in_block_reason_for()`. The dashboard is `offices.html`, `offices.js`, and
`office_availability.php` (actions `save_schedule`, `pause_now`, `resume_now`).

## For visitors

- Time slots never fall in a break. A booking that does is refused with the break's name:
  "The office is closed for Lunch break from 12:00 PM to 1:00 PM. Choose another time."
- **Walk-ins** are refused during a break, a pause, or outside visitor hours, unless the
  office opens again within 30 minutes (a walk-in pass lasts an hour;
  `OFFICE_WALK_IN_LEAD_MINUTES`). The visitor is told when to come back: "IT Department is
  closed for Lunch break until 1:00 PM. You can create a walk-in pass from 12:30 PM, or book
  an appointment for later." An office with no weekly hours saved stays open to walk-ins, as
  before.
- The mobile API gains fields that older app versions ignore:
  - `offices.php`: `breaks` (the named weekly breaks) and `open_status` (`state`, `label`,
    `until`, and a `message` for visitors).
  - `availability.php`: `unavailable_periods` for the date, each a `break` or a `closure`
    with its name, so the app can explain a gap in the times.

## Tests

`php phone_tracker/tools/office_breaks_test.php` checks these rules without a database. The
**Server image** GitHub check runs it too.
