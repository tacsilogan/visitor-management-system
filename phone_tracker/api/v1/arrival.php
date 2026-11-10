<?php
declare(strict_types=1);
require_once __DIR__ . "/_bootstrap.php";
require_once dirname(__DIR__, 2) . "/arrival_service.php";

// The visitor app reports a confirmed arrival at an office stop (VISITOR_NAVIGATION.md).
// POST {appointment_id, method: "gps" | "visitor", distance_meters?, accuracy_meters?}
// appointment_id is the stop (for a visit to several offices, the stop's own appointment).

api_require_method("POST");
$user = api_require_visitor();
$input = api_body();
$appointmentId = (int) ($input["appointment_id"] ?? 0);
if ($appointmentId <= 0) {
    api_fail("Select a valid appointment", 422);
}
$method = strtolower(api_text($input, "method", 10));
$readMeters = function (string $key, float $maximum) use ($input): ?float {
    if (!isset($input[$key]) || $input[$key] === null || $input[$key] === "") {
        return null;
    }
    if (!is_numeric($input[$key])) {
        api_fail("Invalid " . str_replace("_", " ", $key), 422);
    }
    $value = (float) $input[$key];
    if ($value < 0 || $value > $maximum) {
        api_fail("Invalid " . str_replace("_", " ", $key), 422);
    }
    return round($value, 1);
};
$distance = $readMeters("distance_meters", 99999);
$accuracy = $readMeters("accuracy_meters", 99999);
$arrivalDistance = (float) api_setting($conn, "arrival_distance_meters", ARRIVAL_DEFAULT_DISTANCE_METERS, 1, 30);

try {
    $arrival = arrival_record($conn, $appointmentId, (int) $user["id"], $method, $distance, $accuracy, $arrivalDistance);
} catch (mysqli_sql_exception $error) {
    // A database error: log it and keep its details away from the client.
    error_log("Arrival could not be recorded for appointment {$appointmentId}: " . $error->getMessage());
    api_fail("Could not record your arrival. Please try again.", 500);
} catch (RuntimeException $error) {
    $status = $error->getCode();
    api_fail($error->getMessage(), $status >= 400 && $status < 600 ? $status : 500);
} catch (Throwable $error) {
    error_log("Arrival could not be recorded for appointment {$appointmentId}: " . $error->getMessage());
    api_fail("Could not record your arrival. Please try again.", 500);
}

api_success(
    ["appointment_id" => $appointmentId] + $arrival,
    200,
    $arrival["already_recorded"] ? "Arrival was already recorded" : "Arrival recorded"
);
