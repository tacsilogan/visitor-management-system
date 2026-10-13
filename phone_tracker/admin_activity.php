<?php
/*
 * User activity for accountability (administrators).
 *   GET admin_activity.php                      activity entries (JSON; CSV with format=csv)
 *   GET admin_activity.php?view=board           personnel per department with their activity counts
 *   GET admin_activity.php?view=person&user_id= one person's details, devices, and counts
 *   GET admin_activity.php?view=appointment&appointment_id=  everything that happened to one appointment
 * Filters: from, to (YYYY-MM-DD), category, department, user_id, q, before_id, limit.
 */
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/session_bootstrap.php";
require_once __DIR__ . "/activity_service.php";
require_once __DIR__ . "/presence_service.php";

auth_json_exception_guard();
auth_require_method("GET");
require_permission_json("activity.view");
auth_require_schema($conn);
personnel_require_schema($conn);

function activity_date(string $value, DateTimeImmutable $fallback): DateTimeImmutable
{
    $date = DateTimeImmutable::createFromFormat("!Y-m-d", $value);
    return $date && $date->format("Y-m-d") === $value ? $date : $fallback;
}

function activity_person_row(array $row, string $prefix): ?array
{
    if (empty($row[$prefix . "username"])) {
        return null;
    }
    return [
        "name" => personnel_full_name([
            "first_name" => $row[$prefix . "first_name"],
            "last_name" => $row[$prefix . "last_name"],
            "display_name" => $row[$prefix . "display_name"],
            "username" => $row[$prefix . "username"],
        ]),
        "username" => (string) $row[$prefix . "username"],
        "department" => personnel_department_label((string) $row[$prefix . "role"], (string) $row[$prefix . "office_code"]),
    ];
}

/** Activity counts per category for the filters (ignoring the category filter itself). */
function activity_counts(mysqli $conn, array $filters): array
{
    [$where, $params, $types] = activity_where($filters, false);
    $sql = "SELECT g.action, (g.actor_user_id IS NULL) AS by_system, COUNT(*) AS total
            FROM audit_logs g
            LEFT JOIN app_users u ON u.id = g.actor_user_id
            LEFT JOIN appointments a ON a.id = COALESCE(g.appointment_id, CASE WHEN g.entity_type = 'appointment' THEN CAST(g.entity_id AS UNSIGNED) END)
            LEFT JOIN app_users t ON g.entity_type = 'app_user' AND t.id = CAST(g.entity_id AS UNSIGNED)
            WHERE " . implode(" AND ", $where) . "
            GROUP BY g.action, by_system";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $counts = array_fill_keys(ACTIVITY_CATEGORIES, 0);
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $category = activity_category((string) $row["action"], (int) $row["by_system"] === 1 ? null : 1);
        $counts[$category] += (int) $row["total"];
        $counts["all"] += (int) $row["total"];
    }
    $stmt->close();
    return $counts;
}

/** @return array{0: string[], 1: array, 2: string} WHERE parts, params, bind types */
function activity_where(array $filters, bool $withCategory = true): array
{
    $where = ["g.created_at >= ?", "g.created_at < ?"];
    $params = [$filters["from"], $filters["to"]];
    $types = "ss";
    if ($withCategory) {
        $categorySql = activity_category_sql($filters["category"], $params, $types);
        if ($categorySql !== "") {
            $where[] = $categorySql;
        }
    }
    $department = $filters["department"];
    if ($department === "system") {
        $where[] = "g.actor_user_id IS NULL";
    } elseif (in_array($department, ["admin", "security", "visitor"], true)) {
        $where[] = "u.role = ?";
        $params[] = $department;
        $types .= "s";
    } elseif ($department !== "") {
        $where[] = "(u.role = 'offices' AND u.office_code = ?)";
        $params[] = $department;
        $types .= "s";
    }
    if ($filters["user_id"] > 0) {
        $where[] = "g.actor_user_id = ?";
        $params[] = $filters["user_id"];
        $types .= "i";
    }
    if ($filters["q"] !== "") {
        $like = "%" . addcslashes($filters["q"], "%_\\") . "%";
        $where[] = "(CONCAT_WS(' ', u.first_name, u.last_name) LIKE ? OR u.display_name LIKE ? OR u.username LIKE ?
                     OR a.visitor_full_name LIKE ? OR CONCAT_WS(' ', t.first_name, t.last_name) LIKE ? OR t.username LIKE ? OR a.id = ?)";
        array_push($params, $like, $like, $like, $like, $like, $like, (int) ltrim($filters["q"], "#"));
        $types .= "ssssssi";
    }
    return [$where, $params, $types];
}

function activity_entries(mysqli $conn, array $filters, int $limit): array
{
    [$where, $params, $types] = activity_where($filters);
    if ($filters["before_id"] > 0) {
        $where[] = "g.id < ?";
        $params[] = $filters["before_id"];
        $types .= "i";
    }
    $stmt = $conn->prepare(ACTIVITY_SELECT . " WHERE " . implode(" AND ", $where) . " ORDER BY g.id DESC LIMIT " . ($limit + 1));
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $hasMore = count($rows) > $limit;
    $rows = array_slice($rows, 0, $limit);
    return [
        "entries" => array_map("activity_entry", $rows),
        "has_more" => $hasMore,
        "next_before_id" => $hasMore && $rows ? (int) $rows[count($rows) - 1]["id"] : null,
    ];
}

/** Spreadsheet apps run cells that start with = + - @ as formulas; keep them as text. */
function activity_csv_cell(string $value): string
{
    return preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value;
}

$today = new DateTimeImmutable("today");
$from = activity_date((string) ($_GET["from"] ?? ""), $today->modify("-6 days"));
$to = activity_date((string) ($_GET["to"] ?? ""), $today);
if ($to < $from) {
    [$from, $to] = [$to, $from];
}
if ($from < $to->modify("-366 days")) {
    $from = $to->modify("-366 days");
}
$category = (string) ($_GET["category"] ?? "all");
$department = strtoupper(trim((string) ($_GET["department"] ?? "")));
if (in_array(strtolower($department), ["admin", "security", "visitor", "system"], true)) {
    $department = strtolower($department);
} elseif ($department !== "" && !isset(appointment_office_map()[$department])) {
    $department = "";
}
$filters = [
    "from" => $from->format("Y-m-d 00:00:00"),
    "to" => $to->modify("+1 day")->format("Y-m-d 00:00:00"),
    "category" => in_array($category, ACTIVITY_CATEGORIES, true) ? $category : "all",
    "department" => $department,
    "user_id" => max(0, (int) ($_GET["user_id"] ?? 0)),
    "q" => personnel_clean_text((string) ($_GET["q"] ?? ""), 100),
    "before_id" => max(0, (int) ($_GET["before_id"] ?? 0)),
];
$range = ["from" => $from->format("Y-m-d"), "to" => $to->format("Y-m-d")];
$view = (string) ($_GET["view"] ?? "entries");

if ($view === "board") {
    $counts = [];
    $stmt = $conn->prepare(
        "SELECT actor_user_id, action, COUNT(*) AS total FROM audit_logs
         WHERE created_at >= ? AND created_at < ? AND actor_user_id IS NOT NULL
         GROUP BY actor_user_id, action"
    );
    $stmt->bind_param("ss", $filters["from"], $filters["to"]);
    $stmt->execute();
    $buckets = [
        "appointment.approved" => "approved",
        "appointment.rejected" => "declined",
        "appointment.reschedule_proposed" => "rescheduled",
        "appointment.stop_completed" => "meetings_done",
        "appointment.checked_in" => "checked_in",
        "visit.checked_in" => "checked_in",
        "appointment.completed" => "visits_ended",
        "visit.completed" => "visits_ended",
        "appointment.qr_override_created" => "pass_overrides",
        "auth.signed_in" => "sign_ins",
    ];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $id = (int) $row["actor_user_id"];
        $counts[$id] ??= array_fill_keys(["approved", "declined", "rescheduled", "meetings_done", "checked_in", "visits_ended", "pass_overrides", "sign_ins", "schedule_changes", "total"], 0);
        $action = (string) $row["action"];
        if (isset($buckets[$action])) {
            $counts[$id][$buckets[$action]] += (int) $row["total"];
        } elseif (in_array($action, ACTIVITY_SCHEDULE_ACTIONS, true)) {
            $counts[$id]["schedule_changes"] += (int) $row["total"];
        }
        if (!in_array($action, ACTIVITY_SIGNIN_ACTIONS, true)) {
            $counts[$id]["total"] += (int) $row["total"];
        }
    }
    $stmt->close();

    $lastAction = [];
    foreach ($conn->query(
        "SELECT actor_user_id, MAX(created_at) AS last_at FROM audit_logs
         WHERE actor_user_id IS NOT NULL AND action NOT IN ('auth.signed_in', 'auth.signed_out')
         GROUP BY actor_user_id"
    )->fetch_all(MYSQLI_ASSOC) as $row) {
        $lastAction[(int) $row["actor_user_id"]] = $row["last_at"];
    }
    $pending = [];
    foreach ($conn->query("SELECT office_code, COUNT(*) AS total FROM appointments WHERE status = 'pending_approval' GROUP BY office_code")->fetch_all(MYSQLI_ASSOC) as $row) {
        $pending[(string) $row["office_code"]] = (int) $row["total"];
    }

    $active = appointment_office_active_map();
    $departments = [
        "admin" => ["key" => "admin", "name" => "Administration", "kind" => "role", "is_active" => true, "people" => []],
        "security" => ["key" => "security", "name" => "Security", "kind" => "role", "is_active" => true, "people" => []],
    ];
    foreach (appointment_office_map() as $code => $name) {
        $departments[$code] = [
            "key" => $code,
            "name" => $name,
            "kind" => "office",
            "is_active" => isset($active[$code]),
            "pending_appointments" => $pending[$code] ?? 0,
            "people" => [],
        ];
    }
    $people = $conn->query(
        "SELECT id, username, first_name, last_name, display_name, position, role, office_code, is_active,
                last_login_at, last_seen_at, TIMESTAMPDIFF(SECOND, last_seen_at, NOW()) AS seen_seconds_ago,
                (totp_enabled_at IS NOT NULL) AS two_factor_enabled
         FROM app_users
         WHERE role IN ('admin', 'security', 'offices')
         ORDER BY is_active DESC, last_name ASC, first_name ASC, username ASC"
    )->fetch_all(MYSQLI_ASSOC);
    foreach ($people as $row) {
        $id = (int) $row["id"];
        $key = personnel_department_key((string) $row["role"], (string) $row["office_code"]);
        if (!isset($departments[$key])) {
            $departments[$key] = ["key" => $key, "name" => "Unassigned office", "kind" => "office", "is_active" => false, "pending_appointments" => 0, "people" => []];
        }
        $departments[$key]["people"][] = [
            "id" => $id,
            "name" => personnel_full_name($row),
            "has_name" => personnel_has_name($row),
            "username" => (string) $row["username"],
            "position" => (string) $row["position"],
            "role" => (string) $row["role"],
            "is_active" => (int) $row["is_active"] === 1,
            "online" => $row["seen_seconds_ago"] !== null && (int) $row["seen_seconds_ago"] <= 300,
            "last_seen_at" => $row["last_seen_at"],
            "last_login_at" => $row["last_login_at"],
            "last_action_at" => $lastAction[$id] ?? null,
            "two_factor_enabled" => (int) $row["two_factor_enabled"] === 1,
            "counts" => $counts[$id] ?? array_fill_keys(["approved", "declined", "rescheduled", "meetings_done", "checked_in", "visits_ended", "pass_overrides", "sign_ins", "schedule_changes", "total"], 0),
        ];
    }
    // Hide archived offices that have nobody assigned.
    $departments = array_values(array_filter($departments, fn ($d) => $d["kind"] === "role" || $d["is_active"] || $d["people"]));
    auth_json(["success" => true, "range" => $range, "departments" => $departments]);
}

if ($view === "person") {
    $userId = $filters["user_id"];
    $stmt = $conn->prepare(
        "SELECT id, username, first_name, last_name, display_name, position, email, contact_number, role, office_code,
                is_active, created_at, last_login_at, last_login_ip, last_seen_at,
                TIMESTAMPDIFF(SECOND, last_seen_at, NOW()) AS seen_seconds_ago,
                (totp_enabled_at IS NOT NULL) AS two_factor_enabled, must_change_password
         FROM app_users WHERE id = ? LIMIT 1"
    );
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        auth_json_error(404, "not_found", "Person not found.");
    }
    $devices = [];
    $stmt = $conn->prepare(
        "SELECT user_agent, ip_address, channel, MAX(created_at) AS last_used, COUNT(*) AS uses
         FROM auth_login_attempts
         WHERE user_id = ? AND outcome = 'success' AND stage = 'password' AND created_at > NOW() - INTERVAL 30 DAY
         GROUP BY user_agent, ip_address, channel
         ORDER BY last_used DESC
         LIMIT 12"
    );
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $seen = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $device) {
        $label = auth_device_label((string) $device["user_agent"]);
        $devices[] = [
            "device" => $label,
            "ip" => (string) $device["ip_address"],
            "channel" => (string) $device["channel"],
            "last_used" => $device["last_used"],
            "uses" => (int) $device["uses"],
        ];
        $seen[$label . "|" . $device["ip_address"]] = true;
    }
    $stmt->close();
    $failed = auth_scalar($conn, "SELECT COUNT(*) FROM auth_login_attempts WHERE user_id = ? AND outcome = 'failure' AND created_at > NOW() - INTERVAL 30 DAY", "i", [$userId]);
    $monthFilters = array_merge($filters, [
        "from" => $today->modify("-29 days")->format("Y-m-d 00:00:00"),
        "to" => $today->modify("+1 day")->format("Y-m-d 00:00:00"),
        "category" => "all",
        "department" => "",
        "q" => "",
    ]);
    auth_json([
        "success" => true,
        "person" => [
            "id" => (int) $row["id"],
            "name" => personnel_full_name($row),
            "has_name" => personnel_has_name($row),
            "first_name" => (string) $row["first_name"],
            "last_name" => (string) $row["last_name"],
            "username" => (string) $row["username"],
            "role" => (string) $row["role"],
            "department" => personnel_department_label((string) $row["role"], (string) $row["office_code"]),
            "office_code" => (string) $row["office_code"],
            "position" => (string) $row["position"],
            "email" => (string) ($row["email"] ?? ""),
            "contact_number" => (string) $row["contact_number"],
            "is_active" => (int) $row["is_active"] === 1,
            "created_at" => $row["created_at"],
            "last_login_at" => $row["last_login_at"],
            "last_login_ip" => (string) ($row["last_login_ip"] ?? ""),
            "last_seen_at" => $row["last_seen_at"],
            "online" => $row["seen_seconds_ago"] !== null && (int) $row["seen_seconds_ago"] <= 300,
            "two_factor_enabled" => (int) $row["two_factor_enabled"] === 1,
            "must_change_password" => (int) $row["must_change_password"] === 1,
        ],
        "devices" => $devices,
        "distinct_devices" => count($seen),
        "failed_sign_ins_30d" => $failed,
        "counts_30d" => activity_counts($conn, $monthFilters),
    ]);
}

if ($view === "appointment") {
    $appointmentId = max(0, (int) ($_GET["appointment_id"] ?? 0));
    $people = "";
    foreach (["approved" => "ap", "rejected" => "rj", "checked_in" => "ci", "completed" => "co", "cancelled" => "ca"] as $field => $alias) {
        $people .= ", {$alias}.username AS {$alias}_username, {$alias}.first_name AS {$alias}_first_name, {$alias}.last_name AS {$alias}_last_name,
                   {$alias}.display_name AS {$alias}_display_name, {$alias}.role AS {$alias}_role, {$alias}.office_code AS {$alias}_office_code";
    }
    // How the visit ended (live_monitoring_migration.sql); a multi-office visit keeps it on the visit.
    $checkoutSelect = ", NULL AS checkout_method";
    $checkoutJoin = "";
    if (presence_schema_ready($conn)) {
        $checkoutSelect = visit_schema_ready($conn) ? ", COALESCE(a.checkout_method, v.checkout_method) AS checkout_method" : ", a.checkout_method";
        $checkoutJoin = visit_schema_ready($conn) ? "LEFT JOIN visits v ON v.id = a.visit_id" : "";
    }
    $stmt = $conn->prepare(
        "SELECT a.id, a.visitor_full_name, a.visitor_email, a.contact_number, a.office_code, a.visit_type, a.purpose, a.subject,
                a.scheduled_start_at, a.scheduled_end_at, a.appointment_at, a.status, a.created_at, a.rejection_reason,
                a.approved_at, a.rejected_at, a.checked_in_at, a.completed_at, a.cancelled_at {$checkoutSelect} {$people}
         FROM appointments a
         LEFT JOIN app_users ap ON ap.id = a.approved_by_user_id
         LEFT JOIN app_users rj ON rj.id = a.rejected_by_user_id
         LEFT JOIN app_users ci ON ci.id = a.checked_in_by_user_id
         LEFT JOIN app_users co ON co.id = a.completed_by_user_id
         LEFT JOIN app_users ca ON ca.id = a.cancelled_by_user_id
         {$checkoutJoin}
         WHERE a.id = ? LIMIT 1"
    );
    $stmt->bind_param("i", $appointmentId);
    $stmt->execute();
    $appointment = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$appointment) {
        auth_json_error(404, "not_found", "Appointment not found.");
    }
    $handled = [];
    $endedLabel = [
        "scan" => "Checked out by",
        "guard" => "Visit ended by",
        "left_campus" => "Left campus without checking out",
        "end_of_day" => "Ended at the end of the day",
    ][(string) ($appointment["checkout_method"] ?? "")] ?? "Visit ended by";
    foreach (["approved" => ["ap", "Approved by"], "rejected" => ["rj", "Declined by"], "checked_in" => ["ci", "Checked in by"], "completed" => ["co", $endedLabel], "cancelled" => ["ca", "Cancelled by"]] as $field => [$alias, $label]) {
        $person = activity_person_row($appointment, $alias . "_");
        if ($person || $appointment[$field . "_at"]) {
            $handled[] = ["label" => $label, "person" => $person, "at" => $appointment[$field . "_at"]];
        }
    }

    $history = [];
    $stmt = $conn->prepare(
        "SELECT h.from_status, h.to_status, h.changed_at, h.note,
                u.username AS by_username, u.first_name AS by_first_name, u.last_name AS by_last_name,
                u.display_name AS by_display_name, u.role AS by_role, u.office_code AS by_office_code
         FROM appointment_status_history h
         LEFT JOIN app_users u ON u.id = h.changed_by_user_id
         WHERE h.appointment_id = ?
         ORDER BY h.changed_at ASC, h.id ASC"
    );
    $stmt->bind_param("i", $appointmentId);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $history[] = [
            "from" => $row["from_status"] !== null ? activity_status((string) $row["from_status"]) : "",
            "to" => activity_status((string) $row["to_status"]),
            "to_status" => (string) $row["to_status"],
            "at" => $row["changed_at"],
            "note" => (string) $row["note"],
            "by" => activity_person_row($row, "by_"),
        ];
    }
    $stmt->close();

    $idText = (string) $appointmentId;
    $stmt = $conn->prepare(
        ACTIVITY_SELECT . " WHERE (g.appointment_id = ? OR (g.entity_type = 'appointment' AND g.entity_id = ?)) ORDER BY g.id ASC LIMIT 200"
    );
    $stmt->bind_param("is", $appointmentId, $idText);
    $stmt->execute();
    $events = array_map("activity_entry", $stmt->get_result()->fetch_all(MYSQLI_ASSOC));
    $stmt->close();

    auth_json([
        "success" => true,
        "appointment" => [
            "id" => (int) $appointment["id"],
            "visitor_name" => (string) $appointment["visitor_full_name"],
            "visitor_email" => (string) ($appointment["visitor_email"] ?? ""),
            "contact_number" => (string) ($appointment["contact_number"] ?? ""),
            "office" => appointment_office_label((string) $appointment["office_code"]),
            "visit_type" => (string) ($appointment["visit_type"] ?? ""),
            "purpose" => (string) ($appointment["purpose"] ?? ""),
            "subject" => (string) ($appointment["subject"] ?? ""),
            "scheduled_start_at" => $appointment["scheduled_start_at"] ?? $appointment["appointment_at"],
            "scheduled_end_at" => $appointment["scheduled_end_at"],
            "status" => activity_status((string) $appointment["status"]),
            "status_code" => (string) $appointment["status"],
            "created_at" => $appointment["created_at"],
            "rejection_reason" => (string) $appointment["rejection_reason"],
        ],
        "handled_by" => $handled,
        "history" => $history,
        "events" => $events,
    ]);
}

if (($_GET["format"] ?? "") === "csv") {
    $result = activity_entries($conn, $filters, 5000);
    auth_audit($conn, (int) $_SESSION["user_id"], "activity.exported", "activity_log", "", [
        "from" => $range["from"],
        "to" => $range["to"],
        "category" => $filters["category"],
        "department" => $filters["department"],
        "user_id" => $filters["user_id"],
        "rows" => count($result["entries"]),
    ]);
    header("Content-Type: text/csv; charset=utf-8");
    header("Cache-Control: no-store");
    header('Content-Disposition: attachment; filename="activity-log-' . $range["from"] . "-to-" . $range["to"] . '.csv"');
    $out = fopen("php://output", "w");
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ["Date", "Time", "Person", "Username", "Department", "Position", "Activity", "Details", "Visitor", "Appointment", "IP address", "Device"]);
    foreach ($result["entries"] as $entry) {
        $time = strtotime((string) $entry["created_at"]);
        $actor = $entry["actor"];
        fputcsv($out, array_map("activity_csv_cell", [
            date("Y-m-d", $time),
            date("g:i:s A", $time),
            $actor ? $actor["name"] : "System",
            $actor ? $actor["username"] : "",
            $actor ? $actor["department"] : "",
            $actor ? $actor["position"] : "",
            $entry["title"],
            $entry["text"],
            $entry["visitor_name"],
            $entry["appointment_id"] !== null ? "#" . $entry["appointment_id"] : "",
            $entry["ip"],
            $entry["device"],
        ]));
    }
    fclose($out);
    exit;
}

if (($_GET["format"] ?? "") === "xlsx") {
    require_once __DIR__ . "/xlsx_writer.php";
    $result = activity_entries($conn, $filters, 5000);
    auth_audit($conn, (int) $_SESSION["user_id"], "activity.exported", "activity_log", "", [
        "from" => $range["from"],
        "to" => $range["to"],
        "category" => $filters["category"],
        "department" => $filters["department"],
        "user_id" => $filters["user_id"],
        "rows" => count($result["entries"]),
        "format" => "xlsx",
    ]);
    activity_send_workbook($conn, $result, $filters, $from, $to);
}

/** The activity log as a formatted Excel report: a summary sheet and every entry. */
function activity_send_workbook(mysqli $conn, array $result, array $filters, DateTimeImmutable $from, DateTimeImmutable $to): never
{
    $categoryLabels = [
        "all" => "All activity",
        "signins" => "Sign-ins",
        "decisions" => "Approvals & declines",
        "gate" => "Gate & visits",
        "schedules" => "Office schedules",
        "accounts" => "Accounts & security",
        "visitors" => "Visitor requests",
        "system" => "Automatic",
    ];
    $department = $filters["department"];
    $departmentLabel = [
        "" => "All departments",
        "admin" => "Administration",
        "security" => "Security",
        "visitor" => "Visitors",
        "system" => "Automatic (system)",
    ][$department] ?? appointment_office_label($department);
    $entries = $result["entries"];
    $filtersText = [
        $from->format("M j, Y") . ($from == $to ? "" : " – " . $to->format("M j, Y")),
        $categoryLabels[$filters["category"]] ?? "All activity",
        $departmentLabel,
    ];
    if ($filters["user_id"] > 0) {
        $person = $entries && $entries[0]["actor"] ? $entries[0]["actor"]["name"] : "Person #" . $filters["user_id"];
        $filtersText[] = "Person: " . $person;
    }
    if ($filters["q"] !== "") {
        $filtersText[] = "Search: \"" . $filters["q"] . "\"";
    }
    $me = auth_current_user();
    $generated = "Generated " . date("M j, Y, g:i A") . " by " . (($me["display_name"] ?? "") ?: ($me["username"] ?? "an administrator"));

    $book = new XlsxBook("User Activity Log");

    // Summary: how much of each kind of activity, and who was busiest.
    $summary = $book->sheet("Summary");
    $summary->withoutTableTools();
    $summary->title("User Activity Log", implode(" · ", $filtersText) . " · " . $generated);
    $people = [];
    $categories = array_fill_keys(array_keys($categoryLabels), 0);
    foreach ($entries as $entry) {
        $categories[$entry["category"]] = ($categories[$entry["category"]] ?? 0) + 1;
        $key = $entry["actor"] ? $entry["actor"]["username"] : "";
        if ($key !== "") {
            $people[$key] ??= ["name" => $entry["actor"]["name"], "department" => $entry["actor"]["department"], "count" => 0];
            $people[$key]["count"]++;
        }
    }
    $summary->section("Overview");
    $summary->facts([
        ["Activity entries", count($entries), "number"],
        ["People with activity", count($people), "number"],
        ["Automatic (system) entries", $categories["system"] ?? 0, "number"],
        ["Period", $filtersText[0], "text"],
    ]);
    $summary->section("By type of activity");
    $summary->columns([["Type of activity", "text"], ["Entries", "number"], ["Share", "text"]]);
    foreach ($categoryLabels as $key => $label) {
        if ($key === "all" || empty($categories[$key])) {
            continue;
        }
        $summary->row([$label, $categories[$key], count($entries) ? round($categories[$key] * 100 / count($entries)) . "%" : "—"]);
    }
    if ($people) {
        uasort($people, fn ($a, $b) => $b["count"] <=> $a["count"]);
        $summary->section("Most active people");
        $summary->columns([["Person", "text"], ["Department", "text"], ["Entries", "number"]]);
        foreach (array_slice($people, 0, 15) as $person) {
            $summary->row([$person["name"], $person["department"], $person["count"]]);
        }
    }

    // Every entry, newest first, as on screen.
    $sheet = $book->sheet("Activity");
    $sheet->title("User Activity Log", implode(" · ", $filtersText));
    $sheet->columns([
        ["Date", "date"], ["Time", "time"], ["Person", "text"], ["Username", "text"], ["Department", "text"],
        ["Position", "text"], ["Activity", "status"], ["Details", "wrap"], ["Visitor", "text"],
        ["Appointment", "text"], ["IP address", "text"], ["Device", "text"],
    ]);
    foreach ($entries as $entry) {
        $time = strtotime((string) $entry["created_at"]);
        $actor = $entry["actor"];
        $sheet->row([
            date("Y-m-d", $time),
            date("H:i:s", $time),
            $actor ? $actor["name"] : "System",
            $actor ? $actor["username"] : "",
            $actor ? $actor["department"] : "Automatic",
            $actor ? $actor["position"] : "",
            [$entry["title"], (string) $entry["tone"]],
            $entry["text"],
            $entry["visitor_name"],
            $entry["appointment_id"] !== null ? "#" . $entry["appointment_id"] : "",
            $entry["ip"],
            $entry["device"],
        ]);
    }
    if (!$entries) {
        $sheet->note("No activity matches these filters.");
    } elseif ($result["has_more"]) {
        $sheet->note("Showing the newest " . number_format(count($entries)) . " entries. Narrow the dates or filters to export the rest.");
    }
    $book->send("activity-log-" . $from->format("Y-m-d") . "-to-" . $to->format("Y-m-d") . ".xlsx");
}

$limit = min(200, max(1, (int) ($_GET["limit"] ?? 50)));
$result = activity_entries($conn, $filters, $limit);
$payload = ["success" => true, "range" => $range] + $result;
if ($filters["before_id"] === 0) {
    $payload["counts"] = activity_counts($conn, $filters);
}
auth_json($payload);
