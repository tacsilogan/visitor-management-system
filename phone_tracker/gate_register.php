<?php
header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/session_bootstrap.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/gate_service.php";

// Registers a visitor at the gate and checks them in (GATE_REGISTRATION.md). Security only: the
// guard has checked the visitor's ID and read them the notice.

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode(["success" => false, "message" => "Method not allowed"]);
    exit;
}

require_permission_json("visits.scan");
$input = json_decode(file_get_contents("php://input"), true);
if (!is_array($input)) {
    echo json_encode(["success" => false, "message" => "Invalid request body"]);
    exit;
}

try {
    $visit = gate_register_visitor($conn, (int) $_SESSION["user_id"], $input);
    echo json_encode([
        "success" => true,
        "message" => $visit["visitor_full_name"] . " is checked in for " . $visit["office_label"] . ". This visit is not tracked.",
        "visit" => $visit,
    ]);
} catch (InvalidArgumentException $error) {
    $fields = json_decode($error->getMessage(), true);
    echo json_encode([
        "success" => false,
        "message" => "Check the highlighted fields.",
        "errors" => is_array($fields) ? $fields : [],
    ]);
} catch (DomainException $error) {
    echo json_encode(["success" => false, "message" => $error->getMessage()]);
} catch (Throwable $error) {
    error_log("gate_register: " . $error->getMessage());
    echo json_encode(["success" => false, "message" => "Could not register this visitor. Please try again."]);
}
$conn->close();
