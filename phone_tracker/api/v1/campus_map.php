<?php
declare(strict_types=1);
require_once __DIR__ . "/_bootstrap.php";
require_once __DIR__ . "/_appointments.php";
require_once dirname(__DIR__, 2) . "/presence_service.php";
require_once dirname(__DIR__, 2) . "/arrival_service.php";

// The visitor app's campus map and walking guidance (VISITOR_NAVIGATION.md): the campus
// boundary and gates, where the visitor is going, and the rules the server uses to confirm
// a campus exit. The values live on the server so moving a pin, a gate, or the boundary
// needs no app update.
// GET ?appointment_id= any of the visitor's own appointments (any stop of a visit).

/**
 * Where each department is, as written in the Department Directory. Empty until
 * personnel_directory_migration.sql is imported.
 *
 * @return array<string, array{location: string, description: string}>
 */
function campus_map_office_details(mysqli $conn): array
{
    $details = [];
    try {
        $result = $conn->query("SELECT code, location, description FROM offices");
        while ($result && ($office = $result->fetch_assoc())) {
            $details[(string) $office["code"]] = [
                "location" => trim((string) ($office["location"] ?? "")),
                "description" => trim((string) ($office["description"] ?? "")),
            ];
        }
    } catch (mysqli_sql_exception $ignored) {
        // The offices table is not installed yet.
    }
    return $details;
}

api_require_method("GET");
$user = api_require_visitor();
$appointmentId = max(0, (int) ($_GET["appointment_id"] ?? 0));
if ($appointmentId <= 0) {
    api_fail("Select a valid appointment", 422);
}
$row = mobile_owned_appointment($conn, $appointmentId, (int) $user["id"]);
if (!$row) {
    api_fail("Appointment not found", 404);
}

$arrivalDistance = (float) api_setting($conn, "arrival_distance_meters", ARRIVAL_DEFAULT_DISTANCE_METERS, 1, 30);
$campus = presence_campus($conn);
$pins = [];
foreach ($campus["offices"] as $pin) {
    $pins[$pin["code"]] = $pin;
}
$offices = campus_map_office_details($conn);
$visit = visit_for_appointment($conn, $appointmentId);
$stopRows = $visit ? visit_stops($conn, (int) $visit["id"]) : [$row];
$arrivals = arrival_lookup($conn, array_column($stopRows, "id"));
// Walking routes recorded in Campus Map → Routes (CAMPUS_ROUTES.md). Empty until
// campus_routes_migration.sql is imported.
$routes = campus_routes_load($conn);
// An office with a recorded route but no pin is found where its route ends (its door), so
// a route alone is enough to guide visitors there.
$routeEnds = campus_route_ends($routes);
$place = function (array $stop) use ($pins, $offices, $arrivals, $routeEnds): array {
    $code = (string) $stop["office_code"];
    $pin = $pins[$code] ?? null;
    $point = $pin ? [(float) $pin["latitude"], (float) $pin["longitude"]] : ($routeEnds[$code] ?? null);
    $arrival = $arrivals[(int) $stop["id"]] ?? arrival_public(null);
    return [
        "appointment_id" => (int) $stop["id"],
        "office_code" => $code,
        "label" => appointment_office_label($code),
        "location" => $offices[$code]["location"] ?? "",
        "description" => $offices[$code]["description"] ?? "",
        "latitude" => $point ? $point[0] : null,
        "longitude" => $point ? $point[1] : null,
        // Where the coordinates come from: "pin", "route" (the end of a recorded route), or null.
        "location_source" => $pin ? "pin" : ($point ? "route" : null),
        "status" => (string) $stop["status"],
        "visit_type" => (string) ($stop["visit_type"] ?? "appointment"),
        "scheduled_start_at" => $stop["scheduled_start_at"],
        "scheduled_end_at" => $stop["scheduled_end_at"],
        // Set once the visitor's arrival at this office was confirmed (arrival.php).
        "arrived_at" => $arrival["arrived_at"],
        "arrival_method" => $arrival["arrival_method"],
    ];
};

$stops = array_map($place, $stopRows);
// The app joins the routes into one walkway network, so it can guide along walkways to any
// office or gate instead of in a straight line.
$walkingRoutes = array_map(function (array $route): array {
    return [
        "id" => $route["id"],
        "office_code" => $route["office_code"],
        "name" => $route["name"],
        "start_label" => $route["start_label"],
        "distance_meters" => $route["distance_meters"],
        // [[latitude, longitude], ...] from the route's start (usually a gate) to the office.
        "points" => $route["points"],
    ];
}, $routes);
// Gate pins, plus the named starts of routes no gate pin marks ("Main Gate"), so "Guide me
// out" can lead back along a route to where it began.
$gates = array_map(function (array $gate): array {
    return [
        "name" => (string) $gate["name"],
        "latitude" => (float) $gate["latitude"],
        "longitude" => (float) $gate["longitude"],
    ];
}, $campus["gates"]);
$gates = array_merge($gates, campus_route_start_gates($routes, $gates));
// The destination is the first stop still to visit. Once every stop is done it is null,
// and the app guides the visitor back to a gate.
$destination = null;
foreach ($stops as $stop) {
    if (in_array($stop["status"], ["checked_in", "approved"], true)) {
        $destination = $stop;
        break;
    }
}

api_success([
    "appointment_id" => $appointmentId,
    "campus_configured" => (bool) $campus["configured"],
    "campus_updated_at" => $campus["updated_at"],
    "campus_boundary" => array_map(function (array $point): array {
        return ["latitude" => $point[0], "longitude" => $point[1]];
    }, $campus["boundary"]),
    "gates" => $gates,
    "destination" => $destination,
    "stops" => $stops,
    "walking_routes" => $walkingRoutes,
    "exit_policy" => [
        "minimum_accuracy_meters" => PRESENCE_MAX_ACCURACY_METERS,
        "outside_confirmation_points" => PRESENCE_EXIT_MIN_READINGS,
        "outside_confirmation_seconds" => PRESENCE_EXIT_CONFIRM_SECONDS,
        "boundary_buffer_meters" => PRESENCE_EDGE_TOLERANCE_METERS,
    ],
    // Arrival: GPS must place the visitor within arrival_distance_meters of the office pin,
    // from a reading accurate to arrival_max_accuracy_meters, for several readings in a row.
    "arrival_distance_meters" => $arrivalDistance,
    "arrival_max_accuracy_meters" => ARRIVAL_MAX_ACCURACY_METERS,
    // Older app versions read this name; it now carries the same strict distance.
    "arrival_radius_meters" => $arrivalDistance,
    "server_time" => date("Y-m-d H:i:s"),
]);
