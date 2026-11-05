<?php
declare(strict_types=1);
require_once __DIR__ . "/_bootstrap.php";
require_once dirname(__DIR__, 2) . "/presence_service.php";
require_once dirname(__DIR__, 2) . "/office_directions_service.php";

// "I can't find it" (INDOOR_DIRECTIONS.md): a checked-in visitor asks the office to come and
// meet them, and the office is told which step they stopped at.
// POST {appointment_id, step_number?}. appointment_id is the stop, step_number counts from 1.
// data.office_told is false when the office has no active staff account to tell.

const DIRECTION_HELP_REPEAT_SECONDS = 60;

api_require_method("POST");
$user = api_require_visitor();
$input = api_body();
$appointmentId = (int) ($input["appointment_id"] ?? 0);
if ($appointmentId <= 0) {
    api_fail("Select a valid appointment", 422);
}

$load = $conn->prepare("SELECT status, office_code, visitor_full_name FROM appointments WHERE id = ? AND visitor_user_id = ? LIMIT 1");
$visitorId = (int) $user["id"];
$load->bind_param("ii", $appointmentId, $visitorId);
$load->execute();
$stop = $load->get_result()->fetch_assoc();
$load->close();
if (!$stop) {
    api_fail("Appointment not found", 404);
}
if ($stop["status"] !== "checked_in") {
    api_fail("You can ask the office for help once the guard has checked you in", 409);
}

$officeCode = (string) $stop["office_code"];
$officeLabel = appointment_office_label($officeCode);

// A second tap, or a retry after a lost reply, does not tell the office twice.
$recent = $conn->prepare(
    "SELECT COUNT(*) FROM app_notifications
     WHERE appointment_id = ? AND notification_type = 'visitor_needs_help'
       AND created_at > NOW() - INTERVAL " . DIRECTION_HELP_REPEAT_SECONDS . " SECOND"
);
$recent->bind_param("i", $appointmentId);
$recent->execute();
$alreadyTold = (int) $recent->get_result()->fetch_row()[0] > 0;
$recent->close();
if ($alreadyTold) {
    api_success(["appointment_id" => $appointmentId, "office_told" => true, "already_told" => true]);
}

$staff = $conn->prepare("SELECT COUNT(*) FROM app_users WHERE role = 'offices' AND office_code = ? AND is_active = 1");
$staff->bind_param("s", $officeCode);
$staff->execute();
$hasStaff = (int) $staff->get_result()->fetch_row()[0] > 0;
$staff->close();

$steps = office_directions_load($conn, $officeCode)[$officeCode]["steps"] ?? [];
$stepNumber = isset($input["step_number"]) && is_numeric($input["step_number"]) ? (int) $input["step_number"] : 0;
$stepText = $steps[$stepNumber - 1]["text"] ?? "";
$visitorName = trim((string) $stop["visitor_full_name"]) ?: "A visitor";

$conn->begin_transaction();
try {
    if ($hasStaff) {
        visit_notify_office(
            $conn,
            $appointmentId,
            $officeCode,
            "visitor_needs_help",
            "{$visitorName} can't find your office",
            $stepText !== ""
                ? "They stopped at step {$stepNumber}: {$stepText} Someone should go and meet them."
                : "They are near the building. Someone should go and meet them."
        );
    }
    presence_audit($conn, $visitorId, $appointmentId, "visit.help_requested", [
        "office_code" => $officeCode,
        "step" => $stepText !== "" ? $stepNumber : null,
        "office_told" => $hasStaff,
    ]);
    $conn->commit();
} catch (Throwable $error) {
    $conn->rollback();
    api_fail("Could not reach the office. Please try again.", 500, [], $error);
}

api_success(["appointment_id" => $appointmentId, "office_told" => $hasStaff, "already_told" => false]);
