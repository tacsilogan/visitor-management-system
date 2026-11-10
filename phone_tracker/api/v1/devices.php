<?php
declare(strict_types=1);
require_once __DIR__ . "/_bootstrap.php";

api_require_method("POST", "DELETE");
$user = api_require_visitor();
$input = api_body();
$userId = (int) $user["id"];
$installationId = api_text($input, "installation_id", 191);
if ($installationId === "" || strlen($installationId) < 8) {
    api_fail("A stable app installation ID is required", 422);
}

if ($_SERVER["REQUEST_METHOD"] === "DELETE") {
    $stmt = $conn->prepare(
        "UPDATE mobile_devices
         SET is_active = 0, fcm_token = '', fcm_token_hash = SHA2(CONCAT('retired-', id, '-', NOW(6)), 256)
         WHERE visitor_user_id = ? AND installation_id = ?"
    );
    if (!$stmt) {
        api_fail("Mobile API database migration is required", 503);
    }
    $stmt->bind_param("is", $userId, $installationId);
    $stmt->execute();
    $stmt->close();
    api_success([], 200, "Device unregistered");
}

$fcmToken = api_text($input, "fcm_token", 2048);
$appVersion = api_text($input, "app_version", 32);
$deviceModel = api_text($input, "device_model", 100);
if (strlen($fcmToken) < 20) {
    api_fail("A valid Firebase registration token is required", 422);
}
$tokenHash = hash("sha256", $fcmToken);
$conn->begin_transaction();
try {
    $retire = $conn->prepare(
        "UPDATE mobile_devices
         SET is_active = 0, fcm_token = '', fcm_token_hash = SHA2(CONCAT('retired-', id, '-', NOW(6)), 256)
         WHERE fcm_token_hash = ? AND NOT (visitor_user_id = ? AND installation_id = ?)"
    );
    $retire->bind_param("sis", $tokenHash, $userId, $installationId);
    $retire->execute();
    $retire->close();
    $find = $conn->prepare("SELECT id FROM mobile_devices WHERE visitor_user_id = ? AND installation_id = ? FOR UPDATE");
    $find->bind_param("is", $userId, $installationId);
    $find->execute();
    $existing = $find->get_result()->fetch_assoc();
    $find->close();
    if ($existing) {
        $deviceId = (int) $existing["id"];
        $save = $conn->prepare(
            "UPDATE mobile_devices SET platform = 'android', fcm_token = ?, fcm_token_hash = ?,
             app_version = ?, device_model = ?, is_active = 1, last_seen_at = NOW() WHERE id = ?"
        );
        $save->bind_param("ssssi", $fcmToken, $tokenHash, $appVersion, $deviceModel, $deviceId);
    } else {
        $save = $conn->prepare(
            "INSERT INTO mobile_devices
             (visitor_user_id, installation_id, platform, fcm_token, fcm_token_hash, app_version, device_model)
             VALUES (?, ?, 'android', ?, ?, ?, ?)"
        );
        $save->bind_param("isssss", $userId, $installationId, $fcmToken, $tokenHash, $appVersion, $deviceModel);
    }
    $save->execute();
    if (!$existing) {
        $deviceId = (int) $conn->insert_id;
    }
    $save->close();
    $queue = $conn->prepare(
        "INSERT IGNORE INTO notification_deliveries (notification_id, device_id)
         SELECT id, ? FROM app_notifications
         WHERE recipient_user_id = ? AND read_at IS NULL AND created_at >= NOW() - INTERVAL 7 DAY"
    );
    $queue->bind_param("ii", $deviceId, $userId);
    $queue->execute();
    $queued = $queue->affected_rows;
    $queue->close();
    api_audit($conn, $userId, "mobile.device_registered", "mobile_device", (string) $deviceId, [
        "installation_id" => $installationId,
        "app_version" => $appVersion,
        "device_model" => $deviceModel,
    ]);
    $conn->commit();
} catch (Throwable $error) {
    $conn->rollback();
    api_fail("Could not register this device", 500, [], $error);
}
api_success(["device_id" => $deviceId, "queued_notifications" => max(0, $queued)], 200, "Device registered for push notifications");

