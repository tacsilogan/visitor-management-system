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
require_permission_json("office.appointments");

$input = json_decode(file_get_contents("php://input"), true);
if (!is_array($input)) {
    echo json_encode(["success" => false, "message" => "Invalid request body"]);
    exit;
}
$appointmentId = (int) ($input["appointment_id"] ?? 0);
$reason = trim((string) ($input["reason"] ?? ""));
$message = trim((string) ($input["message"] ?? ""));
$rawSlots = isset($input["slots"]) && is_array($input["slots"]) ? $input["slots"] : [];
$officeCode = strtoupper(trim((string) ($_SESSION["office_code"] ?? "")));
$actorId = (int) $_SESSION["user_id"];

if ($appointmentId <= 0 || strlen($reason) < 5) {
    echo json_encode(["success" => false, "message" => "Add an appointment and a clear reason for changing the schedule"]);
    exit;
}
if (count($rawSlots) < 1 || count($rawSlots) > 3) {
    echo json_encode(["success" => false, "message" => "Offer between one and three alternative schedules"]);
    exit;
}
$reason = substr($reason, 0, 255);
$message = substr($message, 0, 1000);

try {
    $settings = office_availability_get_settings($conn, $officeCode);
    $defaultDuration = max(15, (int) $settings["slot_duration_minutes"]);
    $slots = [];
    $seenStarts = [];
    foreach ($rawSlots as $rawSlot) {
        $startRaw = is_array($rawSlot) ? (string) ($rawSlot["start"] ?? "") : (string) $rawSlot;
        $endRaw = is_array($rawSlot) ? (string) ($rawSlot["end"] ?? "") : "";
        $start = new DateTime($startRaw);
        $end = $endRaw !== "" ? new DateTime($endRaw) : (clone $start)->modify("+{$defaultDuration} minutes");
        $key = $start->format("Y-m-d H:i:s");
        if (isset($seenStarts[$key])) {
            throw new RuntimeException("Alternative schedules must be different");
        }
        $seenStarts[$key] = true;
        if ($start < new DateTime("+1 hour") || $end <= $start) {
            throw new RuntimeException("Every proposed schedule must begin at least one hour from now");
        }
        $availability = office_availability_check($conn, $officeCode, $start, $end, $appointmentId);
        if (!$availability["available"]) {
            throw new RuntimeException($start->format("M j, Y g:i A") . ": " . $availability["message"]);
        }
        $slots[] = [$start, $end];
    }

    usort($slots, function (array $left, array $right): int {
        return $left[0] <=> $right[0];
    });
    $deadline = clone $slots[0][0];
    $deadline->modify("-1 hour");
    $latestDeadline = new DateTime("+48 hours");
    if ($deadline > $latestDeadline) {
        $deadline = $latestDeadline;
    }
    if ($deadline <= new DateTime("now")) {
        $deadline = new DateTime("+30 minutes");
    }

    $conn->begin_transaction();
    $lookup = $conn->prepare(
        "SELECT id, visitor_user_id, visitor_full_name, status, scheduled_start_at, visit_type
         FROM appointments WHERE id = ? AND office_code = ? FOR UPDATE"
    );
    $lookup->bind_param("is", $appointmentId, $officeCode);
    $lookup->execute();
    $appointment = $lookup->get_result()->fetch_assoc();
    $lookup->close();
    if (!$appointment) {
        throw new RuntimeException("Appointment not found for this office");
    }
    // An approved appointment can also be moved when the office can no longer keep the
    // agreed time. Its QR pass is withdrawn until the visitor accepts a new time.
    $fromStatus = (string) $appointment["status"];
    if ($appointment["visit_type"] === "walk_in") {
        throw new RuntimeException("Walk-in passes cannot be rescheduled");
    }
    if (!in_array($fromStatus, ["pending_approval", "approved"], true)) {
        throw new RuntimeException("Only pending or approved appointments can be rescheduled");
    }

    $deadlineSql = $deadline->format("Y-m-d H:i:s");
    $proposal = $conn->prepare(
        "INSERT INTO appointment_reschedule_proposals
         (appointment_id, proposed_by_user_id, reason, message, response_deadline)
         VALUES (?, ?, ?, ?, ?)"
    );
    $proposal->bind_param("iisss", $appointmentId, $actorId, $reason, $message, $deadlineSql);
    if (!$proposal->execute()) {
        throw new RuntimeException("Could not save the proposed schedules");
    }
    $proposalId = (int) $conn->insert_id;
    $proposal->close();

    $slotInsert = $conn->prepare(
        "INSERT INTO appointment_reschedule_slots
         (proposal_id, scheduled_start_at, scheduled_end_at) VALUES (?, ?, ?)"
    );
    foreach ($slots as [$start, $end]) {
        $startSql = $start->format("Y-m-d H:i:s");
        $endSql = $end->format("Y-m-d H:i:s");
        $slotInsert->bind_param("iss", $proposalId, $startSql, $endSql);
        if (!$slotInsert->execute()) {
            throw new RuntimeException("Could not save an alternative schedule");
        }
    }
    $slotInsert->close();

    $update = $conn->prepare(
        "UPDATE appointments SET status = 'reschedule_proposed', status_updated_at = NOW()
         WHERE id = ? AND status = ?"
    );
    $update->bind_param("is", $appointmentId, $fromStatus);
    $update->execute();
    if ($update->affected_rows !== 1) {
        throw new RuntimeException("The appointment changed before the proposal was saved");
    }
    $update->close();

    $historyNote = "Office proposed " . count($slots) . " alternative schedule(s): " . $reason;
    $history = $conn->prepare(
        "INSERT INTO appointment_status_history
         (appointment_id, from_status, to_status, changed_by_user_id, note)
         VALUES (?, ?, 'reschedule_proposed', ?, ?)"
    );
    $history->bind_param("isis", $appointmentId, $fromStatus, $actorId, $historyNote);
    $history->execute();
    $history->close();

    $slotLabels = array_map(function (array $slot): string {
        return $slot[0]->format("M j, Y g:i A");
    }, $slots);
    $visitorUserId = (int) $appointment["visitor_user_id"];
    $suggestion = "The office suggested: " . implode(", ", $slotLabels) . ".";
    if ($fromStatus === "approved") {
        $originalTime = date("M j, Y g:i A", strtotime((string) $appointment["scheduled_start_at"]));
        $notificationTitle = "Your appointment time needs to change";
        $notificationMessage = "The office can no longer meet at {$originalTime}. {$suggestion}";
    } else {
        $notificationTitle = "Choose another appointment time";
        $notificationMessage = $suggestion;
    }
    $notificationMessage = trim($notificationMessage . " " . $message);
    $dataJson = json_encode(["proposal_id" => $proposalId, "response_deadline" => $deadlineSql]);
    $notification = $conn->prepare(
        "INSERT INTO app_notifications
         (recipient_user_id, appointment_id, notification_type, title, message, data_json)
         VALUES (?, ?, 'appointment.reschedule_proposed', ?, ?, ?)"
    );
    $notification->bind_param("iisss", $visitorUserId, $appointmentId, $notificationTitle, $notificationMessage, $dataJson);
    $notification->execute();
    $notification->close();

    $audit = $conn->prepare(
        "INSERT INTO audit_logs
         (actor_user_id, appointment_id, action, entity_type, entity_id, details_json, ip_address)
         VALUES (?, ?, 'appointment.reschedule_proposed', 'appointment', ?, ?, ?)"
    );
    if ($audit) {
        $entityId = (string) $appointmentId;
        $details = json_encode(["proposal_id" => $proposalId, "slot_count" => count($slots), "reason" => $reason]);
        $ipAddress = substr((string) ($_SERVER["REMOTE_ADDR"] ?? ""), 0, 45);
        $audit->bind_param("iisss", $actorId, $appointmentId, $entityId, $details, $ipAddress);
        $audit->execute();
        $audit->close();
    }

    $conn->commit();
    $conn->close();
    echo json_encode([
        "success" => true,
        "appointment_id" => $appointmentId,
        "proposal_id" => $proposalId,
        "status" => "reschedule_proposed",
        "response_deadline" => $deadlineSql,
        "message" => "Alternative schedules sent to the visitor",
    ]);
} catch (Throwable $error) {
    try { $conn->rollback(); } catch (Throwable $ignored) {}
    $conn->close();
    echo json_encode(["success" => false, "message" => $error->getMessage()]);
}
