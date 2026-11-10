<?php
declare(strict_types=1);
require_once __DIR__ . "/_bootstrap.php";
require_once __DIR__ . "/_appointments.php";

api_require_method("GET");
$user = api_require_visitor();
refresh_appointment_time_states($conn);
$appointmentId = max(0, (int) ($_GET["id"] ?? 0));
if ($appointmentId <= 0) {
    api_fail("Select a valid appointment", 422);
}
$row = mobile_owned_appointment($conn, $appointmentId, (int) $user["id"]);
if (!$row) {
    api_fail("Appointment not found", 404);
}
api_success(["appointment" => mobile_appointment_payload($conn, $row, true)]);

