<?php
/*
 * Campus Map > Inside (INDOOR_DIRECTIONS.md): each department's building, floor, room,
 * description for visitors, and steps from the building door to the office door.
 *
 * GET   every department's directions.
 * POST  (multipart) office_code, payload: the directions as JSON, and photo_0, photo_1, ...:
 *       new photos, which a step asks for with "new_photo". The browser shrinks photos first.
 */
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/session_bootstrap.php";
require_once __DIR__ . "/appointment_offices.php";
require_once __DIR__ . "/office_directions_service.php";

auth_json_exception_guard();
auth_require_method("GET", "POST");
require_permission_json("campus_map.edit");

if (!office_directions_schema_ready($conn)) {
    auth_json_error(503, "migration_required", OFFICE_DIRECTIONS_MIGRATION_MESSAGE);
}

if (strtoupper((string) $_SERVER["REQUEST_METHOD"]) === "GET") {
    auth_json(["success" => true, "directions" => (object) office_directions_load($conn)]);
}

// PHP drops everything when a request is larger than post_max_size.
if (!isset($_POST["payload"])) {
    auth_json_error(413, "too_large", "These photos are too large to send together. Change fewer photos, save, then add the rest.");
}
$officeCode = strtoupper(trim((string) ($_POST["office_code"] ?? "")));
if (!isset(appointment_office_map()[$officeCode])) {
    auth_json_error(422, "invalid_department", "Choose the department these directions lead to.");
}
$input = json_decode((string) $_POST["payload"], true);
if (!is_array($input)) {
    auth_json_error(400, "invalid_request", "The directions could not be read. Refresh the page and try again.");
}

$stored = [];
try {
    $directions = office_directions_read_input($input);
    // A step keeps a photo only if it is one this department already has, or one that was copied.
    foreach ($directions["steps"] as $index => $step) {
        if (isset($step["new_photo"])) {
            $upload = $_FILES["photo_" . $step["new_photo"]] ?? null;
            if (!is_array($upload)) {
                throw new InvalidArgumentException("The photo of step " . ($index + 1) . " did not arrive. Choose it again.");
            }
            $path = office_directions_store_photo($upload, $index + 1);
            $stored[] = $path;
            $directions["steps"][$index]["photo"] = $path;
        } elseif ($step["photo"] !== "" && !is_file(office_directions_photo_folder() . DIRECTORY_SEPARATOR . basename($step["photo"]))) {
            throw new InvalidArgumentException("The photo of step " . ($index + 1) . " is no longer on the server. Choose it again.");
        }
        unset($directions["steps"][$index]["new_photo"]);
    }
    office_directions_save($conn, $officeCode, $directions, (int) $_SESSION["user_id"]);
} catch (InvalidArgumentException $problem) {
    foreach ($stored as $path) {
        @unlink(office_directions_photo_folder() . DIRECTORY_SEPARATOR . basename($path));
    }
    auth_json_error(422, "invalid_directions", $problem->getMessage());
} catch (Throwable $error) {
    foreach ($stored as $path) {
        @unlink(office_directions_photo_folder() . DIRECTORY_SEPARATOR . basename($path));
    }
    throw $error;
}

$saved = office_directions_load($conn, $officeCode)[$officeCode] ?? office_directions_blank();
auth_audit($conn, (int) $_SESSION["user_id"], "campus.directions_saved", "office", $officeCode, [
    "office_code" => $officeCode,
    "where" => office_directions_where($saved),
    "steps" => count($saved["steps"]),
    "photos" => count(array_filter(array_column($saved["steps"], "photo"))),
]);

auth_json(["success" => true, "directions" => $saved, "message" => "Saved. Visitors see these directions the next time their map loads."]);
