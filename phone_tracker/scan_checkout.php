<?php
header("Content-Type: application/json; charset=utf-8");
require_once __DIR__ . "/session_bootstrap.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/monitoring_service.php";

// Security confirmed the check-out offered by the second scan of a visitor pass
// (scan_appointment.php). The pass code is sent again, so a check-out always needs the
// visitor's pass, never just an id.

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode(["success" => false, "message" => "Method not allowed"]);
    exit;
}

require_permission_json("visits.complete");
$input = json_decode(file_get_contents("php://input"), true);
$token = is_array($input) ? preg_replace("/[^a-f0-9]/i", "", (string) ($input["token"] ?? "")) : "";
if (strlen($token) !== 64) {
    echo json_encode(["success" => false, "message" => "Scan the visitor pass again to check them out."]);
    exit;
}
$token = strtolower($token);

$stmt = $conn->prepare("SELECT id FROM appointments WHERE public_token = ? LIMIT 1");
$stmt->bind_param("s", $token);
$stmt->execute();
$appointmentId = (int) ($stmt->get_result()->fetch_assoc()["id"] ?? 0);
$stmt->close();
if ($appointmentId === 0 && visit_schema_ready($conn)) {
    $visit = visit_load_by_token($conn, $token);
    if ($visit) {
        $stops = visit_stops($conn, (int) $visit["id"]);
        $appointmentId = !empty($visit["tracking_appointment_id"])
            ? (int) $visit["tracking_appointment_id"]
            : (int) ($stops[0]["id"] ?? 0);
    }
}
if ($appointmentId === 0) {
    $conn->close();
    echo json_encode(["success" => false, "message" => "No visit found for this pass."]);
    exit;
}

$visitor = monitor_visitor($conn, $appointmentId);
// The people still with the visitor leave with them (GROUP_VISITS.md).
$withThem = $visitor ? count(array_filter(
    visit_people_list($conn, visit_people_pass_for_row($visitor)),
    fn (array $person): bool => $person["gate_status"] === "in" && $person["left_at"] === null
)) : 0;
if (!$visitor) {
    $conn->close();
    echo json_encode(["success" => false, "message" => "This visitor was never checked in."]);
    exit;
}
if ($visitor["status"] !== "checked_in") {
    $response = gate_scan_completed($conn, $appointmentId, ["visitor_full_name" => $visitor["visitor_full_name"]]);
    $conn->close();
    echo json_encode($response);
    exit;
}

try {
    $ended = gate_checkout($conn, $appointmentId, (int) $_SESSION["user_id"], "scan");
} catch (Throwable $error) {
    error_log("Gate check-out failed for appointment {$appointmentId}: " . $error->getMessage());
    $conn->close();
    echo json_encode(["success" => false, "message" => "Could not check out this visitor. Please try again."]);
    exit;
}
if (!$ended) {
    $response = gate_scan_completed($conn, $appointmentId, ["visitor_full_name" => $visitor["visitor_full_name"]]);
    $conn->close();
    echo json_encode($response);
    exit;
}
$conn->close();

$seconds = max(0, strtotime($ended["completed_at"]) - strtotime((string) $visitor["checked_in_at"]));
echo json_encode([
    "success" => true,
    "action" => "checked_out",
    "message" => "Checked out after " . gate_duration_text($seconds) . " on campus"
        . ($withThem > 0 ? ", with the " . ($withThem === 1 ? "person" : $withThem . " people") . " still with them" : "")
        . ". Location tracking has stopped.",
    "people_checked_out" => $withThem,
    "appointment_id" => $ended["appointment_id"],
    "visitor_full_name" => $visitor["visitor_full_name"],
    "registration_id" => $visitor["registration_id"],
    "office_label" => $visitor["office_label"],
    "checked_in_at" => $visitor["checked_in_at"],
    "completed_at" => $ended["completed_at"],
    "seconds_on_campus" => $seconds,
]);
