<?php
declare(strict_types=1);
require_once __DIR__ . "/_bootstrap.php";
require_once dirname(__DIR__, 2) . "/appointment_offices.php";
require_once dirname(__DIR__, 2) . "/office_availability_service.php";

api_require_method("GET");
$user = api_require_app_user();
if ($user["role"] === "student") {
    // Students walk in to offices; the app only books teachers for them.
    api_success(["offices" => [], "timezone" => "Asia/Manila"]);
}
$offices = [];
$now = new DateTimeImmutable("now");
// Where each office is, so the booking form can say it before the visitor comes.
$locations = [];
try {
    $locationRows = $conn->query("SELECT code, location FROM offices");
    while ($locationRows && ($row = $locationRows->fetch_assoc())) {
        $locations[(string) $row["code"]] = trim((string) ($row["location"] ?? ""));
    }
} catch (Throwable $ignored) {
    // The offices table is not installed yet.
}
foreach (appointment_office_bookable_map() as $code => $label) {
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
    $state = office_open_state($conn, $code, $now);
    $offices[] = [
        "code" => $code,
        "name" => $label,
        "location" => $locations[$code] ?? "",
        "accepting_visitors" => (bool) $settings["accepting_visitors"],
        "unavailable_reason" => (string) $settings["unavailable_reason"],
        "available_again_at" => $settings["available_again_at"],
        "slot_duration_minutes" => (int) $settings["slot_duration_minutes"],
        "maximum_visitors_per_slot" => (int) $settings["maximum_visitors_per_slot"],
        // Bookable windows: a day with a lunch break has two.
        "weekly_hours" => $rules,
        // The named breaks between (or around) those windows.
        "breaks" => array_map(fn(array $row): array => [
            "day_of_week" => (int) $row["day_of_week"],
            "start_time" => substr((string) $row["start_time"], 0, 5),
            "end_time" => substr((string) $row["end_time"], 0, 5),
            "label" => (string) $row["label"],
        ], office_load_break_rows($conn, $code)),
        // Right now: "open", "break", "closed", "paused" or "not_accepting", and a sentence for visitors.
        "open_status" => [
            "state" => $state["state"],
            "label" => $state["label"],
            "until" => $state["until"],
            "message" => office_state_message($state, $label, $now),
        ],
    ];
}
api_success(["offices" => $offices, "timezone" => "Asia/Manila"]);

