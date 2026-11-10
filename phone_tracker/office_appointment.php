<?php
header("Content-Type: application/json; charset=utf-8");
require_once __DIR__ . "/session_bootstrap.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/visit_service.php";
require_once __DIR__ . "/arrival_service.php";

require_permission_json("office.appointments");
$appointmentId = isset($_GET["id"]) ? (int) $_GET["id"] : 0;
$officeCode = strtoupper(trim((string) ($_SESSION["office_code"] ?? "")));
if ($appointmentId <= 0 || $officeCode === "") {
    echo json_encode(["success" => false, "message" => "Invalid appointment"]);
    exit;
}

$visitColumns = visit_appointment_columns($conn, "a");
$arrivalColumns = arrival_schema_ready($conn) ? ", a.arrived_at, a.arrival_method, a.arrival_accuracy_meters" : "";
$stmt = $conn->prepare(
    "SELECT a.id, a.registration_code, a.visitor_full_name, a.visitor_email,
            a.contact_number, a.visit_type, a.purpose, a.destination, a.subject,
            a.additional_details, a.scheduled_start_at, a.scheduled_end_at,
            a.status, a.rejection_reason, a.created_at, a.status_updated_at,
            a.approved_at, a.rejected_at, a.checked_in_at, a.completed_at,
            COALESCE(NULLIF(processor.display_name, ''), processor.username, '') AS processed_by{$visitColumns}{$arrivalColumns}
     FROM appointments a
     LEFT JOIN app_users processor ON processor.id = COALESCE(
         a.approved_by_user_id, a.rejected_by_user_id, a.completed_by_user_id, a.cancelled_by_user_id
     )
     WHERE a.id = ? AND a.office_code = ? LIMIT 1"
);
if (!$stmt) {
    echo json_encode(["success" => false, "message" => "Database update required. Run the Phase 1 migration."]);
    exit;
}
$stmt->bind_param("is", $appointmentId, $officeCode);
$stmt->execute();
$appointment = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$appointment) {
    $conn->close();
    http_response_code(404);
    echo json_encode(["success" => false, "message" => "Appointment not found for this office"]);
    exit;
}
$appointment["id"] = (int) $appointment["id"];

// For a stop of a multi-stop visit, other offices' stops are shown only as busy times,
// so this office can suggest a time that fits without seeing the other requests.
$visitContext = null;
if (!empty($appointment["visit_id"])) {
    $visit = visit_load($conn, (int) $appointment["visit_id"]);
    if ($visit) {
        $stops = visit_stops($conn, (int) $visit["id"]);
        $otherStopTimes = [];
        foreach ($stops as $stop) {
            if ((int) $stop["id"] !== $appointmentId && in_array($stop["status"], VISIT_ACTIVE_STOP_STATUSES, true)) {
                $otherStopTimes[] = [
                    "scheduled_start_at" => $stop["scheduled_start_at"],
                    "scheduled_end_at" => $stop["scheduled_end_at"],
                ];
            }
        }
        $visitContext = [
            "visit_code" => $visit["visit_code"],
            "visit_date" => $visit["visit_date"],
            "status" => $visit["status"],
            "stop_number" => (int) $appointment["stop_number"],
            "stop_count" => count($stops),
            "other_stop_times" => $otherStopTimes,
        ];
    }
}

$history = [];
$historyStmt = $conn->prepare(
    "SELECT h.from_status, h.to_status, h.note, h.changed_at,
            COALESCE(NULLIF(u.display_name, ''), u.username, 'System') AS changed_by
     FROM appointment_status_history h
     LEFT JOIN app_users u ON u.id = h.changed_by_user_id
     WHERE h.appointment_id = ? ORDER BY h.changed_at DESC, h.id DESC"
);
if ($historyStmt) {
    $historyStmt->bind_param("i", $appointmentId);
    $historyStmt->execute();
    $result = $historyStmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $history[] = $row;
    }
    $historyStmt->close();
}

$proposal = null;
$proposalStmt = $conn->prepare(
    "SELECT id, reason, message, status, response_deadline, responded_at, created_at
     FROM appointment_reschedule_proposals
     WHERE appointment_id = ? ORDER BY id DESC LIMIT 1"
);
if ($proposalStmt) {
    $proposalStmt->bind_param("i", $appointmentId);
    $proposalStmt->execute();
    $proposal = $proposalStmt->get_result()->fetch_assoc();
    $proposalStmt->close();
    if ($proposal) {
        $proposal["id"] = (int) $proposal["id"];
        $proposal["slots"] = [];
        $slotStmt = $conn->prepare(
            "SELECT id, scheduled_start_at, scheduled_end_at, is_selected
             FROM appointment_reschedule_slots WHERE proposal_id = ?
             ORDER BY scheduled_start_at ASC"
        );
        if ($slotStmt) {
            $slotStmt->bind_param("i", $proposal["id"]);
            $slotStmt->execute();
            $result = $slotStmt->get_result();
            while ($slot = $result->fetch_assoc()) {
                $slot["id"] = (int) $slot["id"];
                $slot["is_selected"] = (int) $slot["is_selected"];
                $proposal["slots"][] = $slot;
            }
            $slotStmt->close();
        }
    }
}

$conn->close();
echo json_encode([
    "success" => true,
    "appointment" => $appointment,
    "history" => $history,
    "reschedule_proposal" => $proposal,
    "visit" => $visitContext,
]);

