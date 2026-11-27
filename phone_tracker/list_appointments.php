<?php
header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store, max-age=0");
require_once __DIR__ . "/session_bootstrap.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/appointment_offices.php";
require_once __DIR__ . "/appointment_maintenance.php";
require_once __DIR__ . "/arrival_service.php";
require_once __DIR__ . "/gate_service.php";
require_once __DIR__ . "/visit_people_service.php";

require_permission_json("appointments.list");
refresh_appointment_time_states($conn);

$visitColumns = visit_list_columns($conn, "a");
// For the Office "Today" page (OFFICE_MOBILE.md): who has reached the office, how a visit
// ended, and visitors registered at the gate.
if (arrival_schema_ready($conn)) {
    $visitColumns .= ", a.arrived_at, a.arrival_method, a.arrival_accuracy_meters";
}
if (gate_schema_ready($conn)) {
    $visitColumns .= ", a.registration_source";
}
$checkoutColumn = $conn->query("SHOW COLUMNS FROM appointments LIKE 'checkout_method'");
if ($checkoutColumn && $checkoutColumn->num_rows > 0) {
    $visitColumns .= ", a.checkout_method";
}
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

// "Waiting for visitor": the times this office offered, when, and until when the visitor can choose.
if ($isOfficeUser) {
    $waiting = [];
    foreach ($rows as $index => $row) {
        if ($row["status"] === "reschedule_proposed") {
            $waiting[(int) $row["id"]] = $index;
        }
    }
    if ($waiting) {
        $ids = implode(",", array_keys($waiting));
        $offers = $conn->query(
            "SELECT p.id AS proposal_id, p.appointment_id, p.created_at, p.response_deadline, s.scheduled_start_at
             FROM appointment_reschedule_proposals p
             JOIN appointment_reschedule_slots s ON s.proposal_id = p.id
             WHERE p.appointment_id IN ({$ids}) AND p.status = 'pending'
             ORDER BY p.id DESC, s.scheduled_start_at ASC"
        );
        $latest = [];
        while ($offers && ($offer = $offers->fetch_assoc())) {
            $appointmentId = (int) $offer["appointment_id"];
            $latest[$appointmentId] = $latest[$appointmentId] ?? (int) $offer["proposal_id"];
            if ($latest[$appointmentId] !== (int) $offer["proposal_id"]) {
                continue;
            }
            $index = $waiting[$appointmentId];
            $rows[$index]["offer"] = $rows[$index]["offer"] ?? [
                "sent_at" => $offer["created_at"],
                "response_deadline" => $offer["response_deadline"],
                "times" => [],
            ];
            $rows[$index]["offer"]["times"][] = $offer["scheduled_start_at"];
        }
    }
}
// The people coming with each visitor (GROUP_VISITS.md).
$peopleByAppointment = visit_people_for_rows($conn, $rows);
foreach ($rows as $index => $row) {
    $rows[$index]["people"] = visit_people_payloads($peopleByAppointment[$row["id"]] ?? []);
}
$conn->close();

echo json_encode(["success" => true, "data" => $rows]);
