<?php
header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/session_bootstrap.php";
require_once __DIR__ . "/appointment_offices.php";
require_once __DIR__ . "/appointment_maintenance.php";

require_permission_json("visits.monitor");
refresh_appointment_time_states($conn);

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

$rows = [];
$officeMap = appointment_office_map();
$visitColumns = visit_appointment_columns($conn, "a");
// The QR pass codes stay out of this list: Security reads them from the visitor's pass.
$checkoutSelect = presence_schema_ready($conn) ? "a.checkout_method" : "NULL AS checkout_method";
$result = $conn->query(
    "SELECT a.id, a.visitor_full_name, a.visitor_email,
            a.registration_code, a.office_code, a.device_name, a.appointment_at,
            a.scheduled_start_at, a.scheduled_end_at, a.status,
            a.checked_in_at, a.completed_at, a.cancelled_at, a.created_at, {$checkoutSelect},
            {$visitTypeSelect}, {$purposeSelect}, {$subjectSelect}{$visitColumns}
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

$conn->close();

echo json_encode([
    "success" => true,
    "summary" => $summary,
    "visitors" => $rows,
]);
