<?php
/*
 * A teacher turning phone alerts on or off, and what teacher-sw.js asks for when a push wakes it.
 * GET            the server's public key
 * GET ?latest=1  the newest unread notification, for the phone to show
 * POST           {action: "subscribe" | "unsubscribe", endpoint}
 */
header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store, max-age=0");

require_once __DIR__ . "/session_bootstrap.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/web_push_service.php";

require_permission_json("teacher.dashboard");
$userId = (int) $_SESSION["user_id"];

if ($_SERVER["REQUEST_METHOD"] === "GET") {
    if (!empty($_GET["latest"])) {
        $stmt = $conn->prepare(
            "SELECT title, message FROM app_notifications
             WHERE recipient_user_id = ? AND read_at IS NULL
             ORDER BY created_at DESC, id DESC LIMIT 1"
        );
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        echo json_encode(["success" => true, "notification" => $row ?: null]);
        exit;
    }
    try {
        echo json_encode(["success" => true, "public_key" => web_push_keys($conn)["public"]]);
    } catch (Throwable $error) {
        error_log("Phone alert keys: " . $error->getMessage());
        echo json_encode(["success" => false, "message" => "Phone alerts aren't available on this server."]);
    }
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode(["success" => false, "message" => "Method not allowed"]);
    exit;
}
$input = json_decode(file_get_contents("php://input"), true);
$action = is_array($input) ? (string) ($input["action"] ?? "") : "";
$endpoint = is_array($input) ? trim((string) ($input["endpoint"] ?? "")) : "";
if ($endpoint === "" || !in_array($action, ["subscribe", "unsubscribe"], true)) {
    echo json_encode(["success" => false, "message" => "Invalid request"]);
    exit;
}
$hash = hash("sha256", $endpoint);
if ($action === "subscribe") {
    if (!web_push_allowed_endpoint($endpoint)) {
        echo json_encode(["success" => false, "message" => "This browser's alert service isn't supported."]);
        exit;
    }
    $label = substr((string) ($_SERVER["HTTP_USER_AGENT"] ?? ""), 0, 100);
    $stmt = $conn->prepare(
        "INSERT INTO web_push_subscriptions (user_id, endpoint, endpoint_hash, device_label) VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), endpoint = VALUES(endpoint), failure_count = 0"
    );
    $stmt->bind_param("isss", $userId, $endpoint, $hash, $label);
    $stmt->execute();
    $stmt->close();
    echo json_encode(["success" => true]);
    exit;
}
$stmt = $conn->prepare("DELETE FROM web_push_subscriptions WHERE endpoint_hash = ? AND user_id = ?");
$stmt->bind_param("si", $hash, $userId);
$stmt->execute();
$stmt->close();
echo json_encode(["success" => true]);
