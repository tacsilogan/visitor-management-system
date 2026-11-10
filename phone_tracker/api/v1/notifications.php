<?php
declare(strict_types=1);
require_once __DIR__ . "/_bootstrap.php";

api_require_method("GET");
$user = api_require_visitor();
$userId = (int) $user["id"];
$limit = max(1, min(100, (int) ($_GET["limit"] ?? 30)));
$beforeId = max(0, (int) ($_GET["before_id"] ?? 0));
$unreadOnly = filter_var($_GET["unread_only"] ?? false, FILTER_VALIDATE_BOOLEAN);
$where = "recipient_user_id = ?";
$types = "i";
$params = [$userId];
if ($beforeId > 0) {
    $where .= " AND id < ?";
    $types .= "i";
    $params[] = $beforeId;
}
if ($unreadOnly) {
    $where .= " AND read_at IS NULL";
}
$types .= "i";
$params[] = $limit + 1;
$stmt = $conn->prepare(
    "SELECT id, appointment_id, notification_type, title, message, data_json, read_at, created_at
     FROM app_notifications WHERE {$where} ORDER BY id DESC LIMIT ?"
);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();
$items = [];
while ($row = $result->fetch_assoc()) {
    $decodedData = $row["data_json"] ? json_decode((string) $row["data_json"], true) : null;
    $items[] = [
        "id" => (int) $row["id"],
        "appointment_id" => $row["appointment_id"] === null ? null : (int) $row["appointment_id"],
        "type" => (string) $row["notification_type"],
        "title" => (string) $row["title"],
        "message" => (string) $row["message"],
        "data" => is_array($decodedData) ? $decodedData : null,
        "read_at" => $row["read_at"],
        "created_at" => $row["created_at"],
    ];
}
$stmt->close();
$hasMore = count($items) > $limit;
if ($hasMore) {
    array_pop($items);
}
$count = $conn->prepare("SELECT COUNT(*) AS total FROM app_notifications WHERE recipient_user_id = ? AND read_at IS NULL");
$count->bind_param("i", $userId);
$count->execute();
$unreadCount = (int) ($count->get_result()->fetch_assoc()["total"] ?? 0);
$count->close();
api_success([
    "notifications" => $items,
    "unread_count" => $unreadCount,
    "pagination" => [
        "has_more" => $hasMore,
        "next_before_id" => $hasMore && $items ? (int) end($items)["id"] : null,
    ],
]);

