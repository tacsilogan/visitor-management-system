# Visits to a teacher

A visitor can visit one teacher, not only an office. Each teacher has their own sign-in and dashboard,
sets their own hours, and accepts or declines their own visitors, without going through office staff.
ISATU students book teachers too, and teachers ask for their own account, which their department's
Dean's Office approves: see [SCHOOL_ACCOUNTS.md](SCHOOL_ACCOUNTS.md).

## How departments are organized

```
CCI, College of Computing and Informatics     a department: holds the rest, can't be booked itself
├── CCI Dean's Office, CCI Tech Support       offices: visitors can book them
└── IT, CS, IS                                courses: group the teachers
    └── Prof. Elena Reyes ...                 teachers: visitors can book each one
```

- A course with Office Personnel also appears to visitors as an office (CCI IT Office). Without them it
  only groups teachers.
- An office with no one assigned is hidden from visitors, because nobody could answer them. To list every
  active office again, set `APPOINTMENT_HIDE_UNSTAFFED_OFFICES` to false in `appointment_offices.php`.
- All of these are rows in `offices`, told apart by `kind` and joined by `parent_code`. Old appointments
  keep working because no code changed.

## How a teacher works in the system

A teacher is a calendar row of their own in `offices` (`kind = 'teacher'`, code `T` + their user id), and
their account's `office_code` points at it. So weekly hours, bookings, approvals and "only your own" are
the office rules. `teacher_service.php` holds only what is different:

| Rule | Why |
|---|---|
| No saved hours means not listed and not bookable | A new office is bookable 8 to 5 before anyone signs in. A teacher must not be. |
| A walk-in is a request, with no pass until they accept | Your decision: a walk-in depends on the teacher. |
| A walk-in closes after 10 minutes with no answer | Nobody waits at the gate for a teacher who is in class. |
| A walk-in holds the teacher's next free slot | The teacher sees which time it would take. |
| Visitors see available or the next free time, never why | "In Room 204" or "on leave" would tell strangers where a teacher is. |
| A teacher's room only comes with an accepted pass | The same reason. |
| A teacher sees no visitor phone, email or map position | The app carries messages, and Office Personnel already can't see the map. |
| A booked request with no answer 2 hours before alerts both sides | Today a request can wait until its time has passed. |
| "Mark as done" keeps the visit open until the gate | The visitor is still on campus. The app then guides them out. |
| Not in multi-office visits yet | One stop waiting for a teacher while another has a pass needs its own design. |

Map pins, routes and directions come from the course, or else the department (`appointment_office_chain()`),
so the admin writes no directions per teacher. The teacher's room becomes the last step.

## Where things are

| | |
|---|---|
| Migration | `teacher_visits_migration.sql` |
| Rules | `teacher_service.php`, `appointment_offices.php` |
| Dashboard | `teacher.html`, `teacher.js`, `teacher.css`, `teacher_dashboard.php`, `teacher_hours.php` |
| Answers | `office_appointment_action.php`, `office_propose_reschedule.php` (also used by offices) |
| Visitor API | `api/v1/teachers.php`, `api/v1/_appointments.php`, `api/v1/availability.php`, `api/v1/campus_map.php` |
| Admin | Add user and Department Directory in `admin.html`, `admin.js`, `admin_departments.php`, `add_user.php` |
| Office Personnel | The Teachers tab, `office_teachers.php` |
| Email alerts | `teacher_queue_alert_email()`, templates in `mail_service.php` |
| Phone alerts | `web_push_service.php`, `teacher_push.php`, `teacher-sw.js`, `workers/send_web_push.php` |
| App | `TeacherVisits.kt`, `TeacherScreens.kt`, `BookingScreen.kt`, `AppointmentDetailScreen.kt` |

Permissions: a teacher has no `appointments.list`, because `list_appointments.php` only limits Office
Personnel to their own office. Every other office endpoint limits everyone to the session's `office_code`.

## Alerts

- **On the page:** the dashboard checks every 15 seconds, and plays a short sound for a new walk-in.
- **Email:** each new request, walk-in and reminder, through the existing email queue. It goes out within a
  minute or so, when email is set up on the server (DEPLOYMENT.md). A teacher can turn it off in Profile.
- **Phone:** a teacher turns it on in Profile. It needs an https address, so it works on Railway but not at
  a local `http://192.168...` address. The push carries no message: it wakes `teacher-sw.js`, which asks this
  server what's new with the teacher's own session, so no visitor's name passes through a browser maker's
  servers. The signing keys are made once and kept in `mobile_api_settings`. On an iPhone it only works after
  adding the page to the Home Screen.

## Trying it

`php phone_tracker/tools/teacher_visits_test.php` checks the rules without a database.
