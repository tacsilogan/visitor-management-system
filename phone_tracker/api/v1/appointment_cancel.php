<?php
declare(strict_types=1);
require_once __DIR__ . "/_bootstrap.php";
require_once __DIR__ . "/_appointments.php";

api_require_method("POST");
$user = api_require_visitor();
$input = api_body();
$appointmentId = (int) ($input["appointment_id"] ?? 0);
$reason = api_text($input, "reason", 500);
if ($appointmentId <= 0) {
    api_fail("Select a valid appointment", 422);
}
try {
    $result = mobile_cancel_appointment($conn, $appointmentId, (int) $user["id"], $reason);
    api_success($result, 200, "Appointment cancelled");
} catch (DomainException $error) {
    api_fail($error->getMessage(), $error->getMessage() === "Appointment not found" ? 404 : 409);
} catch (Throwable $error) {
    api_fail("Could not cancel the appointment", 500, [], $error);
}

