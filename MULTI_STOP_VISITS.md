# Multi-stop visits and reschedule improvements

Approved by the project owner on September 29, 2026. This change adds one new booking
type and improves the existing "suggest another time" flow. Single appointments and
walk-ins work exactly as before.

## Multi-stop visits

A visitor can book one campus trip with **two or three different offices on the same
day**, for example the Dean's Office at 9:00 and the IT Department at 10:00. In the app
this is the **Visiting more than one office?** switch under both **Walk-in** and
**Appointment**.

- **Appointment + several offices:** one date, a time slot per office; each office
  approves its own stop.
- **Walk-in + several offices:** the visitor lists the offices in the order they will go.
  Every office must be accepting visitors. All stops are approved at once and the pass
  works immediately for one hour, like a single walk-in. After check-in the visit gets the
  same four hours on campus as a single walk-in. Walk-in stops cannot be rescheduled.

| Belongs to the visit (being on campus) | Belongs to each stop (an office meeting) |
|---|---|
| One QR pass and one gate check-in | Office approval, decline, or suggested time |
| One GPS tracking session and consent decision | Office availability and capacity |
| Checkout at the gate | Office-scoped access; offices never see each other's stops |

Each stop is an ordinary row in `appointments`, linked by `appointments.visit_id` and
numbered by `stop_number`. The `visits` table holds the pass token, check-in, and
completion. GPS points are stored against one stop (`visits.tracking_appointment_id`), so
the existing tracking, route-history, and retention code is reused unchanged.

### Rules

- Two or three stops, each a different office, all on one day.
- Appointment stops need at least 10 minutes between them, and none may clash with the
  visitor's other bookings. All stops are created together or not at all.
- One open multi-stop visit per visitor per day.
- The pass appears once any office approves. It is valid from 30 minutes before the
  first approved stop until the end of the last approved stop.
- One scan checks in every approved stop. A stop approved later, while the visitor is on
  campus, joins the active check-in without another scan.
- An office marks its stop done with **Mark meeting done**. Tracking continues because the
  visitor is still on campus.
- The visit ends when Security scans the visit pass a second time at the gate (or presses
  **End visit**); stops still waiting for an office decision are then cancelled and their
  offices told the visitor left. It also ends on a confirmed campus exit or at the end of
  the day. See `LIVE_MONITORING.md`.
- If an office suggests a time for a stop, it must fit around the visitor's other stops.
  A time on a different day moves that stop out of the visit, and it gets its own pass.
- A visit closes automatically when none of its stops can happen (all declined, cancelled,
  or expired). Cancelling the visit cancels every stop and notifies each office.
- Security or Admin can still authorize an early or late pass with a recorded reason.

### What each role sees

- **Visitor app:** Book → Walk-in or Appointment → turn on "Visiting more than one
  office?". The visit appears as one card; the
  visit screen shows the pass, each stop's status, and the campus map with the next stop
  after check-in.
- **Office dashboard:** the request shows "Stop 1 of 2" and the visitor's other stop
  times (without the other offices' names or details) so a suggested time can fit.
- **Security dashboard and scanner:** one row, one scan, and one live map marker per
  visit; the route shows every office in order.

## Reschedule improvements

- One suggested time is accepted with a single **Accept suggested time** tap.
- Suggested times show their date, time range, and length.
- **None of these work** declines the suggestion and opens the booking form with the same
  office, purpose, subject, and details filled in.
- Offices can also suggest a new time for an **approved** appointment. The QR pass is
  withdrawn until the visitor accepts, and the visitor is told the original time can no
  longer be kept.
- Cancelling a request with an open proposal now releases the proposal's held time slots.
  Previously those slots stayed held.
- Tapping a push notification now opens the related appointment or visit when the app was
  in the background. Previously the app ignored the id because Android delivers it as
  text, not a number.

## Installation

Run `phone_tracker/multi_stop_visits_migration.sql` after `mobile_api_migration.sql`. It is
additive and safe to run more than once. Without it, single appointments keep working and
the multi-stop endpoints answer HTTP 503.

## Verification (September 29, 2026)

- 100 automated server checks passed against a disposable MariaDB database through the PHP
  built-in server, covering booking validation, approvals, gate check-in, late approval,
  shared tracking, Mark meeting done, Security views, checkout, rescheduling inside a
  visit, leaving a visit, rescheduling an approved appointment, whole-visit cancel,
  released proposal holds, automatic closing and completion, time overrides, walk-in
  visits to several offices, and the unchanged single-appointment flow.
- Android: unit tests, Android lint (no new issues), and `assembleDebug` pass.
- Not yet verified: the flow on a physical phone, and the Office and Security dashboard
  changes in a browser.

## Known limits

- A single appointment can still be booked at the same time as a stop of a visit. The
  overlap check applies to multi-stop bookings and to times accepted for a visit's stops.
- Admin analytics count each stop as an appointment. Define visit-level counting in
  Phase 6.
- Visits are created only from the app. The old web visitor portal was retired on
  2026-11-15; `index.html` now tells visitor accounts to use the ISATU Visits app.
