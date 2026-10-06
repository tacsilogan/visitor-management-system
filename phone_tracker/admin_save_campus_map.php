<?php
header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/session_bootstrap.php";
require_once __DIR__ . "/campus_map_service.php";

require_permission_json("campus_map.edit");

const CAMPUS_GATE_TOLERANCE_METERS = 40;

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode(["success" => false, "message" => "Method not allowed"]);
    exit;
}

$input = json_decode(file_get_contents("php://input"), true);
if (!is_array($input)) {
    echo json_encode(["success" => false, "message" => "Invalid body"]);
    exit;
}

function campus_fail(string $message): void
{
    echo json_encode(["success" => false, "message" => $message]);
    exit;
}

/**
 * @return array{0: float, 1: float}
 */
function campus_read_point($latitude, $longitude, string $what): array
{
    if (!is_numeric($latitude) || !is_numeric($longitude)
        || abs((float) $latitude) > 90 || abs((float) $longitude) > 180) {
        campus_fail("Invalid coordinates for " . $what . ".");
    }
    return [round((float) $latitude, 7), round((float) $longitude, 7)];
}

/**
 * How a pin was placed: "gps" (the admin stood there; accuracy in meters) or "map".
 *
 * @return array{0: string, 1: ?float}
 */
function campus_read_placement($item): array
{
    $placedBy = is_array($item) && ($item["placed_by"] ?? "") === "gps" ? "gps" : "map";
    $accuracy = null;
    if ($placedBy === "gps" && isset($item["accuracy_meters"]) && is_numeric($item["accuracy_meters"])) {
        $accuracy = round(max(0, min(999, (float) $item["accuracy_meters"])), 1);
    }
    return [$placedBy, $accuracy];
}

$boundaryInput = isset($input["boundary"]) && is_array($input["boundary"]) ? $input["boundary"] : [];
$gatesInput = isset($input["gates"]) && is_array($input["gates"]) ? $input["gates"] : [];
$officesInput = isset($input["offices"]) && is_array($input["offices"]) ? $input["offices"] : [];

if (count($boundaryInput) < 3 || count($boundaryInput) > 200) {
    campus_fail("Draw the campus boundary with at least 3 corners (at most 200).");
}
if (count($gatesInput) > 20) {
    campus_fail("A campus can have at most 20 gates.");
}

$boundary = [];
foreach ($boundaryInput as $index => $corner) {
    if (!is_array($corner) || count($corner) < 2) {
        campus_fail("Invalid boundary corner.");
    }
    $boundary[] = campus_read_point($corner[0], $corner[1], "boundary corner " . ((int) $index + 1));
}

$gates = [];
$gateNames = [];
foreach ($gatesInput as $gate) {
    $name = is_array($gate) ? trim((string) ($gate["name"] ?? "")) : "";
    if ($name === "" || mb_strlen($name) > 100) {
        campus_fail("Each gate needs a name of up to 100 characters.");
    }
    if (isset($gateNames[mb_strtolower($name)])) {
        campus_fail("Gate names must be unique: " . $name);
    }
    $gateNames[mb_strtolower($name)] = true;
    $point = campus_read_point($gate["latitude"] ?? null, $gate["longitude"] ?? null, $name);
    $gates[] = ["name" => $name, "point" => $point, "placement" => campus_read_placement($gate)];
}

$officeMap = appointment_office_map();
$offices = [];
$officePlacement = [];
foreach ($officesInput as $office) {
    $code = is_array($office) ? strtoupper(trim((string) ($office["code"] ?? ""))) : "";
    if (!isset($officeMap[$code])) {
        campus_fail("Unknown office in office pins.");
    }
    if (isset($offices[$code])) {
        campus_fail("Each office can only have one pin.");
    }
    $offices[$code] = campus_read_point($office["latitude"] ?? null, $office["longitude"] ?? null, $officeMap[$code]);
    $officePlacement[$code] = campus_read_placement($office);
}

foreach ($gates as $gate) {
    // Gates sit on the campus edge, so allow them a little outside the drawn line.
    if (!campus_contains($boundary, $gate["point"][0], $gate["point"][1])
        && campus_distance_to_boundary_meters($boundary, $gate["point"][0], $gate["point"][1]) > CAMPUS_GATE_TOLERANCE_METERS) {
        campus_fail($gate["name"] . " is outside the campus boundary. Drag it inside on the Gates tab, or adjust the boundary.");
    }
}
foreach ($offices as $code => $point) {
    if (!campus_contains($boundary, $point[0], $point[1])) {
        campus_fail("The " . $officeMap[$code] . " pin is outside the campus boundary. Move it inside on the Offices tab (drag it, or stand at the door and use Pin at my location), or adjust the boundary. Removing the pin would leave visitors without directions.");
    }
}

if (!campus_map_ensure_table($conn)) {
    campus_fail("Could not prepare the campus map table.");
}

$userId = (int) $_SESSION["user_id"];
// Databases without campus_routes_migration.sql keep saving pins without their placement.
$hasPlacement = campus_routes_schema_ready($conn);
$conn->begin_transaction();
try {
    $conn->query("DELETE FROM campus_places");
    $stmt = $conn->prepare($hasPlacement
        ? "INSERT INTO campus_places (place_type, office_code, name, latitude, longitude, accuracy_meters, placed_by, sort_order, updated_by_user_id)
           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        : "INSERT INTO campus_places (place_type, office_code, name, latitude, longitude, sort_order, updated_by_user_id)
           VALUES (?, ?, ?, ?, ?, ?, ?)"
    );
    $insert = function (string $type, ?string $officeCode, string $name, array $point, int $order, array $placement = ["map", null]) use ($stmt, $userId, $hasPlacement): void {
        [$placedBy, $accuracy] = $placement;
        if ($hasPlacement) {
            $stmt->bind_param("sssdddsii", $type, $officeCode, $name, $point[0], $point[1], $accuracy, $placedBy, $order, $userId);
        } else {
            $stmt->bind_param("sssddii", $type, $officeCode, $name, $point[0], $point[1], $order, $userId);
        }
        if (!$stmt->execute()) {
            throw new RuntimeException("Insert failed");
        }
    };
    foreach ($boundary as $index => $point) {
        $insert("boundary", null, "Campus boundary", $point, $index);
    }
    foreach ($gates as $index => $gate) {
        $insert("gate", null, $gate["name"], $gate["point"], $index, $gate["placement"]);
    }
    $order = 0;
    foreach ($offices as $code => $point) {
        $insert("office", $code, $officeMap[$code], $point, $order++, $officePlacement[$code]);
    }
    $stmt->close();
    $conn->commit();
} catch (Throwable $error) {
    $conn->rollback();
    campus_fail("Could not save the campus map.");
}

$gpsPins = count(array_filter(array_merge(
    array_map(fn(array $gate): string => $gate["placement"][0], $gates),
    array_map(fn(array $placement): string => $placement[0], $officePlacement)
), fn(string $placedBy): bool => $placedBy === "gps"));
auth_audit($conn, $userId, "campus.map_saved", "campus_map", "campus", [
    "corners" => count($boundary),
    "gates" => count($gates),
    "offices" => count($offices),
    "gps_pins" => $gpsPins,
]);

$campus = campus_map_load($conn);
$conn->close();

echo json_encode(["success" => true, "campus" => $campus]);
