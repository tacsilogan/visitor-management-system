<?php
/*
 * One office's pin on the campus map, from the guided office setup (admin.js). The Campus Map
 * page saves every pin at once (admin_save_campus_map.php); this changes only the one office.
 *   POST {"office_code": "IT", "latitude": 10.686, "longitude": 122.512, "placed_by": "map"|"gps", "accuracy_meters": 6}
 */
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/session_bootstrap.php";
require_once __DIR__ . "/campus_map_service.php";

auth_json_exception_guard();
auth_require_method("POST");
auth_require_csrf();
require_permission_json("campus_map.edit");

$input = auth_json_body();
$code = strtoupper(trim((string) ($input["office_code"] ?? "")));
$latitude = $input["latitude"] ?? null;
$longitude = $input["longitude"] ?? null;
if (!is_numeric($latitude) || !is_numeric($longitude) || abs((float) $latitude) > 90 || abs((float) $longitude) > 180) {
    auth_json_error(422, "invalid_point", "Drop the pin on the map first.");
}
$latitude = round((float) $latitude, 7);
$longitude = round((float) $longitude, 7);
$stmt = $conn->prepare("SELECT name FROM offices WHERE code = ? LIMIT 1");
$stmt->bind_param("s", $code);
$stmt->execute();
$name = $stmt->get_result()->fetch_row()[0] ?? null;
$stmt->close();
if ($name === null) {
    auth_json_error(404, "not_found", "That office was not found.");
}
if (!campus_map_ensure_table($conn)) {
    auth_json_error(503, "not_ready", "The campus map is not set up on this server yet.");
}
$boundary = campus_map_load($conn)["boundary"] ?? [];
if (count($boundary) >= 3 && !campus_contains($boundary, $latitude, $longitude)) {
    auth_json_error(422, "outside", "The pin is outside the campus boundary. Drag it inside the dashed line.");
}
$placedBy = ($input["placed_by"] ?? "") === "gps" ? "gps" : "map";
$accuracy = $placedBy === "gps" && is_numeric($input["accuracy_meters"] ?? null) ? round(max(0, min(999, (float) $input["accuracy_meters"])), 1) : null;
$userId = (int) $_SESSION["user_id"];
$hasPlacement = campus_routes_schema_ready($conn);

$conn->begin_transaction();
try {
    $order = (int) $conn->query("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM campus_places WHERE place_type = 'office'")->fetch_row()[0];
    $remove = $conn->prepare("DELETE FROM campus_places WHERE place_type = 'office' AND office_code = ?");
    $remove->bind_param("s", $code);
    $remove->execute();
    $remove->close();
    if ($hasPlacement) {
        $insert = $conn->prepare(
            "INSERT INTO campus_places (place_type, office_code, name, latitude, longitude, accuracy_meters, placed_by, sort_order, updated_by_user_id)
             VALUES ('office', ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $insert->bind_param("ssdddsii", $code, $name, $latitude, $longitude, $accuracy, $placedBy, $order, $userId);
    } else {
        $insert = $conn->prepare(
            "INSERT INTO campus_places (place_type, office_code, name, latitude, longitude, sort_order, updated_by_user_id)
             VALUES ('office', ?, ?, ?, ?, ?, ?)"
        );
        $insert->bind_param("ssddii", $code, $name, $latitude, $longitude, $order, $userId);
    }
    $insert->execute();
    $insert->close();
    $conn->commit();
} catch (Throwable $error) {
    $conn->rollback();
    throw $error;
}
auth_audit($conn, $userId, "campus.office_pin_saved", "office", $code, ["office_code" => $code, "placed_by" => $placedBy]);
auth_json(["success" => true, "message" => $name . " is on the campus map."]);
