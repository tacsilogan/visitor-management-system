<?php
header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/session_bootstrap.php";
require_once __DIR__ . "/appointment_offices.php";
require_once __DIR__ . "/appointment_maintenance.php";
require_once __DIR__ . "/personnel.php";

require_permission_json("dashboard.admin");
refresh_appointment_time_states($conn);

/**
 * This lets the dashboard use optional fields later without requiring the
 * front end to change when purpose, subject, or visit_type are added.
 */
function appointment_column_exists(mysqli $conn, string $column): bool
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

$summary = [
    "active_visitors" => 0,
    "pending_approvals" => 0,
    "recent_activities" => 0,
];

$summaryResult = $conn->query(
    "SELECT
        SUM(status = 'checked_in') AS active_visitors,
        SUM(status = 'pending_approval') AS pending_approvals
     FROM appointments"
);
if ($summaryResult) {
    $row = $summaryResult->fetch_assoc();
    $summary["active_visitors"] = (int) ($row["active_visitors"] ?? 0);
    $summary["pending_approvals"] = (int) ($row["pending_approvals"] ?? 0);
}

$activityCountResult = $conn->query(
    "SELECT COUNT(*) AS total
     FROM appointment_status_history
     WHERE changed_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"
);
if ($activityCountResult) {
    $row = $activityCountResult->fetch_assoc();
    $summary["recent_activities"] = (int) ($row["total"] ?? 0);
}

$visitTypeSelect = appointment_column_exists($conn, "visit_type")
    ? "a.visit_type"
    : "'Appointment' AS visit_type";
$purposeSelect = appointment_column_exists($conn, "purpose")
    ? "a.purpose"
    : "NULL AS purpose";
$subjectSelect = appointment_column_exists($conn, "subject")
    ? "a.subject"
    : "NULL AS subject";

// Who handled each appointment, by real name when it is on file.
$personnelReady = personnel_schema_ready($conn);
$handledBy = [];
foreach (["ap" => "approved_by_name", "rj" => "rejected_by_name", "ci" => "checked_in_by_name", "co" => "completed_by_name"] as $alias => $column) {
    $display = "COALESCE(NULLIF({$alias}.display_name, ''), {$alias}.username)";
    $handledBy[] = ($personnelReady
        ? "COALESCE(NULLIF(TRIM(CONCAT_WS(' ', {$alias}.first_name, {$alias}.last_name)), ''), {$display})"
        : $display) . " AS " . $column;
}
$handledBySelect = implode(", ", $handledBy);
$visitorSql =
    "SELECT a.id, a.visitor_full_name, a.visitor_email, a.office_code,
            a.appointment_at, a.checked_in_at, a.completed_at, a.cancelled_at,
            a.status, a.status_updated_at, a.created_at,
            {$visitTypeSelect}, {$purposeSelect}, {$subjectSelect},
            COALESCE(a.status_updated_at, a.appointment_at, a.created_at) AS activity_at,
            {$handledBySelect}
     FROM appointments a
     LEFT JOIN app_users ap ON ap.id = a.approved_by_user_id
     LEFT JOIN app_users rj ON rj.id = a.rejected_by_user_id
     LEFT JOIN app_users ci ON ci.id = a.checked_in_by_user_id
     LEFT JOIN app_users co ON co.id = a.completed_by_user_id
     ORDER BY COALESCE(a.status_updated_at, a.created_at) DESC, a.id DESC
     LIMIT 20";

$recentVisitors = [];
$visitorResult = $conn->query($visitorSql);
$officeMap = appointment_office_map();
if ($visitorResult) {
    while ($row = $visitorResult->fetch_assoc()) {
        $officeCode = (string) ($row["office_code"] ?? "");
        $row["id"] = (int) $row["id"];
        $row["office_label"] = $officeMap[$officeCode] ?? $officeCode;
        $recentVisitors[] = $row;
    }
}

$recentActivities = [];
$activityResult = $conn->query(
    "SELECT h.id, h.appointment_id, h.from_status, h.to_status, h.changed_at, h.note,
            a.visitor_full_name, a.office_code,
            COALESCE(NULLIF(u.display_name, ''), u.username, '') AS changed_by_name
     FROM appointment_status_history h
     INNER JOIN appointments a ON a.id = h.appointment_id
     LEFT JOIN app_users u ON u.id = h.changed_by_user_id
     ORDER BY h.changed_at DESC, h.id DESC
     LIMIT 10"
);
if ($activityResult) {
    while ($row = $activityResult->fetch_assoc()) {
        $row["id"] = (int) $row["id"];
        $row["appointment_id"] = (int) $row["appointment_id"];
        $recentActivities[] = $row;
    }
}

$conn->close();

echo json_encode([
    "success" => true,
    "summary" => $summary,
    "recent_visitors" => $recentVisitors,
    "recent_activities" => $recentActivities,
]);
