<?php
declare(strict_types=1);
require_once __DIR__ . "/_bootstrap.php";

api_require_method("POST");
$user = api_require_visitor();
$input = api_body();
$userId = (int) $user["id"];
$markAll = !empty($input["mark_all"]);
$notificationId = (int) ($input["notification_id"] ?? 0);
if (!$markAll && $notificationId <= 0) {
    api_fail("Select a notification or use mark_all", 422);
}
if ($markAll) {
    $stmt = $conn->prepare("UPDATE app_notifications SET read_at = COALESCE(read_at, NOW()) WHERE recipient_user_id = ?");
    $stmt->bind_param("i", $userId);
} else {
    $stmt = $conn->prepare("UPDATE app_notifications SET read_at = COALESCE(read_at, NOW()) WHERE id = ? AND recipient_user_id = ?");
    $stmt->bind_param("ii", $notificationId, $userId);
}
$stmt->execute();
$changed = $stmt->affected_rows;
$stmt->close();
if (!$markAll && $changed < 1) {
    $check = $conn->prepare("SELECT id FROM app_notifications WHERE id = ? AND recipient_user_id = ? LIMIT 1");
    $check->bind_param("ii", $notificationId, $userId);
    $check->execute();
    $exists = (bool) $check->get_result()->fetch_assoc();
    $check->close();
    if (!$exists) {
        api_fail("Notification not found", 404);
    }
}
api_success(["updated" => $changed], 200, "Notification status updated");

