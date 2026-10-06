<?php
require_once __DIR__ . "/appointment_offices.php";

const CAMPUS_PLACES_TABLE_SQL = "CREATE TABLE IF NOT EXISTS `campus_places` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `place_type` enum('boundary','gate','office') NOT NULL,
  `office_code` varchar(32) DEFAULT NULL,
  `name` varchar(100) NOT NULL,
  `latitude` decimal(10,7) NOT NULL,
  `longitude` decimal(10,7) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `updated_by_user_id` int(11) DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_campus_places_type` (`place_type`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

/** Gates and route points may sit this far outside the drawn boundary (the campus edge). */
const CAMPUS_EDGE_TOLERANCE_METERS = 40;
/** A route starting this close to a gate pin starts at that gate. */
const CAMPUS_ROUTE_START_GATE_METERS = 25;
const CAMPUS_ROUTES_MIGRATION_MESSAGE = "Database update required: import phone_tracker/campus_routes_migration.sql into phone_tracker.";

function campus_map_ensure_table(mysqli $conn): bool
{
    return (bool) $conn->query(CAMPUS_PLACES_TABLE_SQL);
}

/**
 * True once campus_routes_migration.sql is imported: walking routes, and pins that remember
 * whether they were placed on the map or from GPS (with the accuracy).
 */
function campus_routes_schema_ready(mysqli $conn): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    try {
        $ready = (bool) $conn->query("SELECT id, office_code, points_json FROM campus_routes LIMIT 0")
            && (bool) $conn->query("SELECT accuracy_meters, placed_by FROM campus_places LIMIT 0");
    } catch (Throwable) {
        $ready = false;
    }
    return $ready;
}

/**
 * @return array{configured: bool, boundary: array<int, array{0: float, 1: float}>,
 *               gates: array<int, array<string, mixed>>, offices: array<int, array<string, mixed>>,
 *               updated_at: ?string}
 */
function campus_map_load(mysqli $conn): array
{
    $campus = ["configured" => false, "boundary" => [], "gates" => [], "offices" => [], "updated_at" => null];
    $exists = $conn->query("SHOW TABLES LIKE 'campus_places'");
    if (!$exists || $exists->num_rows === 0) {
        return $campus;
    }

    $placement = campus_routes_schema_ready($conn) ? ", accuracy_meters, placed_by" : ", NULL AS accuracy_meters, 'map' AS placed_by";
    $result = $conn->query(
        "SELECT place_type, office_code, name, latitude, longitude, updated_at{$placement}
         FROM campus_places
         ORDER BY place_type ASC, sort_order ASC, id ASC"
    );
    if (!$result) {
        return $campus;
    }
    while ($row = $result->fetch_assoc()) {
        $latitude = (float) $row["latitude"];
        $longitude = (float) $row["longitude"];
        // How the pin was placed: "gps" (the admin stood there; accuracy in meters) or "map".
        $accuracy = $row["accuracy_meters"] === null ? null : (float) $row["accuracy_meters"];
        $placedBy = $row["placed_by"] === "gps" ? "gps" : "map";
        if ($campus["updated_at"] === null || $row["updated_at"] > $campus["updated_at"]) {
            $campus["updated_at"] = $row["updated_at"];
        }
        if ($row["place_type"] === "boundary") {
            $campus["boundary"][] = [$latitude, $longitude];
        } elseif ($row["place_type"] === "gate") {
            $campus["gates"][] = [
                "name" => $row["name"],
                "latitude" => $latitude,
                "longitude" => $longitude,
                "accuracy_meters" => $accuracy,
                "placed_by" => $placedBy,
            ];
        } else {
            $code = (string) $row["office_code"];
            $campus["offices"][] = [
                "code" => $code,
                "label" => appointment_office_label($code),
                "latitude" => $latitude,
                "longitude" => $longitude,
                "accuracy_meters" => $accuracy,
                "placed_by" => $placedBy,
            ];
        }
    }
    $campus["configured"] = count($campus["boundary"]) >= 3;
    return $campus;
}

function campus_distance_meters(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $toRad = M_PI / 180;
    $dLat = ($lat2 - $lat1) * $toRad;
    $dLng = ($lng2 - $lng1) * $toRad;
    $h = sin($dLat / 2) ** 2 + cos($lat1 * $toRad) * cos($lat2 * $toRad) * sin($dLng / 2) ** 2;
    return 2 * 6371000 * asin(min(1, sqrt($h)));
}

/**
 * Shortest distance from a point to the boundary outline, using a local flat projection.
 *
 * @param array<int, array{0: float, 1: float}> $boundary
 */
function campus_distance_to_boundary_meters(array $boundary, float $latitude, float $longitude): float
{
    $metersPerLat = 111320;
    $metersPerLng = 111320 * cos(deg2rad($latitude));
    $nearest = INF;
    $count = count($boundary);
    for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
        $ax = ($boundary[$j][1] - $longitude) * $metersPerLng;
        $ay = ($boundary[$j][0] - $latitude) * $metersPerLat;
        $bx = ($boundary[$i][1] - $longitude) * $metersPerLng;
        $by = ($boundary[$i][0] - $latitude) * $metersPerLat;
        $dx = $bx - $ax;
        $dy = $by - $ay;
        $lengthSquared = $dx * $dx + $dy * $dy;
        $t = $lengthSquared > 0 ? max(0, min(1, -($ax * $dx + $ay * $dy) / $lengthSquared)) : 0;
        $nearest = min($nearest, hypot($ax + $t * $dx, $ay + $t * $dy));
    }
    return $nearest;
}

/**
 * Ray-casting point-in-polygon test. The campus is small enough to treat lat/lng as flat.
 *
 * @param array<int, array{0: float, 1: float}> $boundary
 */
function campus_contains(array $boundary, float $latitude, float $longitude): bool
{
    $inside = false;
    $count = count($boundary);
    for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
        [$latI, $lngI] = $boundary[$i];
        [$latJ, $lngJ] = $boundary[$j];
        if (($latI > $latitude) !== ($latJ > $latitude)
            && $longitude < ($lngJ - $lngI) * ($latitude - $latI) / ($latJ - $latI) + $lngI) {
            $inside = !$inside;
        }
    }
    return $inside;
}

/** True when a point is inside the campus or within CAMPUS_EDGE_TOLERANCE_METERS of its edge. */
function campus_point_near_campus(array $boundary, float $latitude, float $longitude): bool
{
    return campus_contains($boundary, $latitude, $longitude)
        || campus_distance_to_boundary_meters($boundary, $latitude, $longitude) <= CAMPUS_EDGE_TOLERANCE_METERS;
}

/**
 * Length of a path in meters.
 *
 * @param array<int, array{0: float, 1: float}> $points
 */
function campus_path_length_meters(array $points): float
{
    $total = 0.0;
    for ($index = 1, $count = count($points); $index < $count; $index++) {
        $total += campus_distance_meters($points[$index - 1][0], $points[$index - 1][1], $points[$index][0], $points[$index][1]);
    }
    return $total;
}

/**
 * Where each office's recorded routes end: the office's door, as the administrator walked
 * it. The most recently saved route wins. Used for an office that has a route but no pin.
 *
 * @param array<int, array<string, mixed>> $routes from campus_routes_load()
 * @return array<string, array{0: float, 1: float}> office code => [latitude, longitude]
 */
function campus_route_ends(array $routes): array
{
    $ends = [];
    $savedAt = [];
    foreach ($routes as $route) {
        $points = $route["points"];
        if (count($points) < 2) {
            continue;
        }
        $code = (string) $route["office_code"];
        $when = (string) ($route["updated_at"] ?? "");
        if (!isset($ends[$code]) || strcmp($when, $savedAt[$code]) > 0) {
            $last = $points[count($points) - 1];
            $ends[$code] = [(float) $last[0], (float) $last[1]];
            $savedAt[$code] = $when;
        }
    }
    return $ends;
}

/**
 * The named starts of recorded routes ("Main Gate", "Guard house") that no gate pin marks,
 * as extra gates, so "Guide me out" can lead back along a route to where it began.
 *
 * @param array<int, array<string, mixed>> $routes from campus_routes_load()
 * @param array<int, array<string, mixed>> $gates the campus map's gate pins
 * @return array<int, array{name: string, latitude: float, longitude: float}>
 */
function campus_route_start_gates(array $routes, array $gates): array
{
    $extra = [];
    foreach ($routes as $route) {
        $name = trim((string) $route["start_label"]);
        $first = $route["points"][0] ?? null;
        if ($name === "" || !is_array($first)) {
            continue;
        }
        foreach (array_merge($gates, $extra) as $gate) {
            $near = campus_distance_meters((float) $first[0], (float) $first[1], (float) $gate["latitude"], (float) $gate["longitude"])
                <= CAMPUS_ROUTE_START_GATE_METERS;
            if ($near || mb_strtolower((string) $gate["name"]) === mb_strtolower($name)) {
                continue 2;
            }
        }
        $extra[] = ["name" => $name, "latitude" => (float) $first[0], "longitude" => (float) $first[1]];
    }
    return $extra;
}

/** "First Last", else the display name, else the username of whoever saved a route. */
function campus_route_person(?string $firstName, ?string $lastName, ?string $displayName, ?string $username = null): string
{
    $name = trim(trim((string) $firstName) . " " . trim((string) $lastName));
    if ($name === "") {
        $name = trim((string) $displayName);
    }
    return $name !== "" ? $name : trim((string) $username);
}

/**
 * Every walking route, grouped by department in the order they were recorded. Each route's
 * points run from its start (usually a gate) to the department.
 *
 * @return array<int, array<string, mixed>>
 */
function campus_routes_load(mysqli $conn, ?int $routeId = null): array
{
    if (!campus_routes_schema_ready($conn)) {
        return [];
    }
    $sql = "SELECT r.id, r.office_code, r.name, r.start_label, r.method, r.points_json, r.point_count,
                   r.distance_meters, r.duration_seconds, r.average_accuracy_meters, r.created_at, r.updated_at,
                   c.first_name AS created_first, c.last_name AS created_last, c.display_name AS created_display, c.username AS created_username,
                   u.first_name AS updated_first, u.last_name AS updated_last, u.display_name AS updated_display, u.username AS updated_username
            FROM campus_routes r
            LEFT JOIN app_users c ON c.id = r.created_by_user_id
            LEFT JOIN app_users u ON u.id = r.updated_by_user_id";
    if ($routeId !== null) {
        $stmt = $conn->prepare($sql . " WHERE r.id = ?");
        $stmt->bind_param("i", $routeId);
        $stmt->execute();
        $result = $stmt->get_result();
        $stmt->close();
    } else {
        $result = $conn->query($sql . " ORDER BY r.office_code ASC, r.id ASC");
    }
    $routes = [];
    while ($row = $result->fetch_assoc()) {
        $points = json_decode((string) $row["points_json"], true);
        $routes[] = [
            "id" => (int) $row["id"],
            "office_code" => (string) $row["office_code"],
            "office_label" => appointment_office_label((string) $row["office_code"]),
            "name" => (string) $row["name"],
            "start_label" => (string) $row["start_label"],
            "method" => $row["method"] === "drawn" ? "drawn" : "walked",
            "points" => is_array($points) ? $points : [],
            "point_count" => (int) $row["point_count"],
            "distance_meters" => (float) $row["distance_meters"],
            "duration_seconds" => $row["duration_seconds"] === null ? null : (int) $row["duration_seconds"],
            "average_accuracy_meters" => $row["average_accuracy_meters"] === null ? null : (float) $row["average_accuracy_meters"],
            "created_by" => campus_route_person($row["created_first"], $row["created_last"], $row["created_display"], $row["created_username"]),
            "updated_by" => campus_route_person($row["updated_first"], $row["updated_last"], $row["updated_display"], $row["updated_username"]),
            "created_at" => (string) $row["created_at"],
            "updated_at" => (string) $row["updated_at"],
        ];
    }
    return $routes;
}
