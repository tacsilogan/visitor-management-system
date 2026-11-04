<?php
/**
 * Checks the weekly-break logic in office_availability_service.php without a database:
 *
 *   php phone_tracker/tools/office_breaks_test.php
 *
 * Exits with 1 and lists what failed when a check does not hold.
 */
declare(strict_types=1);

date_default_timezone_set("Asia/Manila");
require_once dirname(__DIR__) . "/office_availability_service.php";

$checks = 0;
$failures = 0;

function check(bool $holds, string $what): void
{
    global $checks, $failures;
    $checks++;
    if (!$holds) {
        $failures++;
        fwrite(STDERR, "FAIL: {$what}\n");
    }
}

function same(mixed $actual, mixed $expected, string $what): void
{
    check($actual === $expected, $what . "\n      expected " . var_export($expected, true) . "\n      got      " . var_export($actual, true));
}

function fails_with(callable $action, string $message, string $what): void
{
    try {
        $action();
        check(false, "{$what}: no error");
    } catch (RuntimeException $error) {
        check(str_contains($error->getMessage(), $message), "{$what}: the error was \"{$error->getMessage()}\"");
    }
}

function day(int $dayOfWeek, string $open, string $close, array $breaks = []): array
{
    return [
        "day_of_week" => $dayOfWeek,
        "enabled" => true,
        "start_time" => $open,
        "end_time" => $close,
        "breaks" => array_map(fn(array $break): array => [
            "start_time" => $break[0],
            "end_time" => $break[1],
            "label" => $break[2] ?? "",
        ], $breaks),
    ];
}

function at(string $dateTime): DateTimeImmutable
{
    return new DateTimeImmutable($dateTime);
}

const LUNCH = ["12:00", "13:00", "Lunch break"];

// 2026-11-02 is a Monday.
$weekdays = [];
foreach ([1, 2, 3, 4, 5] as $weekday) {
    $weekdays[] = day($weekday, "08:00", "17:00", [LUNCH]);
}
$week = office_normalize_week($weekdays);
$windows = [];
$breaks = [];
foreach ($week as $weekday => $schedule) {
    $windows[$weekday] = $schedule["windows"];
    $breaks[$weekday] = $schedule["breaks"];
}
$open = ["accepting_visitors" => 1];

// Clock text.
same(office_minutes_clock(0), "12:00 AM", "midnight");
same(office_minutes_clock(720), "12:00 PM", "noon");
same(office_minutes_clock(13 * 60 + 5), "1:05 PM", "afternoon");
same(office_time_minutes("8:30"), 510, "a one-digit hour");
same(office_time_minutes("24:00"), null, "no hour 24");
same(office_time_minutes("noon"), null, "not a time");

// Windows: the hours with the breaks cut out.
same(office_day_windows(480, 1020, [["start" => 720, "end" => 780]]), [[480, 720], [780, 1020]], "a lunch break splits the day");
same(office_day_windows(480, 1020, [["start" => 480, "end" => 510]]), [[510, 1020]], "a break at the start of the day");
same(office_day_windows(480, 1020, [["start" => 960, "end" => 1020]]), [[480, 960]], "a break at the end of the day");
same(office_day_windows(480, 1020, [["start" => 720, "end" => 750], ["start" => 750, "end" => 780]]), [[480, 720], [780, 1020]], "breaks that touch");
same($week[1]["windows"], [[480, 720], [780, 1020]], "Monday's windows");
same(array_keys($week), [1, 2, 3, 4, 5], "only the open days");
same($week[1]["breaks"][0]["label"], "Lunch break", "the break keeps its name");

// What the dashboard may not save.
fails_with(fn() => office_normalize_week([day(1, "17:00", "08:00")]), "closing time must be after", "hours the wrong way round");
fails_with(fn() => office_normalize_week([day(1, "08:00", "17:00", [["11:00", "18:00", "Lunch"]])]), "must be within the visitor hours", "a break past closing");
fails_with(fn() => office_normalize_week([day(1, "08:00", "17:00", [["12:00", "13:00", "Lunch"], ["12:30", "13:30", "Meeting"]])]), "Lunch and Meeting overlap", "overlapping breaks");
fails_with(fn() => office_normalize_week([day(1, "08:00", "17:00", [["13:00", "12:00", "Lunch"]])]), "needs an end time after its start", "a break the wrong way round");
fails_with(fn() => office_normalize_week([day(1, "08:00", "12:00", [["08:00", "12:00", "All day"]])]), "Close the day instead", "a break as long as the day");
fails_with(fn() => office_normalize_week([day(1, "08:00", "17:00", [["08:00", "08:30"], ["09:00", "09:30"], ["10:00", "10:30"], ["11:00", "11:30"], ["12:00", "12:30"]])]), "at most 4 breaks", "too many breaks");
fails_with(fn() => office_normalize_week([day(1, "08:00", "17:00"), day(1, "09:00", "17:00")]), "repeated day", "the same day twice");
same(office_normalize_week([day(1, "08:00", "17:00", [["12:00", "13:00", "   "]])])[1]["breaks"][0]["label"], "Break", "an unnamed break");
same(office_normalize_week([day(1, "08:00", "17:00", [["12:00", "13:00", "  Lunch   break "]])])[1]["breaks"][0]["label"], "Lunch break", "spaces in a name");
same(office_normalize_week([["day_of_week" => 6, "enabled" => false, "start_time" => "x", "end_time" => "y"]]), [], "a closed day is not checked");

// Back to the dashboard: the saved windows and names become the same week again.
$rules = [];
$breakRows = [];
foreach ($week as $weekday => $schedule) {
    foreach ($schedule["windows"] as [$start, $end]) {
        $rules[] = ["day_of_week" => $weekday, "start_time" => office_minutes_sql($start), "end_time" => office_minutes_sql($end), "is_active" => 1];
    }
    foreach ($schedule["breaks"] as $break) {
        $breakRows[] = ["day_of_week" => $weekday, "start_time" => office_minutes_sql($break["start"]), "end_time" => office_minutes_sql($break["end"]), "label" => $break["label"]];
    }
}
$editing = office_week_for_editing($rules, $breakRows);
same(count($editing), 7, "seven days to edit");
same($editing[0], ["day_of_week" => 1, "enabled" => true, "start_time" => "08:00", "end_time" => "17:00", "breaks" => [["start_time" => "12:00", "end_time" => "13:00", "label" => "Lunch break"]]], "Monday comes back as saved");
same($editing[5]["enabled"], false, "Saturday stays closed");
same(office_normalize_week($editing), $week, "a round trip changes nothing");
same(office_week_for_editing(array_slice($rules, 0, 2), [])[0]["breaks"], [["start_time" => "12:00", "end_time" => "13:00", "label" => "Break"]], "a gap without a saved name");
$flagDay = office_normalize_week([day(1, "08:00", "17:00", [["08:00", "08:30", "Flag ceremony"], ["16:30", "17:00", "Cleanup"]])])[1];
same($flagDay["windows"], [[510, 990]], "breaks at both ends of the day");
same(
    office_week_for_editing(
        [["day_of_week" => 1, "start_time" => "08:30:00", "end_time" => "16:30:00"]],
        [
            ["day_of_week" => 1, "start_time" => "08:00:00", "end_time" => "08:30:00", "label" => "Flag ceremony"],
            ["day_of_week" => 1, "start_time" => "16:30:00", "end_time" => "17:00:00", "label" => "Cleanup"],
        ]
    )[0],
    ["day_of_week" => 1, "enabled" => true, "start_time" => "08:00", "end_time" => "17:00", "breaks" => [
        ["start_time" => "08:00", "end_time" => "08:30", "label" => "Flag ceremony"],
        ["start_time" => "16:30", "end_time" => "17:00", "label" => "Cleanup"],
    ]],
    "breaks at both ends come back"
);
same(
    office_week_for_editing(
        [["day_of_week" => 1, "start_time" => "08:00:00", "end_time" => "12:00:00"], ["day_of_week" => 1, "start_time" => "13:00:00", "end_time" => "17:00:00"]],
        [
            ["day_of_week" => 1, "start_time" => "12:00:00", "end_time" => "12:30:00", "label" => "Lunch"],
            ["day_of_week" => 1, "start_time" => "12:30:00", "end_time" => "13:00:00", "label" => "Prayer"],
        ]
    )[0]["breaks"],
    [["start_time" => "12:00", "end_time" => "12:30", "label" => "Lunch"], ["start_time" => "12:30", "end_time" => "13:00", "label" => "Prayer"]],
    "two breaks back to back"
);

// Where the office stands now.
$state = office_open_state_at(at("2026-11-02 10:00"), $open, $windows, $breaks, []);
same([$state["state"], $state["until"], $state["next"]], ["open", "2026-11-02 12:00:00", "Lunch break"], "Monday morning");
$state = office_open_state_at(at("2026-11-02 12:30"), $open, $windows, $breaks, []);
same([$state["state"], $state["label"], $state["until"]], ["break", "Lunch break", "2026-11-02 13:00:00"], "lunch time");
$state = office_open_state_at(at("2026-11-02 16:00"), $open, $windows, $breaks, []);
same([$state["state"], $state["next"]], ["open", "Closing"], "late afternoon");
$state = office_open_state_at(at("2026-11-02 07:00"), $open, $windows, $breaks, []);
same([$state["state"], $state["until"]], ["closed", "2026-11-02 08:00:00"], "before opening");
$state = office_open_state_at(at("2026-11-02 18:00"), $open, $windows, $breaks, []);
same([$state["state"], $state["until"]], ["closed", "2026-11-03 08:00:00"], "after closing");
$state = office_open_state_at(at("2026-11-06 18:00"), $open, $windows, $breaks, []);
same([$state["state"], $state["until"]], ["closed", "2026-11-09 08:00:00"], "Friday evening, over the weekend");
$state = office_open_state_at(at("2026-11-02 10:00"), ["accepting_visitors" => 0, "unavailable_reason" => "Inventory week", "available_again_at" => "2026-11-09 08:00:00"], $windows, $breaks, []);
same([$state["state"], $state["label"]], ["not_accepting", "Inventory week"], "switched off");
$meeting = ["id" => 7, "starts_at" => "2026-11-02 10:00:00", "ends_at" => "2026-11-02 11:00:00", "is_available" => 0, "reason" => "Staff meeting"];
$state = office_open_state_at(at("2026-11-02 10:30"), $open, $windows, $breaks, [$meeting]);
same([$state["state"], $state["label"], $state["until"], $state["pause_id"]], ["paused", "Staff meeting", "2026-11-02 11:00:00", 7], "during a posted meeting");
$state = office_open_state_at(at("2026-11-02 09:00"), $open, $windows, $breaks, [$meeting]);
same([$state["state"], $state["until"], $state["next"]], ["open", "2026-11-02 10:00:00", "Staff meeting"], "open until a posted meeting");
$early = ["id" => 8, "starts_at" => "2026-11-02 07:30:00", "ends_at" => "2026-11-02 09:00:00", "is_available" => 0, "reason" => "Late start"];
$state = office_open_state_at(at("2026-11-02 07:00"), $open, $windows, $breaks, [$early]);
same([$state["state"], $state["until"]], ["closed", "2026-11-02 09:00:00"], "a late start moves the opening");
$saturday = ["id" => 9, "starts_at" => "2026-11-07 09:00:00", "ends_at" => "2026-11-07 12:00:00", "is_available" => 1, "reason" => "Enrollment Saturday"];
$state = office_open_state_at(at("2026-11-07 10:00"), $open, $windows, $breaks, [$saturday]);
same([$state["state"], $state["label"], $state["until"]], ["open", "Enrollment Saturday", "2026-11-07 12:00:00"], "posted extra hours");
same(office_open_state_at(at("2026-11-07 10:00"), $open, [], [], [])["state"], "open", "no weekly hours: always open");
$flagWindows = [1 => $flagDay["windows"]];
$flagBreaks = [1 => $flagDay["breaks"]];
$state = office_open_state_at(at("2026-11-02 08:10"), $open, $flagWindows, $flagBreaks, []);
same([$state["state"], $state["label"], $state["until"]], ["break", "Flag ceremony", "2026-11-02 08:30:00"], "during the flag ceremony");
$state = office_open_state_at(at("2026-11-02 12:30"), $open, [1 => [[480, 720], [780, 1020]]], [], []);
same([$state["state"], $state["label"]], ["break", "Break"], "a gap without a saved name");

// Walk-ins: blocked during a break or closed hours, unless the office opens within 30 minutes.
$monday = fn(string $time): DateTimeImmutable => at("2026-11-02 {$time}");
$reason = office_walk_in_block_reason_for(office_open_state_at($monday("12:10"), $open, $windows, $breaks, []), "IT Department", $monday("12:10"));
same($reason, "IT Department is closed for Lunch break until 1:00 PM. You can create a walk-in pass from 12:30 PM, or book an appointment for later.", "a walk-in at lunch");
same(office_walk_in_block_reason_for(office_open_state_at($monday("12:40"), $open, $windows, $breaks, []), "IT Department", $monday("12:40")), null, "a walk-in near the end of lunch");
same(office_walk_in_block_reason_for(office_open_state_at($monday("07:45"), $open, $windows, $breaks, []), "IT Department", $monday("07:45")), null, "a walk-in just before opening");
same(office_walk_in_block_reason_for(office_open_state_at($monday("10:00"), $open, $windows, $breaks, []), "IT Department", $monday("10:00")), null, "a walk-in while open");
same(
    office_walk_in_block_reason_for(office_open_state_at($monday("06:00"), $open, $windows, $breaks, []), "IT Department", $monday("06:00")),
    "IT Department is closed now and opens again at 8:00 AM. You can book an appointment for later.",
    "a walk-in at dawn"
);
same(
    office_walk_in_block_reason_for(office_open_state_at(at("2026-11-06 18:00"), $open, $windows, $breaks, []), "IT Department", at("2026-11-06 18:00")),
    "IT Department is closed now and opens again on Monday at 8:00 AM. You can book an appointment for later.",
    "a walk-in on Friday evening"
);
same(
    office_walk_in_block_reason_for(office_open_state_at($monday("10:30"), $open, $windows, $breaks, [$meeting]), "IT Department", $monday("10:30")),
    "IT Department is unavailable until 11:00 AM: Staff meeting.",
    "a walk-in during a posted meeting"
);
same(
    office_walk_in_block_reason_for(office_open_state_at($monday("12:40"), $open, [1 => [[480, 720], [780, 1020]]], [], []), "IT Department", $monday("12:40")),
    null,
    "an unnamed break ending within 30 minutes"
);
same(
    office_walk_in_block_reason_for(office_open_state_at($monday("12:10"), $open, [1 => [[480, 720], [780, 1020]]], [], []), "IT Department", $monday("12:10")),
    "IT Department is closed for a break until 1:00 PM. You can create a walk-in pass from 12:30 PM, or book an appointment for later.",
    "an unnamed break"
);

// Visits a new schedule would leave out.
same(office_conflict_reason($week, $monday("12:00"), $monday("13:00")), "During Lunch break (12:00 PM – 1:00 PM)", "a visit at lunch");
same(office_conflict_reason($week, $monday("11:30"), $monday("12:30")), "During Lunch break (12:00 PM – 1:00 PM)", "a visit running into lunch");
same(office_conflict_reason($week, $monday("10:00"), $monday("11:00")), null, "a morning visit");
same(office_conflict_reason($week, at("2026-11-07 10:00"), at("2026-11-07 11:00")), "Saturdays are now closed", "a Saturday visit");
same(office_conflict_reason($week, $monday("16:30"), $monday("17:30")), "Outside the new hours (8:00 AM – 5:00 PM)", "a visit past closing");

// Dates in sentences.
same(office_when("2026-11-02 13:00:00", $monday("10:00")), "at 1:00 PM", "later today");
same(office_when("2026-11-03 08:00:00", $monday("18:00")), "tomorrow at 8:00 AM", "tomorrow");
same(office_when("2026-11-06 08:00:00", $monday("18:00")), "on Friday at 8:00 AM", "later this week");
same(office_when("2026-11-12 08:00:00", $monday("18:00")), "on Nov 12 at 8:00 AM", "more than a week away");

if ($failures > 0) {
    fwrite(STDERR, "{$failures} of {$checks} checks failed.\n");
    exit(1);
}
echo "All {$checks} checks passed.\n";
