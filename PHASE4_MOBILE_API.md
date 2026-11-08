# Phase 4 — Mobile API and notification foundation

The visitor Android app uses the versioned JSON API under:

`/visitor-management-system/phone_tracker/api/v1`

The staff website continues using its existing PHP session endpoints. The native app
uses bearer tokens, so mobile authentication does not alter Admin, Security, or Office
login behavior.

## Installation

1. Import the existing schemas through `appointments.sql`/Phase 1.
2. Import `phone_tracker/mobile_api_migration.sql` into `phone_tracker`.
3. Use HTTPS before testing on a physical phone outside local development.
4. Keep `phone_tracker/config/mobile_api.php` and the Firebase service-account JSON out
   of Git. Both are ignored by `phone_tracker/config/.gitignore`.

The migration is additive and repeatable. It adds visitor email/profile fields, hashed
API access tokens, one-time account-action tokens, email outbox records, Android device
registrations, notification delivery records, tracking sessions, location idempotency
fields, and configurable mobile policies.

## Common API rules

- Send and receive JSON using UTF-8.
- Authenticated requests use `Authorization: Bearer <access_token>`.
- Raw access tokens are never stored in the database; only SHA-256 hashes are stored.
  A password-reset code is kept only as a hash keyed by the server's secret
  (`auth/_password_reset.php`). The short-lived raw code waits in its `outbound_emails`
  row only until the email worker sends it or gives up; the worker then deletes it from
  the row.
- "Forgot password?" emails a 6-digit code, as signing up does. It works for 15 minutes
  and only while it is the account's newest code; 5 wrong tries end it, and an account is
  sent at most 5 codes an hour. `reset_password.php` takes it in the `token` field, which
  every app version sends. The reply to a code request is the same for any email.
- Access tokens expire after 30 days by default and are revoked by sign-out or password
  reset.
- Authentication and recovery requests are rate-limited by identity and IP address.
- Production responses never expose password-reset tokens. During isolated testing,
  `VISITOR_APP_ENV=development` can return a `development_reset_token` field.
- Successful responses contain `success`, `data`, and `meta`. Errors contain `success`,
  `message`, optional field `errors`, and `meta`.
- `data` is always a JSON object. PHP encodes an empty array as `[]`, so `api_success()` sends
  `{}` instead: before that fix, the reply to "Forgot password?" in production was `data: []`,
  and the app failed to read it ("Expected BEGIN_OBJECT but was BEGIN_ARRAY ... path $.data").
  Inside `data`, an object that can be absent is `null`, never `[]`. The app (0.10.0 and newer)
  also reads an empty `[]` as an empty object, but older apps on phones don't.
- Every response includes an API version and request ID.

## Endpoint directory

| Method | Endpoint | Authentication | Purpose |
|---|---|---|---|
| GET | `/health.php` | Public | Database/API readiness |
| POST | `/auth/register.php` | Public | Register a visitor account |
| POST | `/auth/login.php` | Public | Issue a bearer token |
| POST | `/auth/logout.php` | Bearer | Revoke the current token |
| POST | `/auth/request_password_reset.php` | Public | Email a 6-digit reset code (same reply for any email) |
| POST | `/auth/reset_password.php` | Emailed code | Set a new password and revoke all sessions |
| GET/PATCH | `/me.php` | Bearer | Read/update visitor name and contact number |
| GET | `/offices.php` | Bearer | The five official offices and their rules |
| GET | `/availability.php` | Bearer | Available slots for an office/date |
| GET/POST | `/appointments.php` | Bearer | Visit history, walk-in pass, or appointment request |
| GET | `/appointment.php?id=` | Bearer | Details, proposal, and eligible QR pass |
| POST | `/appointment_cancel.php` | Bearer | Cancel an eligible appointment |
| POST | `/reschedule_response.php` | Bearer | Accept/decline an Office proposal |
| GET | `/notifications.php` | Bearer | Paginated in-app notifications |
| POST | `/notification_read.php` | Bearer | Mark one/all notifications read |
| POST/DELETE | `/devices.php` | Bearer | Register, rotate, or remove an FCM token |
| GET/POST | `/consent.php` | Bearer | Inspect, grant, or withdraw GPS consent |
| GET/POST | `/tracking.php` | Bearer | Inspect/start/stop an appointment session |
| GET | `/campus_map.php?appointment_id=` | Bearer | Campus boundary, gates, destination and stops (with Department Directory locations and recorded arrivals), campus-exit rules, and the arrival rule for the visitor map (`VISITOR_NAVIGATION.md`) |
| POST | `/arrival.php` | Bearer | Confirmed arrival at an office stop (`method` gps or visitor); notifies the office and Security |
| POST | `/locations.php` | Bearer | Idempotent single/batch GPS uploads (inside the campus only) |

The machine-readable contract is in `phone_tracker/api/v1/openapi.yaml`.

## QR privacy rule

Creating an appointment returns `qr_pass: null` until Office approval. Creating a
walk-in returns an approved QR pass immediately when the office is accepting visitors.
The app renders only the returned `qr_pass.payload` and never invents its own token.

The pass is accepted from 30 minutes before the start through the scheduled end. The
Security web workflow remains responsible for scanning and recorded exceptions.

## Location lifecycle and offline queue

1. Signup records versioned tracking consent. Each created visit receives its own
   auditable consent record without asking the visitor to accept the same notice again.
2. Security scans the approved QR and changes the appointment to `checked_in`.
3. The app calls `tracking.php` with `action: start`.
4. The app displays a persistent Android foreground-service notification while sharing.
5. The app captures no more often than the server-provided upload interval (15 seconds
   by default), assigns a stable `client_event_id`, and stores unsent points locally.
6. `locations.php` accepts up to 100 points per batch by default. Re-sending a batch is
   safe: duplicate event IDs are counted but not stored twice.
7. Positions outside the campus boundary are never stored; the server only notes that
   the visitor is outside. Three accurate readings outside over five minutes confirm a
   campus exit: the visit ends and the session closes with `ended_reason = campus_exit`
   (see `LIVE_MONITORING.md`).
8. Completion closes the server tracking session. Points captured before completion
   may arrive during the 24-hour offline grace period; a batch with only later captures
   gets 409, which tells the app to stop tracking.
9. Raw GPS points use a configurable 90-day default retention. The cleanup worker is a
   dry run unless explicitly invoked with `--apply`.

These values live in `mobile_api_settings`. Confirm the 90-day retention period with
the institution's privacy officer before a real pilot.

## Firebase Cloud Messaging

Future notifications inserted by the Office, Security, or maintenance workflow are
automatically queued for every active Android installation. Register/rotate the current
Firebase token through `devices.php` whenever `FirebaseMessagingService.onNewToken`
fires.

The worker uses the FCM HTTP v1 endpoint and a short-lived OAuth 2.0 access token:

`php phone_tracker/workers/send_push_notifications.php`

Configuration:

1. Copy `phone_tracker/config/mobile_api.example.php` to `mobile_api.php`.
2. Add the Firebase project ID.
3. Place the service-account file outside the public web root when deployed, then set
   its path through `FIREBASE_SERVICE_ACCOUNT_FILE` or the local config. A hosted server
   can instead put the whole JSON (or its base64) in `FIREBASE_SERVICE_ACCOUNT_JSON`; the
   project ID is then read from it.
4. Enable the Firebase Cloud Messaging API v1.
5. `workers/run_scheduled_jobs.php` runs the worker every minute together with the
   other background jobs (`DEPLOYMENT.md`).

The repository contains no Firebase credential. Without configuration, the worker exits
safely and queued in-app notifications continue to work.

## Account recovery email

Password-reset messages are placed in `outbound_emails`, and
`workers/send_outbound_emails.php` (`mail_service.php`) sends them through Brevo, Resend,
or SMTP, as configured by environment variables (`DEPLOYMENT.md`, step 4). Failed sends
are retried after 1, 2, 4, and 8 minutes; a code that expires first is not sent.
`php phone_tracker/workers/send_outbound_emails.php --test you@example.com` checks the
setup. Production endpoints never return reset tokens and always use a generic response
for unknown email addresses.

## Kotlin handoff for Phase 5

Recommended Android structure:

- Jetpack Compose presentation layer with screen/view-model state.
- Repository layer for authentication, offices, appointments, notifications, and
  tracking.
- Retrofit/OkHttp network layer with a bearer-token interceptor.
- Encrypted local token storage using Android Keystore-backed storage.
- Room queue keyed by `client_event_id` for offline GPS batches.
- Coroutines and Flow for API/database state.
- A visible foreground location service active only during a checked-in visit.
- Firebase Messaging service that rotates tokens through `devices.php`.

Do not embed the database password, Firebase service-account key, or any staff account
inside the Android application.

## Tests completed

- Repeatable fresh schema migration.
- Registration with required tracking consent and immediate sign-in.
- Password recovery and revocation of every older access token.
- Bearer sign-out and role isolation from staff web sessions.
- Exactly five official offices and generated availability slots.
- Appointment creation without QR disclosure, Office approval, and QR release.
- Immediate walk-in pass creation for an office accepting visitors.
- Visitor cancellation and Office-proposed schedule acceptance.
- Android token registration, notification queue creation, read state, and removal.
- Security check-in, tracking start, idempotent GPS batches, completion stop, offline
  pre-completion upload, and rejection of a post-completion capture.
- Retention worker dry-run behavior and safe failure when Firebase is unconfigured.

Real FCM delivery and password-recovery email delivery require project/provider credentials and therefore
remain deployment tasks rather than locally fabricated tests.
