# Students and teachers

ISATU students and teachers have accounts of their own. Outside visitors work as before, except that
every new app account now confirms its email with a code.

| Account | Made by | Email | Checked by |
|---|---|---|---|
| Student | The student, in the app | Only `@students.isatu.edu.ph` | A 6-digit code sent to that email |
| Teacher | The teacher, on the website (or the admin, with Add user) | Only `@isatu.edu.ph` | A code, then their department's Dean's Office |
| Visitor | The visitor, in the app | Any email except a student's | A code sent to that email |
| Office Personnel, Security, Admin | The admin, with Add user | Any | The admin |

The server decides the role, never the form: sign-up only ever makes a visitor or a student, and a
teacher only after the Dean's Office approves.

## Students

- **Booking:** a student books teachers only, any teacher in the school. Offices stay walk-in for
  them, so `offices.php` gives them an empty list and booking an office is refused.
- **No pass, gate scan or tracking:** a student's visit (`appointments.visitor_kind = 'student'`) has
  no QR pass, no location consent and no tracking session. `tracking.php`, `locations.php` and the
  multi-office `visits.php` refuse a student's token (`api_require_visitor()`), and the other app
  endpoints accept both (`api_require_app_user()`).
- **Arriving:** once the teacher accepts, the app shows the room and the written directions from
  `campus_map.php`. "I'm here" (`arrival.php`, method `visitor`) works from 30 minutes before the
  visit until it ends, without a gate scan. The teacher sees "Here now".
- **Ending:** the teacher's Mark as done completes a student's visit. A visitor's still ends at the
  gate. If the teacher forgets, `appointment_maintenance.php` completes it at its end time when the
  student said they were there, and closes it as missed when they didn't.
- **Limits:** one open visit per student per teacher.
- **Student ID:** as printed on the ISATU ID card, 4 digits, 4 digits and a letter (`2023-4363-I`).
  The server stores it with a capital letter and the dashes in place
  (`school_student_number_normalize()`); anything else is refused. The app types it into boxes.
- **What the teacher sees:** name, Student ID, course, year and section ("IT 3-B", from
  `student_profiles`), reason and note. No email or phone number.
- **Who else:** students never appear on the guard's lists or the live map, and are left out of the
  visitor numbers (`appointment_visitors_only_sql()`). Analytics counts student visits separately.

## Teachers

1. On the sign-in page, "Teacher? Request an account" opens `teacher_request.html`. The teacher gives
   their school email, name, department, course and their own password.
2. A code goes to the email. Entering it sends the request to the department's Dean's Office, and
   its Office Personnel get a notification.
3. The Dean's Office approves (it can correct the course first) or rejects with a reason. Either way
   the teacher gets an email. Approving makes the account and its calendar
   (`teacher_create_calendar()`), with the password the teacher chose.
4. The teacher signs in and sets their hours, as before.

A department is offered on the request page only while its Dean's Office has active Office
Personnel. The Dean's Office can also turn off the account of a teacher who left (their open
requests and visits are cancelled, and each person is told) and turn it back on. The admin's Add
user → Teacher still works, for exceptions. Removing a teacher's account (admin) cancels the same
visits and also removes their hours; see AUTH_SECURITY.md, "Removing an account".

## Departments

The admin's **Add department** makes a department like CCI and its Dean's Office together
(`<CODE>_DEANS`, marked `offices.approves_teachers = 1`). The button that used to be called Add
department makes an office on its own, like the Registrar's Office, so it is now **Add office**. The
department card says who approves its teachers and whether anyone can yet.

## Email codes

`school_accounts_service.php`. A code is 6 digits, works for 10 minutes and once, allows 5 wrong
tries, and can be sent again after 60 seconds, at most 5 times per sign-up. Only an HMAC of it is
stored (key: `config/auth_secret.php`); the email queue drops the code once it is sent. Sign-ups are
also limited per email and per network. A pending sign-up lives in `account_requests` and becomes an
account only when the code is entered, so nothing is left behind for an email nobody can open.

The code is sent at once when email is set up (DEPLOYMENT.md, step 4), or else waits in the queue.
Without email nobody can sign up, so set it up before inviting people. Brevo's free plan allows 300
emails a day, shared with password resets and teacher alerts.

## Where things are

| | |
|---|---|
| Migration | `school_accounts_migration.sql` |
| Rules | `school_accounts_service.php` |
| App sign-up | `api/v1/auth/register.php`, `confirm_signup.php`, `resend_code.php`, `signup_options.php` |
| Teacher request page | `teacher_request.html`, `teacher_request.js`, `teacher_request.php` |
| Dean's Office | `office_teacher_requests.php`, the Teachers tab in `offices.html` / `offices.js`, `office_teachers.php` |
| Admin | Add department in `admin_departments.php` / `admin.js`, the Students tab, `list_users.php` |
| App | `SignupScreens.kt`, `StudentVisitScreens.kt`, `BookingScreen.kt`, `TeacherScreens.kt`, `MainScreens.kt` |

Apps from before 0.8.0 can still sign in, but can't create accounts, because they don't know the code step.
Student sign-up needs app 0.9.0 or newer: older apps can't type the letter at the end of a Student ID,
which the server now requires.
