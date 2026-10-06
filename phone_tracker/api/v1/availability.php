<?php
declare(strict_types=1);
require_once __DIR__ . "/_bootstrap.php";
require_once dirname(__DIR__, 2) . "/appointment_offices.php";
require_once dirname(__DIR__, 2) . "/office_availability_service.php";

api_require_method("GET");
api_require_visitor();
$officeCode = strtoupper(trim((string) ($_GET["office_code"] ?? "")));
$dateText = trim((string) ($_GET["date"] ?? ""));
$officeMap = appointment_office_active_map();
if (!isset($officeMap[$officeCode])) {
    api_fail("Select a valid office", 422);
}
$date = DateTimeImmutable::createFromFormat("!Y-m-d", $dateText);
$errors = DateTimeImmutable::getLastErrors();
if (!$date || ($errors !== false && ((int) $errors["warning_count"] > 0 || (int) $errors["error_count"] > 0))) {
    api_fail("Date must use YYYY-MM-DD", 422);
}
$today = new DateTimeImmutable("today");
if ($date < $today || $date > $today->modify("+180 days")) {
    api_fail("Choose a date within the next 180 days", 422);
}

try {
    $settings = office_availability_get_settings($conn, $officeCode);
} catch (Throwable $error) {
    api_fail("Office availability database migration is required", 503, [], $error);
}
if (!(int) $settings["accepting_visitors"]) {
    api_success([
        "office_code" => $officeCode,
        "date" => $dateText,
        "slots" => [],
        "accepting_visitors" => false,
        "message" => (string) ($settings["unavailable_reason"] ?: "This office is not accepting visitors right now."),
    ]);
}
$duration = max(15, (int) $settings["slot_duration_minutes"]);
$windows = [];
$day = (int) $date->format("N");
$rule = $conn->prepare(
    "SELECT start_time, end_time FROM office_availability_rules
     WHERE office_code = ? AND day_of_week = ? AND is_active = 1 ORDER BY start_time"
);
$rule->bind_param("si", $officeCode, $day);
$rule->execute();
$ruleResult = $rule->get_result();
while ($row = $ruleResult->fetch_assoc()) {
    $windows[] = [substr((string) $row["start_time"], 0, 5), substr((string) $row["end_time"], 0, 5)];
}
$rule->close();
if (!$windows) {
    $countRules = $conn->prepare(
        "SELECT COUNT(*) AS total FROM office_availability_rules WHERE office_code = ? AND is_active = 1"
    );
    $countRules->bind_param("s", $officeCode);
    $countRules->execute();
    $hasConfiguredRules = (int) ($countRules->get_result()->fetch_assoc()["total"] ?? 0) > 0;
    $countRules->close();
    if (!$hasConfiguredRules) {
        $windows[] = ["08:00", "17:00"];
    }
}
$extra = $conn->prepare(
    "SELECT starts_at, ends_at FROM office_availability_exceptions
     WHERE office_code = ? AND is_available = 1 AND DATE(starts_at) = ? ORDER BY starts_at"
);
$extra->bind_param("ss", $officeCode, $dateText);
$extra->execute();
$extraResult = $extra->get_result();
while ($row = $extraResult->fetch_assoc()) {
    $windows[] = [date("H:i", strtotime((string) $row["starts_at"])), date("H:i", strtotime((string) $row["ends_at"]))];
}
$extra->close();

$slots = [];
$seen = [];
foreach ($windows as [$startTime, $endTime]) {
    $cursor = new DateTime($dateText . " " . $startTime . ":00");
    $windowEnd = new DateTime($dateText . " " . $endTime . ":00");
    while ($cursor < $windowEnd) {
        $end = clone $cursor;
        $end->modify("+{$duration} minutes");
        if ($end > $windowEnd) {
            break;
        }
        $key = $cursor->format("Y-m-d H:i:s");
        if (!isset($seen[$key]) && $cursor > new DateTime("now")) {
            $availability = office_availability_check($conn, $officeCode, $cursor, $end);
            if ($availability["available"]) {
                $slots[] = [
                    "scheduled_start_at" => $cursor->format("Y-m-d H:i:s"),
                    "scheduled_end_at" => $end->format("Y-m-d H:i:s"),
                    "remaining_capacity" => (int) $availability["remaining"],
                ];
            }
            $seen[$key] = true;
        }
        $cursor->modify("+{$duration} minutes");
    }
}
usort($slots, fn(array $a, array $b): int => strcmp($a["scheduled_start_at"], $b["scheduled_start_at"]));
api_success([
    "office_code" => $officeCode,
    "office_name" => $officeMap[$officeCode],
    "date" => $dateText,
    "timezone" => "Asia/Manila",
    "slot_duration_minutes" => $duration,
    "accepting_visitors" => true,
    "slots" => $slots,
]);
