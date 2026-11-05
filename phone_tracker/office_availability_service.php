<?php

/**
 * Shared availability rules used by visitor booking, office approval, and
 * reschedule proposals. Keeping this in one file prevents the UI and API from
 * disagreeing about whether a time is bookable.
 */

function office_availability_get_settings(mysqli $conn, string $officeCode): array
{
    $insert = $conn->prepare(
        "INSERT IGNORE INTO office_availability_settings (office_code) VALUES (?)"
    );
    if ($insert) {
        $insert->bind_param("s", $officeCode);
        $insert->execute();
        $insert->close();
    }

    $stmt = $conn->prepare(
        "SELECT office_code, accepting_visitors, slot_duration_minutes,
                maximum_visitors_per_slot, unavailable_reason, available_again_at,
                updated_at
         FROM office_availability_settings
         WHERE office_code = ? LIMIT 1"
    );
    if (!$stmt) {
        throw new RuntimeException("Availability settings are not installed");
    }
    $stmt->bind_param("s", $officeCode);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: [
        "office_code" => $officeCode,
        "accepting_visitors" => 1,
        "slot_duration_minutes" => 30,
        "maximum_visitors_per_slot" => 1,
        "unavailable_reason" => "",
        "available_again_at" => null,
        "updated_at" => null,
    ];
}

/**
 * @return array{available:bool,message:string,capacity:int,used:int,remaining:int,source:string}
 */
function office_availability_check(
    mysqli $conn,
    string $officeCode,
    DateTimeInterface $start,
    DateTimeInterface $end,
    int $excludeAppointmentId = 0
): array {
    $result = [
        "available" => false,
        "message" => "This time is not available.",
        "capacity" => 0,
        "used" => 0,
        "remaining" => 0,
        "source" => "settings",
    ];

    if ($end <= $start || $start->format("Y-m-d") !== $end->format("Y-m-d")) {
        $result["message"] = "Appointments must start and end on the same day.";
        return $result;
    }

    $settings = office_availability_get_settings($conn, $officeCode);
    if (!(int) $settings["accepting_visitors"]) {
        $message = trim((string) $settings["unavailable_reason"]);
        $message = $message !== "" ? $message : "This office is not accepting visitors right now.";
        if (!empty($settings["available_again_at"])) {
            $message .= " Available again: " . date("M j, Y g:i A", strtotime((string) $settings["available_again_at"])) . ".";
        }
        $result["message"] = $message;
        return $result;
    }

    $slotDurationMinutes = max(15, (int) $settings["slot_duration_minutes"]);
    $requestedDurationMinutes = (int) round(($end->getTimestamp() - $start->getTimestamp()) / 60);
    if ($requestedDurationMinutes !== $slotDurationMinutes) {
        $result["message"] = "Appointments for this office use {$slotDurationMinutes}-minute time slots.";
        return $result;
    }

    $startSql = $start->format("Y-m-d H:i:s");
    $endSql = $end->format("Y-m-d H:i:s");
    $capacity = max(1, (int) $settings["maximum_visitors_per_slot"]);
    $scheduleAllowed = false;
    $source = "default";
    $slotAnchorTimestamp = null;

    $exception = $conn->prepare(
        "SELECT id, starts_at, ends_at, is_available, capacity_override, reason
         FROM office_availability_exceptions
         WHERE office_code = ? AND starts_at < ? AND ends_at > ?
         ORDER BY id DESC LIMIT 1"
    );
    if ($exception) {
        $exception->bind_param("sss", $officeCode, $endSql, $startSql);
        $exception->execute();
        $exceptionRow = $exception->get_result()->fetch_assoc();
        $exception->close();
        if ($exceptionRow) {
            $exceptionStarts = strtotime((string) $exceptionRow["starts_at"]);
            $exceptionEnds = strtotime((string) $exceptionRow["ends_at"]);
            $coversRequest = $exceptionStarts <= $start->getTimestamp() && $exceptionEnds >= $end->getTimestamp();
            if (!(int) $exceptionRow["is_available"] || !$coversRequest) {
                $reason = trim((string) $exceptionRow["reason"]);
                $result["message"] = $reason !== "" ? $reason : "The office is unavailable during this time.";
                $result["source"] = "exception";
                return $result;
            }
            $scheduleAllowed = true;
            $source = "exception";
            $slotAnchorTimestamp = $exceptionStarts;
            if ($exceptionRow["capacity_override"] !== null) {
                $capacity = max(1, (int) $exceptionRow["capacity_override"]);
            }
        }
    }

    if (!$scheduleAllowed) {
        $countStmt = $conn->prepare(
            "SELECT COUNT(*) AS total FROM office_availability_rules
             WHERE office_code = ? AND is_active = 1"
        );
        $ruleCount = 0;
        if ($countStmt) {
            $countStmt->bind_param("s", $officeCode);
            $countStmt->execute();
            $ruleCount = (int) ($countStmt->get_result()->fetch_assoc()["total"] ?? 0);
            $countStmt->close();
        }

        // Existing installations had no weekly rules. Until an office saves a
        // schedule, remain backward compatible and allow times under the global setting.
        if ($ruleCount === 0) {
            $scheduleAllowed = true;
            $source = "default";
        } else {
            $dayOfWeek = (int) $start->format("N");
            $startTime = $start->format("H:i:s");
            $endTime = $end->format("H:i:s");
            $rule = $conn->prepare(
                "SELECT start_time, end_time, capacity_override
                 FROM office_availability_rules
                 WHERE office_code = ? AND day_of_week = ? AND is_active = 1
                   AND start_time <= ? AND end_time >= ?
                 ORDER BY start_time ASC LIMIT 1"
            );
            if ($rule) {
                $rule->bind_param("siss", $officeCode, $dayOfWeek, $startTime, $endTime);
                $rule->execute();
                $ruleRow = $rule->get_result()->fetch_assoc();
                $rule->close();
                if ($ruleRow) {
                    $scheduleAllowed = true;
                    $source = "weekly_schedule";
                    $slotAnchorTimestamp = strtotime($start->format("Y-m-d") . " " . (string) $ruleRow["start_time"]);
                    if ($ruleRow["capacity_override"] !== null) {
                        $capacity = max(1, (int) $ruleRow["capacity_override"]);
                    }
                }
            }
        }
    }

    if (!$scheduleAllowed) {
        $result["message"] = office_break_message($conn, $officeCode, $start, $end)
            ?? "The selected time is outside this office's visitor hours.";
        $result["source"] = "weekly_schedule";
        return $result;
    }

    if ($slotAnchorTimestamp !== null) {
        $offsetSeconds = $start->getTimestamp() - $slotAnchorTimestamp;
        if ($offsetSeconds < 0 || $offsetSeconds % ($slotDurationMinutes * 60) !== 0) {
            $result["message"] = "Choose a time that follows the office's {$slotDurationMinutes}-minute appointment slots.";
            $result["source"] = $source;
            return $result;
        }
    }

    $used = 0;
    $appointments = $conn->prepare(
        "SELECT COUNT(*) AS total
         FROM appointments
         WHERE office_code = ?
           AND status IN ('pending_approval', 'reschedule_proposed', 'approved', 'checked_in')
           AND scheduled_start_at < ? AND scheduled_end_at > ?
           AND id <> ?"
    );
    if ($appointments) {
        $appointments->bind_param("sssi", $officeCode, $endSql, $startSql, $excludeAppointmentId);
        $appointments->execute();
        $used += (int) ($appointments->get_result()->fetch_assoc()["total"] ?? 0);
        $appointments->close();
    }

    // Pending proposed slots temporarily hold capacity so the office does not
    // offer the same last slot to several visitors.
    $proposals = $conn->prepare(
        "SELECT COUNT(*) AS total
         FROM appointment_reschedule_slots s
         INNER JOIN appointment_reschedule_proposals p ON p.id = s.proposal_id AND p.status = 'pending'
         INNER JOIN appointments a ON a.id = p.appointment_id
         WHERE a.office_code = ? AND a.id <> ?
           AND s.scheduled_start_at < ? AND s.scheduled_end_at > ?"
    );
    if ($proposals) {
        $proposals->bind_param("siss", $officeCode, $excludeAppointmentId, $endSql, $startSql);
        $proposals->execute();
        $used += (int) ($proposals->get_result()->fetch_assoc()["total"] ?? 0);
        $proposals->close();
    }

    $remaining = max(0, $capacity - $used);
    return [
        "available" => $remaining > 0,
        "message" => $remaining > 0 ? "Time is available." : "This appointment time is already full.",
        "capacity" => $capacity,
        "used" => $used,
        "remaining" => $remaining,
        "source" => $source,
    ];
}

/*
 * Breaks in the weekly visitor hours (a lunch break, a flag ceremony, a staff meeting).
 *
 * The bookable time is saved in office_availability_rules with the breaks cut out: Monday
 * 8:00-5:00 with lunch 12:00-1:00 becomes the windows 8:00-12:00 and 1:00-5:00. So
 * office_availability_check() and every visitor slot list skip breaks on their own, and
 * office_availability_breaks (office_breaks_migration.sql) only keeps each break's name and
 * span. Before that migration runs, breaks still work; they are just all called "Break".
 *
 * Inside these functions a time of day is a number of minutes after midnight.
 */

const OFFICE_DAY_NAMES = [
    1 => "Monday", 2 => "Tuesday", 3 => "Wednesday", 4 => "Thursday",
    5 => "Friday", 6 => "Saturday", 7 => "Sunday",
];
const OFFICE_MAX_BREAKS_PER_DAY = 4;
const OFFICE_BREAK_LABEL_MAX = 60;
const OFFICE_DEFAULT_BREAK_LABEL = "Break";
/** A walk-in pass lasts an hour, so one may be created this long before an office opens again. */
const OFFICE_WALK_IN_LEAD_MINUTES = 30;

/** "08:30" or "08:30:00" as minutes after midnight, or null when it is not a time of day. */
function office_time_minutes(string $value): ?int
{
    if (!preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', trim($value), $parts)) {
        return null;
    }
    $hours = (int) $parts[1];
    $minutes = (int) $parts[2];
    return $hours <= 23 && $minutes <= 59 ? $hours * 60 + $minutes : null;
}

/** "08:30:00", for a TIME column. */
function office_minutes_sql(int $minutes): string
{
    return sprintf("%02d:%02d:00", intdiv($minutes, 60), $minutes % 60);
}

/** "08:30", the value of a time field on the dashboard. */
function office_minutes_input(int $minutes): string
{
    return sprintf("%02d:%02d", intdiv($minutes, 60), $minutes % 60);
}

/** "8:30 AM". */
function office_minutes_clock(int $minutes): string
{
    $hours = intdiv($minutes, 60) % 24;
    return sprintf("%d:%02d %s", $hours % 12 === 0 ? 12 : $hours % 12, $minutes % 60, $hours < 12 ? "AM" : "PM");
}

function office_break_label(string $label): string
{
    $label = trim((string) preg_replace('/\s+/u', " ", $label));
    return $label === "" ? OFFICE_DEFAULT_BREAK_LABEL : mb_substr($label, 0, OFFICE_BREAK_LABEL_MAX);
}

/** A break for a sentence: its name, or "a break" when it has none ("closed for a break"). */
function office_break_phrase(string $label): string
{
    return $label === OFFICE_DEFAULT_BREAK_LABEL ? "a break" : $label;
}

/**
 * A day's bookable windows: its visitor hours with the breaks cut out.
 *
 * @param list<array{start:int,end:int}> $breaks sorted, not overlapping, within the hours
 * @return list<array{0:int,1:int}>
 */
function office_day_windows(int $open, int $close, array $breaks): array
{
    $windows = [];
    $cursor = $open;
    foreach ($breaks as $break) {
        if ($break["start"] > $cursor) {
            $windows[] = [$cursor, $break["start"]];
        }
        $cursor = max($cursor, $break["end"]);
    }
    if ($close > $cursor) {
        $windows[] = [$cursor, $close];
    }
    return $windows;
}

/**
 * Checks the weekly schedule the dashboard sends and works out each open day's windows.
 *
 * @param array $schedules [{day_of_week, enabled, start_time, end_time, breaks: [{start_time, end_time, label}]}]
 * @return array<int, array{open:int, close:int, breaks:list<array{start:int,end:int,label:string}>, windows:list<array{0:int,1:int}>}>
 *         the open days, keyed by day of the week (1 = Monday)
 * @throws RuntimeException naming the day and what to fix
 */
/**
 * A copy of the page from before breaks existed sends days with no "breaks" list. It shows only the
 * last part of a day with a break, so saving it would delete the rest of that day's hours.
 */
function office_schedules_from_old_page(array $schedules): bool
{
    foreach ($schedules as $schedule) {
        if (is_array($schedule) && !array_key_exists("breaks", $schedule)) {
            return true;
        }
    }
    return false;
}

function office_normalize_week(array $schedules): array
{
    $week = [];
    foreach ($schedules as $schedule) {
        if (!is_array($schedule) || empty($schedule["enabled"])) {
            continue;
        }
        $day = (int) ($schedule["day_of_week"] ?? 0);
        if (!isset(OFFICE_DAY_NAMES[$day]) || isset($week[$day])) {
            throw new RuntimeException("The weekly schedule has an unknown or repeated day");
        }
        $dayName = OFFICE_DAY_NAMES[$day];
        $open = office_time_minutes((string) ($schedule["start_time"] ?? ""));
        $close = office_time_minutes((string) ($schedule["end_time"] ?? ""));
        if ($open === null || $close === null || $open >= $close) {
            throw new RuntimeException("{$dayName}: the closing time must be after the opening time");
        }
        $rawBreaks = isset($schedule["breaks"]) && is_array($schedule["breaks"]) ? array_values($schedule["breaks"]) : [];
        if (count($rawBreaks) > OFFICE_MAX_BREAKS_PER_DAY) {
            throw new RuntimeException("{$dayName}: a day can have at most " . OFFICE_MAX_BREAKS_PER_DAY . " breaks");
        }
        $breaks = [];
        foreach ($rawBreaks as $raw) {
            $raw = is_array($raw) ? $raw : [];
            $label = office_break_label((string) ($raw["label"] ?? ""));
            $start = office_time_minutes((string) ($raw["start_time"] ?? ""));
            $end = office_time_minutes((string) ($raw["end_time"] ?? ""));
            if ($start === null || $end === null || $start >= $end) {
                throw new RuntimeException("{$dayName}: {$label} needs an end time after its start time");
            }
            if ($start < $open || $end > $close) {
                throw new RuntimeException(sprintf(
                    "%s: %s (%s – %s) must be within the visitor hours (%s – %s)",
                    $dayName,
                    $label,
                    office_minutes_clock($start),
                    office_minutes_clock($end),
                    office_minutes_clock($open),
                    office_minutes_clock($close)
                ));
            }
            $breaks[] = ["start" => $start, "end" => $end, "label" => $label];
        }
        usort($breaks, fn(array $a, array $b): int => $a["start"] <=> $b["start"]);
        for ($index = 1; $index < count($breaks); $index++) {
            if ($breaks[$index]["start"] < $breaks[$index - 1]["end"]) {
                throw new RuntimeException("{$dayName}: {$breaks[$index - 1]["label"]} and {$breaks[$index]["label"]} overlap");
            }
        }
        $windows = office_day_windows($open, $close, $breaks);
        if (!$windows) {
            throw new RuntimeException("{$dayName}: the breaks take up all of the visitor hours. Close the day instead.");
        }
        $week[$day] = ["open" => $open, "close" => $close, "breaks" => $breaks, "windows" => $windows];
    }
    ksort($week);
    return $week;
}

/**
 * The weekly schedule as the dashboard edits it, rebuilt from the saved windows and break
 * names. A gap between two windows of a day is a break. A saved break that ends where the
 * day's first window starts, or starts where its last one ends, is a break at the start or
 * end of the day (a morning flag ceremony).
 *
 * @param list<array> $rules office_availability_rules rows
 * @param list<array> $breakRows office_availability_breaks rows
 * @return list<array{day_of_week:int, enabled:bool, start_time:string, end_time:string, breaks:list<array{start_time:string,end_time:string,label:string}>}>
 */
function office_week_for_editing(array $rules, array $breakRows): array
{
    $windowsByDay = office_windows_by_day($rules);
    $savedByDay = office_breaks_by_day($breakRows);
    $week = [];
    foreach (OFFICE_DAY_NAMES as $day => $name) {
        $windows = $windowsByDay[$day] ?? [];
        if (!$windows) {
            $week[] = ["day_of_week" => $day, "enabled" => false, "start_time" => "08:00", "end_time" => "17:00", "breaks" => []];
            continue;
        }
        $saved = $savedByDay[$day] ?? [];
        $open = $windows[0][0];
        $close = $windows[count($windows) - 1][1];
        $breaks = [];
        while (($leading = office_find_break($saved, null, $open)) !== null) {
            $breaks[] = $leading;
            $open = $leading["start"];
        }
        while (($trailing = office_find_break($saved, $close, null)) !== null) {
            $breaks[] = $trailing;
            $close = $trailing["end"];
        }
        for ($index = 1; $index < count($windows); $index++) {
            $gapStart = $windows[$index - 1][1];
            $gapEnd = $windows[$index][0];
            if ($gapEnd <= $gapStart) {
                continue;
            }
            // The saved breaks that fill the gap exactly keep their names; otherwise the
            // gap is one break, named after the first saved break inside it.
            $inside = array_values(array_filter(
                $saved,
                fn(array $break): bool => $break["start"] >= $gapStart && $break["end"] <= $gapEnd
            ));
            if (office_breaks_fill($inside, $gapStart, $gapEnd)) {
                array_push($breaks, ...$inside);
            } else {
                $breaks[] = ["start" => $gapStart, "end" => $gapEnd, "label" => $inside[0]["label"] ?? OFFICE_DEFAULT_BREAK_LABEL];
            }
        }
        usort($breaks, fn(array $a, array $b): int => $a["start"] <=> $b["start"]);
        $week[] = [
            "day_of_week" => $day,
            "enabled" => true,
            "start_time" => office_minutes_input($open),
            "end_time" => office_minutes_input($close),
            "breaks" => array_map(fn(array $break): array => [
                "start_time" => office_minutes_input($break["start"]),
                "end_time" => office_minutes_input($break["end"]),
                "label" => $break["label"],
            ], $breaks),
        ];
    }
    return $week;
}

/** The saved break that starts at [$startsAt] or ends at [$endsAt] (null means any). */
function office_find_break(array $saved, ?int $startsAt, ?int $endsAt): ?array
{
    foreach ($saved as $break) {
        if (($startsAt === null || $break["start"] === $startsAt) && ($endsAt === null || $break["end"] === $endsAt)) {
            return $break;
        }
    }
    return null;
}

/** Whether sorted [$breaks] cover [$from, $to] exactly, end to end. */
function office_breaks_fill(array $breaks, int $from, int $to): bool
{
    $cursor = $from;
    foreach ($breaks as $break) {
        if ($break["start"] !== $cursor) {
            return false;
        }
        $cursor = $break["end"];
    }
    return $breaks !== [] && $cursor === $to;
}

/**
 * @param list<array> $rules office_availability_rules rows
 * @return array<int, list<array{0:int,1:int}>> active windows by day of the week, sorted
 */
function office_windows_by_day(array $rules): array
{
    $byDay = [];
    foreach ($rules as $rule) {
        $day = (int) ($rule["day_of_week"] ?? 0);
        $start = office_time_minutes((string) ($rule["start_time"] ?? ""));
        $end = office_time_minutes((string) ($rule["end_time"] ?? ""));
        if (isset(OFFICE_DAY_NAMES[$day]) && $start !== null && $end !== null && $start < $end
            && (int) ($rule["is_active"] ?? 1) === 1) {
            $byDay[$day][] = [$start, $end];
        }
    }
    foreach ($byDay as &$windows) {
        usort($windows, fn(array $a, array $b): int => $a[0] <=> $b[0]);
    }
    unset($windows);
    return $byDay;
}

/**
 * @param list<array> $rows office_availability_breaks rows
 * @return array<int, list<array{start:int,end:int,label:string}>> named breaks by day of the week, sorted
 */
function office_breaks_by_day(array $rows): array
{
    $byDay = [];
    foreach ($rows as $row) {
        $day = (int) ($row["day_of_week"] ?? 0);
        $start = office_time_minutes((string) ($row["start_time"] ?? ""));
        $end = office_time_minutes((string) ($row["end_time"] ?? ""));
        if (isset(OFFICE_DAY_NAMES[$day]) && $start !== null && $end !== null && $start < $end) {
            $byDay[$day][] = ["start" => $start, "end" => $end, "label" => office_break_label((string) ($row["label"] ?? ""))];
        }
    }
    foreach ($byDay as &$breaks) {
        usort($breaks, fn(array $a, array $b): int => $a["start"] <=> $b["start"]);
    }
    unset($breaks);
    return $byDay;
}

/** Whether office_breaks_migration.sql has run, so break names can be stored. */
function office_breaks_ready(mysqli $conn): bool
{
    static $ready = null;
    if ($ready === null) {
        $result = $conn->query("SHOW TABLES LIKE 'office_availability_breaks'");
        $ready = $result instanceof mysqli_result && $result->num_rows > 0;
    }
    return $ready;
}

/** @return list<array> the office's active office_availability_rules rows */
function office_load_rule_rows(mysqli $conn, string $officeCode): array
{
    $stmt = $conn->prepare(
        "SELECT day_of_week, start_time, end_time, is_active FROM office_availability_rules
         WHERE office_code = ? AND is_active = 1 ORDER BY day_of_week, start_time"
    );
    $stmt->bind_param("s", $officeCode);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/** @return list<array> the office's office_availability_breaks rows (none before the migration) */
function office_load_break_rows(mysqli $conn, string $officeCode): array
{
    if (!office_breaks_ready($conn)) {
        return [];
    }
    $stmt = $conn->prepare(
        "SELECT day_of_week, start_time, end_time, label FROM office_availability_breaks
         WHERE office_code = ? ORDER BY day_of_week, start_time"
    );
    $stmt->bind_param("s", $officeCode);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/** @return list<array> the office's posted changes that overlap [$from, $to], newest first */
function office_load_exceptions_between(mysqli $conn, string $officeCode, DateTimeInterface $from, DateTimeInterface $to): array
{
    $fromSql = $from->format("Y-m-d H:i:s");
    $toSql = $to->format("Y-m-d H:i:s");
    $stmt = $conn->prepare(
        "SELECT id, starts_at, ends_at, is_available, reason FROM office_availability_exceptions
         WHERE office_code = ? AND ends_at > ? AND starts_at < ? ORDER BY id DESC"
    );
    $stmt->bind_param("sss", $officeCode, $fromSql, $toSql);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/**
 * Replaces the office's weekly windows and break names with [$week] (from
 * office_normalize_week). Call it inside a transaction.
 */
function office_store_week(mysqli $conn, string $officeCode, array $week): void
{
    $delete = $conn->prepare("DELETE FROM office_availability_rules WHERE office_code = ?");
    $delete->bind_param("s", $officeCode);
    $delete->execute();
    $delete->close();
    $insert = $conn->prepare(
        "INSERT INTO office_availability_rules (office_code, day_of_week, start_time, end_time, is_active)
         VALUES (?, ?, ?, ?, 1)"
    );
    foreach ($week as $day => $schedule) {
        foreach ($schedule["windows"] as [$start, $end]) {
            $startSql = office_minutes_sql($start);
            $endSql = office_minutes_sql($end);
            $insert->bind_param("siss", $officeCode, $day, $startSql, $endSql);
            $insert->execute();
        }
    }
    $insert->close();

    if (!office_breaks_ready($conn)) {
        return;
    }
    $delete = $conn->prepare("DELETE FROM office_availability_breaks WHERE office_code = ?");
    $delete->bind_param("s", $officeCode);
    $delete->execute();
    $delete->close();
    $insert = $conn->prepare(
        "INSERT INTO office_availability_breaks (office_code, day_of_week, start_time, end_time, label)
         VALUES (?, ?, ?, ?, ?)"
    );
    foreach ($week as $day => $schedule) {
        foreach ($schedule["breaks"] as $break) {
            $startSql = office_minutes_sql($break["start"]);
            $endSql = office_minutes_sql($break["end"]);
            $insert->bind_param("sisss", $officeCode, $day, $startSql, $endSql, $break["label"]);
            $insert->execute();
        }
    }
    $insert->close();
}

/**
 * When a booking falls in one of the office's named breaks, says so, e.g. "The office is on
 * Lunch break from 12:00 PM to 1:00 PM. Choose another time." Null otherwise.
 */
function office_break_message(mysqli $conn, string $officeCode, DateTimeInterface $start, DateTimeInterface $end): ?string
{
    $day = (int) $start->format("N");
    $from = (int) $start->format("G") * 60 + (int) $start->format("i");
    $to = $from + intdiv($end->getTimestamp() - $start->getTimestamp(), 60);
    foreach (office_breaks_by_day(office_load_break_rows($conn, $officeCode))[$day] ?? [] as $break) {
        if ($from < $break["end"] && $break["start"] < $to) {
            return sprintf(
                "The office is closed for %s from %s to %s. Choose another time.",
                office_break_phrase($break["label"]),
                office_minutes_clock($break["start"]),
                office_minutes_clock($break["end"])
            );
        }
    }
    return null;
}

/**
 * @return array{state:string, label:string, until:?string, next:?string, pause_id:?int}
 */
function office_state(string $state, string $label, ?string $until, ?string $next = null, ?int $pauseId = null): array
{
    return ["state" => $state, "label" => $label, "until" => $until, "next" => $next, "pause_id" => $pauseId];
}

/**
 * Where an office stands at [$now] for a visitor walking over.
 *
 * state is "open", "break" (between two windows of the day, or in a named break), "closed"
 * (before or after the day's hours, or a closed day), "paused" (a posted "Office
 * unavailable" change in effect) or "not_accepting" (switched off on the dashboard).
 * until is when that state ends, "Y-m-d H:i:s", when known; next is what follows an open
 * window: a break's name, or "Closing".
 *
 * @param array $settings office_availability_settings row
 * @param array<int, list<array{0:int,1:int}>> $windowsByDay office_windows_by_day()
 * @param array<int, list<array{start:int,end:int,label:string}>> $breaksByDay office_breaks_by_day()
 * @param list<array> $exceptions posted changes that end after [$now], newest first
 * @return array{state:string, label:string, until:?string, next:?string, pause_id:?int}
 */
function office_open_state_at(DateTimeImmutable $now, array $settings, array $windowsByDay, array $breaksByDay, array $exceptions): array
{
    if (!(int) ($settings["accepting_visitors"] ?? 1)) {
        $reason = trim((string) ($settings["unavailable_reason"] ?? ""));
        $until = !empty($settings["available_again_at"]) ? (string) $settings["available_again_at"] : null;
        return office_state("not_accepting", $reason !== "" ? $reason : "Not accepting visitors", $until);
    }
    $stamp = $now->getTimestamp();
    // A posted change in effect now wins over the weekly hours, as in office_availability_check().
    foreach ($exceptions as $exception) {
        $starts = strtotime((string) $exception["starts_at"]);
        $ends = strtotime((string) $exception["ends_at"]);
        if ($starts <= $stamp && $stamp < $ends) {
            $reason = trim((string) ($exception["reason"] ?? ""));
            $until = date("Y-m-d H:i:s", $ends);
            return (int) $exception["is_available"]
                ? office_state("open", $reason !== "" ? $reason : "Extra visitor hours", $until)
                : office_state("paused", $reason !== "" ? $reason : "Office unavailable", $until, null, (int) $exception["id"]);
        }
    }
    if (!$windowsByDay) {
        // No weekly hours saved yet: visitors may come at any time, as bookings already allow.
        return office_state("open", "Open", null);
    }

    $day = (int) $now->format("N");
    $minute = (int) $now->format("G") * 60 + (int) $now->format("i");
    $today = $windowsByDay[$day] ?? [];
    $breaks = $breaksByDay[$day] ?? [];
    foreach ($today as $index => [$start, $end]) {
        if ($start <= $minute && $minute < $end) {
            $until = office_time_on($now, $end);
            $nextBreak = office_find_break($breaks, $end, null);
            $next = $nextBreak["label"] ?? (isset($today[$index + 1]) ? OFFICE_DEFAULT_BREAK_LABEL : "Closing");
            // A posted "Office unavailable" change that starts before this window ends.
            foreach ($exceptions as $exception) {
                $closureStart = strtotime((string) $exception["starts_at"]);
                if (!(int) $exception["is_available"] && $closureStart > $stamp && $closureStart < strtotime($until)) {
                    $until = date("Y-m-d H:i:s", $closureStart);
                    $next = trim((string) ($exception["reason"] ?? "")) ?: "Office unavailable";
                }
            }
            return office_state("open", "Open", $until, $next);
        }
    }

    $opensAt = office_next_opening($now, $windowsByDay, $exceptions);
    foreach ($breaks as $break) {
        if ($break["start"] <= $minute && $minute < $break["end"]) {
            return office_state("break", $break["label"], $opensAt);
        }
    }
    $first = $today[0][0] ?? null;
    $last = $today ? $today[count($today) - 1][1] : null;
    if ($first !== null && $first < $minute && $minute < $last) {
        return office_state("break", OFFICE_DEFAULT_BREAK_LABEL, $opensAt);
    }
    return office_state("closed", "Closed", $opensAt);
}

/** [$minutes] after midnight on [$now]'s date, as "Y-m-d H:i:s". */
function office_time_on(DateTimeImmutable $now, int $minutes): string
{
    return $now->setTime(intdiv($minutes, 60), $minutes % 60)->format("Y-m-d H:i:s");
}

/**
 * When the office next opens after [$now] within a week, skipping time a posted "Office
 * unavailable" change takes away. Null when it doesn't open in that time.
 */
function office_next_opening(DateTimeImmutable $now, array $windowsByDay, array $exceptions): ?string
{
    $stamp = $now->getTimestamp();
    $closures = array_values(array_filter($exceptions, fn(array $exception): bool => !(int) $exception["is_available"]));
    for ($offset = 0; $offset <= 7; $offset++) {
        $date = $now->setTime(0, 0)->modify("+{$offset} days");
        foreach ($windowsByDay[(int) $date->format("N")] ?? [] as [$start, $end]) {
            $windowEnd = $date->getTimestamp() + $end * 60;
            if ($windowEnd <= $stamp) {
                continue;
            }
            $candidate = max($date->getTimestamp() + $start * 60, $stamp);
            do {
                $moved = false;
                foreach ($closures as $closure) {
                    $closureStart = strtotime((string) $closure["starts_at"]);
                    $closureEnd = strtotime((string) $closure["ends_at"]);
                    if ($closureStart <= $candidate && $candidate < $closureEnd) {
                        $candidate = $closureEnd;
                        $moved = true;
                    }
                }
            } while ($moved && $candidate < $windowEnd);
            if ($candidate < $windowEnd && $candidate > $stamp) {
                return date("Y-m-d H:i:s", $candidate);
            }
        }
    }
    return null;
}

/** office_open_state_at() for an office as saved now. */
function office_open_state(mysqli $conn, string $officeCode, DateTimeImmutable $now): array
{
    return office_open_state_at(
        $now,
        office_availability_get_settings($conn, $officeCode),
        office_windows_by_day(office_load_rule_rows($conn, $officeCode)),
        office_breaks_by_day(office_load_break_rows($conn, $officeCode)),
        office_load_exceptions_between($conn, $officeCode, $now, $now->modify("+8 days"))
    );
}

/** "at 1:00 PM", "tomorrow at 8:00 AM", "on Monday at 8:00 AM", or "on Nov 12 at 8:00 AM". */
function office_when(string $dateTime, DateTimeImmutable $now): string
{
    $moment = new DateTimeImmutable($dateTime);
    $days = (int) $now->setTime(0, 0)->diff($moment->setTime(0, 0))->format("%r%a");
    $time = $moment->format("g:i A");
    if ($days === 0) {
        return "at {$time}";
    }
    if ($days === 1) {
        return "tomorrow at {$time}";
    }
    return ($days < 7 ? "on " . $moment->format("l") : "on " . $moment->format("M j")) . " at {$time}";
}

/** One sentence for a visitor about where [$officeName] stands, from office_open_state_at(). */
function office_state_message(array $state, string $officeName, DateTimeImmutable $now): string
{
    $until = $state["until"];
    switch ($state["state"]) {
        case "open":
            return $until !== null
                ? "{$officeName} is open until " . (new DateTimeImmutable($until))->format("g:i A") . "."
                : "{$officeName} is open.";
        case "break":
            $what = office_break_phrase($state["label"]);
            return $until !== null
                ? "{$officeName} is closed for {$what} until " . (new DateTimeImmutable($until))->format("g:i A") . "."
                : "{$officeName} is closed for {$what}.";
        case "paused":
            return "{$officeName} is unavailable until " . (new DateTimeImmutable($until))->format("g:i A") . ": {$state["label"]}.";
        case "not_accepting":
            return "{$officeName} is not accepting visitors"
                . ($state["label"] !== "Not accepting visitors" ? ": " . rtrim($state["label"], ".") : "")
                . ($until !== null ? ". Available again " . office_when($until, $now) . "." : ".");
        default:
            return $until !== null
                ? "{$officeName} is closed now and opens again " . office_when($until, $now) . "."
                : "{$officeName} is closed now.";
    }
}

/**
 * Why a walk-in pass to the office can't be created at [$now], or null when it can. A pass
 * lasts an hour, so one may be created up to OFFICE_WALK_IN_LEAD_MINUTES before the office
 * opens again after a break or in the morning.
 */
function office_walk_in_block_reason_for(array $state, string $officeName, DateTimeImmutable $now): ?string
{
    if ($state["state"] === "open") {
        return null;
    }
    $until = $state["until"];
    if (in_array($state["state"], ["break", "closed"], true) && $until !== null
        && strtotime($until) - $now->getTimestamp() <= OFFICE_WALK_IN_LEAD_MINUTES * 60) {
        return null;
    }
    $message = office_state_message($state, $officeName, $now);
    if ($state["state"] === "break" && $until !== null) {
        $from = (new DateTimeImmutable($until))->modify("-" . OFFICE_WALK_IN_LEAD_MINUTES . " minutes");
        return "{$message} You can create a walk-in pass from {$from->format("g:i A")}, or book an appointment for later.";
    }
    if ($state["state"] === "closed") {
        return "{$message} You can book an appointment for later.";
    }
    return $message;
}

function office_walk_in_block_reason(mysqli $conn, string $officeCode, string $officeName, DateTimeImmutable $now): ?string
{
    return office_walk_in_block_reason_for(office_open_state($conn, $officeCode, $now), $officeName, $now);
}

/**
 * Why a visit from [$start] to [$end] would be outside the weekly [$week] (from
 * office_normalize_week), or null when it fits.
 */
function office_conflict_reason(array $week, DateTimeInterface $start, DateTimeInterface $end): ?string
{
    $day = (int) $start->format("N");
    if (!isset($week[$day])) {
        return OFFICE_DAY_NAMES[$day] . "s are now closed";
    }
    $from = (int) $start->format("G") * 60 + (int) $start->format("i");
    $to = $from + intdiv($end->getTimestamp() - $start->getTimestamp(), 60);
    foreach ($week[$day]["windows"] as [$open, $close]) {
        if ($open <= $from && $to <= $close) {
            return null;
        }
    }
    foreach ($week[$day]["breaks"] as $break) {
        if ($from < $break["end"] && $break["start"] < $to) {
            return "During " . $break["label"] . " (" . office_minutes_clock($break["start"]) . " – " . office_minutes_clock($break["end"]) . ")";
        }
    }
    return "Outside the new hours (" . office_minutes_clock($week[$day]["open"]) . " – " . office_minutes_clock($week[$day]["close"]) . ")";
}

/**
 * Visits already booked at the office, from [$now] on, that the weekly [$week] would leave
 * outside visitor hours. Visits inside posted extra hours still fit. Nothing changes here:
 * the office decides what to do with them.
 *
 * @return list<array{id:int, code:string, visitor:string, status:string, starts_at:string, ends_at:string, reason:string}>
 */
function office_schedule_conflicts(mysqli $conn, string $officeCode, array $week, DateTimeImmutable $now): array
{
    $nowSql = $now->format("Y-m-d H:i:s");
    $stmt = $conn->prepare(
        "SELECT id, registration_code, visitor_full_name, status, scheduled_start_at, scheduled_end_at
         FROM appointments
         WHERE office_code = ? AND visit_type <> 'walk_in'
           AND status IN ('pending_approval', 'approved', 'reschedule_proposed')
           AND scheduled_start_at >= ?
         ORDER BY scheduled_start_at ASC LIMIT 300"
    );
    $stmt->bind_param("ss", $officeCode, $nowSql);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    if (!$rows) {
        return [];
    }
    $lastEnd = new DateTimeImmutable((string) $rows[count($rows) - 1]["scheduled_end_at"]);
    $extraHours = array_values(array_filter(
        office_load_exceptions_between($conn, $officeCode, $now, $lastEnd->modify("+1 day")),
        fn(array $exception): bool => (bool) (int) $exception["is_available"]
    ));

    $conflicts = [];
    foreach ($rows as $row) {
        $start = new DateTimeImmutable((string) $row["scheduled_start_at"]);
        $end = new DateTimeImmutable((string) $row["scheduled_end_at"]);
        foreach ($extraHours as $extra) {
            if (strtotime((string) $extra["starts_at"]) <= $start->getTimestamp() && $end->getTimestamp() <= strtotime((string) $extra["ends_at"])) {
                continue 2;
            }
        }
        $reason = office_conflict_reason($week, $start, $end);
        if ($reason !== null) {
            $conflicts[] = [
                "id" => (int) $row["id"],
                "code" => (string) ($row["registration_code"] ?? ""),
                "visitor" => (string) $row["visitor_full_name"],
                "status" => (string) $row["status"],
                "starts_at" => (string) $row["scheduled_start_at"],
                "ends_at" => (string) $row["scheduled_end_at"],
                "reason" => $reason,
            ];
        }
    }
    return $conflicts;
}

/**
 * Visits booked at the office that overlap [$from, $to], for warning about a pause.
 *
 * @return list<array{id:int, visitor:string, starts_at:string}>
 */
function office_visits_between(mysqli $conn, string $officeCode, DateTimeInterface $from, DateTimeInterface $to): array
{
    $fromSql = $from->format("Y-m-d H:i:s");
    $toSql = $to->format("Y-m-d H:i:s");
    $stmt = $conn->prepare(
        "SELECT id, visitor_full_name, scheduled_start_at FROM appointments
         WHERE office_code = ? AND visit_type <> 'walk_in'
           AND status IN ('pending_approval', 'approved', 'reschedule_proposed')
           AND scheduled_start_at < ? AND scheduled_end_at > ?
         ORDER BY scheduled_start_at ASC LIMIT 50"
    );
    $stmt->bind_param("sss", $officeCode, $toSql, $fromSql);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return array_map(fn(array $row): array => [
        "id" => (int) $row["id"],
        "visitor" => (string) $row["visitor_full_name"],
        "starts_at" => (string) $row["scheduled_start_at"],
    ], $rows);
}
