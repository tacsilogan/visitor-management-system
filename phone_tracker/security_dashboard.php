<?php
header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/session_bootstrap.php";
require_once __DIR__ . "/appointment_offices.php";
require_once __DIR__ . "/appointment_maintenance.php";
require_once __DIR__ . "/monitoring_service.php";
require_once __DIR__ . "/gate_service.php";
require_once __DIR__ . "/visit_people_service.php";

// The Security dashboard: who is inside now (with how their location is reporting), who is still
// expected today, who left today, and the latest records for "All records and filters". Each
// visit lists the people with the visitor (GROUP_VISITS.md), so Find can search them too.

require_permission_json("visits.monitor");
refresh_appointment_time_states($conn);

/** The fields the dashboard lists show; exact positions stay on the live map. */
function security_list_entry(array $entry): array
{
    unset($entry["latitude"], $entry["longitude"], $entry["accuracy"], $entry["heading"], $entry["moving"]);
    return $entry;
}

/**
 * Bookings and walk-in passes for today that have not reached the gate: approved ones can be
 * checked in (with or without the QR code), the others are still waiting for the office.
 *
 * @return list<array<string, mixed>>
 */
function security_expected_today(mysqli $conn): array
{
    $statuses = "'approved', 'pending_approval', 'reschedule_proposed'";
    $today = "a.scheduled_start_at >= CURDATE() AND a.scheduled_start_at < CURDATE() + INTERVAL 1 DAY";
    $visitReady = visit_schema_ready($conn);
    $rows = [];
    $result = $conn->query(
        "SELECT a.id, a.registration_code, a.visitor_full_name, a.office_code, a.visit_type, a.purpose,
                a.subject, a.scheduled_start_at, a.scheduled_end_at, a.status, a.created_at
         FROM appointments a
         WHERE a.status IN ({$statuses}) AND {$today}" . ($visitReady ? " AND a.visit_id IS NULL" : "") . "
         ORDER BY a.scheduled_start_at ASC, a.id ASC
         LIMIT 200"
    );
    foreach ($result ? $result->fetch_all(MYSQLI_ASSOC) : [] as $row) {
        $id = (int) $row["id"];
        $year = $row["created_at"] ? date("Y", strtotime((string) $row["created_at"])) : date("Y");
        $rows[] = [
            "id" => $id,
            "visit_id" => null,
            "registration_id" => $row["registration_code"] ?: ("V-" . $year . "-" . str_pad((string) $id, 6, "0", STR_PAD_LEFT)),
            "visitor_full_name" => (string) $row["visitor_full_name"],
            "office_label" => appointment_office_label((string) $row["office_code"]),
            "visit_type" => (string) $row["visit_type"],
            "purpose" => (string) $row["purpose"],
            "subject" => (string) $row["subject"],
            "scheduled_start_at" => $row["scheduled_start_at"],
            "scheduled_end_at" => $row["scheduled_end_at"],
            "status" => (string) $row["status"],
        ];
    }
    if ($visitReady) {
        $visits = $conn->query(
            "SELECT DISTINCT a.visit_id FROM appointments a JOIN visits v ON v.id = a.visit_id
             WHERE v.status = 'open' AND a.status IN ({$statuses}) AND {$today}
             LIMIT 100"
        );
        foreach ($visits ? $visits->fetch_all(MYSQLI_ASSOC) : [] as $visit) {
            $row = security_visit_row($conn, (int) $visit["visit_id"]);
            if ($row && in_array($row["status"], ["approved", "pending_approval", "reschedule_proposed"], true)) {
                $rows[] = $row;
            }
        }
    }
    usort($rows, fn (array $a, array $b): int => strcmp((string) $a["scheduled_start_at"], (string) $b["scheduled_start_at"]));
    return $rows;
}

/**
 * One Security dashboard row summarizing a multi-stop visit. Its id is the stop that
 * owns the visit's tracking session, so Monitor and End visit act on the whole visit.
 */
function security_visit_row(mysqli $conn, int $visitId): ?array
{
    $visit = visit_load($conn, $visitId);
    $stops = $visit ? visit_stops($conn, $visitId) : [];
    if (!$stops) {
        return null;
    }
    $first = $stops[0];
    $last = $stops[count($stops) - 1];
    $status = (string) $visit["status"];
    if ($status === "checked_in" || $status === "completed") {
        $displayStatus = $status;
    } else {
        $statuses = array_column($stops, "status");
        $displayStatus = in_array("approved", $statuses, true) ? "approved" : (string) $first["status"];
    }
    $purposes = array_values(array_unique(array_filter(array_column($stops, "purpose"))));
    return [
        "id" => !empty($visit["tracking_appointment_id"]) ? (int) $visit["tracking_appointment_id"] : (int) $first["id"],
        "visit_id" => $visitId,
        "visitor_full_name" => $first["visitor_full_name"],
        "visitor_email" => $first["visitor_email"],
        "registration_code" => $visit["visit_code"],
        "registration_id" => $visit["visit_code"],
        "office_code" => $first["office_code"],
        "office_label" => visit_route_label($stops),
        "device_name" => $first["device_name"],
        "appointment_at" => $first["scheduled_start_at"],
        "scheduled_start_at" => $first["scheduled_start_at"],
        "scheduled_end_at" => $last["scheduled_end_at"],
        "status" => $displayStatus,
        "checked_in_at" => $visit["checked_in_at"],
        "completed_at" => $visit["completed_at"],
        "checkout_method" => $visit["checkout_method"] ?? null,
        "cancelled_at" => $status === "closed" ? $visit["closed_at"] : null,
        "created_at" => $visit["created_at"],
        "visit_type" => "multi_stop",
        "purpose" => implode(", ", $purposes),
        "subject" => count($stops) . " office stops",
        "is_inside_campus" => $status === "checked_in",
    ];
}

/**
 * Adds the people with each visitor, and the ID the visitor showed at the gate, to dashboard
 * entries (anything with "id" and, for a visit to several offices, "visit_id").
 */
function security_with_people(mysqli $conn, array $entries): array
{
    if (!$entries) {
        return $entries;
    }
    $people = visit_people_for_rows($conn, $entries);
    $ids = [];
    if (gate_schema_ready($conn)) {
        $list = implode(",", array_map(fn (array $entry): int => (int) $entry["id"], $entries));
        $reason = visit_people_schema_ready($conn) ? "no_id_shown, no_id_reason" : "0 AS no_id_shown, '' AS no_id_reason";
        foreach ($conn->query("SELECT id, id_type_shown, {$reason} FROM appointments WHERE id IN ({$list})") as $row) {
            $ids[(int) $row["id"]] = $row;
        }
    }
    foreach ($entries as $index => $entry) {
        $id = (int) $entry["id"];
        $entries[$index]["people"] = visit_people_payloads($people[$id] ?? []);
        $entries[$index]["id_type_shown"] = (string) ($ids[$id]["id_type_shown"] ?? "");
        // The guard let the visitor in without an ID (GROUP_VISITS.md); older check-ins have neither.
        $entries[$index]["no_id_shown"] = !empty($ids[$id]["no_id_shown"]);
        $entries[$index]["no_id_reason"] = (string) ($ids[$id]["no_id_reason"] ?? "");
    }
    return $entries;
}

function security_appointment_column_exists(mysqli $conn, string $column): bool
{
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS total
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'appointments'
           AND COLUMN_NAME = ?"
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param("s", $column);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int) ($row["total"] ?? 0) > 0;
}

$hasVisitType = security_appointment_column_exists($conn, "visit_type");
$visitTypeSelect = $hasVisitType ? "a.visit_type" : "'Appointment' AS visit_type";
$purposeSelect = security_appointment_column_exists($conn, "purpose")
    ? "a.purpose"
    : "NULL AS purpose";
$subjectSelect = security_appointment_column_exists($conn, "subject")
    ? "a.subject"
    : "NULL AS subject";

$walkInTodayExpression = $hasVisitType
    ? "SUM(DATE(COALESCE(checked_in_at, appointment_at)) = CURDATE()
           AND LOWER(REPLACE(visit_type, '_', '-')) = 'walk-in'
           AND status IN ('checked_in', 'completed'))"
    : "0";
$appointmentTodayExpression = $hasVisitType
    ? "SUM(DATE(appointment_at) = CURDATE()
           AND LOWER(REPLACE(visit_type, '_', '-')) <> 'walk-in'
           AND status IN ('approved', 'checked_in', 'completed'))"
    : "SUM(DATE(appointment_at) = CURDATE() AND status IN ('approved', 'checked_in', 'completed'))";

$summary = [
    "inside_campus" => 0,
    "walk_in_today" => 0,
    "appointment_today" => 0,
    "checked_out_today" => 0,
];

// A multi-stop visit counts once for people on campus and people checked out.
$visitReady = visit_schema_ready($conn);
$insideExpression = $visitReady
    ? "SUM(status = 'checked_in' AND visit_id IS NULL)
       + (SELECT COUNT(*) FROM visits WHERE status = 'checked_in')"
    : "SUM(status = 'checked_in')";
$checkedOutExpression = $visitReady
    ? "SUM(status = 'completed' AND DATE(completed_at) = CURDATE() AND visit_id IS NULL)
       + (SELECT COUNT(*) FROM visits WHERE status = 'completed' AND DATE(completed_at) = CURDATE())"
    : "SUM(status = 'completed' AND DATE(completed_at) = CURDATE())";
$summaryResult = $conn->query(
    "SELECT
        {$insideExpression} AS inside_campus,
        {$walkInTodayExpression} AS walk_in_today,
        {$appointmentTodayExpression} AS appointment_today,
        {$checkedOutExpression} AS checked_out_today
     FROM appointments"
);
if ($summaryResult) {
    $row = $summaryResult->fetch_assoc();
    foreach ($summary as $key => $unused) {
        $summary[$key] = (int) ($row[$key] ?? 0);
    }
}
// People, not only visitors: everyone who came in with a visitor and hasn't left (GROUP_VISITS.md).
$withVisitors = visit_people_inside_counts($conn);
$summary["inside_with_visitors"] = $withVisitors["people"];
$summary["inside_under_18"] = $withVisitors["under_18"];
$summary["inside_people"] = $summary["inside_campus"] + $withVisitors["people"];

$rows = [];
$officeMap = appointment_office_map();
$visitColumns = visit_appointment_columns($conn, "a");
// The QR pass codes stay out of this list: Security reads them from the visitor's pass.
$checkoutSelect = presence_schema_ready($conn) ? "a.checkout_method" : "NULL AS checkout_method";
$gateSelect = gate_schema_ready($conn) ? ", a.registration_source" : ", 'app' AS registration_source";
$result = $conn->query(
    "SELECT a.id, a.visitor_full_name, a.visitor_email,
            a.registration_code, a.office_code, a.device_name, a.appointment_at,
            a.scheduled_start_at, a.scheduled_end_at, a.status,
            a.checked_in_at, a.completed_at, a.cancelled_at, a.created_at, {$checkoutSelect},
            {$visitTypeSelect}, {$purposeSelect}, {$subjectSelect}{$visitColumns}{$gateSelect}
     FROM appointments a
     ORDER BY COALESCE(a.status_updated_at, a.created_at) DESC, a.id DESC
     LIMIT 100"
);
$shownVisits = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        if (!empty($row["visit_id"])) {
            // Security sees one row per multi-stop visit rather than one per office stop.
            $visitId = (int) $row["visit_id"];
            if (!isset($shownVisits[$visitId])) {
                $shownVisits[$visitId] = true;
                $visitRow = security_visit_row($conn, $visitId);
                if ($visitRow) {
                    $rows[] = $visitRow;
                }
            }
            continue;
        }
        $id = (int) $row["id"];
        $officeCode = (string) ($row["office_code"] ?? "");
        $createdYear = $row["created_at"] ? date("Y", strtotime($row["created_at"])) : date("Y");
        $row["id"] = $id;
        $row["registration_id"] = $row["registration_code"] ?: ("V-" . $createdYear . "-" . str_pad((string) $id, 6, "0", STR_PAD_LEFT));
        $row["office_label"] = $officeMap[$officeCode] ?? $officeCode;
        $row["is_inside_campus"] = $row["status"] === "checked_in"
            && !empty($row["checked_in_at"])
            && empty($row["completed_at"]);
        $rows[] = $row;
    }
}

$inside = security_with_people($conn, array_map("security_list_entry", monitor_visitors($conn, "active")));
$left = security_with_people($conn, array_map("security_list_entry", monitor_visitors($conn, "finished")));
$expected = security_with_people($conn, security_expected_today($conn));
$rows = security_with_people($conn, $rows);

$conn->close();

echo json_encode([
    "success" => true,
    "server_time" => date("Y-m-d H:i:s"),
    "rules" => monitor_rules(),
    "summary" => $summary,
    "inside" => $inside,
    "expected" => $expected,
    "left" => $left,
    "visitors" => $rows,
]);
