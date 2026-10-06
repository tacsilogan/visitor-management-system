# Deploying the ISATU Visitor Management System

This guide puts the staff website and the visitor app's server online at an HTTPS address
on [Railway](https://railway.com) (Hobby plan), then builds the Android app that talks to
it. Most of the work is automatic: on every start the server creates or updates the
database, secures the seeded accounts, starts the background jobs, and prints a readiness
report. Plan about an hour for the first deployment.

## What runs where

| Part | What it is |
|---|---|
| **Web service** | This repository's `Dockerfile`: Apache and PHP 8.2 serving `phone_tracker`, plus the background jobs (emails, push notifications, visit timing, location-history cleanup). |
| **MariaDB service** | The database. It must be MariaDB, not MySQL: the database files use MariaDB syntax that MySQL rejects. |
| **Two volumes** | `/var/lib/mysql` on MariaDB (the data) and `/data` on the web service (sign-in sessions and staff photos). Without them, every deploy loses that data. |
| **Android app** | Built on your laptop with the HTTPS address inside, then shared as an APK. |

HTTPS comes from Railway, which also makes the iPhone camera and the browser location
work on the Security pages (they are blocked on plain `http://` addresses).

## Before you start

- The code you want online is pushed to GitHub. Railway deploys from there.
- A Railway account on the Hobby plan.
- A free [Brevo](https://www.brevo.com) account for email. Railway's Hobby plan blocks
  ordinary SMTP email, and Brevo sends over HTTPS instead.
- Your laptop with XAMPP and the Android build tools (see `TEAMMATE_SETUP.md`).

To make a random secret on the laptop (you need several below), run in PowerShell:

```powershell
C:\xampp\php\php.exe -r "echo bin2hex(random_bytes(32));"
```

## 1. The database

1. In Railway, create a **New Project → Empty Project**.
2. **Create → Docker Image**, image `mariadb:11.4`. Open the new service's **Settings** and
   rename it to `MariaDB` exactly; the web service refers to it by that name.
3. In its **Variables**, add:

   | Variable | Value |
   |---|---|
   | `MARIADB_ROOT_PASSWORD` | a random secret |
   | `MARIADB_DATABASE` | `phone_tracker` |
   | `MARIADB_USER` | `isatu` |
   | `MARIADB_PASSWORD` | another random secret |

4. In its **Settings**, set the **Region** to Singapore, then attach a **volume** with mount
   path `/var/lib/mysql` (right-click the service on the project canvas, or use its
   settings). The volume is created in the service's region.
5. Deploy it. Its log ends with `ready for connections`.

Leave the database without a public address. The web service reaches it over Railway's
private network.

## 2. The web service

1. **Create → GitHub Repo**, pick this repository and the branch to put online. Railway
   finds the `Dockerfile` by itself.
2. In its **Variables**, add (type them exactly; `${{ }}` copies the database's values):

   | Variable | Value |
   |---|---|
   | `ISATU_DB_HOST` | `${{MariaDB.RAILWAY_PRIVATE_DOMAIN}}` |
   | `ISATU_DB_PORT` | `3306` |
   | `ISATU_DB_NAME` | `${{MariaDB.MARIADB_DATABASE}}` |
   | `ISATU_DB_USER` | `${{MariaDB.MARIADB_USER}}` |
   | `ISATU_DB_PASSWORD` | `${{MariaDB.MARIADB_PASSWORD}}` |
   | `ISATU_AUTH_SECRET` | a random secret (64 characters from the command above) |
   | `PORT` | `8080` |

   `ISATU_AUTH_SECRET` encrypts everyone's two-step verification setup. Store a copy
   somewhere safe and never change it: a new value breaks every authenticator app (backup
   codes still work).
3. In its **Settings**, set:

   | Setting | Value |
   |---|---|
   | Region | Singapore. Set it before adding the volume, which is created in the same region. |
   | Healthcheck Path | `/phone_tracker/api/v1/`. Railway refuses paths with a dot, such as `health.php`. |
   | Restart Policy | On Failure, 10 retries |
   | Watch Paths | `index.php`, `phone_tracker/**`, `Dockerfile`, `docker/**`, so changes to the Android app do not redeploy the website |
   | Serverless | Off |

4. Attach a **volume** with mount path `/data`.
5. **Settings → Networking → Generate Domain**, target port `8080`. You get an address
   like `https://isatu-visitors.up.railway.app`.
6. Deploy. The first build takes a few minutes.

## 3. First start and first sign-in

Open the web service's **Deploy Logs**. On the first start you will see the tables being
created, the ISATU campus map being loaded, and then this box:

```
====================================================================
 FIRST SIGN-IN: these accounts no longer accept the password "password".
 Temporary passwords (shown only this once, valid until ...):
   admin        Kp7m-Qx3t-Zr9w
   security     ...
   offices      ...
 Each account chooses its own password at its first sign-in.
====================================================================
```

Write these down now. They are printed only once and expire after 7 days. On a public
server anyone could otherwise sign in with the well-known password `password` before you
do. (To choose the admin's temporary password yourself, add the variable
`ISATU_ADMIN_PASSWORD`, at least 10 characters, before the first deploy.) The demo
`visitor` account is turned off; visitors register in the app.

Below the box is the **readiness report**: `[OK]`, `[WARN]` (works, but needs
attention), and `[FAIL]` (fix before real use). Expect warnings about email and office
accounts until steps 4 and 5 are done.

Then open your address, sign in as `admin` with the temporary password, choose your own
password, and set up two-step verification with Google or Microsoft Authenticator. Keep
the backup codes it shows.

## 4. Email for password-reset codes

Visitors who forget their password receive a recovery code by email.

1. In Brevo, open **Senders, Domains & Dedicated IPs → Senders**, add the address emails
   should come from (for example the office's Gmail), and confirm it from that inbox.
2. Open **SMTP & API → API Keys** and generate a key.
3. Add to the web service's variables:

   | Variable | Value |
   |---|---|
   | `BREVO_API_KEY` | the key |
   | `MAIL_FROM_ADDRESS` | the confirmed sender address |
   | `MAIL_FROM_NAME` | optional, defaults to `ISATU Visitor Management` |

4. After the redeploy, send yourself a test with the
   [Railway CLI](https://docs.railway.com/cli/ssh) (it asks you to register an SSH key
   the first time). Use your web service's name in place of `web`:

   ```
   railway ssh -s web -- php /var/www/html/phone_tracker/workers/send_outbound_emails.php --test you@example.com
   ```

Brevo's free plan sends 300 emails a day. Mail from a Gmail sender can land in spam; tell
visitors to check there. Other options: `RESEND_API_KEY` for [Resend](https://resend.com)
if you own a domain, or `SMTP_HOST`, `SMTP_PORT`, `SMTP_USERNAME`, `SMTP_PASSWORD`, and
`SMTP_ENCRYPTION` on a host that allows SMTP (Railway Pro, a school server).

## 5. Staff accounts and the campus map

- **Staff accounts.** In **User Management → Add user**, create an account for every
  guard (Security) and each department's Office Personnel, so the activity log shows real
  names. A department without an active Office Personnel account never sees its
  appointment requests or visitor arrivals; the readiness report lists such departments.
  Suspend the seeded `security` and `offices` accounts once people have their own.
- **Campus map.** The ISATU boundary and the CS Department, Dean's Office, IT Department,
  and IS Department pins are already loaded. In **Campus Map**, add the Tech Support pin
  and the gates.

## 6. The Android app for visitors

Release APKs are built on **one build laptop**: the laptop of the team member who builds
the releases. It holds the signing folder `isatu-visitor-signing` in that person's user
folder, with the key (`isatu-visitor-release.jks`) and its passwords (`keystore.properties`).
Neither is in Git. Build every release there: phones only install an update signed with the
same key.

1. Add the hosted address to `visitor_app/local.properties`:

   ```
   ISATU_RELEASE_API_BASE_URL=https://isatu-visitor.up.railway.app/phone_tracker/api/v1/
   ```

   Keep any `ISATU_API_BASE_URL` line: test builds keep using a laptop on the same Wi-Fi.
2. Copy `keystore.properties` from the signing folder to `visitor_app/keystore.properties`.
   Its `storeFile` line holds the full path of the key file, with forward slashes.
3. Build it in PowerShell, with `JAVA_HOME` set to JDK 17 or newer (Android Studio's own
   `jbr` folder works):

   ```powershell
   cd <project folder>\visitor_app
   $env:JAVA_HOME = "C:\Program Files\Android\Android Studio\jbr"
   .\gradlew.bat testDebugUnitTest assembleRelease --no-daemon
   ```

   The build stops with an explanation if the address is not `https://` or the signing
   key is missing.
4. Share `visitor_app\app\build\outputs\apk\release\app-release.apk` (Google Drive works).
5. A phone that has an app signed with another key (a test version, or a build from another
   laptop) must uninstall it first.
6. For every new version, raise `versionCode` and `versionName` in
   `visitor_app/app/build.gradle.kts`.

**Back up the signing folder** (`isatu-visitor-signing`, which holds the key and a copy of
its passwords) somewhere private, such as a private Google Drive folder and a USB stick.
If it is lost, phones refuse updates and every visitor must uninstall and reinstall the
app.

## 7. Push notifications (optional)

The app works without them; visitors see updates when they open it. To turn them on:

1. In the [Firebase console](https://console.firebase.google.com), add an Android app
   with package `ph.edu.isatu.visitor`, download `google-services.json` into
   `visitor_app/app/`, and rebuild the app.
2. Create a service-account key (**Project settings → Service accounts → Generate new
   private key**) and paste the whole JSON file's contents into the web service variable
   `FIREBASE_SERVICE_ACCOUNT_JSON`.

## Updating the system later

- Push to the deployed branch. Railway rebuilds and redeploys by itself, only when server
  files change. Zero setup is needed on the server.
- **Adding a database migration:** append the file name to `SETUP_FILES` in
  `phone_tracker/tools/setup_database.php` (and to the list in `TEAMMATE_SETUP.md`). The
  server applies it on its next start. Write migrations so they can run twice
  (`CREATE TABLE IF NOT EXISTS`, `ADD COLUMN IF NOT EXISTS`).
- The GitHub check **Server image** (`.github/workflows/server-image.yml`) builds the
  image, starts it next to MariaDB, and tests sign-in, privacy rules, and uploads on every
  push that touches the server. On a fork, enable it once under the **Actions** tab. Deploy
  only when it is green.

## Day-to-day operations

| Task | How |
|---|---|
| See what the server is doing | Web service → **Deploy Logs**. Background jobs log only when they send something or fail. |
| Run the readiness check | `railway ssh -s web -- php /var/www/html/phone_tracker/tools/preflight.php` |
| Admin locked out or lost their phone | `railway ssh -s web -- php /var/www/html/phone_tracker/tools/auth_recovery.php reset-password admin` (also `unlock` and `reset-2fa`) |
| Database status | `railway ssh -s web -- php /var/www/html/phone_tracker/tools/setup_database.php --status` |
| Back up the database | Use Railway's volume backups if your plan has them. Otherwise, once a month, enable the MariaDB service's TCP proxy and run `C:\xampp\mysql\bin\mysqldump.exe -h <proxy host> -P <proxy port> -u root -p phone_tracker > backup.sql`, then turn the proxy off. |

## Bringing your local data instead of starting empty (optional)

1. If your local campus map is a test map, restore the real one first, or redraw it on the
   server afterwards.
2. Export: `C:\xampp\mysql\bin\mysqldump.exe -u root --routines phone_tracker > export.sql`
3. Before the web service's first deploy, enable the MariaDB service's **TCP proxy** and
   import as `root` (the dump's trigger needs it):
   `C:\xampp\mysql\bin\mysql.exe -h <proxy host> -P <proxy port> -u root -p phone_tracker < export.sql`
4. Set `ISATU_AUTH_SECRET` to the value inside `phone_tracker/config/auth_secret.php`
   (between the quotes), so existing authenticator setups keep working.
5. Turn the TCP proxy off and deploy the web service. It records the existing tables and
   applies any missing changes. Staff upload their profile photos again.

## Settings reference

| Variable | Needed | Meaning |
|---|---|---|
| `ISATU_DB_HOST`, `ISATU_DB_PORT`, `ISATU_DB_NAME`, `ISATU_DB_USER`, `ISATU_DB_PASSWORD` | yes | The database. `ISATU_DATABASE_URL` (`mysql://user:password@host:port/name`) works instead. |
| `ISATU_AUTH_SECRET` | strongly recommended | 64 hexadecimal characters that encrypt two-step verification. Without it, the key is kept on the `/data` volume. |
| `PORT` | yes on Railway | The port Apache listens on (the image defaults to 8080). |
| `BREVO_API_KEY`, `MAIL_FROM_ADDRESS` | for email | See step 4. `MAIL_FROM_NAME`, `MAIL_REPLY_TO`, and `MAIL_TRANSPORT` (`brevo`, `resend`, `smtp`) are optional. |
| `ISATU_ADMIN_PASSWORD` | no | The admin's temporary password on the very first start. |
| `FIREBASE_SERVICE_ACCOUNT_JSON` | no | Push notifications (step 7). |
| `ISATU_TRUST_PROXY` | set by the image | `1`: read the visitor's address and HTTPS from Railway's proxy. Use `0` on a server without a proxy in front. |
| `VISITOR_APP_ENV` | set by the image | `production`. Never `development` online: it shows reset codes in API replies. |

## After deploying: acceptance test

Do this once with two phones before telling visitors about the app:

1. Your address opens the sign-in page with a padlock in the address bar.
2. Admin sign-in, new password, and two-step verification work.
3. A new Security account signs in on a phone, including an iPhone, and the QR scanner
   opens the camera.
4. On an Android phone, the release app installs, registers a visitor, and requests an
   appointment; the department approves it.
5. Security scans the pass: the visitor's **Campus visit** map opens and Security sees the
   live trail.
6. At the office pin the visitor is told they arrived and the office sees **Arrived**.
7. A second scan checks the visitor out.
8. **Forgot password?** in the app sends an email whose code resets the password.
9. The readiness report shows no `[FAIL]`.

## Running on a school server instead

The same code runs on any Apache with PHP 8.1 or newer and MariaDB 10.4 or newer:

- Apache needs `AllowOverride All` for the site folder and `mod_headers`. The `.htaccess`
  files hold the security headers and keep configuration and tools private.
- The site needs an HTTPS certificate. Phones block the camera and location on `http://`.
- Create the tables with `php phone_tracker/tools/setup_database.php --secure-default-accounts`.
- Set the variables above as system environment variables, so both Apache and the
  scheduled task see them, and restart Apache. Leave `ISATU_TRUST_PROXY` unset unless a
  reverse proxy sits in front.
- Run the background jobs every minute. On Windows, create a Task Scheduler task with
  program `C:\xampp\php\php.exe` and argument
  `C:\xampp\htdocs\visitor-management-system\phone_tracker\workers\run_scheduled_jobs.php --once`.
  On Linux, add a cron line: `* * * * * php /path/to/phone_tracker/workers/run_scheduled_jobs.php --once`.
