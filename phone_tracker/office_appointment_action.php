<?php
header("Content-Type: application/json; charset=utf-8");
require_once __DIR__ . "/session_bootstrap.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/office_availability_service.php";
require_once __DIR__ . "/visit_service.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode(["success" => false, "message" => "Method not allowed"]);
    exit;
}
require_permission_json("office.appointments");

$input = json_decode(file_get_contents("php://input"), true);
if (!is_array($input)) {
    echo json_encode(["success" => false, "message" => "Invalid request body"]);
    exit;
}
$appointmentId = (int) ($input["appointment_id"] ?? 0);
$action = strtolower(trim((string) ($input["action"] ?? "")));
$reason = trim((string) ($input["reason"] ?? ""));
$officeCode = strtoupper(trim((string) ($_SESSION["office_code"] ?? "")));
$actorId = (int) $_SESSION["user_id"];

if ($appointmentId <= 0 || !in_array($action, ["approve", "reject", "complete"], true)) {
    echo json_encode(["success" => false, "message" => "Invalid appointment action"]);
    exit;
}
if ($action === "reject" && strlen($reason) < 5) {
    echo json_encode(["success" => false, "message" => "Explain why the appointment is being declined"]);
    exit;
}
$reason = substr($reason, 0, 500);

$conn->begin_transaction();
try {
    $visitColumns = visit_appointment_columns($conn);
    $lookup = $conn->prepare(
        "SELECT id, office_code, visitor_user_id, visitor_full_name, status,
                scheduled_start_at, scheduled_end_at{$visitColumns}
         FROM appointments WHERE id = ? AND office_code = ? FOR UPDATE"
    );
    if (!$lookup) {
        throw new RuntimeException("Database update required");
    }
    $lookup->bind_param("is", $appointmentId, $officeCode);
    $lookup->execute();
    $appointment = $lookup->get_result()->fetch_assoc();
    $lookup->close();
    if (!$appointment) {
        throw new RuntimeException("Appointment not found for this office");
    }
    $isVisitStop = !empty($appointment["visit_id"]);
    $officeLabel = appointment_office_label($officeCode);
    if ($action === "complete") {
        if ($appointment["status"] !== "checked_in" || !$isVisitStop) {
            throw new RuntimeException("Only a checked-in stop of a multi-stop visit can be marked done");
        }
    } elseif ($appointment["status"] !== "pending_approval") {
        throw new RuntimeException("Only pending appointment requests can be processed");
    }

    $fromStatus = (string) $appointment["status"];
    if ($action === "approve") {
        $start = new DateTime((string) $appointment["scheduled_start_at"]);
        $end = new DateTime((string) $appointment["scheduled_end_at"]);
        if ($end <= new DateTime("now")) {
            throw new RuntimeException("This appointment time has already ended");
        }
        $availability = office_availability_check($conn, $officeCode, $start, $end, $appointmentId);
        if (!$availability["available"]) {
            throw new RuntimeException($availability["message"] . " Suggest another schedule instead.");
        }
        // A stop approved while its multi-stop visit is on campus joins that check-in.
        $toStatus = visit_status_for_approval($conn, $appointmentId);
        $update = $conn->prepare(
            "UPDATE appointments
             SET status = ?, status_updated_at = NOW(), approved_at = NOW(),
                 approved_by_user_id = ?, qr_issued_at = NOW(), rejection_reason = '',
                 checked_in_at = IF(? = 'checked_in', NOW(), checked_in_at)
             WHERE id = ? AND status = 'pending_approval'"
        );
        $update->bind_param("sisi", $toStatus, $actorId, $toStatus, $appointmentId);
        $historyNote = "Appointment approved by office personnel";
        $scheduleText = date("M j, Y g:i A", strtotime((string) $appointment["scheduled_start_at"]));
        $notificationTitle = "Appointment approved";
        if ($toStatus === "checked_in") {
            $historyNote .= "; added to the visitor's active campus visit";
            $notificationMessage = "Your {$officeLabel} stop at {$scheduleText} was approved and added to your active campus visit.";
        } elseif ($isVisitStop) {
            $notificationMessage = "Your {$officeLabel} stop at {$scheduleText} was approved. It is now part of your visit pass.";
        } else {
            $notificationMessage = "Your appointment for {$scheduleText} was approved. Your QR visitor pass is now available.";
        }
    } elseif ($action === "reject") {
        $toStatus = "rejected";
        $update = $conn->prepare(
            "UPDATE appointments
             SET status = 'rejected', status_updated_at = NOW(), rejected_at = NOW(),
                 rejected_by_user_id = ?, rejection_reason = ?
             WHERE id = ? AND status = 'pending_approval'"
        );
        $update->bind_param("isi", $actorId, $reason, $appointmentId);
        $historyNote = "Declined: " . $reason;
        $notificationTitle = "Appointment declined";
        $notificationMessage = "The office declined your appointment. Reason: " . $reason;
    } else {
        // The office's meeting is over. The visit itself, and its GPS tracking, continue
        // until Security checks the visitor out or the visit's last stop ends.
        $toStatus = "completed";
        $update = $conn->prepare(
            "UPDATE appointments
             SET status = 'completed', status_updated_at = NOW(), completed_at = NOW(), completed_by_user_id = ?
             WHERE id = ? AND status = 'checked_in'"
        );
        $update->bind_param("ii", $actorId, $appointmentId);
        $historyNote = "Meeting marked done by office personnel";
        $notificationTitle = "Office stop completed";
        $notificationMessage = "{$officeLabel} marked your meeting as done.";
    }

    if (!$update || !$update->execute() || $update->affected_rows !== 1) {
        throw new RuntimeException("The appointment changed before this action was completed");
    }
    $update->close();

    $history = $conn->prepare(
        "INSERT INTO appointment_status_history
         (appointment_id, from_status, to_status, changed_by_user_id, note)
         VALUES (?, ?, ?, ?, ?)"
    );
    $history->bind_param("issis", $appointmentId, $fromStatus, $toStatus, $actorId, $historyNote);
    $history->execute();
    $history->close();

    $visitorUserId = (int) $appointment["visitor_user_id"];
    $notificationType = $action === "complete" ? "appointment.stop_completed" : "appointment." . $toStatus;
    $notification = $conn->prepare(
        "INSERT INTO app_notifications
         (recipient_user_id, appointment_id, notification_type, title, message)
         VALUES (?, ?, ?, ?, ?)"
    );
    $notification->bind_param("iisss", $visitorUserId, $appointmentId, $notificationType, $notificationTitle, $notificationMessage);
    $notification->execute();
    $notification->close();

    $audit = $conn->prepare(
        "INSERT INTO audit_logs
         (actor_user_id, appointment_id, action, entity_type, entity_id, details_json, ip_address)
         VALUES (?, ?, ?, 'appointment', ?, ?, ?)"
    );
    if ($audit) {
        $auditAction = $action === "complete" ? "appointment.stop_completed" : "appointment." . $toStatus;
        $entityId = (string) $appointmentId;
        $details = json_encode(["from_status" => $fromStatus, "to_status" => $toStatus, "reason" => $reason]);
        $ipAddress = substr((string) ($_SERVER["REMOTE_ADDR"] ?? ""), 0, 45);
        $audit->bind_param("iissss", $actorId, $appointmentId, $auditAction, $entityId, $details, $ipAddress);
        $audit->execute();
        $audit->close();
    }

    $conn->commit();
    $conn->close();
    $messages = [
        "approve" => $toStatus === "checked_in" ? "Appointment approved and added to the visitor's active visit" : "Appointment approved",
        "reject" => "Appointment declined",
        "complete" => "Meeting marked as done",
    ];
    echo json_encode([
        "success" => true,
        "appointment_id" => $appointmentId,
        "status" => $toStatus,
        "message" => $messages[$action],
    ]);
} catch (Throwable $error) {
    $conn->rollback();
    $conn->close();
    echo json_encode(["success" => false, "message" => $error->getMessage()]);
}
