<?php
declare(strict_types=1);
require_once __DIR__ . "/_bootstrap.php";
require_once __DIR__ . "/_visits.php";

api_require_method("POST");
$user = api_require_visitor();
if (!visit_schema_ready($conn)) {
    api_fail("Multi-stop visits require multi_stop_visits_migration.sql", 503);
}
$input = api_body();
$visitId = (int) ($input["visit_id"] ?? 0);
$reason = api_text($input, "reason", 500);
if ($visitId <= 0) {
    api_fail("Select a valid visit", 422);
}
try {
    $result = mobile_cancel_visit($conn, $visitId, (int) $user["id"], $reason);
    api_success($result, 200, "Visit cancelled");
} catch (DomainException $error) {
    api_fail($error->getMessage(), $error->getMessage() === "Visit not found" ? 404 : 409);
} catch (Throwable $error) {
    api_fail("Could not cancel the visit", 500, [], $error);
}
