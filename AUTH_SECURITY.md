# Sign-in and account security

How the website and the visitor app protect accounts. Setup steps for teammates are in
`TEAMMATE_SETUP.md`; this file explains the design.

Database changes are in `phone_tracker/auth_security_migration.sql` (safe to re-run).

## Sign-in flow (website)

`login.html` sends the username or email and password to `login.php`. When the password
is right the browser gets a short "pending" sign-in, not a session, and the server asks
for any remaining steps in this order:

1. **Two-step code** (`login_two_factor.php`) if the account has two-step verification,
   unless the browser was trusted for 30 days. A backup code also works.
2. **New password** (`login_change_password.php`) if the password is a temporary one or
   the published default.
3. **Two-step setup** (`login_two_factor_setup.php`) if the role requires it and it is
   not set up yet. Ends by showing 10 backup codes once.

Only then does the server create the session. The page plays a 4-second logo animation
while this happens ("The sign-in animation" in `login.js`). The four checks end at fixed
moments counted from the sign-in, so a slow server shortens the later ones and does not
lengthen the whole. A wrong password returns to the form after about 2 seconds instead.

## Lockout rules

All counting happens on the server in `auth_security.php` and covers the website and the
visitor app (`api/v1/auth/login.php`). Wrong two-step codes and failed "Confirm it's you"
checks count too.

| Rule | Trigger | Result |
|---|---|---|
| Account on one network | 5 failures for one account from one IP | Paused 10 min there; 20, 40, 80 min… (max 24 h) for repeats within 24 h |
| Account under attack | 15 failures for one account within 60 min from 3+ IPs | Account paused everywhere for 60 min |
| Password spraying | 20 failures from one IP across any accounts within 10 min | That IP paused for 10 min |

- A successful sign-in resets the first rule for that account and IP.
- Usernames that do not exist are counted and answered exactly like real ones, and
  take the same time (a bcrypt check runs either way), so attempts reveal nothing.
- Names typed for unknown accounts are stored masked (`gu****1`).
- Admins see lockouts, sign-in history, and security events on the **Security** page and
  can unlock from there. History is kept for 90 days.
- Behind a reverse proxy every request may come from the proxy's IP; configure the proxy
  and Apache to pass the real client IP before relying on the per-IP rules.

## Sessions

`session_bootstrap.php` runs on every protected request:

- Cookie `ISATU_VMS_SESSION`: HttpOnly, SameSite=Strict, Secure over HTTPS, new ID after
  every sign-in step, strict mode on.
- Stays signed in until the user signs out, for at most 12 hours after sign-in (one
  shift). Inactivity alone signs nobody out, and closing the browser does not either: the
  cookie lasts as long as the session. Both limits are settings in `config/auth.php`
  (`idle_timeout_minutes`, default 0 = off; `session_max_hours`, default 12).
- Sessions are stored in their own folder (`isatu_vms_sessions` inside PHP's session
  folder), so other PHP apps on the server, such as phpMyAdmin, cannot delete them early.
- The account is re-checked on every request. Suspending, deleting, resetting a password
  or two-step, or "Sign out everywhere" ends the user's open sessions immediately
  (`app_users.session_version`).
- Every POST/PUT/PATCH/DELETE needs the CSRF token in the `X-CSRF-Token` header. The
  token is in the `ISATU_VMS_CSRF` cookie; `auth.js` adds the header to every
  same-origin `fetch` automatically, so page code does not need to.
- `auth.js` sends the user back to the sign-in page with the reason when the server ends
  a session, and confirms the role with the server on every page load.

## Passwords

- At least 10 characters with a letter and a number, at most 64. Common passwords
  (including "word + numbers" like `Password2026!`), keyboard runs, and passwords that
  contain the username or name are refused. The checks are in `auth_password_problems()`
  and repeated live in the browser (`auth_ui.js`).
- Admins never set or see a lasting password. **Add user** and **Reset password** create
  a one-time temporary password (for example `Kq7m-Xr4p-Tz9w`) that expires in 24 hours.
- The seeded staff accounts must change `password` at their first sign-in.
- Changing a password signs the account out everywhere else and forgets trusted browsers.

## Two-step verification

- Authenticator app codes (TOTP, RFC 6238: 6 digits, 30 seconds, ±1 step). Each code
  works once.
- Required for roles in `two_factor_required_roles` (default: admin), optional for
  Security and Office Personnel under **Profile menu → Account security**.
- Secrets are encrypted with AES-256-GCM using `config/auth_secret.php`, which is
  generated automatically, ignored by Git, and must be backed up with the server.
- 10 one-time backup codes, stored as bcrypt hashes.
- "Don't ask again on this browser for 30 days" stores only a SHA-256 hash of a random
  cookie token. It can be revoked from Account security.

## "Confirm it's you"

Creating an admin, removing a user, resetting a password or two-step verification,
turning two-step on or off, and new backup codes all ask for the password or an
authenticator code if the user has not confirmed in the last 15 minutes
(`auth_step_up.php`).

## Permissions

Endpoints check a permission, not a role name (`permissions.php`).

| Permission | Admin | Security | Office | Visitor |
|---|:-:|:-:|:-:|:-:|
| `dashboard.admin`, `users.manage`, `departments.manage`, `activity.view`, `security.manage`, `analytics.view`, `campus_map.edit` | ✓ | | | |
| `campus_map.view`, `visits.scan`, `visits.complete`, `visits.qr_override`, `visits.monitor`, `locations.view_live`, `routes.view` | ✓ | ✓ | | |
| `appointments.list` | ✓ | | ✓ | |
| `office.dashboard`, `office.appointments`, `office.availability`, `office.notifications` | | | ✓ | |
| `profile.self` | ✓ | ✓ | ✓ | |
| `appointments.book`, `tracking.self` | | | | ✓ |

Office Personnel still only see their own office's records. To add a role, add it to
`AUTH_ROLE_PERMISSIONS`; new endpoints should call `require_permission_json("...")`.

## Admin tools

- **Security page:** counts, active lockouts with live countdowns, sign-in history
  (website and visitor app), and security events.
- **User Management → Manage:** last sign-in, failed attempts, password and two-step
  status, and Unlock / Reset password / Reset two-step / Sign out everywhere.
- The last active administrator cannot be suspended or removed.
- Command line recovery when no admin can sign in: `phone_tracker/tools/auth_recovery.php`
  (`unlock`, `reset-password`, `reset-2fa`). Every action is written to `audit_logs`.

## Other protections

- The four old endpoints that returned visitor locations without signing in, and the
  unused web sign-up endpoint, were removed.
- `phone_tracker/.htaccess` stops other sites from framing the pages (clickjacking) and
  turns off MIME sniffing.
- Use HTTPS on the deployed server; over plain HTTP anyone on the same Wi-Fi can read
  passwords in transit.
