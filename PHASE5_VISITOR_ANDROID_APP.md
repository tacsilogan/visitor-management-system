# Phase 5 — Native visitor Android application

## Current status

The first native Kotlin/Jetpack Compose implementation is in `visitor_app`. It uses the
Phase 4 API without changing the PHP staff login or replacing the existing database.

On September 20, 2026, Android Studio/SDK 37 and the real Firebase Android client were
connected. Gradle 9.6 successfully completed `testDebugUnitTest`, `lintDebug`,
`assembleDebug`, and `assembleRelease`. Firebase resource processing passed for both
variants. The release output remains unsigned and is not an app-store artifact.
Physical-device behavior, real push delivery, password-recovery email, GPS/offline behavior,
and the complete appointment workflow still require acceptance testing.

The local Phase 4 API was rechecked through Apache on September 20, 2026: its index and
health endpoints returned HTTP 200, MySQL reported connected, and protected visitor
endpoints correctly returned HTTP 401 without a bearer token.

## Product rules preserved

- Visitor and ISATU student signup exists only in the app. The staff sign-in page only lets a
  teacher request an account, which their department's Dean's Office approves.
- Since app 0.8.0, every new account confirms its email with a 6-digit code before it exists. A
  visitor agrees to tracking; a student is never tracked, has no pass, books teachers only and
  says "I'm here" instead (SCHOOL_ACCOUNTS.md). Visit decisions, proposed schedules, reminders,
  and cancellations are delivered through Notifications and Firebase push notifications.
- Since app 0.9.0 a visitor signs up on one short page, and a student in four steps that each fit
  above the keyboard (who you are, your class, sign-in details, then a summary with Edit on each
  part). The Student ID and the email code are typed into boxes (`ui/FormParts.kt`); the rules
  behind them are in `ui/SignupRules.kt` and its tests.
- The look since 0.9.0: ISATU navy at the top of every screen with a gold edge, white below, and
  gold for progress and the selected tab. The pieces are in `ui/FormParts.kt` (`NavyTopBar`,
  `NavyBand`, `GoldEdge`); use them on new screens rather than a white top bar.
- Since app 0.10.0 the app is called **ISATU Visits** on the phone, because ISATU students use it
  as well as visitors (the package id stays `ph.edu.isatu.visitor`, so it updates over older
  copies). The website keeps its name. Log in and Create an account put their card in the middle
  of the screen, between a blurred photo of the ISATU building's front fading into navy, with the
  logo centred above the card, and a navy wave with the university's name (`BrandedAuthPage` in
  `ui/AuthScreens.kt`). The photo is blurred beforehand, because Android before 12 can't blur:
  to change it, replace `res/drawable-nodpi/isatu_campus_front.jpg` with another blurred picture
  of about 800 × 543. The email last signed in with is remembered on the
  phone and filled in (never the password); the keyboard's Next goes to the password and Done logs
  in. Right after an account is made, the opening animation plays once more with "Welcome, (first
  name)" and "Your student account is ready" or "Your visitor account is ready" (`Welcome` in
  `ui/OpeningTimeline.kt`). Since 0.10.1, logging in plays it too, with "Welcome back, (first
  name)" on one line, and the opening says "Welcome to ISATU Visits" when nobody is logged in.
- Since 0.10.2 the Terms and Privacy Notice is Version 4 (`terms-privacy-v4`), effective November
  15, 2026, with ISATU's answers in place of the highlighted blanks: a parent or guardian agrees for
  someone under 18; visits, consent and activity records are kept 1 year and an account 1 year
  without use; the contact is mail@isatu.edu.ph. The server deletes on that schedule every day
  (`phone_tracker/record_retention_service.php`, checked by `tools/record_retention_test.php`).
- "Forgot password?" works like signing up since 0.10.0: the email, then the 6-digit code in boxes
  with the new password (`RecoverForm` in `ui/AuthScreens.kt`), then Log in with the email filled
  in. Before that, no app version could reset a password on the live server (PHASE4_MOBILE_API.md).
- Bookable destinations come from the API and remain limited to IT Department,
  IS Department, CS Department, Dean's Office, and Tech Support.
- Walk-ins receive an immediate pass when the selected office is accepting visitors.
  Appointments can use only availability returned by the selected office.
- Approval exposes a QR pass; pending requests never receive one.
- QR validity remains 30 minutes before the appointment through its scheduled end.
- `window_closed` and `completed` both display as **Appointment done**; there is no
  no-show label.
- Tracking consent is accepted during account creation and copied into an auditable
  visit record. Android's required operating-system location permission is requested as
  part of signup; it is separate from legal consent and does not start collection.
- Since app 0.9.0 a visitor's signup has one required checkbox, which agrees to the Terms and
  Privacy Notice (`ui/TermsNotice.kt`, with a link to read it) and names location sharing in its
  words; a student's names only the notice. `CONSENT_VERSION` in `app/build.gradle.kts` names
  the notice's version and goes to the server with signup and each visit's consent. An account
  that hasn't agreed to this version on this phone sees the notice once before the app opens,
  and can agree or sign out. That agreement is saved on the phone; the server first sees it
  with the account's next visit. The notice quotes server settings (listed at the top of
  `TermsNotice.kt`), so update it when they change. Its highlighted blanks still need ISATU's
  answers before real visitors use it.
- A foreground notification remains visible while tracking. Offline points stay on the
  phone until the API accepts them.
- Android 13 and newer users receive an in-app explanation and button for enabling
  appointment notifications instead of an unexplained permission prompt.
- The Android Back button returns from appointment details to the visit list.
- The launcher uses a padded adaptive icon so system icon masks do not crop the seal.
- The approved pre-oral visual direction is now adapted into a native mobile dashboard,
  branded authentication, status-aware visits, a single Notifications screen, clearer
  visitor-pass details, and a consistent profile/privacy area.
- Booking uses standard visit-purpose categories so future analytics are not fragmented
  by inconsistent capitalization or wording.
- Home uses the header bell as the single Notifications entry point instead of
  repeating notification summary and latest-update cards in the content area.
- Appointment dates are limited to the server-supported 180-day window; choosing a
  date automatically loads available times, shown as compact selectable slots with a
  clear selected-schedule confirmation.

## Main source map

| Area | Location |
|---|---|
| Gradle/project configuration | `visitor_app/*.gradle.kts`, `visitor_app/app/build.gradle.kts` |
| API request/response contract | `visitor_app/app/src/main/java/ph/edu/isatu/visitor/data` |
| Encrypted session and install ID | `data/SecureTokenStore.kt` |
| Offline GPS queue | `data/LocationDatabase.kt` |
| Foreground tracking and retry | `service/VisitorTrackingService.kt`, `service/LocationUploadWorker.kt` |
| Firebase client handling | `service/VisitorMessagingService.kt` |
| Authentication UI | `ui/AuthScreens.kt` |
| Home, visits, Notifications and Profile | `ui/MainScreens.kt` |
| Walk-in and appointment registration | `ui/BookingScreen.kt` |
| QR, rescheduling and automatic post-scan tracking UI | `ui/AppointmentDetailScreen.kt` |

## Remaining Phase 5 acceptance work

1. Test layout and accessibility on a small phone and a common modern phone.
2. Test the full app/API/Office/Security workflow on a physical Android device.
3. Verify real Firebase push delivery on that device.
4. Connect the institutional email provider and test password-reset links on-phone.
5. Confirm location collection, offline recovery, checkout stop, battery behavior and
   the Security map during a controlled campus walk.
6. Prepare a signed internal test build only after the privacy and retention decisions
   are approved.

Do not finalize Phase 6 analytics until the core physical workflow passes. Those tests
confirm that the event data used by analytics is actually produced correctly.

- Visits to a teacher (TEACHER_VISITS.md): the Book tab asks Office or Teacher. A visitor finds a teacher by
  name or course, and sees only "Available now" or the next free time. A walk-in to a teacher waits for them
  to accept, with a 10-minute timer, and if they can't, the visitor can pick another time or go to an office.
