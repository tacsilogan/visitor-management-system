<?php
/*
 * Visit lists for the Admin dashboard (admin.js), opened from Home's "Right now" numbers and
 * from a visitor's page. Never where a visitor is: only Security sees the live map.
 *   GET admin_visits.php?list=campus     visitors inside the campus now
 *   GET admin_visits.php?list=today      visits booked for today
 *   GET admin_visits.php?list=waiting    requests waiting for an office
 *   GET admin_visits.php?list=recent     the newest requests
 *   GET admin_visits.php?visitor=ID      one visitor account and all their visits
 *   GET admin_visits.php?appointment=ID  the visitor of one visit (gate visitors have no account)
 */
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/session_bootstrap.php";
require_once __DIR__ . "/appointment_maintenance.php";
require_once __DIR__ . "/admin_service.php";

auth_json_exception_guard();
auth_require_method("GET");
require_admin_json();
auth_require_schema($conn);
date_default_timezone_set("Asia/Manila");

const ADMIN_VISIT_STATUS = [
    "pending_approval" => ["Waiting for the office", "amber"],
    "approved" => ["Approved", "blue"],
    "reschedule_proposed" => ["Other times offered", "amber"],
    "checked_in" => ["On campus", "green"],
    "completed" => ["Completed", "gray"],
    "rejected" => ["Declined", "red"],
    "cancelled" => ["Cancelled", "gray"],
    "unanswered" => ["No answer from the office", "red"],
    "window_closed" => ["Didn't come", "gray"],
];

function admin_visit_row(array $row): array
{
    $status = (string) $row["status"];
    [$label, $tone] = ADMIN_VISIT_STATUS[$status] ?? [ucfirst(str_replace("_", " ", $status)), "gray"];
    $start = $row["scheduled_start_at"] ?: $row["appointment_at"];
    return [
        "id" => (int) $row["id"],
        "code" => (string) ($row["registration_code"] ?: "V-" . date("Y", strtotime((string) $row["created_at"])) . "-" . str_pad((string) $row["id"], 6, "0", STR_PAD_LEFT)),
        "visitor" => (string) $row["visitor_full_name"],
        "visitor_user_id" => $row["visitor_user_id"] !== null ? (int) $row["visitor_user_id"] : null,
        "office" => appointment_office_label((string) $row["office_code"]),
        "office_code" => (string) $row["office_code"],
        "purpose" => (string) ($row["purpose"] ?? ""),
        "starts_at" => $start,
        "ends_at" => $row["scheduled_end_at"],
        "created_at" => $row["created_at"],
        "checked_in_at" => $row["checked_in_at"],
        "completed_at" => $row["completed_at"],
        "status" => $status,
        "status_label" => $label,
        "tone" => $tone,
        "walk_in" => ($row["visit_type"] ?? "") === "walk_in",
        "at_gate" => ($row["registration_source"] ?? "app") === "gate",
    ];
}

const ADMIN_VISIT_COLUMNS = "a.id, a.registration_code, a.visitor_full_name, a.visitor_user_id, a.office_code, a.purpose,
    a.appointment_at, a.scheduled_start_at, a.scheduled_end_at, a.created_at, a.checked_in_at, a.completed_at, a.status,
    a.visit_type, a.registration_source";

function admin_visit_query(mysqli $conn, string $where, string $order, string $types = "", array $params = [], int $limit = 100): array
{
    $stmt = $conn->prepare("SELECT " . ADMIN_VISIT_COLUMNS . " FROM appointments a WHERE " . $where . " ORDER BY " . $order . " LIMIT " . $limit);
    if ($types !== "") {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $rows = array_map("admin_visit_row", $stmt->get_result()->fetch_all(MYSQLI_ASSOC));
    $stmt->close();
    return $rows;
}

refresh_appointment_time_states($conn);
$now = new DateTimeImmutable("now");
$nowSql = $now->format("Y-m-d H:i:s");
$todayStart = $now->setTime(0, 0)->format("Y-m-d H:i:s");
$todayEnd = $now->setTime(23, 59, 59)->format("Y-m-d H:i:s");

if (isset($_GET["visitor"]) || isset($_GET["appointment"])) {
    $userId = max(0, (int) ($_GET["visitor"] ?? 0));
    $name = "";
    if (isset($_GET["appointment"])) {
        $appointmentId = max(0, (int) $_GET["appointment"]);
        $stmt = $conn->prepare("SELECT visitor_user_id, visitor_full_name FROM appointments WHERE id = ? LIMIT 1");
        $stmt->bind_param("i", $appointmentId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            auth_json_error(404, "not_found", "That visit was not found.");
        }
        $userId = (int) ($row["visitor_user_id"] ?? 0);
        $name = (string) $row["visitor_full_name"];
    }
    $account = null;
    if ($userId > 0) {
        $stmt = $conn->prepare(
            "SELECT id, username, email, first_name, last_name, display_name, contact_number, is_active, created_at,
                    last_login_at, notice_version, notice_agreed_at
             FROM app_users WHERE id = ? AND role = 'visitor' LIMIT 1"
        );
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            $account = [
                "id" => (int) $row["id"],
                "name" => personnel_full_name($row),
                "username" => (string) $row["username"],
                "email" => (string) ($row["email"] ?? ""),
                "contact_number" => (string) $row["contact_number"],
                "is_active" => (int) $row["is_active"] === 1,
                "created_at" => $row["created_at"],
                "last_login_at" => $row["last_login_at"],
                "notice_version" => (string) $row["notice_version"],
            ];
            $name = $account["name"];
        }
    }
    $visits = $account
        ? admin_visit_query($conn, "a.visitor_user_id = ?", "COALESCE(a.scheduled_start_at, a.appointment_at) DESC", "i", [$userId], 200)
        : admin_visit_query($conn, "a.visitor_user_id IS NULL AND a.visitor_full_name = ?", "COALESCE(a.scheduled_start_at, a.appointment_at) DESC", "s", [$name], 200);
    $count = fn (array $statuses): int => count(array_filter($visits, fn (array $visit): bool => in_array($visit["status"], $statuses, true)));
    auth_json([
        "success" => true,
        "visitor" => [
            "name" => $name,
            "account" => $account,
            "visits" => count($visits),
            "completed" => $count(["completed", "checked_in"]),
            "no_shows" => $count(["window_closed"]),
            "at_gate" => count(array_filter($visits, fn (array $visit): bool => $visit["at_gate"])),
        ],
        "visits" => $visits,
    ]);
}

$list = (string) ($_GET["list"] ?? "today");
switch ($list) {
    case "campus":
        $rows = admin_visit_query($conn, "a.status = 'checked_in'", "a.checked_in_at DESC");
        break;
    case "waiting":
        $rows = admin_visit_query($conn, "a.status = 'pending_approval' AND a.scheduled_end_at > ?", "a.created_at ASC", "s", [$nowSql]);
        break;
    case "recent":
        $rows = admin_visit_query($conn, "1 = 1", "a.created_at DESC", "", [], 60);
        break;
    default:
        $list = "today";
        $rows = admin_visit_query(
            $conn,
            "COALESCE(a.scheduled_start_at, a.appointment_at) BETWEEN ? AND ? AND a.status <> 'cancelled'",
            "COALESCE(a.scheduled_start_at, a.appointment_at) ASC",
            "ss",
            [$todayStart, $todayEnd]
        );
}
auth_json(["success" => true, "list" => $list, "visits" => $rows]);
