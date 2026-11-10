<?php
header("Content-Type: application/json; charset=utf-8");
require_once __DIR__ . "/session_bootstrap.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/appointment_offices.php";

require_permission_json("visits.monitor");

$stmt = $conn->prepare(
    "SELECT id, public_token, office_code, visitor_full_name, visitor_email, device_name, appointment_at, checked_in_at
     FROM appointments
     WHERE status = 'checked_in'
     ORDER BY checked_in_at DESC, id DESC"
);
if (!$stmt) {
    echo json_encode(["success" => false, "message" => "Server error"]);
    exit;
}

$stmt->execute();
$res = $stmt->get_result();
$rows = [];
$map = appointment_office_map();
while ($row = $res->fetch_assoc()) {
    $code = (string) $row["office_code"];
    $row["office_label"] = $map[$code] ?? $code;
    $rows[] = $row;
}

$stmt->close();
$conn->close();

echo json_encode([
    "success" => true,
    "data" => $rows,
]);
