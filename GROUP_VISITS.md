# People with a visitor, the ID check, and getting the app

## Why

Visitors often come with others: a parent with a child, a couple, a teacher with a class.
Before this change only the account holder had a record. The guard couldn't note who came
in with them, the "inside campus" count missed everyone else, and an adult could walk in on
someone else's pass without showing an ID. The app itself was shared as an APK on Google
Drive, with no official place to download it and no way to tell visitors about a new version.

Each person with a visitor now has a name and an age group, the guard checks each adult's ID
at the gate, and the website's main address is where visitors get the app. Only the visitor
needs the app, and only the visitor's phone is tracked.

## People with a visitor

**Booking (app 0.13.0).** Under the visitor type, "Anyone coming with you?" has **Add a
person**: the full name as on their ID, and **18 or older** or **Under 18**. Up to 10 people
(`VISIT_PEOPLE_MAX`). A name needs 2 to 150 characters with at least one letter; spaces are
tidied the same way on the phone and on the server.

The list belongs to the pass, so it works for one office and for several:
`visit_people.appointment_id` for a single visit, `visit_people.visit_id` for a visit to
several offices (one pass for the whole trip, `MULTI_STOP_VISITS.md`).

**Changing the list.** **Change** on the pass, or **Change who's coming** in the visit
details, until the guard checks the visitor in: while a single visit is waiting for approval,
approved, or has a new time suggested (`MOBILE_PEOPLE_EDITABLE_STATUSES`), and while a trip is
open. `POST api/v1/visit_people.php` replaces the whole list. The office always sees the
latest list. It gets no message about the change, even after approving the visit.

**In the app.** The pass shows "With you · 3" and each name ("· under 18" for a child), and
Home shows "You + 3" on the visit card.

## At the gate: the ID check

Scanning a pass no longer checks the visitor in at once. `scan_appointment.php` takes a `step`:

1. `check` (the default) changes nothing. It answers `action: "check_ids"` with the pass
   holder and the people on the pass, and the scan page shows the ID check card
   (`id_check.js`).
2. The guard goes down the list:
   - the holder: the type of ID they showed;
   - each adult: the type of ID (the type only, never its number), or **No ID** with a reason;
   - each child counts as here unless the guard taps **Didn't come**;
   - **Edit name** when the ID spells it differently (the booked name is kept in
     `booked_name`);
   - **Name doesn't match** turns one person away; the others still come in;
   - **Add someone who came** for a person who wasn't on the list.
3. `confirm` checks the visitor in and writes every answer in the same transaction. The format
   is described above `visit_people_clean_check()`. Anyone on the pass who is missing from the
   answer didn't come. Each person turned away gets their own activity record.
4. `refuse` is **Name doesn't match** on the holder. Nobody comes in, the pass still works for
   its owner, and the activity log records `appointment.pass_refused`.

The same card opens for **Check in without QR** on the Security dashboard
(`GATE_REGISTRATION.md`). **Register at the gate** has a list of people too, for a visitor
without the app; each adult needs an ID type there as well.

## On campus

- **Security Home.** "Inside campus now" counts people: the visitors and everyone still with
  them, with how many are under 18. **Find anyone** searches the people with a visitor too, by
  name, by the visitor's name, or by the pass reference. A visitor's row shows "+2 with them",
  and "No ID shown" when the holder had none.
- **Someone leaves early.** **Left early** on that person (`security_visit_person.php`,
  `action: "left"`). They no longer count as inside.
- **Someone arrives later.** While the visitor is on campus, the guard checks that person's ID
  and taps **Check in** or **Not let in** (`check_in`, `refuse`). Both need the `visits.scan`
  permission.
- **Check-out.** When the visitor is checked out, everyone still with them leaves too. The
  message says how many.
- **Offices** see the names on the Today and Requests cards, and in the visit details, under
  "People with them", what happened to each at the gate (came in, didn't come, left early, not
  let in).
- **Admin.** Reports count the people, the visits with people, those under 18, and those not
  let in. The activity log names everyone who came in, with the type of ID each showed.

## Privacy

- **Notice Version 6** (`terms-privacy-v6`, `TermsNotice.kt`). A visitor's pass covers the
  people they add. They tell those people first that ISATU keeps their names. ISATU keeps each
  name and whether the person is 18 or older with the visit. The guard notes the type of each
  ID, never its number. Only the visitor's phone shares a position. Everyone who signs in to
  0.13.0 agrees again, with a "What changed" box at the top.
- **When it takes effect.** On the day 0.13.0 is published on the website. Set
  `TermsNotice.EFFECTIVE_DATE` to that day before building the release.
- **The gate notice** (`gate-notice-2`, `GATE_NOTICE_TEXT`) now mentions the names of anyone
  with the visitor and the type of ID each adult showed.
- **Deleted after a year.** The people are deleted with their visit by
  `record_retention_service.php`; the database removes them with the visit's row. Their
  activity records are filed under the visit's appointment, so they go after a year as well.

## Getting the app

- **The main address** (`index.php`, drawn by `phone_tracker/get_app_page.php`) is now the
  visitors' page:
  - **Download** with the current version, its size and date, and what's new;
  - how to install it;
  - a QR code for opening the page on a phone from a laptop;
  - what to do without an Android phone (Register at the gate);
  - **Staff sign in**, at the top and at the bottom.

  Until a version is published, the page says the app is coming soon.
- **`download_app.php`** serves the current APK. A download is counted by the time only,
  never by who.
- **`api/v1/app_release.php`** (no sign-in) gives the current version. From 0.13.0, the app
  shows "A new version is ready" on Home when the published version is newer than its own.
  **Update** opens the download page.
- **The poster** (`app_poster.php`, A4, English) has a QR code to the main address, for the
  gate and the offices. The Admin prints it from **Menu › Visitor app**.

## Publishing a new version

In **Admin › Menu › Visitor app**:

1. Build the signed release (`DEPLOYMENT.md`, step 6) with a higher `versionCode`.
2. **Choose the new app file.** The server reads the file itself, not its name, and refuses it
   unless it is:
   - an Android app, up to 100 MB;
   - ISATU Visits (`ph.edu.isatu.visitor`);
   - newer than the current version;
   - signed with the same key (read from the APK Signing Block, v2 or v3);
   - not the exact file of a kept version.
3. Optionally write **What's new** (up to 160 characters), then **Publish**. It asks "Confirm
   it's you".
4. The version before is kept. **Go back to 0.12.0** gives new downloads that version again;
   phones that installed the newer one keep it. After going back, the same button reads **Use
   0.13.0 again**. Either way it asks "Confirm it's you", and the activity log records it.

The sheet also shows the downloads (in all and this week) and how many phones that used the
app in the last 30 days have an older version.

**The signing key.** The first version published sets the key every later version must have.
To check even the first upload, set `ISATU_APP_SIGNING_SHA256` on the server to the release
key's SHA-256 fingerprint, the `SHA256:` line of
`keytool -list -v -keystore isatu-visitor-release.jks` (the colons may stay).

**Where the files are kept.** Outside the web folder: `/data/private/apps` on Railway
(`ISATU_DATA_DIR`), or `phone_tracker/private/apps` on a laptop, which `.htaccess` closes to
browsers. Only the current and the previous versions are kept. An upload that is never
published is replaced by the next one, or removed with **Cancel**.

**Upload size.** The server allows 4 MB uploads, too small for the app.
`phone_tracker/.htaccess` raises the limit to 110 MB for `admin_app_release.php` only. That
works where PHP runs inside Apache, as in the Docker image and XAMPP. Anywhere else, raise
`upload_max_filesize` and `post_max_size` in `php.ini`.

## Database

`group_visits_migration.sql`, applied on the next start by `tools/setup_database.php`:

- `visit_people`: each person with a visitor, and what happened at the gate (`gate_status`
  expected, in, absent or refused; `id_type_shown`; `no_id_reason`; `left_at`).
- `appointments.no_id_shown` and `no_id_reason`: the holder showed no ID.
- `app_releases` and `app_release_downloads`: the published app versions and the download
  count.

## Files

- Server: `visit_people_service.php`, `scan_appointment.php`, `visit_service.php`,
  `gate_service.php`, `gate_register.php`, `security_dashboard.php`,
  `security_visit_person.php`, `scan_checkout.php`, `monitoring_service.php`,
  `office_dashboard.php`, `office_appointment.php`, `list_appointments.php`,
  `activity_service.php`, `admin_report.php`.
- App API: `api/v1/_appointments.php`, `api/v1/_visits.php`, `api/v1/visit_people.php`,
  `api/v1/app_release.php`, `api/v1/openapi.yaml`.
- Getting the app: `app_release_service.php`, `admin_app_release.php`, `download_app.php`,
  `get_app_page.php`, `app_poster.php`, `index.php`, `assets/images/get-app/`.
- Website: `id_check.js`, `security_scan.html`/`.js`, `dashboard.html`/`.js`,
  `gate_register.js`, `offices.html`/`.js`, `admin.html`/`.js`, `style.css`.
- App: `PeopleWithYou.kt`, `BookingScreen.kt`, `VisitorPass.kt`, `AppointmentDetailScreen.kt`,
  `VisitDetailScreen.kt`, `MainScreens.kt` (Home and the update card), `TermsNotice.kt`,
  `TermsScreens.kt`, `AppViewModel.kt`, `Models.kt`, `VisitorApi.kt`, `VisitorRepository.kt`.
  Pictures of the new screens: `GroupScreenshots.kt` (`:app:updateDebugScreenshotTest`).
- Tests: `tools/group_visits_test.php`, run by the GitHub "Server image" check, and
  `TermsNoticeTest.kt` for Version 6.
