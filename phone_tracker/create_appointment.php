<?php
header("Content-Type: application/json; charset=utf-8");
require_once __DIR__ . "/session_bootstrap.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/appointment_offices.php";
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

function appointment_input(array $input, string $key, int $maxLength = 0): string
{
    $value = isset($input[$key]) ? trim((string) $input[$key]) : "";
    return $maxLength > 0 && strlen($value) > $maxLength ? substr($value, 0, $maxLength) : $value;
}

function parse_appointment_datetime(string $value): ?DateTime
{
    foreach (["Y-m-d\TH:i", "Y-m-d H:i:s", "Y-m-d H:i", "Y-m-d"] as $format) {
        $date = DateTime::createFromFormat($format, $value);
        $errors = DateTime::getLastErrors();
        if ($date && ($errors === false || ((int) $errors["warning_count"] === 0 && (int) $errors["error_count"] === 0))) {
            if ($format === "Y-m-d") {
                $date->setTime(9, 0);
            }
            return $date;
        }
    }
    return null;
}

$officeCode = strtoupper(appointment_input($input, "office_code", 16));
$visitorName = appointment_input($input, "visitor_full_name", 150);
$visitorEmail = appointment_input($input, "visitor_email", 190);
$contactNumber = appointment_input($input, "contact_number", 32);
$deviceName = appointment_input($input, "device_name", 100);
$visitType = strtolower(appointment_input($input, "visit_type", 20));
$purpose = appointment_input($input, "purpose", 100);
$destination = appointment_input($input, "destination", 150);
$subject = appointment_input($input, "subject", 150);
$additionalDetails = appointment_input($input, "additional_details", 3000);
$consentGranted = !empty($input["consent_granted"]);
$consentVersion = appointment_input($input, "consent_version", 32);
$startRaw = appointment_input($input, "scheduled_start_at");
if ($startRaw === "") {
    $startRaw = appointment_input($input, "appointment_at");
}
$endRaw = appointment_input($input, "scheduled_end_at");

// Only offices with someone to answer (appointment_office_bookable_map()).
$offices = appointment_office_bookable_map();
if ($officeCode === "" || !isset($offices[$officeCode])) {
    echo json_encode(["success" => false, "message" => "Select a valid office"]);
    exit;
}
if ($visitorName === "") {
    echo json_encode(["success" => false, "message" => "Enter your full name"]);
    exit;
}
if ($visitorEmail !== "" && !filter_var($visitorEmail, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(["success" => false, "message" => "Enter a valid email address"]);
    exit;
}
if (!in_array($visitType, ["appointment", "walk_in"], true)) {
    $visitType = "appointment";
}
if (!$consentGranted) {
    echo json_encode(["success" => false, "message" => "Location-tracking consent is required for a campus visit"]);
    exit;
}
if ($consentVersion === "") {
    $consentVersion = "2026-09-18";
}

$scheduledStart = parse_appointment_datetime($startRaw);
if (!$scheduledStart) {
    echo json_encode(["success" => false, "message" => "Choose a valid appointment date and time"]);
    exit;
}
$availabilitySettings = null;
try {
    $availabilitySettings = office_availability_get_settings($conn, $officeCode);
} catch (Throwable $error) {
    echo json_encode(["success" => false, "message" => "Run phase1_workflow_migration.sql before creating appointments"]);
    exit;
}
$scheduledEnd = $endRaw !== "" ? parse_appointment_datetime($endRaw) : null;
if (!$scheduledEnd) {
    $scheduledEnd = clone $scheduledStart;
    $slotDuration = max(15, (int) ($availabilitySettings["slot_duration_minutes"] ?? 30));
    $scheduledEnd->modify("+{$slotDuration} minutes");
}
if ($scheduledEnd <= $scheduledStart) {
    echo json_encode(["success" => false, "message" => "Appointment end time must be after its start time"]);
    exit;
}
if (($scheduledEnd->getTimestamp() - $scheduledStart->getTimestamp()) > 8 * 60 * 60) {
    echo json_encode(["success" => false, "message" => "Appointment duration cannot exceed eight hours"]);
    exit;
}

$now = new DateTime("now");
if ($visitType === "appointment" && $scheduledStart < $now) {
    echo json_encode(["success" => false, "message" => "Choose a future appointment time"]);
    exit;
}
$availability = office_availability_check($conn, $officeCode, $scheduledStart, $scheduledEnd);
if (!$availability["available"]) {
    echo json_encode(["success" => false, "message" => $availability["message"]]);
    exit;
}

if ($deviceName === "") {
    $deviceName = $visitorName;
}
$visitorUserId = (int) $_SESSION["user_id"];
$appointmentStart = $scheduledStart->format("Y-m-d H:i:s");
$appointmentEnd = $scheduledEnd->format("Y-m-d H:i:s");

try {
    $token = bin2hex(random_bytes(32));
} catch (Exception $e) {
    echo json_encode(["success" => false, "message" => "Could not generate appointment token"]);
    exit;
}

$conn->begin_transaction();
try {
    $stmt = $conn->prepare(
        "INSERT INTO appointments
         (public_token, office_code, visitor_full_name, visitor_email, contact_number,
          device_name, visit_type, purpose, destination, subject, additional_details,
          appointment_at, scheduled_start_at, scheduled_end_at, visitor_user_id, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending_approval')"
    );
    if (!$stmt) {
        throw new RuntimeException("Could not prepare appointment insert");
    }
    $stmt->bind_param(
        "ssssssssssssssi",
        $token,
        $officeCode,
        $visitorName,
        $visitorEmail,
        $contactNumber,
        $deviceName,
        $visitType,
        $purpose,
        $destination,
        $subject,
        $additionalDetails,
        $appointmentStart,
        $appointmentStart,
        $appointmentEnd,
        $visitorUserId
    );
    if (!$stmt->execute()) {
        throw new RuntimeException("Could not save appointment");
    }
    $newId = (int) $conn->insert_id;
    $stmt->close();

    $registrationCode = "V-" . $scheduledStart->format("Y") . "-" . str_pad((string) $newId, 6, "0", STR_PAD_LEFT);
    $codeStmt = $conn->prepare("UPDATE appointments SET registration_code = ? WHERE id = ?");
    if (!$codeStmt) {
        throw new RuntimeException("Could not prepare registration code");
    }
    $codeStmt->bind_param("si", $registrationCode, $newId);
    $codeStmt->execute();
    $codeStmt->close();

    $hist = $conn->prepare(
        "INSERT INTO appointment_status_history
         (appointment_id, from_status, to_status, changed_by_user_id, note)
         VALUES (?, NULL, 'pending_approval', ?, 'Appointment request created')"
    );
    if (!$hist) {
        throw new RuntimeException("Could not prepare status history");
    }
    $hist->bind_param("ii", $newId, $visitorUserId);
    $hist->execute();
    $hist->close();

    $consent = $conn->prepare(
        "INSERT INTO visitor_consents
         (appointment_id, visitor_user_id, consent_type, consent_version, device_info)
         VALUES (?, ?, 'location_tracking', ?, ?)"
    );
    if (!$consent) {
        throw new RuntimeException("Could not prepare consent record");
    }
    $deviceInfo = isset($_SERVER["HTTP_USER_AGENT"]) ? substr((string) $_SERVER["HTTP_USER_AGENT"], 0, 255) : "";
    $consent->bind_param("iiss", $newId, $visitorUserId, $consentVersion, $deviceInfo);
    $consent->execute();
    $consent->close();

    $notification = $conn->prepare(
        "INSERT INTO app_notifications
         (recipient_user_id, appointment_id, notification_type, title, message)
         SELECT id, ?, 'appointment_request', 'New appointment request', ?
         FROM app_users
         WHERE role = 'offices' AND office_code = ? AND is_active = 1"
    );
    if ($notification) {
        $notificationMessage = $visitorName . " requested " . $appointmentStart . ".";
        $notification->bind_param("iss", $newId, $notificationMessage, $officeCode);
        $notification->execute();
        $notification->close();
    }

    $audit = $conn->prepare(
        "INSERT INTO audit_logs
         (actor_user_id, appointment_id, action, entity_type, entity_id, details_json, ip_address)
         VALUES (?, ?, 'appointment.created', 'appointment', ?, ?, ?)"
    );
    if ($audit) {
        $entityId = (string) $newId;
        $details = json_encode([
            "office_code" => $officeCode,
            "visit_type" => $visitType,
            "scheduled_start_at" => $appointmentStart,
            "scheduled_end_at" => $appointmentEnd,
        ]);
        $ipAddress = isset($_SERVER["REMOTE_ADDR"]) ? substr((string) $_SERVER["REMOTE_ADDR"], 0, 45) : "";
        $audit->bind_param("iisss", $visitorUserId, $newId, $entityId, $details, $ipAddress);
        $audit->execute();
        $audit->close();
    }

    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    $conn->close();
    echo json_encode(["success" => false, "message" => "Could not save appointment. Run phase1_workflow_migration.sql first."]);
    exit;
}

$conn->close();
echo json_encode([
    "success" => true,
    "appointment_id" => $newId,
    "registration_code" => $registrationCode,
    "public_token" => $token,
    "status" => "pending_approval",
    "office_code" => $officeCode,
    "office_label" => $offices[$officeCode],
    "visitor_full_name" => $visitorName,
    "device_name" => $deviceName,
    "appointment_at" => $appointmentStart,
    "scheduled_start_at" => $appointmentStart,
    "scheduled_end_at" => $appointmentEnd,
]);
