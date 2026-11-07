<?php
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/session_bootstrap.php";
require_once __DIR__ . "/teacher_service.php";

require_permission_json("users.manage");

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode(["success" => false, "message" => "Method not allowed"]);
    exit;
}

$input = json_decode(file_get_contents("php://input"), true);
if (!is_array($input) || !isset($input["id"])) {
    echo json_encode(["success" => false, "message" => "Invalid body"]);
    exit;
}

$id = (int) $input["id"];
$selfId = (int) $_SESSION["user_id"];

if ($id <= 0) {
    echo json_encode(["success" => false, "message" => "Invalid user"]);
    exit;
}

if ($id === $selfId) {
    echo json_encode(["success" => false, "message" => "You cannot delete your own account"]);
    exit;
}

$lookup = $conn->prepare("SELECT id, username, role, office_code, is_active FROM app_users WHERE id = ? LIMIT 1");
$lookup->bind_param("i", $id);
$lookup->execute();
$user = $lookup->get_result()->fetch_assoc();
$lookup->close();

if (!$user) {
    echo json_encode(["success" => false, "message" => "User not found"]);
    exit;
}

if ($user["role"] === "admin" && (int) $user["is_active"] === 1) {
    $activeAdmins = auth_scalar($conn, "SELECT COUNT(*) FROM app_users WHERE role = 'admin' AND is_active = 1");
    if ($activeAdmins <= 1) {
        echo json_encode(["success" => false, "message" => "You cannot remove the last active administrator"]);
        exit;
    }
}

require_step_up_json();

// A teacher's visits keep their name; the calendar itself stops being bookable.
if ($user["role"] === "teacher") {
    teacher_retire_calendar($conn, (string) $user["office_code"]);
}

$stmt = $conn->prepare("DELETE FROM app_users WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $id);
$stmt->execute();
$affected = $stmt->affected_rows;
$stmt->close();

if ($affected === 0) {
    echo json_encode(["success" => false, "message" => "User not found"]);
    exit;
}

auth_audit($conn, $selfId, "user.deleted", "app_user", (string) $id, [
    "username" => (string) $user["username"],
    "role" => (string) $user["role"],
]);

echo json_encode(["success" => true]);
