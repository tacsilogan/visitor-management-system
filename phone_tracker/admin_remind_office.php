<?php
/*
 * "Remind the office" (admin.js): each staff member of an office gets a notification on their
 * dashboard about the requests waiting for their answer. At most once every 10 minutes per office.
 *   POST {"office_code": "IT"}
 */
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/session_bootstrap.php";
require_once __DIR__ . "/admin_service.php";

auth_json_exception_guard();
auth_require_method("POST");
auth_require_csrf();
require_admin_json();
date_default_timezone_set("Asia/Manila");

$input = auth_json_body();
$code = strtoupper(trim((string) ($input["office_code"] ?? "")));
$stmt = $conn->prepare("SELECT code, name, is_active FROM offices WHERE code = ? LIMIT 1");
$stmt->bind_param("s", $code);
$stmt->execute();
$office = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$office || (int) $office["is_active"] !== 1) {
    auth_json_error(404, "not_found", "That office was not found.");
}

$now = new DateTimeImmutable("now");
$requests = admin_waiting_requests($conn, $now)[$code] ?? [];
if (!$requests) {
    auth_json_error(409, "nothing_waiting", $office["name"] . " has no requests waiting anymore.");
}
$stmt = $conn->prepare(
    "SELECT MAX(created_at) FROM audit_logs
     WHERE action = 'office.reminded' AND entity_type = 'office' AND entity_id = ? AND created_at > NOW() - INTERVAL 10 MINUTE"
);
$stmt->bind_param("s", $code);
$stmt->execute();
$recent = $stmt->get_result()->fetch_row()[0];
$stmt->close();
if ($recent) {
    auth_json_error(429, "reminded_recently", $office["name"] . " was reminded at " . date("g:i A", strtotime((string) $recent)) . ". Give them a few minutes to answer.");
}

$sent = admin_notify_office_staff(
    $conn,
    $code,
    "office.request_reminder",
    "Requests are waiting for your answer",
    admin_reminder_message($requests, $now),
    ["by" => "admin", "appointment_ids" => array_map("intval", array_column($requests, "id"))]
);
if ($sent === 0) {
    auth_json_error(409, "no_staff", $office["name"] . " has no active Office Personnel to remind. Add someone first.");
}
$adminId = (int) $_SESSION["user_id"];
auth_audit($conn, $adminId, "office.reminded", "office", $code, [
    "office_code" => $code,
    "requests" => count($requests),
    "staff" => $sent,
]);
auth_json([
    "success" => true,
    "message" => "Reminder sent to " . $office["name"] . " (" . admin_plural($sent, "person", "people") . ").",
]);
