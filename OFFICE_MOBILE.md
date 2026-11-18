# The Office Personnel pages on phones, tablets and laptops

Rebuilt on 2026-11-18 so Office Personnel can work from a phone: `phone_tracker/offices.html`,
`phone_tracker/offices.js`, and the "Office Personnel pages" block at the end of
`phone_tracker/style.css`. The office rules are the same as before (`PHASE2_OFFICE_DASHBOARD.md`,
`OFFICE_BREAKS.md`); this is about where things are and how they work on a small screen.

## One menu

**Today · Requests · Visits · Hours · Menu.** Requests shows a red number for the requests waiting
for an answer.

- Phones (up to 720 px wide): a bar at the bottom.
- Tablets (721 to 940 px): a strip of icons with small names.
- Laptops: the blue side menu.

Each page has its own address (`offices.html`, `#requests`, `#visits`, `#hours`, `#menu`), and the
old ones (`#dashboard`, `#availability`, `#history`) still open the right page. The phone's Back
button goes to the page before, and closes an open sheet first.

## Today

- **The office's status** (open, on a break, closed, paused) with **Pause** or **Resume**. It also
  shows on Hours and, in one line, in the top bar of every page.
- **A visitor who can't find the office** (they tapped "I can't find it" in the app) stays on
  every page until someone taps **Someone is going**. Opening the notification doesn't clear it.
- **Four numbers:** to answer, here now, on campus, later today.
- **Needs your answer:** the two soonest requests, with **Approve**, **Other time** and
  **Decline** on the card.
- **Today's visitors** in groups: **Here now** (their phone confirmed they reached the office),
  **On campus** (checked in at the gate), **Later today** (approved), **Done** (checked out,
  meeting done, or didn't come). A checked-in stop of a visit to several offices has
  **Meeting done**.

## Requests

- **Needs your answer**, soonest visit first (it used to be the oldest request first). A request
  nobody answers becomes "No office response" when its time ends (`appointment_maintenance.php`),
  so each card says when that is.
- **Waiting for visitor:** the times the office offered, when, and until when the visitor can
  choose.
- **Details** open as a sheet: when and why first, empty fields left out, an Email link, and the
  activity. Decline, Other time and Approve stay at the bottom. Approve still asks first, because
  it issues the QR pass.
- **Decline:** tap a quick reason or write one (at least 5 characters, as before).
- **Other time:** tap up to 3 free times from the office's hours (`office_free_times.php`), or
  pick another day. Times that clash with the visitor's other visits, with the 10-minute gap
  between stops, are left out.
- There is no call button. Phone numbers are plain text, and the page asks phones not to turn
  them into call links (`format-detection`).

## Visits

Today, Upcoming, Past (finished: completed, declined, cancelled, no office response, didn't come)
and All, with one search. Past and All also filter by status. Cards on phones, a table on laptops,
30 at a time with **Show more**.

## Hours

- The status and Pause on top.
- **Every week:** seven rows, each with a bar (green: visitors can book; yellow: a break) and the
  number of times. Tap a day to change its hours and breaks in a sheet; what visitors can book
  updates as you type. **Copy** puts that day on the other open days.
- **Each booking:** how long one visit lasts and how many visitors can book the same time.
- **Temporary changes:** the current and coming ones. Changes that ended in the last 30 days,
  such as past pauses, are folded under "Ended in the last 30 days".
- **Accepting visitors**, for long closures.
- After any change a bar with **Discard** and **Save** appears. Saving still warns when booked
  visits fall outside the new hours.

## Menu

The account, Edit profile (the photo), Account security (password, two-step sign-in, signed-in
devices), Notifications, Refresh now, and Sign out, which asks first.

## Server

| File | What changed |
|---|---|
| `phone_tracker/list_appointments.php` | Also returns `arrived_at`, `arrival_method`, `arrival_accuracy_meters`, `registration_source` and `checkout_method`; for an office account, requests waiting for the visitor carry `offer` (`times`, `sent_at`, `response_deadline`). |
| `phone_tracker/office_free_times.php` | New. `GET ?appointment_id=`: up to 3 days with free times, looking up to 3 weeks ahead, starting an hour from now (the rule `office_propose_reschedule.php` checks). Office Personnel only (`office.appointments`). |
| `phone_tracker/office_availability_service.php` | `office_free_slots()`: one day's bookable times, worked out the way the visitor app lists them. |
| `phone_tracker/office_availability.php` | The "saved with booked visits outside" message names the new pages. |

No database change. The visitor app and the Admin pages are unchanged. The Security pages' Menu
gained Account security, which phones could no longer reach after the Security pages moved to a
bottom menu.
