<?php
header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store, max-age=0");

require_once __DIR__ . "/session_bootstrap.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/appointment_offices.php";
require_once __DIR__ . "/appointment_maintenance.php";
require_once __DIR__ . "/arrival_service.php";
require_once __DIR__ . "/personnel.php";
require_once __DIR__ . "/visit_people_service.php";

require_permission_json("office.dashboard");
refresh_appointment_time_states($conn);

$officeCode = strtoupper(trim((string) ($_SESSION["office_code"] ?? "")));
$officeMap = appointment_office_map();
if ($officeCode === "" || !isset($officeMap[$officeCode])) {
    $conn->close();
    http_response_code(403);
    echo json_encode(["success" => false, "message" => "This account has no valid office assignment."]);
    exit;
}
$userId = (int) $_SESSION["user_id"];
$profile = [
    "username" => (string) ($_SESSION["username"] ?? ""),
    "display_name" => (string) ($_SESSION["display_name"] ?? ""),
    "profile_image_url" => "",
];
$positionColumn = personnel_schema_ready($conn) ? ", position" : "";
$profileStmt = $conn->prepare(
    "SELECT username, display_name, profile_image{$positionColumn} FROM app_users WHERE id = ? LIMIT 1"
);
if ($profileStmt) {
    $profileStmt->bind_param("i", $userId);
    $profileStmt->execute();
    $profileRow = $profileStmt->get_result()->fetch_assoc();
    if ($profileRow) {
        $profile = [
            "username" => (string) $profileRow["username"],
            "display_name" => (string) $profileRow["display_name"],
            "position" => (string) ($profileRow["position"] ?? ""),
            "profile_image_url" => $profileRow["profile_image"] ? (string) $profileRow["profile_image"] : "",
        ];
        $_SESSION["display_name"] = $profile["display_name"];
    }
    $profileStmt->close();
}

$summary = [
    "pending_approvals" => 0,
    "approved" => 0,
    "rejected" => 0,
    "todays_appointments" => 0,
];
$summaryStmt = $conn->prepare(
    "SELECT
        SUM(status = 'pending_approval') AS pending_approvals,
        SUM(status = 'approved') AS approved,
        SUM(status = 'rejected') AS rejected,
        SUM(DATE(scheduled_start_at) = CURDATE()
            AND status IN ('pending_approval','reschedule_proposed','approved','checked_in','completed','window_closed')) AS todays_appointments
     FROM appointments WHERE office_code = ?"
);
if ($summaryStmt) {
    $summaryStmt->bind_param("s", $officeCode);
    $summaryStmt->execute();
    $row = $summaryStmt->get_result()->fetch_assoc() ?: [];
    foreach ($summary as $key => $value) {
        $summary[$key] = (int) ($row[$key] ?? 0);
    }
    $summaryStmt->close();
}

$recent = [];
$visitColumns = visit_list_columns($conn, "a");
$arrivalColumns = arrival_schema_ready($conn) ? ", a.arrived_at, a.arrival_method, a.arrival_accuracy_meters" : "";
$recentStmt = $conn->prepare(
    "SELECT a.id, a.registration_code, a.visitor_full_name, a.visitor_email,
            a.contact_number, a.visit_type, a.purpose, a.destination, a.subject,
            a.additional_details, a.scheduled_start_at, a.scheduled_end_at,
            a.status, a.rejection_reason, a.created_at, a.status_updated_at,
            COALESCE(NULLIF(processor.display_name, ''), processor.username, '') AS processed_by{$visitColumns}{$arrivalColumns}
     FROM appointments a
     LEFT JOIN app_users processor ON processor.id = COALESCE(
        a.approved_by_user_id, a.rejected_by_user_id, a.completed_by_user_id, a.cancelled_by_user_id
     )
     WHERE a.office_code = ?
     ORDER BY COALESCE(a.status_updated_at, a.created_at) DESC, a.id DESC
     LIMIT 12"
);
if ($recentStmt) {
    $recentStmt->bind_param("s", $officeCode);
    $recentStmt->execute();
    $result = $recentStmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $row["id"] = (int) $row["id"];
        $recent[] = $row;
    }
    $recentStmt->close();
}

// The people coming with each visitor (GROUP_VISITS.md).
$peopleByAppointment = visit_people_for_rows($conn, $recent);
foreach ($recent as $index => $row) {
    $recent[$index]["people"] = visit_people_payloads($peopleByAppointment[$row["id"]] ?? []);
}

$notifications = [];
$notificationStmt = $conn->prepare(
    "SELECT id, appointment_id, notification_type, title, message, data_json,
            read_at, created_at
     FROM app_notifications
     WHERE recipient_user_id = ?
     ORDER BY created_at DESC, id DESC LIMIT 8"
);
if ($notificationStmt) {
    $notificationStmt->bind_param("i", $userId);
    $notificationStmt->execute();
    $result = $notificationStmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $row["id"] = (int) $row["id"];
        $row["appointment_id"] = $row["appointment_id"] !== null ? (int) $row["appointment_id"] : null;
        $notifications[] = $row;
    }
    $notificationStmt->close();
}

$unread = 0;
$unreadStmt = $conn->prepare(
    "SELECT COUNT(*) AS total FROM app_notifications
     WHERE recipient_user_id = ? AND read_at IS NULL"
);
if ($unreadStmt) {
    $unreadStmt->bind_param("i", $userId);
    $unreadStmt->execute();
    $unread = (int) ($unreadStmt->get_result()->fetch_assoc()["total"] ?? 0);
    $unreadStmt->close();
}

$conn->close();
echo json_encode([
    "success" => true,
    "office" => ["code" => $officeCode, "label" => $officeMap[$officeCode]],
    "profile" => $profile,
    "summary" => $summary,
    "recent_appointments" => $recent,
    "notifications" => $notifications,
    "unread_notifications" => $unread,
]);
