<?php
declare(strict_types=1);
require_once __DIR__ . "/_bootstrap.php";
require_once dirname(__DIR__, 2) . "/visit_service.php";

api_require_method("GET", "POST");
$user = api_require_visitor();
$userId = (int) $user["id"];
$input = $_SERVER["REQUEST_METHOD"] === "POST" ? api_body() : [];
$appointmentId = (int) ($input["appointment_id"] ?? $_GET["appointment_id"] ?? 0);
if ($appointmentId <= 0) {
    api_fail("Select a valid appointment", 422);
}
$owned = $conn->prepare("SELECT id, status FROM appointments WHERE id = ? AND visitor_user_id = ? LIMIT 1");
$owned->bind_param("ii", $appointmentId, $userId);
$owned->execute();
$appointment = $owned->get_result()->fetch_assoc();
$owned->close();
if (!$appointment) {
    api_fail("Appointment not found", 404);
}
if ($_SERVER["REQUEST_METHOD"] === "GET") {
    // A checked-in multi-stop visit is tracked under the consent of one of its stops.
    $appointmentId = visit_tracking_appointment_id($conn, $appointmentId);
}

if ($_SERVER["REQUEST_METHOD"] === "GET") {
    $stmt = $conn->prepare(
        "SELECT id, consent_version, consented_at, withdrawn_at
         FROM visitor_consents
         WHERE appointment_id = ? AND visitor_user_id = ? AND consent_type = 'location_tracking'
         ORDER BY id DESC LIMIT 1"
    );
    $stmt->bind_param("ii", $appointmentId, $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    api_success(["consent" => $row ? [
        "id" => (int) $row["id"],
        "version" => (string) $row["consent_version"],
        "consented_at" => $row["consented_at"],
        "withdrawn_at" => $row["withdrawn_at"],
        "active" => $row["withdrawn_at"] === null,
    ] : null]);
}

$action = strtolower(api_text($input, "action", 16));
if (!in_array($action, ["grant", "withdraw"], true)) {
    api_fail("Consent action must be grant or withdraw", 422);
}
if ($action === "grant") {
    if (in_array($appointment["status"], ["completed", "cancelled", "rejected", "unanswered", "window_closed"], true)) {
        api_fail("Consent cannot be added to a closed appointment", 409);
    }
    $version = api_text($input, "consent_version", 32);
    if ($version === "") {
        api_fail("The displayed consent policy version is required", 422);
    }
    $active = $conn->prepare(
        "SELECT id FROM visitor_consents
         WHERE appointment_id = ? AND visitor_user_id = ? AND consent_type = 'location_tracking' AND withdrawn_at IS NULL
         ORDER BY id DESC LIMIT 1"
    );
    $active->bind_param("ii", $appointmentId, $userId);
    $active->execute();
    $existing = $active->get_result()->fetch_assoc();
    $active->close();
    if ($existing) {
        api_success(["consent_id" => (int) $existing["id"], "active" => true], 200, "Location consent is already active");
    }
    $deviceInfo = substr((string) ($_SERVER["HTTP_USER_AGENT"] ?? "Android app"), 0, 255);
    $insert = $conn->prepare(
        "INSERT INTO visitor_consents
         (appointment_id, visitor_user_id, consent_type, consent_version, device_info)
         VALUES (?, ?, 'location_tracking', ?, ?)"
    );
    $insert->bind_param("iiss", $appointmentId, $userId, $version, $deviceInfo);
    $insert->execute();
    $consentId = (int) $conn->insert_id;
    $insert->close();
    api_audit($conn, $userId, "mobile.location_consent_granted", "visitor_consent", (string) $consentId, ["version" => $version], $appointmentId);
    api_success(["consent_id" => $consentId, "active" => true], 201, "Location consent recorded");
}

$conn->begin_transaction();
try {
    // Withdrawing on any stop of a multi-stop visit withdraws it for the whole visit.
    $withdrawn = 0;
    $withdraw = $conn->prepare(
        "UPDATE visitor_consents SET withdrawn_at = NOW()
         WHERE appointment_id = ? AND visitor_user_id = ? AND consent_type = 'location_tracking' AND withdrawn_at IS NULL"
    );
    $end = $conn->prepare(
        "UPDATE location_tracking_sessions SET ended_at = COALESCE(ended_at, NOW()), ended_reason = 'consent_withdrawn'
         WHERE appointment_id = ? AND visitor_user_id = ? AND ended_at IS NULL"
    );
    foreach (visit_consent_appointment_ids($conn, $appointmentId) as $consentAppointmentId) {
        $withdraw->bind_param("ii", $consentAppointmentId, $userId);
        $withdraw->execute();
        $withdrawn += max(0, $withdraw->affected_rows);
        $end->bind_param("ii", $consentAppointmentId, $userId);
        $end->execute();
    }
    $withdraw->close();
    $end->close();
    api_audit($conn, $userId, "mobile.location_consent_withdrawn", "appointment", (string) $appointmentId, [], $appointmentId);
    $conn->commit();
} catch (Throwable $error) {
    $conn->rollback();
    api_fail("Could not withdraw location consent", 500, [], $error);
}
api_success(["active" => false, "withdrawn_records" => max(0, $withdrawn)], 200, "Location sharing consent withdrawn");

