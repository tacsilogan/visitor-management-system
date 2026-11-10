<?php
header("Content-Type: application/json; charset=utf-8");
require_once __DIR__ . "/session_bootstrap.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/office_availability_service.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode(["success" => false, "message" => "Method not allowed"]);
    exit;
}
require_permission_json("appointments.book");
$input = json_decode(file_get_contents("php://input"), true);
if (!is_array($input)) {
    echo json_encode(["success" => false, "message" => "Invalid request body"]);
    exit;
}

$proposalId = (int) ($input["proposal_id"] ?? 0);
$action = strtolower(trim((string) ($input["action"] ?? "")));
$selectedSlotId = (int) ($input["selected_slot_id"] ?? 0);
$visitorUserId = (int) $_SESSION["user_id"];
if ($proposalId <= 0 || !in_array($action, ["accept", "decline"], true)) {
    echo json_encode(["success" => false, "message" => "Invalid reschedule response"]);
    exit;
}
if ($action === "accept" && $selectedSlotId <= 0) {
    echo json_encode(["success" => false, "message" => "Choose one of the proposed schedules"]);
    exit;
}

$conn->begin_transaction();
try {
    $lookup = $conn->prepare(
        "SELECT p.id, p.appointment_id, p.proposed_by_user_id, p.status AS proposal_status, p.response_deadline,
                a.office_code, a.status AS appointment_status, a.visitor_full_name
         FROM appointment_reschedule_proposals p
         INNER JOIN appointments a ON a.id = p.appointment_id
         WHERE p.id = ? AND a.visitor_user_id = ? FOR UPDATE"
    );
    $lookup->bind_param("ii", $proposalId, $visitorUserId);
    $lookup->execute();
    $proposal = $lookup->get_result()->fetch_assoc();
    $lookup->close();
    if (!$proposal || $proposal["proposal_status"] !== "pending" || $proposal["appointment_status"] !== "reschedule_proposed") {
        throw new RuntimeException("This schedule proposal is no longer available");
    }
    if (!empty($proposal["response_deadline"]) && strtotime((string) $proposal["response_deadline"]) < time()) {
        throw new RuntimeException("The response deadline for this proposal has passed");
    }

    $appointmentId = (int) $proposal["appointment_id"];
    $officeCode = (string) $proposal["office_code"];
    if ($action === "accept") {
        $slotStmt = $conn->prepare(
            "SELECT id, scheduled_start_at, scheduled_end_at
             FROM appointment_reschedule_slots WHERE id = ? AND proposal_id = ? LIMIT 1"
        );
        $slotStmt->bind_param("ii", $selectedSlotId, $proposalId);
        $slotStmt->execute();
        $slot = $slotStmt->get_result()->fetch_assoc();
        $slotStmt->close();
        if (!$slot) {
            throw new RuntimeException("The selected schedule is not part of this proposal");
        }
        $start = new DateTime((string) $slot["scheduled_start_at"]);
        $end = new DateTime((string) $slot["scheduled_end_at"]);
        if ($start <= new DateTime("now")) {
            throw new RuntimeException("The selected schedule has already passed");
        }
        $availability = office_availability_check($conn, $officeCode, $start, $end, $appointmentId);
        if (!$availability["available"]) {
            throw new RuntimeException($availability["message"] . " Ask the office for another schedule.");
        }

        $startSql = $start->format("Y-m-d H:i:s");
        $endSql = $end->format("Y-m-d H:i:s");
        $approvedByUserId = (int) $proposal["proposed_by_user_id"];
        $updateAppointment = $conn->prepare(
            "UPDATE appointments
             SET appointment_at = ?, scheduled_start_at = ?, scheduled_end_at = ?,
                 status = 'approved', status_updated_at = NOW(), approved_at = NOW(),
                 approved_by_user_id = ?, qr_issued_at = NOW(), rejection_reason = ''
             WHERE id = ? AND status = 'reschedule_proposed'"
        );
        $updateAppointment->bind_param("sssii", $startSql, $startSql, $endSql, $approvedByUserId, $appointmentId);
        $updateAppointment->execute();
        if ($updateAppointment->affected_rows !== 1) {
            throw new RuntimeException("The appointment changed before your response was saved");
        }
        $updateAppointment->close();

        $proposalUpdate = $conn->prepare(
            "UPDATE appointment_reschedule_proposals SET status = 'accepted', responded_at = NOW()
             WHERE id = ? AND status = 'pending'"
        );
        $proposalUpdate->bind_param("i", $proposalId);
        $proposalUpdate->execute();
        $proposalUpdate->close();
        $slotUpdate = $conn->prepare(
            "UPDATE appointment_reschedule_slots SET is_selected = (id = ?) WHERE proposal_id = ?"
        );
        $slotUpdate->bind_param("ii", $selectedSlotId, $proposalId);
        $slotUpdate->execute();
        $slotUpdate->close();
        $toStatus = "approved";
        $historyNote = "Visitor accepted the proposed schedule for " . $start->format("M j, Y g:i A");
        $officeNotificationTitle = "Proposed schedule accepted";
        $officeNotificationMessage = $proposal["visitor_full_name"] . " accepted " . $start->format("M j, Y g:i A") . ".";
    } else {
        $proposalUpdate = $conn->prepare(
            "UPDATE appointment_reschedule_proposals SET status = 'declined', responded_at = NOW()
             WHERE id = ? AND status = 'pending'"
        );
        $proposalUpdate->bind_param("i", $proposalId);
        $proposalUpdate->execute();
        $proposalUpdate->close();
        $updateAppointment = $conn->prepare(
            "UPDATE appointments
             SET status = 'cancelled', status_updated_at = NOW(), cancelled_at = NOW(),
                 cancelled_by_user_id = ?
             WHERE id = ? AND status = 'reschedule_proposed'"
        );
        $updateAppointment->bind_param("ii", $visitorUserId, $appointmentId);
        $updateAppointment->execute();
        $updateAppointment->close();
        $toStatus = "cancelled";
        $historyNote = "Visitor declined the proposed schedules";
        $officeNotificationTitle = "Proposed schedule declined";
        $officeNotificationMessage = $proposal["visitor_full_name"] . " declined the alternative schedules.";
    }

    $history = $conn->prepare(
        "INSERT INTO appointment_status_history
         (appointment_id, from_status, to_status, changed_by_user_id, note)
         VALUES (?, 'reschedule_proposed', ?, ?, ?)"
    );
    $history->bind_param("isis", $appointmentId, $toStatus, $visitorUserId, $historyNote);
    $history->execute();
    $history->close();

    $notifyOffice = $conn->prepare(
        "INSERT INTO app_notifications
         (recipient_user_id, appointment_id, notification_type, title, message)
         SELECT id, ?, 'appointment.reschedule_response', ?, ?
         FROM app_users WHERE role = 'offices' AND office_code = ? AND is_active = 1"
    );
    $notifyOffice->bind_param("isss", $appointmentId, $officeNotificationTitle, $officeNotificationMessage, $officeCode);
    $notifyOffice->execute();
    $notifyOffice->close();

    $audit = $conn->prepare(
        "INSERT INTO audit_logs
         (actor_user_id, appointment_id, action, entity_type, entity_id, details_json, ip_address)
         VALUES (?, ?, 'appointment.reschedule_response', 'appointment', ?, ?, ?)"
    );
    if ($audit) {
        $entityId = (string) $appointmentId;
        $details = json_encode(["proposal_id" => $proposalId, "response" => $action, "selected_slot_id" => $selectedSlotId ?: null]);
        $ipAddress = substr((string) ($_SERVER["REMOTE_ADDR"] ?? ""), 0, 45);
        $audit->bind_param("iisss", $visitorUserId, $appointmentId, $entityId, $details, $ipAddress);
        $audit->execute();
        $audit->close();
    }

    $conn->commit();
    $conn->close();
    echo json_encode([
        "success" => true,
        "appointment_id" => $appointmentId,
        "status" => $toStatus,
        "message" => $action === "accept" ? "Schedule accepted. Your QR pass is now available." : "Proposed schedules declined.",
    ]);
} catch (Throwable $error) {
    $conn->rollback();
    $conn->close();
    echo json_encode(["success" => false, "message" => $error->getMessage()]);
}
