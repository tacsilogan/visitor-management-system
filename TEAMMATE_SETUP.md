# Teammate setup and testing

Use the Git repository for normal sharing. Do not send an unfiltered copy of the
project folder: the working folder can contain a Firebase service-account private key,
machine-specific Android settings, database backups, and build output.

## Files that must never be committed or placed in a shared ZIP

- `phone_tracker/config/firebase-service-account.json`
- `phone_tracker/config/mobile_api.php`
- `phone_tracker/config/auth.php` and `phone_tracker/config/auth_secret.php`
- `visitor_app/local.properties`
- Android signing keys (`*.jks`, `*.keystore`, and `keystore.properties`)
- `.backups/`, `.gradle-user/`, `debug.log`, and generated `build/` directories

The repository ignore rules cover these paths. Before pushing, always run
`git status --short` and confirm none of them appears.

`visitor_app/app/google-services.json` is also intentionally ignored. It does not
contain the server private key, but the project owner should distribute it to trusted
Android developers through a private team channel or let an authorized teammate
download it from the Firebase project. Never substitute the server service-account
JSON for this Android client file.

## One-time setup for each teammate

1. Install Git, XAMPP, and Android Studio with Android SDK 37.
2. Clone the repository into `C:\xampp\htdocs\visitor-management-system`.
3. Start Apache and MySQL in XAMPP.
4. Create a MySQL database named `phone_tracker`.
5. For a new database, import the SQL files in this order:
   - `phone_tracker/phone_tracker.sql`
   - `phone_tracker/login_users.sql`
   - `phone_tracker/appointments.sql`
   - `phone_tracker/office_catalog_migration.sql`
   - `phone_tracker/app_users_profile_migration.sql`
   - `phone_tracker/mobile_api_migration.sql`
   - `phone_tracker/multi_stop_visits_migration.sql`
   - `phone_tracker/campus_map_migration.sql`
   - `phone_tracker/auth_security_migration.sql`
   - `phone_tracker/personnel_directory_migration.sql`
   - `phone_tracker/live_monitoring_migration.sql`
   - `phone_tracker/visitor_arrival_migration.sql`
   - `phone_tracker/campus_routes_migration.sql`
   - `phone_tracker/office_breaks_migration.sql`
   - `phone_tracker/indoor_directions_migration.sql`
   - `phone_tracker/teacher_visits_migration.sql`
   - `phone_tracker/school_accounts_migration.sql`
   - `phone_tracker/account_removal_migration.sql`
   - `phone_tracker/password_reset_code_migration.sql`

   An existing database only needs the files it is missing. Every migration can be run
   again safely. Do not import `app_users_office_code_migration.sql`,
   `appointments_status_migration.sql`, or `phase1_workflow_migration.sql` into a new
   database; they only upgrade databases created before those changes.

   Instead of importing by hand, you can run
   `C:\xampp\php\php.exe phone_tracker\tools\setup_database.php`, which imports whatever
   is missing in this order (and loads the ISATU campus map into an empty map). The online
   server runs the same tool on every start. **When you add a migration**, append it both
   to this list and to `SETUP_FILES` in `phone_tracker/tools/setup_database.php`.
6. Open `http://localhost/visitor-management-system/` and test the staff website. The
   first sign-in is different now; see **Sign-in security** below.
7. In Android Studio, open the `visitor_app` folder, not the repository root.
8. Allow Android Studio to create `visitor_app/local.properties` and complete Gradle
   sync.
9. Put the approved Android Firebase client file at
   `visitor_app/app/google-services.json`.

Only the computer that runs the PHP push worker needs the server service-account key
and `phone_tracker/config/mobile_api.php`. Ordinary Android testers do not need either
server secret.

**On the website, ask with the system's own dialog.** For a question before an action, or
an error message, use `AppDialog.confirm()` or `AppDialog.alert()` from
`phone_tracker/dialogs.js` instead of the browser's `confirm()` or `alert()`. They open in
the middle of the screen in the system's style, and the button names the action. A new page
that needs them loads `dialogs.js` right after `auth.js`.

## Sign-in security

The full design is in `AUTH_SECURITY.md`. What you will notice:

- The seeded `admin`, `security`, and `offices` accounts still start with the password
  `password`, but each must choose a new password at its first sign-in.
- Admin accounts must set up two-step verification at their first sign-in. Install
  Google Authenticator or Microsoft Authenticator on your phone, scan the QR code, and
  save the 10 backup codes it shows. Security and Office accounts can turn it on under
  **Profile menu → Account security**.
- New accounts get a one-time temporary password from **User Management → Add user**.
  It expires after 24 hours; the user picks their own password when they sign in.
- Five wrong attempts on one account from one device pause sign-in there for
  10 minutes (longer on repeats). An admin can unlock it on the **Security** page.
- You stay signed in until you sign out, for up to 12 hours (one shift). Inactivity and
  closing the browser no longer sign you out.

Development machines only: to skip the admin authenticator step locally, copy
`phone_tracker/config/auth.example.php` to `phone_tracker/config/auth.php` and set
`"two_factor_required_roles" => []`. Never do this on the deployed server, and never
commit `auth.php`.

If nobody can sign in as an administrator (for example a lost phone and lost backup
codes), run one of these on the computer that hosts XAMPP:

```powershell
C:\xampp\php\php.exe phone_tracker\tools\auth_recovery.php unlock admin
C:\xampp\php\php.exe phone_tracker\tools\auth_recovery.php reset-2fa admin
C:\xampp\php\php.exe phone_tracker\tools\auth_recovery.php reset-password admin
```

`phone_tracker/config/auth_secret.php` is created automatically the first time someone
sets up two-step verification. It encrypts the authenticator secrets: keep it private,
back it up with the server, and never commit it. If it is lost, authenticator codes
stop working (backup codes and the recovery commands above still work).

## Create one Office Personnel account per destination

Office access is intentionally isolated by destination. An IT account cannot see IS,
CS, Dean's Office, or Tech Support requests. Sign in as Admin, open **User Management**,
and create at least one active **Office Personnel** account for each office being
tested:

- IT Department (`IT`)
- IS Department (`IS`)
- CS Department (`CS`)
- Dean's Office (`DEANS`)
- Tech Support (`TECH_SUPPORT`)

These five are the starting departments. Administrators add, rename, archive, or delete
departments in **User Management → Department Directory**; new departments appear in the
visitor app, the booking page, and the campus map automatically. Every account needs the
real first and last name of the person using it; see `ACCOUNTABILITY.md`.

When testing an appointment, sign into the Office dashboard with the account assigned
to the same destination selected in the visitor app. Do not solve missing requests by
showing every office's records to one personnel account; that would bypass the intended
access boundary.

## Phone connection to a teammate's local XAMPP server

The phone and computer must be on the same trusted Wi-Fi network. On the computer,
run `ipconfig` and copy its active Wi-Fi IPv4 address. Add this line to
`visitor_app/local.properties`, replacing the example address:

```properties
ISATU_API_BASE_URL=http://192.168.1.25/visitor-management-system/phone_tracker/api/v1/
```

Allow Apache through Windows Firewall on private networks. The emulator can keep the
default `10.0.2.2` address. Local HTTP is enabled only in debug builds; production must
use HTTPS.

The visitor's campus map uses free OpenFreeMap tiles and needs no map key. To use another
MapLibre style, add `ISATU_MAP_STYLE_URL=...` to the same file. The phone needs internet for
the map images; directions still work without them (`VISITOR_NAVIGATION.md`).

## Use the Security scanner from a phone

The Security dashboard is a responsive web page, so guards do not need a separate
Android app. Connect the guard's phone to the same trusted Wi-Fi as the XAMPP
computer, then open this address in the phone browser (replace the example IP):

```text
http://192.168.1.25/visitor-management-system/phone_tracker/login.html
```

Sign in with an active Security account and open **Scan QR**. On a local `http://`
address, mobile browsers block the embedded live camera. Use **Take QR photo** and,
after pressing the shutter, tap the camera app's checkmark or **Use photo** to return
the image to the website. Do not tap the QR link suggested by the camera app. You can
also use **Choose QR screenshot** for a saved test image. The website decodes the
image on the phone and sends the pass to the same
`scan_appointment.php` endpoint used by the desktop scanner. The resulting check-in
is written to the central MySQL database and appears on the Security computer.
Scanning the same pass again when the visitor leaves offers a one-tap **Check out**.

Continuous live scanning is available on `localhost` and when the deployed site uses
trusted HTTPS. For phone testing, use a QR shown on a second device and allow Apache
through Windows Firewall on private networks. Do not expose the local XAMPP server
directly to the public internet.

## Build and install the test app

The simplest method is Android Studio's **Run app** button with a USB-debugging phone
or emulator selected. The command-line verification used by the project owner is:

```powershell
.\gradlew.bat testDebugUnitTest lintDebug assembleDebug
```

The debug APK is generated at
`visitor_app/app/build/outputs/apk/debug/app-debug.apk`. It is for testing only. The app
handed to visitors is the signed release build that talks to the online server over
HTTPS; `DEPLOYMENT.md` explains how to put the system online and build that app.

## Required acceptance workflow

1. Register a visitor, accept tracking consent, and confirm immediate sign-in.
2. Create a walk-in pass for an office accepting visitors and scan it in Security.
3. Separately request an available appointment with one of the five approved offices.
4. Approve it from the matching Office dashboard.
5. Confirm the phone receives a notification and displays the appointment QR pass.
6. Scan the appointment pass from Security during its valid window.
7. Grant Android's one-time precise-location permission during signup. Keep the visitor
   pass open while Security scans it and confirm sharing starts automatically after check-in.
8. Confirm Security sees the visitor's arrow on **Visitor Monitoring**, and that selecting
   them draws the path they walked. On the phone, confirm the campus map shows the office
   pin, the distance, and spoken directions, and says "You have arrived" only when standing
   on the pin (within about 3 m, outdoors); from farther away it says "very close" and offers
   **I'm here**. Confirm Security shows **Arrived** and the office's status reads **Arrived**
   (place the office's pin at its entrance in **Campus Map** first; see `VISITOR_NAVIGATION.md`).
9. Disconnect the phone briefly, reconnect it, and confirm queued points upload.
10. Scan the same pass again in Security and press **Check out**. Confirm tracking stops
    and later uploads are rejected.
11. On another visit, carry the phone outside the campus boundary. Confirm the visitor
    disappears from the map at once and the visit ends after 5 minutes outside, marked
    **Left campus**.

### Multi-stop visit

1. In **Book**, choose **Appointment**, turn on **Visiting more than one office?**, pick
   today, and add two offices at least 10 minutes apart (for example CS, then Dean's
   Office).
2. Approve only the first stop from its Office account. Confirm the visit pass appears in
   the app and lists only that stop.
3. Scan the visit pass from Security. Confirm one row and one map marker appear, and the
   app opens the campus map with the next stop.
4. Approve the second stop from its Office account while the visitor is checked in.
   Confirm it shows **Checked in** without another scan.
5. From the first office, press **Mark meeting done**. Confirm the app moves to the next
   stop and GPS keeps uploading.
6. Scan the visit pass again in Security and press **Check out**. Confirm both stops are
   completed and tracking stops.
7. Choose **Walk-in**, turn on **Visiting more than one office?**, and list two offices.
   Confirm the pass appears immediately and one scan checks in both offices.
8. On another visit, have one office press **Suggest another time** with a single option.
   Confirm the app offers **Accept suggested time** in one tap, and that **This time
   doesn't work** opens Book with the office, purpose, and subject filled in.

Record the phone model, Android version, tester, date, and any failed step. Do not use
real visitor personal data during development testing.
