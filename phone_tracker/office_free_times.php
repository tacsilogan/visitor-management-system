<?php
// The free times an office can offer with "Other time" (OFFICE_MOBILE.md): the next few days
// with bookable slots, starting an hour from now (office_propose_reschedule.php refuses
// anything sooner), without the times that clash with the visitor's other visits.
header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store, max-age=0");
require_once __DIR__ . "/session_bootstrap.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/office_availability_service.php";
require_once __DIR__ . "/visit_service.php";

require_permission_json("office.appointments");

const FREE_TIMES_DAYS = 3;
const FREE_TIMES_LOOK_AHEAD_DAYS = 21;

$officeCode = strtoupper(trim((string) ($_SESSION["office_code"] ?? "")));
$appointmentId = (int) ($_GET["appointment_id"] ?? 0);
if ($officeCode === "" || $appointmentId <= 0) {
    http_response_code(422);
    echo json_encode(["success" => false, "message" => "Choose an appointment first."]);
    exit;
}

$lookup = $conn->prepare(
    "SELECT id, visitor_user_id, visit_type, visit_id, scheduled_start_at
     FROM appointments WHERE id = ? AND office_code = ? LIMIT 1"
);
$lookup->bind_param("is", $appointmentId, $officeCode);
$lookup->execute();
$appointment = $lookup->get_result()->fetch_assoc();
$lookup->close();
if (!$appointment) {
    $conn->close();
    http_response_code(404);
    echo json_encode(["success" => false, "message" => "Appointment not found for this office."]);
    exit;
}

try {
    $settings = office_availability_get_settings($conn, $officeCode);
    $visitorUserId = (int) ($appointment["visitor_user_id"] ?? 0);
    $earliest = new DateTimeImmutable("+1 hour");
    $today = new DateTimeImmutable("today");
    $days = [];
    for ($offset = 0; $offset < FREE_TIMES_LOOK_AHEAD_DAYS && count($days) < FREE_TIMES_DAYS; $offset++) {
        $date = $today->modify("+{$offset} day");
        $times = [];
        foreach (office_free_slots($conn, $officeCode, $date, $earliest, $appointmentId) as $slot) {
            $clash = $visitorUserId > 0 ? visit_find_schedule_clash(
                $conn,
                $visitorUserId,
                new DateTime($slot["scheduled_start_at"]),
                new DateTime($slot["scheduled_end_at"]),
                $appointmentId
            ) : null;
            if (!$clash) {
                $times[] = ["start" => $slot["scheduled_start_at"], "end" => $slot["scheduled_end_at"]];
            }
        }
        if ($times) {
            $days[] = ["date" => $date->format("Y-m-d"), "times" => $times];
        }
    }
    $conn->close();
    echo json_encode([
        "success" => true,
        "accepting_visitors" => (bool) (int) $settings["accepting_visitors"],
        "slot_duration_minutes" => max(15, (int) $settings["slot_duration_minutes"]),
        "days" => $days,
    ]);
} catch (Throwable $error) {
    $conn->close();
    error_log("office_free_times: " . $error->getMessage());
    echo json_encode(["success" => false, "message" => "Could not list the free times. Pick a date and time instead."]);
}
