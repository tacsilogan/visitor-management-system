<?php
declare(strict_types=1);
require_once __DIR__ . "/_bootstrap.php";
require_once dirname(__DIR__, 2) . "/appointment_offices.php";
require_once dirname(__DIR__, 2) . "/office_availability_service.php";

api_require_method("GET");
api_require_visitor();
$offices = [];
foreach (appointment_office_active_map() as $code => $label) {
    try {
        $settings = office_availability_get_settings($conn, $code);
    } catch (Throwable $error) {
        api_fail("Office availability database migration is required", 503, [], $error);
    }
    $rules = [];
    $stmt = $conn->prepare(
        "SELECT day_of_week, start_time, end_time, capacity_override
         FROM office_availability_rules
         WHERE office_code = ? AND is_active = 1
         ORDER BY day_of_week, start_time"
    );
    if ($stmt) {
        $stmt->bind_param("s", $code);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $rules[] = [
                "day_of_week" => (int) $row["day_of_week"],
                "start_time" => substr((string) $row["start_time"], 0, 5),
                "end_time" => substr((string) $row["end_time"], 0, 5),
                "capacity" => $row["capacity_override"] === null ? null : (int) $row["capacity_override"],
            ];
        }
        $stmt->close();
    }
    $offices[] = [
        "code" => $code,
        "name" => $label,
        "accepting_visitors" => (bool) $settings["accepting_visitors"],
        "unavailable_reason" => (string) $settings["unavailable_reason"],
        "available_again_at" => $settings["available_again_at"],
        "slot_duration_minutes" => (int) $settings["slot_duration_minutes"],
        "maximum_visitors_per_slot" => (int) $settings["maximum_visitors_per_slot"],
        "weekly_hours" => $rules,
    ];
}
api_success(["offices" => $offices, "timezone" => "Asia/Manila"]);

