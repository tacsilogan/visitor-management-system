<?php
header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store, max-age=0");
require_once __DIR__ . "/session_bootstrap.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/appointment_offices.php";
require_once __DIR__ . "/appointment_maintenance.php";

require_permission_json("appointments.list");
refresh_appointment_time_states($conn);

$visitColumns = visit_list_columns($conn, "a");
$sql =
    "SELECT a.id, a.registration_code, a.public_token, a.office_code,
            a.visitor_full_name, a.visitor_email, a.contact_number, a.device_name,
            a.visit_type, a.purpose, a.destination, a.subject, a.additional_details,
            a.appointment_at, a.scheduled_start_at, a.scheduled_end_at, a.status,
            a.approved_at, a.rejected_at, a.rejection_reason,
            a.checked_in_at, a.completed_at, a.cancelled_at, a.status_updated_at, a.created_at,
            u.username AS visitor_username,
            CASE
                WHEN a.status = 'approved' THEN COALESCE(NULLIF(approved_user.display_name, ''), approved_user.username, '')
                WHEN a.status = 'rejected' THEN COALESCE(NULLIF(rejected_user.display_name, ''), rejected_user.username, '')
                WHEN a.status = 'completed' THEN COALESCE(NULLIF(completed_user.display_name, ''), completed_user.username, 'System')
                WHEN a.status = 'cancelled' THEN COALESCE(NULLIF(cancelled_user.display_name, ''), cancelled_user.username, '')
                ELSE ''
            END AS processed_by,
            CASE
                WHEN a.status = 'approved' THEN a.approved_at
                WHEN a.status = 'rejected' THEN a.rejected_at
                WHEN a.status = 'completed' THEN a.completed_at
                WHEN a.status = 'cancelled' THEN a.cancelled_at
                ELSE a.status_updated_at
            END AS processed_at{$visitColumns}
     FROM appointments a
     LEFT JOIN app_users u ON u.id = a.visitor_user_id
     LEFT JOIN app_users approved_user ON approved_user.id = a.approved_by_user_id
     LEFT JOIN app_users rejected_user ON rejected_user.id = a.rejected_by_user_id
     LEFT JOIN app_users completed_user ON completed_user.id = a.completed_by_user_id
     LEFT JOIN app_users cancelled_user ON cancelled_user.id = a.cancelled_by_user_id";

$isOfficeUser = $_SESSION["role"] === "offices";
$officeCode = isset($_SESSION["office_code"]) ? (string) $_SESSION["office_code"] : "";
if ($isOfficeUser) {
    $sql .= " WHERE a.office_code = ?";
}
$sql .= " ORDER BY a.scheduled_start_at DESC, a.id DESC";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    $conn->close();
    echo json_encode(["success" => false, "message" => "Database update required. Run phase1_workflow_migration.sql."]);
    exit;
}
if ($isOfficeUser) {
    if ($officeCode === "") {
        $stmt->close();
        $conn->close();
        echo json_encode(["success" => false, "message" => "This office account has no assigned office"]);
        exit;
    }
    $stmt->bind_param("s", $officeCode);
}
$stmt->execute();
$result = $stmt->get_result();
$rows = [];
$map = appointment_office_map();
while ($row = $result->fetch_assoc()) {
    $code = (string) $row["office_code"];
    $row["id"] = (int) $row["id"];
    $row["office_label"] = $map[$code] ?? $code;
    $row["visit_id"] = $row["visit_id"] !== null ? (int) $row["visit_id"] : null;
    $row["stop_number"] = $row["stop_number"] !== null ? (int) $row["stop_number"] : null;
    $row["visit_stop_count"] = $row["visit_stop_count"] !== null ? (int) $row["visit_stop_count"] : null;
    $rows[] = $row;
}
$stmt->close();
$conn->close();

echo json_encode(["success" => true, "data" => $rows]);
