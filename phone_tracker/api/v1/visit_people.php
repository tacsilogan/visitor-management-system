<?php
declare(strict_types=1);
require_once __DIR__ . "/_bootstrap.php";
require_once __DIR__ . "/_appointments.php";

// The visitor changes who comes with them (GROUP_VISITS.md), until the guard checks them in:
// {"appointment_id": n} for a visit to one office, or {"visit_id": n} for a visit to several,
// with "people": [{"full_name": "…", "is_adult": true}] (an empty list for nobody).
api_require_method("POST");
$user = api_require_visitor();
$input = api_body();
$appointmentId = max(0, (int) ($input["appointment_id"] ?? 0));
$visitId = max(0, (int) ($input["visit_id"] ?? 0));
if (($appointmentId > 0) === ($visitId > 0)) {
    api_fail("Select one appointment or one visit", 422);
}
api_rate_limit($conn, "visit_people_change", (string) $user["id"], 30, 3600);
try {
    $people = visit_people_clean_booking($input["people"] ?? []);
    $saved = mobile_change_people($conn, (int) $user["id"], $appointmentId ?: null, $visitId ?: null, $people);
    api_success(["people" => $saved], 200, $saved ? "The people with you are saved" : "Nobody is coming with you");
} catch (InvalidArgumentException $error) {
    $errors = json_decode($error->getMessage(), true);
    api_fail("Check the people with you", 422, is_array($errors) ? $errors : []);
} catch (DomainException $error) {
    $notFound = in_array($error->getMessage(), ["Appointment not found", "Visit not found"], true);
    api_fail($error->getMessage(), $notFound ? 404 : 409);
} catch (Throwable $error) {
    api_fail("Could not save the people with you", 500, [], $error);
}
