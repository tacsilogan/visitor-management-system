<?php
/*
 * Everything the Teacher dashboard shows, for the signed-in teacher only. Visitor phone numbers,
 * email addresses and locations are not read here at all.
 */
header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store, max-age=0");

require_once __DIR__ . "/session_bootstrap.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/teacher_service.php";
require_once __DIR__ . "/appointment_maintenance.php";
require_once __DIR__ . "/school_accounts_service.php";

require_permission_json("teacher.dashboard");
if (!teacher_schema_ready($conn)) {
    echo json_encode(["success" => false, "message" => "Database update required: import phone_tracker/teacher_visits_migration.sql into phone_tracker."]);
    exit;
}
refresh_appointment_time_states($conn);

$code = strtoupper(trim((string) ($_SESSION["office_code"] ?? "")));
$userId = (int) $_SESSION["user_id"];
$calendar = appointment_office_row($code);
if ($calendar === null || $calendar["kind"] !== "teacher") {
    echo json_encode(["success" => false, "message" => "This account has no teacher calendar. Ask the administrator to check it."]);
    exit;
}

$now = new DateTimeImmutable("now");
$today = $now->format("Y-m-d");
$settings = office_availability_get_settings($conn, $code);

$stmt = $conn->prepare("SELECT username, email, position, profile_image, first_name, last_name, name_title FROM app_users WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $userId);
$stmt->execute();
$account = $stmt->get_result()->fetch_assoc() ?: [];
$stmt->close();

/** What the dashboard calls a visit's state. */
function teacher_visit_state(array $row, string $today): string
{
    if (!empty($row["meeting_done_at"])) {
        return "done";
    }
    switch ((string) $row["status"]) {
        case "pending_approval":
            return "pending";
        case "reschedule_proposed":
            return "proposed";
        case "approved":
            // A student passes no gate: "I'm here" in the app is their arrival.
            if (($row["visitor_kind"] ?? "visitor") === "student" && !empty($row["arrived_at"])) {
                return "here";
            }
            return substr((string) $row["scheduled_start_at"], 0, 10) === $today ? "expected" : "accepted";
        case "checked_in":
            return !empty($row["arrived_at"]) ? "arrived" : "campus";
        case "completed":
            return "done";
        case "rejected":
            return "declined";
        case "cancelled":
            return "cancelled";
        case "window_closed":
            return "missed";
        case "unanswered":
            return "noanswer";
    }
    return "accepted";
}

$walkIns = [];
$requests = [];
$todayVisits = [];
$upcoming = [];
$past = [];
$result = $conn->prepare(
    "SELECT id, registration_code, visitor_full_name, visit_type, purpose, subject, additional_details,
            scheduled_start_at, scheduled_end_at, status, answer_by_at, arrived_at, checked_in_at,
            meeting_done_at, rejection_reason, status_updated_at, created_at, visitor_user_id"
    . (school_schema_ready($conn) ? ", visitor_kind" : ", 'visitor' AS visitor_kind") . "
     FROM appointments WHERE office_code = ?
     ORDER BY scheduled_start_at DESC, id DESC LIMIT 300"
);
$result->bind_param("s", $code);
$result->execute();
$visitRows = $result->get_result()->fetch_all(MYSQLI_ASSOC);
// A student's course, year and section, and student number: what a teacher needs to know who came.
$studentProfiles = school_student_profiles($conn, array_map(
    fn(array $row): int => (int) $row["visitor_user_id"],
    array_filter($visitRows, fn(array $row): bool => $row["visitor_kind"] === "student")
));
foreach ($visitRows as $row) {
    $state = teacher_visit_state($row, $today);
    $profile = $row["visitor_kind"] === "student" ? ($studentProfiles[(int) $row["visitor_user_id"]] ?? null) : null;
    $visit = [
        "id" => (int) $row["id"],
        "code" => (string) $row["registration_code"],
        "visitor" => (string) $row["visitor_full_name"],
        "visit_type" => (string) $row["visit_type"],
        "purpose" => (string) $row["purpose"],
        "subject" => (string) $row["subject"],
        "details" => (string) ($row["additional_details"] ?? ""),
        "start" => (string) $row["scheduled_start_at"],
        "end" => (string) $row["scheduled_end_at"],
        "state" => $state,
        "checked_in_at" => $row["checked_in_at"],
        "arrived_at" => $row["arrived_at"],
        "reason" => (string) $row["rejection_reason"],
        "created_at" => (string) $row["created_at"],
        "is_student" => $row["visitor_kind"] === "student",
        "student" => $profile ? [
            "label" => $profile["label"],
            "student_number" => $profile["student_number"],
        ] : null,
    ];
    $date = substr($visit["start"], 0, 10);
    $over = in_array($state, ["done", "declined", "cancelled", "missed", "noanswer"], true);
    if ($state === "pending" && $row["answer_by_at"] !== null) {
        $visit["seconds_left"] = max(0, strtotime((string) $row["answer_by_at"]) - $now->getTimestamp());
        $walkIns[] = $visit;
    } elseif ($state === "pending") {
        $requests[] = $visit;
    } elseif ($over) {
        $past[] = $visit;
    } elseif ($date === $today) {
        $todayVisits[] = $visit;
    } elseif ($date > $today) {
        $upcoming[] = $visit;
    } else {
        // Still on campus from an earlier day, or waiting for the time to pass.
        $todayVisits[] = $visit;
    }
}
$result->close();
$byStart = fn(array $a, array $b): int => strcmp($a["start"], $b["start"]);
usort($walkIns, $byStart);
usort($requests, $byStart);
usort($todayVisits, $byStart);
usort($upcoming, $byStart);
$past = array_slice($past, 0, 60);

$windowsByDay = office_windows_by_day(office_load_rule_rows($conn, $code));
$hours = [];
foreach (OFFICE_DAY_NAMES as $day => $name) {
    $hours[] = [
        "day_of_week" => $day,
        "name" => $name,
        "windows" => array_map(fn(array $window): array => [
            "start" => office_minutes_input($window[0]),
            "end" => office_minutes_input($window[1]),
        ], $windowsByDay[$day] ?? []),
    ];
}

// Time off: whole days set aside ahead of time. A pause from "I'm not available" is the status above.
$timeOff = [];
$off = $conn->prepare(
    "SELECT id, starts_at, ends_at, reason FROM office_availability_exceptions
     WHERE office_code = ? AND is_available = 0 AND ends_at > NOW() AND reason = 'Time off'
     ORDER BY starts_at ASC LIMIT 30"
);
$off->bind_param("s", $code);
$off->execute();
foreach ($off->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
    $timeOff[] = ["id" => (int) $row["id"], "starts_at" => (string) $row["starts_at"], "ends_at" => (string) $row["ends_at"]];
}
$off->close();

$notifications = [];
$unread = 0;
$bell = $conn->prepare(
    "SELECT id, appointment_id, notification_type, title, message, read_at, created_at
     FROM app_notifications WHERE recipient_user_id = ? ORDER BY created_at DESC, id DESC LIMIT 20"
);
$bell->bind_param("i", $userId);
$bell->execute();
foreach ($bell->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
    $unread += $row["read_at"] === null ? 1 : 0;
    $notifications[] = [
        "id" => (int) $row["id"],
        "type" => (string) $row["notification_type"],
        "title" => (string) $row["title"],
        "message" => (string) $row["message"],
        "read" => $row["read_at"] !== null,
        "created_at" => (string) $row["created_at"],
    ];
}
$bell->close();

$chain = appointment_office_chain($code);
$status = teacher_status_now($conn, $code, $now);
// What "Other time" can offer. The office rules ask for at least an hour's notice.
$freeTimes = teacher_free_slots($conn, $code, $now, 8, 60);
$conn->close();
echo json_encode([
    "success" => true,
    "server_time" => $now->format("Y-m-d H:i:s"),
    "teacher" => [
        "code" => $code,
        "name" => appointment_office_label($code),
        "short_name" => teacher_short_label($code),
        "first_name" => (string) ($account["first_name"] ?? ""),
        "username" => (string) ($account["username"] ?? ""),
        "email" => (string) ($account["email"] ?? ""),
        "position" => (string) ($account["position"] ?? ""),
        "profile_image_url" => !empty($account["profile_image"]) ? (string) $account["profile_image"] : "",
        "room" => $calendar["location"],
        "course" => $chain[1] ?? "",
        "department" => $chain[count($chain) - 1] ?? "",
        "place" => appointment_teacher_place_label($code),
    ],
    "status" => $status,
    "walk_ins" => $walkIns,
    "requests" => $requests,
    "today" => $todayVisits,
    "upcoming" => $upcoming,
    "past" => $past,
    "hours" => $hours,
    "settings" => [
        "slot_duration_minutes" => max(15, (int) $settings["slot_duration_minutes"]),
        "auto_accept" => (int) ($settings["auto_accept"] ?? 0) === 1,
        "accept_walk_ins" => (int) ($settings["accept_walk_ins"] ?? 1) === 1,
        "email_alerts" => (int) ($settings["email_alerts"] ?? 1) === 1,
    ],
    "time_off" => $timeOff,
    "free_times" => $freeTimes,
    "notifications" => $notifications,
    "unread_notifications" => $unread,
]);
