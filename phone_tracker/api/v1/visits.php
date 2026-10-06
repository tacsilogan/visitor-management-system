<?php
declare(strict_types=1);
require_once __DIR__ . "/_bootstrap.php";
require_once __DIR__ . "/_visits.php";

api_require_method("POST");
$user = api_require_visitor();
if (!visit_schema_ready($conn)) {
    api_fail("Multi-stop visits require multi_stop_visits_migration.sql", 503);
}
$visitorUserId = (int) $user["id"];
$input = api_body();
api_rate_limit($conn, "appointment_create", (string) $visitorUserId, 20, 3600);
try {
    $visit = mobile_create_visit($conn, $user, $input);
    $isWalkIn = ($visit["stops"][0]["visit_type"] ?? "") === "walk_in";
    api_success(["visit" => $visit], 201, $isWalkIn
        ? "Walk-in visit pass ready. Present it to Security with a valid ID."
        : "Multi-stop visit submitted. Each office will review its own stop.");
} catch (InvalidArgumentException $error) {
    $errors = json_decode($error->getMessage(), true);
    api_fail("Check the visit details", 422, is_array($errors) ? $errors : []);
} catch (DomainException $error) {
    api_fail($error->getMessage(), 409);
} catch (Throwable $error) {
    api_fail("Could not create the multi-stop visit", 500, [], $error);
}
