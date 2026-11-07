<?php

/*
 * Teachers (TEACHER_VISITS.md). Each teacher is a calendar row in `offices` (kind "teacher", code
 * "T" + their user id) with their account's office_code pointing at it. So the rules for weekly
 * hours, bookings and approvals, and the "only your own" check on every staff endpoint, are the
 * ones offices already use. This file holds only what is different about a teacher.
 *
 * What a visitor may learn about a teacher is deliberately small: available now, or the next free
 * time. Never why. A teacher's room is only given to a visitor they accepted.
 */

require_once __DIR__ . "/appointment_offices.php";
require_once __DIR__ . "/office_availability_service.php";
require_once __DIR__ . "/visit_service.php";

const TEACHER_TITLES = ["Prof.", "Dr.", "Engr.", "Mr.", "Ms.", "Mrs."];
const TEACHER_VISIT_LENGTHS = [15, 30, 60];
/** How long a teacher has to answer a walk-in request before it closes. */
const TEACHER_WALK_IN_ANSWER_MINUTES = 10;
/** A walk-in can only wait this long for the teacher's next free slot. */
const TEACHER_WALK_IN_MAX_WAIT_MINUTES = 60;
/** A slot with less than this left is too short to walk over for. */
const TEACHER_WALK_IN_MIN_LEFT_MINUTES = 15;
/** How far ahead a next free time is looked for. */
const TEACHER_FREE_SLOT_SEARCH_DAYS = 21;
/** A booked request with no answer this close to its time alerts both sides, once. */
const TEACHER_ANSWER_REMINDER_MINUTES = 120;

/** Whether teacher_visits_migration.sql has run. */
function teacher_schema_ready(mysqli $conn): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    try {
        foreach ([
            "SELECT kind, parent_code, owner_user_id FROM offices LIMIT 0",
            "SELECT answer_by_at, answer_reminded_at, meeting_done_at FROM appointments LIMIT 0",
            "SELECT auto_accept, accept_walk_ins, email_alerts FROM office_availability_settings LIMIT 0",
            "SELECT name_title FROM app_users LIMIT 0",
        ] as $sql) {
            $result = $conn->query($sql);
            if ($result === false) {
                return $ready = false;
            }
            $result->free();
        }
    } catch (mysqli_sql_exception $error) {
        return $ready = false;
    }
    return $ready = true;
}

/** "Prof. Elena Reyes". */
function teacher_display_name(string $title, string $firstName, string $lastName): string
{
    return trim($title . " " . $firstName . " " . $lastName);
}

/** The active courses a teacher can belong to, code => "CCI IT Office". */
function teacher_course_map(): array
{
    $courses = [];
    $active = appointment_office_active_map();
    foreach ($active as $code => $name) {
        if ((appointment_office_row($code)["kind"] ?? "") === "course") {
            $courses[$code] = $name;
        }
    }
    return $courses;
}

/**
 * The name on a teacher's calendar. Names are unique in `offices`, so a second teacher with the same
 * name also shows their course.
 */
function teacher_unique_name(mysqli $conn, string $name, string $courseCode, string $exceptCode = ""): string
{
    $candidates = [$name, $name . " (" . $courseCode . ")"];
    for ($n = 2; $n <= 20; $n++) {
        $candidates[] = $name . " (" . $courseCode . " " . $n . ")";
    }
    $stmt = $conn->prepare("SELECT COUNT(*) FROM offices WHERE name = ? AND code <> ?");
    foreach ($candidates as $candidate) {
        $stmt->bind_param("ss", $candidate, $exceptCode);
        $stmt->execute();
        if ((int) $stmt->get_result()->fetch_row()[0] === 0) {
            $stmt->close();
            return $candidate;
        }
    }
    $stmt->close();
    throw new RuntimeException("Too many teachers share this name.");
}

/**
 * Creates the calendar for a new teacher account. The caller owns the transaction. Nothing is bookable
 * until the teacher saves their hours.
 *
 * @return string the calendar code, also written to the account's office_code
 */
function teacher_create_calendar(mysqli $conn, int $userId, string $title, string $firstName, string $lastName, string $courseCode, string $room, int $adminId): string
{
    $code = "T" . $userId;
    $name = teacher_unique_name($conn, teacher_display_name($title, $firstName, $lastName), $courseCode);
    $stmt = $conn->prepare(
        "INSERT INTO offices (code, kind, parent_code, owner_user_id, name, location, is_active, sort_order, created_by_user_id)
         VALUES (?, 'teacher', ?, ?, ?, ?, 1, 1000, ?)"
    );
    $stmt->bind_param("ssissi", $code, $courseCode, $userId, $name, $room, $adminId);
    $stmt->execute();
    $stmt->close();
    $settings = $conn->prepare(
        "INSERT IGNORE INTO office_availability_settings (office_code, slot_duration_minutes, maximum_visitors_per_slot)
         VALUES (?, 30, 1)"
    );
    $settings->bind_param("s", $code);
    $settings->execute();
    $settings->close();
    $link = $conn->prepare("UPDATE app_users SET office_code = ? WHERE id = ?");
    $link->bind_param("si", $code, $userId);
    $link->execute();
    $link->close();
    return $code;
}

/** Keeps the calendar in step when the admin edits a teacher. */
function teacher_update_calendar(mysqli $conn, string $code, string $title, string $firstName, string $lastName, string $courseCode, string $room): void
{
    $name = teacher_unique_name($conn, teacher_display_name($title, $firstName, $lastName), $courseCode, $code);
    $stmt = $conn->prepare("UPDATE offices SET name = ?, parent_code = ?, location = ? WHERE code = ? AND kind = 'teacher'");
    $stmt->bind_param("ssss", $name, $courseCode, $room, $code);
    $stmt->execute();
    $stmt->close();
}

/**
 * The account is being deleted. A calendar with visits stays, archived, so old records keep the
 * teacher's name; one with no visits is removed.
 */
function teacher_retire_calendar(mysqli $conn, string $code): void
{
    if ($code === "" || !appointment_is_teacher_code($code)) {
        return;
    }
    $count = $conn->prepare("SELECT COUNT(*) FROM appointments WHERE office_code = ?");
    $count->bind_param("s", $code);
    $count->execute();
    $hasVisits = (int) $count->get_result()->fetch_row()[0] > 0;
    $count->close();
    $statements = [
        "DELETE FROM office_availability_rules WHERE office_code = ?",
        "DELETE FROM office_availability_exceptions WHERE office_code = ?",
    ];
    if (office_breaks_ready($conn)) {
        $statements[] = "DELETE FROM office_availability_breaks WHERE office_code = ?";
    }
    if ($hasVisits) {
        $statements[] = "UPDATE offices SET is_active = 0 WHERE code = ?";
    } else {
        $statements[] = "DELETE FROM office_availability_settings WHERE office_code = ?";
        $statements[] = "DELETE FROM offices WHERE code = ?";
    }
    foreach ($statements as $sql) {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $code);
        $stmt->execute();
        $stmt->close();
    }
}

/**
 * The teacher's week, as the dashboard sends it, in the shape office_normalize_week() checks: the gaps
 * between a day's times become unnamed breaks, so the office rules store it unchanged.
 *
 * @param array $days [{day_of_week, windows: [{start, end}]}]
 * @return list<array> schedules for office_normalize_week()
 * @throws RuntimeException naming the day and what to fix
 */
function teacher_schedules_from_windows(array $days): array
{
    $schedules = [];
    foreach ($days as $dayInput) {
        if (!is_array($dayInput)) {
            continue;
        }
        $day = (int) ($dayInput["day_of_week"] ?? 0);
        if (!isset(OFFICE_DAY_NAMES[$day])) {
            throw new RuntimeException("The week has an unknown day");
        }
        $dayName = OFFICE_DAY_NAMES[$day];
        $windows = [];
        foreach ((array) ($dayInput["windows"] ?? []) as $window) {
            $start = office_time_minutes((string) ($window["start"] ?? ""));
            $end = office_time_minutes((string) ($window["end"] ?? ""));
            if ($start === null || $end === null || $start >= $end) {
                throw new RuntimeException("{$dayName}: each time needs an end after its start");
            }
            $windows[] = [$start, $end];
        }
        if (!$windows) {
            continue;
        }
        if (count($windows) > OFFICE_MAX_BREAKS_PER_DAY + 1) {
            throw new RuntimeException("{$dayName}: a day can have at most " . (OFFICE_MAX_BREAKS_PER_DAY + 1) . " times");
        }
        usort($windows, fn(array $a, array $b): int => $a[0] <=> $b[0]);
        $breaks = [];
        for ($index = 1; $index < count($windows); $index++) {
            if ($windows[$index][0] < $windows[$index - 1][1]) {
                throw new RuntimeException(sprintf(
                    "%s: %s and %s overlap",
                    $dayName,
                    office_minutes_clock($windows[$index - 1][0]) . " to " . office_minutes_clock($windows[$index - 1][1]),
                    office_minutes_clock($windows[$index][0]) . " to " . office_minutes_clock($windows[$index][1])
                ));
            }
            if ($windows[$index][0] > $windows[$index - 1][1]) {
                $breaks[] = [
                    "start_time" => office_minutes_input($windows[$index - 1][1]),
                    "end_time" => office_minutes_input($windows[$index][0]),
                    "label" => OFFICE_DEFAULT_BREAK_LABEL,
                ];
            }
        }
        $schedules[] = [
            "day_of_week" => $day,
            "enabled" => true,
            "start_time" => office_minutes_input($windows[0][0]),
            "end_time" => office_minutes_input($windows[count($windows) - 1][1]),
            "breaks" => $breaks,
        ];
    }
    return $schedules;
}

/**
 * A day's bookable slots, each starting on the slot boundary of its window, as
 * office_availability_check() requires.
 *
 * @param list<array{0:int,1:int}> $windows minutes after midnight
 * @return list<array{0:int,1:int}>
 */
function teacher_day_slots(array $windows, int $lengthMinutes): array
{
    $slots = [];
    foreach ($windows as [$start, $end]) {
        for ($cursor = $start; $cursor + $lengthMinutes <= $end; $cursor += $lengthMinutes) {
            $slots[] = [$cursor, $cursor + $lengthMinutes];
        }
    }
    return $slots;
}

/**
 * Which of today's slots a walk-in could use at [$nowMinute]: one with enough time left to walk over,
 * and that starts soon enough to wait for.
 *
 * @param list<array{0:int,1:int}> $slots
 * @return list<array{0:int,1:int}>
 */
function teacher_walk_in_candidates(array $slots, int $nowMinute): array
{
    return array_values(array_filter($slots, function (array $slot) use ($nowMinute): bool {
        [$start, $end] = $slot;
        $minimumLeft = min(TEACHER_WALK_IN_MIN_LEFT_MINUTES, intdiv($end - $start, 2));
        return $end - $nowMinute >= $minimumLeft && $start - $nowMinute <= TEACHER_WALK_IN_MAX_WAIT_MINUTES;
    }));
}

/**
 * The slot a walk-in would take if the teacher accepts, or null when they can't be seen soon.
 *
 * @return array{start:DateTimeImmutable, end:DateTimeImmutable}|null
 */
function teacher_walk_in_slot(mysqli $conn, string $code, DateTimeImmutable $now): ?array
{
    $state = office_open_state($conn, $code, $now);
    if ($state["state"] !== "open") {
        return null;
    }
    $settings = office_availability_get_settings($conn, $code);
    $length = max(15, (int) $settings["slot_duration_minutes"]);
    $windows = office_windows_by_day(office_load_rule_rows($conn, $code))[(int) $now->format("N")] ?? [];
    $minute = (int) $now->format("G") * 60 + (int) $now->format("i");
    $midnight = $now->setTime(0, 0);
    foreach (teacher_walk_in_candidates(teacher_day_slots($windows, $length), $minute) as [$startMinute, $endMinute]) {
        $start = $midnight->modify("+{$startMinute} minutes");
        $end = $midnight->modify("+{$endMinute} minutes");
        if (office_availability_check($conn, $code, $start, $end)["available"]) {
            return ["start" => $start, "end" => $end];
        }
    }
    return null;
}

/**
 * The teacher's next free times from [$from] on, soonest first.
 *
 * @return list<array{start:string,end:string}> "Y-m-d H:i:s"
 */
function teacher_free_slots(mysqli $conn, string $code, DateTimeImmutable $from, int $limit, int $minimumLeadMinutes = 0, int $searchDays = TEACHER_FREE_SLOT_SEARCH_DAYS): array
{
    $settings = office_availability_get_settings($conn, $code);
    if (!(int) $settings["accepting_visitors"]) {
        return [];
    }
    $length = max(15, (int) $settings["slot_duration_minutes"]);
    $windowsByDay = office_windows_by_day(office_load_rule_rows($conn, $code));
    if (!$windowsByDay) {
        return [];
    }
    $earliest = $from->modify("+{$minimumLeadMinutes} minutes");
    $found = [];
    for ($offset = 0; $offset <= $searchDays && count($found) < $limit; $offset++) {
        $midnight = $from->setTime(0, 0)->modify("+{$offset} days");
        foreach (teacher_day_slots($windowsByDay[(int) $midnight->format("N")] ?? [], $length) as [$startMinute, $endMinute]) {
            $start = $midnight->modify("+{$startMinute} minutes");
            if ($start <= $earliest) {
                continue;
            }
            $end = $midnight->modify("+{$endMinute} minutes");
            if (office_availability_check($conn, $code, $start, $end)["available"]) {
                $found[] = ["start" => $start->format("Y-m-d H:i:s"), "end" => $end->format("Y-m-d H:i:s")];
                if (count($found) >= $limit) {
                    break;
                }
            }
        }
    }
    return $found;
}

/**
 * What a visitor sees about a teacher. Reasons, class times and the room are left out on purpose.
 *
 * @param int $withSlots how many next free times to include
 */
function teacher_public(mysqli $conn, string $code, DateTimeImmutable $now, int $withSlots = 0): array
{
    $row = appointment_office_row($code) ?? [];
    $settings = office_availability_get_settings($conn, $code);
    $walkInSlot = teacher_walk_in_slot($conn, $code, $now);
    $acceptsWalkIns = (int) ($settings["accept_walk_ins"] ?? 1) === 1;
    $free = teacher_free_slots($conn, $code, $now, max(1, $withSlots));
    $chain = appointment_office_chain($code);
    $courseCode = $chain[1] ?? "";
    $departmentCode = $chain[count($chain) - 1] ?? "";
    $payload = [
        "code" => $code,
        "name" => appointment_office_label($code),
        "short_name" => teacher_short_label($code),
        "course" => $courseCode,
        "department" => $departmentCode,
        "department_name" => appointment_office_label($departmentCode),
        "available_now" => $walkInSlot !== null,
        "accepts_walk_ins" => $acceptsWalkIns,
        "can_walk_in" => $walkInSlot !== null && $acceptsWalkIns,
        "next_free_at" => $free[0]["start"] ?? null,
        "slot_duration_minutes" => max(15, (int) $settings["slot_duration_minutes"]),
    ];
    if ($withSlots > 0) {
        $payload["next_free_slots"] = array_map(fn(array $slot): array => [
            "scheduled_start_at" => $slot["start"],
            "scheduled_end_at" => $slot["end"],
        ], $free);
        $payload["fallback_office"] = appointment_fallback_office($code);
    }
    return $payload;
}

/** "Prof. Reyes", or the full name for a teacher with no title on file. */
function teacher_short_label(string $code): string
{
    $row = appointment_office_row($code);
    $short = trim((string) ($row["short_name"] ?? ""));
    // short_name is "title last"; with no title it is only the last name, so use the full name.
    if ($short === "" || strpos($short, " ") === false) {
        return appointment_office_label($code);
    }
    return $short;
}

/** The sentence a visitor sees when a teacher's time can't be booked, whatever the real reason is. */
function teacher_public_refusal(string $code): string
{
    return teacher_short_label($code) . " isn't free at that time. Pick another time.";
}

/** Where a teacher stands right now, for their own dashboard. */
function teacher_status_now(mysqli $conn, string $code, DateTimeImmutable $now): array
{
    $state = office_open_state($conn, $code, $now);
    $windowsByDay = office_windows_by_day(office_load_rule_rows($conn, $code));
    $today = $windowsByDay[(int) $now->format("N")] ?? [];
    $minute = (int) $now->format("G") * 60 + (int) $now->format("i");
    $laterToday = [];
    foreach ($today as [$start, $end]) {
        if ($start > $minute) {
            $laterToday[] = [office_minutes_input($start), office_minutes_input($end)];
        }
    }
    return [
        "state" => $state["state"],
        "until" => $state["until"],
        "pause_id" => $state["pause_id"],
        "later_today" => $laterToday,
        "has_hours" => $windowsByDay !== [],
    ];
}

/** The site's address, for links in emails. Behind Railway's proxy the scheme comes from a header. */
function teacher_site_base_url(): string
{
    $configured = trim((string) (getenv("PUBLIC_BASE_URL") ?: ""));
    if ($configured !== "") {
        return rtrim($configured, "/");
    }
    $host = (string) ($_SERVER["HTTP_HOST"] ?? "");
    if ($host === "") {
        // A scheduled job has no request to read the address from. Railway says what it is.
        $railway = trim((string) (getenv("RAILWAY_PUBLIC_DOMAIN") ?: ""));
        return $railway !== "" ? "https://" . $railway . "/phone_tracker" : "";
    }
    $scheme = function_exists("auth_request_is_https") && auth_request_is_https() ? "https" : "http";
    // From phone_tracker/api/v1/x.php or phone_tracker/x.php, up to phone_tracker.
    $path = (string) preg_replace('#/(api/v1/)?[^/]*$#', "", (string) ($_SERVER["SCRIPT_NAME"] ?? ""));
    return $scheme . "://" . $host . $path;
}

/**
 * Queues an email to the teacher about a visitor. No email is queued when the teacher turned email
 * alerts off, has no address on file, or the email tables are missing.
 *
 * @param array{visitor:string, when:string, purpose:string, subject:string} $visit
 */
function teacher_queue_alert_email(mysqli $conn, string $code, string $template, string $subject, array $visit): void
{
    if (!teacher_schema_ready($conn)) {
        return;
    }
    $stmt = $conn->prepare(
        "SELECT u.id, u.email, s.email_alerts
         FROM offices o
         INNER JOIN app_users u ON u.id = o.owner_user_id AND u.is_active = 1
         LEFT JOIN office_availability_settings s ON s.office_code = o.code
         WHERE o.code = ? AND o.kind = 'teacher' LIMIT 1"
    );
    $stmt->bind_param("s", $code);
    $stmt->execute();
    $owner = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $email = trim((string) ($owner["email"] ?? ""));
    if (!$owner || $email === "" || (int) ($owner["email_alerts"] ?? 1) !== 1) {
        return;
    }
    $userId = (int) $owner["id"];
    $payload = json_encode($visit + ["link" => teacher_site_base_url() . "/teacher.html"], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    try {
        $queue = $conn->prepare(
            "INSERT INTO outbound_emails (user_id, recipient_email, template_key, subject, payload_json)
             VALUES (?, ?, ?, ?, ?)"
        );
        $queue->bind_param("issss", $userId, $email, $template, $subject, $payload);
        $queue->execute();
        $queue->close();
    } catch (mysqli_sql_exception $error) {
        error_log("Teacher alert email was not queued: " . $error->getMessage());
    }
}

/**
 * The teacher can't keep a visit they accepted. The visitor is told and their pass stops working.
 * The caller owns the transaction, and has locked the row.
 *
 * @param array{id:int|string, office_code:string, visitor_user_id:int|string, status:string, scheduled_start_at:string} $appointment
 * @param string|null $historyNote the note in the visit's history, when someone other than the teacher cancelled it
 */
function teacher_cancel_visit(mysqli $conn, array $appointment, int $actorId, string $reason, ?string $historyNote = null): void
{
    $appointmentId = (int) $appointment["id"];
    $from = (string) $appointment["status"];
    if (!in_array($from, ["approved", "reschedule_proposed"], true)) {
        throw new RuntimeException("Only a visit that hasn't started can be cancelled");
    }
    $update = $conn->prepare(
        "UPDATE appointments SET status = 'cancelled', status_updated_at = NOW(), cancelled_at = NOW(), cancelled_by_user_id = ?
         WHERE id = ? AND status = ?"
    );
    $update->bind_param("iis", $actorId, $appointmentId, $from);
    $update->execute();
    $changed = $update->affected_rows === 1;
    $update->close();
    if (!$changed) {
        throw new RuntimeException("The visit changed before this could be saved");
    }
    visit_withdraw_open_proposals($conn, $appointmentId);
    $note = $historyNote ?? ("Cancelled by the teacher" . ($reason !== "" ? ": " . $reason : ""));
    $history = $conn->prepare(
        "INSERT INTO appointment_status_history (appointment_id, from_status, to_status, changed_by_user_id, note)
         VALUES (?, ?, 'cancelled', ?, ?)"
    );
    $history->bind_param("isis", $appointmentId, $from, $actorId, $note);
    $history->execute();
    $history->close();
    $teacherName = teacher_short_label((string) $appointment["office_code"]);
    $time = date("M j, g:i A", strtotime((string) $appointment["scheduled_start_at"]));
    $isStudent = (string) ($appointment["visitor_kind"] ?? "visitor") === "student";
    visit_notify_visitor(
        $conn,
        (int) $appointment["visitor_user_id"],
        $appointmentId,
        "appointment.cancelled",
        "{$teacherName} can't meet you",
        $isStudent
            ? "Your visit on {$time} was cancelled. You can book another time."
            : "Your visit on {$time} was cancelled, so your pass no longer works. You can book another time."
    );
    teacher_audit($conn, $actorId, $appointmentId, "teacher.visit_cancelled", ["from_status" => $from]);
}

/**
 * The teacher stops taking visits, because their account was turned off or removed: each open
 * request is refused and each upcoming visit cancelled, with a message to the visitor. $why finishes
 * "The teacher's account was …" in the visit history. The caller owns the transaction. Returns how
 * many visits it closed.
 */
function teacher_close_open_visits(mysqli $conn, string $code, int $actorId, string $why): int
{
    $teacherName = teacher_short_label($code);
    $note = "The teacher's account was {$why}";
    $open = $conn->prepare(
        "SELECT * FROM appointments
         WHERE office_code = ? AND status IN ('pending_approval', 'reschedule_proposed', 'approved') AND scheduled_end_at > NOW()
         FOR UPDATE"
    );
    $open->bind_param("s", $code);
    $open->execute();
    $appointments = $open->get_result()->fetch_all(MYSQLI_ASSOC);
    $open->close();
    foreach ($appointments as $appointment) {
        $appointmentId = (int) $appointment["id"];
        if ($appointment["status"] === "pending_approval") {
            $reason = $teacherName . " is no longer taking visits.";
            $reject = $conn->prepare(
                "UPDATE appointments SET status = 'rejected', status_updated_at = NOW(), rejection_reason = ?
                 WHERE id = ? AND status = 'pending_approval'"
            );
            $reject->bind_param("si", $reason, $appointmentId);
            $reject->execute();
            $reject->close();
            visit_history($conn, $appointmentId, "pending_approval", "rejected", $actorId, $note);
            visit_notify_visitor($conn, (int) $appointment["visitor_user_id"], $appointmentId, "appointment.rejected",
                "{$teacherName} can't see you", "{$teacherName} is no longer taking visits. You can book another teacher.");
        } else {
            teacher_cancel_visit($conn, $appointment, $actorId, "", "Cancelled because the teacher's account was {$why}");
        }
    }
    return count($appointments);
}

/**
 * The meeting is over. The visit itself goes on until the visitor leaves through the gate, as it
 * does for a stop of a multi-office visit. The caller owns the transaction.
 */
function teacher_finish_meeting(mysqli $conn, array $appointment, int $actorId): void
{
    $appointmentId = (int) $appointment["id"];
    if ((string) ($appointment["visitor_kind"] ?? "visitor") === "student") {
        teacher_finish_student_visit($conn, $appointment, $actorId);
        return;
    }
    if ((string) $appointment["status"] !== "checked_in") {
        throw new RuntimeException("Only a visitor who has passed the gate can be marked done");
    }
    $update = $conn->prepare("UPDATE appointments SET meeting_done_at = NOW() WHERE id = ? AND meeting_done_at IS NULL");
    $update->bind_param("i", $appointmentId);
    $update->execute();
    $changed = $update->affected_rows === 1;
    $update->close();
    if (!$changed) {
        return;
    }
    $note = "Meeting marked done by the teacher";
    $history = $conn->prepare(
        "INSERT INTO appointment_status_history (appointment_id, from_status, to_status, changed_by_user_id, note)
         VALUES (?, 'checked_in', 'checked_in', ?, ?)"
    );
    $history->bind_param("iis", $appointmentId, $actorId, $note);
    $history->execute();
    $history->close();
    visit_notify_visitor(
        $conn,
        (int) $appointment["visitor_user_id"],
        $appointmentId,
        "appointment.meeting_done",
        "Meeting done",
        teacher_short_label((string) $appointment["office_code"]) . " marked your meeting as done. Show your pass at the gate when you leave."
    );
    teacher_audit($conn, $actorId, $appointmentId, "teacher.meeting_done", []);
}

/**
 * A student's visit has no gate, so the teacher's "done" ends it. The caller owns the transaction.
 */
function teacher_finish_student_visit(mysqli $conn, array $appointment, int $actorId): void
{
    $appointmentId = (int) $appointment["id"];
    if ((string) $appointment["status"] !== "approved") {
        throw new RuntimeException("Only an accepted visit can be marked done");
    }
    $update = $conn->prepare(
        "UPDATE appointments
         SET status = 'completed', status_updated_at = NOW(), completed_at = NOW(), completed_by_user_id = ?,
             meeting_done_at = NOW()
         WHERE id = ? AND status = 'approved'"
    );
    $update->bind_param("ii", $actorId, $appointmentId);
    $update->execute();
    $changed = $update->affected_rows === 1;
    $update->close();
    if (!$changed) {
        throw new RuntimeException("The visit changed before this could be saved");
    }
    $note = "Visit marked done by the teacher";
    $history = $conn->prepare(
        "INSERT INTO appointment_status_history (appointment_id, from_status, to_status, changed_by_user_id, note)
         VALUES (?, 'approved', 'completed', ?, ?)"
    );
    $history->bind_param("iis", $appointmentId, $actorId, $note);
    $history->execute();
    $history->close();
    visit_notify_visitor(
        $conn,
        (int) $appointment["visitor_user_id"],
        $appointmentId,
        "appointment.completed",
        "Visit done",
        teacher_short_label((string) $appointment["office_code"]) . " marked your visit as done."
    );
    teacher_audit($conn, $actorId, $appointmentId, "teacher.student_visit_done", []);
}

function teacher_audit(mysqli $conn, int $actorId, ?int $appointmentId, string $action, array $details): void
{
    $audit = $conn->prepare(
        "INSERT INTO audit_logs (actor_user_id, appointment_id, action, entity_type, entity_id, details_json, ip_address)
         VALUES (?, ?, ?, 'appointment', ?, ?, ?)"
    );
    if (!$audit) {
        return;
    }
    $entityId = (string) ($appointmentId ?? "");
    $json = json_encode($details);
    $ip = substr((string) ($_SERVER["REMOTE_ADDR"] ?? ""), 0, 45);
    $audit->bind_param("iissss", $actorId, $appointmentId, $action, $entityId, $json, $ip);
    $audit->execute();
    $audit->close();
}

/**
 * Accepted visits that overlap [$from, $to], so the teacher can choose to cancel them when going away.
 *
 * @return list<array{id:int, visitor:string, starts_at:string, status:string}>
 */
function teacher_visits_between(mysqli $conn, string $code, DateTimeInterface $from, DateTimeInterface $to): array
{
    $fromSql = $from->format("Y-m-d H:i:s");
    $toSql = $to->format("Y-m-d H:i:s");
    $stmt = $conn->prepare(
        "SELECT id, visitor_full_name, scheduled_start_at, status FROM appointments
         WHERE office_code = ? AND status IN ('approved', 'reschedule_proposed')
           AND scheduled_start_at < ? AND scheduled_end_at > ?
         ORDER BY scheduled_start_at ASC LIMIT 50"
    );
    $stmt->bind_param("sss", $code, $toSql, $fromSql);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return array_map(fn(array $row): array => [
        "id" => (int) $row["id"],
        "visitor" => (string) $row["visitor_full_name"],
        "starts_at" => (string) $row["scheduled_start_at"],
        "status" => (string) $row["status"],
    ], $rows);
}
