<?php
/*
 * The Reports page and its printable monthly report (admin.js): the period's numbers compared
 * with the period before, what stands out, visits per day, each office with its answer times,
 * why people visit, and the busiest hours. The Excel and CSV exports stay in admin_analytics.php.
 *   GET admin_report.php?period=today|week|month|quarter
 */
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/session_bootstrap.php";
require_once __DIR__ . "/appointment_maintenance.php";
require_once __DIR__ . "/analytics_period.php";
require_once __DIR__ . "/admin_service.php";

auth_json_exception_guard();
auth_require_method("GET");
require_permission_json("analytics.view");
date_default_timezone_set("Asia/Manila");
refresh_appointment_time_states($conn);

[$period, $start, $now] = analytics_period_range((string) ($_GET["period"] ?? "month"));

/** The same stretch of time one period earlier, cut at the same point. */
function report_previous(string $period, DateTimeImmutable $start, DateTimeImmutable $now): array
{
    switch ($period) {
        case "today":
        case "week":
            return [$start->modify("-7 days"), $now->modify("-7 days")];
        case "quarter":
            $previousStart = $start->modify("-3 months");
            $end = $previousStart->add($start->diff($now));
            return [$previousStart, min($end, $start)];
        default:
            $previousStart = $start->modify("first day of last month");
            $end = $previousStart->add($start->diff($now));
            return [$previousStart, min($end, $start)];
    }
}

/** Visits, average length, answered in time, and visitors who didn't come, for [$from, $to]. */
function report_numbers(mysqli $conn, DateTimeImmutable $from, DateTimeImmutable $to): array
{
    $fromSql = $from->format("Y-m-d H:i:s");
    $toSql = $to->format("Y-m-d H:i:s");
    $stmt = $conn->prepare(
        "SELECT
            SUM(checked_in_at BETWEEN ? AND ?) AS visits,
            SUM(checked_in_at BETWEEN ? AND ? AND registration_source = 'gate') AS at_gate,
            AVG(CASE WHEN checked_in_at BETWEEN ? AND ? AND completed_at > checked_in_at
                     AND TIMESTAMPDIFF(MINUTE, checked_in_at, completed_at) <= 600
                THEN TIMESTAMPDIFF(MINUTE, checked_in_at, completed_at) END) AS average_minutes,
            SUM(status = 'unanswered' AND unanswered_at BETWEEN ? AND ?) AS expired,
            SUM(status = 'window_closed' AND window_closed_at BETWEEN ? AND ?) AS no_shows,
            SUM(approved_at BETWEEN ? AND ?) AS approved
         FROM appointments"
    );
    $stmt->bind_param("ssssssssssss", $fromSql, $toSql, $fromSql, $toSql, $fromSql, $toSql, $fromSql, $toSql, $fromSql, $toSql, $fromSql, $toSql);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $stmt = $conn->prepare(
        "SELECT COUNT(*) FROM appointments a
         WHERE (a.approved_by_user_id IS NOT NULL AND a.approved_at BETWEEN ? AND ?)
            OR (a.rejected_by_user_id IS NOT NULL AND a.rejected_at BETWEEN ? AND ?)
            OR EXISTS (SELECT 1 FROM appointment_reschedule_proposals p WHERE p.appointment_id = a.id AND p.created_at BETWEEN ? AND ?)"
    );
    $stmt->bind_param("ssssss", $fromSql, $toSql, $fromSql, $toSql, $fromSql, $toSql);
    $stmt->execute();
    $answered = (int) $stmt->get_result()->fetch_row()[0];
    $stmt->close();
    $expired = (int) $row["expired"];
    return [
        "visits" => (int) $row["visits"],
        "at_gate" => (int) $row["at_gate"],
        "average_minutes" => $row["average_minutes"] !== null ? (int) round((float) $row["average_minutes"]) : null,
        "answered" => $answered,
        "expired" => $expired,
        "answered_in_time" => $answered + $expired > 0 ? (int) round($answered * 100 / ($answered + $expired)) : null,
        "no_shows" => (int) $row["no_shows"],
        "approved" => (int) $row["approved"],
    ];
}

[$previousStart, $previousEnd] = report_previous($period, $start, $now);
$current = report_numbers($conn, $start, $now);
$previous = report_numbers($conn, $previousStart, $previousEnd);
$startSql = $start->format("Y-m-d H:i:s");
$nowSql = $now->format("Y-m-d H:i:s");

// Every visit in the period: when it started, where, and why.
$stmt = $conn->prepare(
    "SELECT office_code, purpose, checked_in_at FROM appointments WHERE checked_in_at BETWEEN ? AND ? ORDER BY checked_in_at"
);
$stmt->bind_param("ss", $startSql, $nowSql);
$stmt->execute();
$visits = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Visits per hour (today), per day (this week, this month) or per week (this quarter).
$buckets = [];
if ($period === "today") {
    for ($hour = 7; $hour <= 18; $hour++) {
        $moment = $start->setTime($hour, 0);
        $buckets[$moment->format("Y-m-d H")] = ["label" => $moment->format("g A"), "full" => $moment->format("g:i A") . " to " . $moment->modify("+1 hour")->format("g:i A"), "weekend" => false, "visits" => 0, "future" => $moment > $now];
    }
    $keyOf = fn (int $time): string => date("Y-m-d H", $time);
} elseif ($period === "quarter") {
    for ($week = $start->modify("monday this week"); $week <= $now; $week = $week->modify("+7 days")) {
        $buckets[$week->format("o-W")] = ["label" => $week->format("M j"), "full" => "Week of " . $week->format("M j"), "weekend" => false, "visits" => 0, "future" => false];
    }
    $keyOf = fn (int $time): string => date("o-W", $time);
} else {
    $last = $period === "week" ? $start->modify("+6 days") : $now;
    for ($day = $start; $day <= $last; $day = $day->modify("+1 day")) {
        $buckets[$day->format("Y-m-d")] = [
            "label" => $period === "week" ? $day->format("D") : $day->format("j"),
            "full" => $day->format("D, M j"),
            "weekend" => (int) $day->format("N") >= 6,
            "visits" => 0,
            "future" => $day > $now,
        ];
    }
    $keyOf = fn (int $time): string => date("Y-m-d", $time);
}

$heat = array_fill(0, 7, array_fill(0, 10, 0));
$purposes = [];
$officeVisits = [];
foreach ($visits as $visit) {
    $time = strtotime((string) $visit["checked_in_at"]);
    $key = $keyOf($time);
    if (isset($buckets[$key])) {
        $buckets[$key]["visits"]++;
    }
    $hour = (int) date("G", $time);
    if ($hour >= 8 && $hour <= 17) {
        $heat[(int) date("N", $time) - 1][$hour - 8]++;
    }
    $purpose = trim((string) ($visit["purpose"] ?? ""));
    if ($purpose !== "") {
        $purposes[$purpose] = ($purposes[$purpose] ?? 0) + 1;
    }
    $code = (string) $visit["office_code"];
    $officeVisits[$code] = ($officeVisits[$code] ?? 0) + 1;
}
arsort($purposes);
$purposeList = [];
$other = 0;
foreach ($purposes as $label => $count) {
    if (count($purposeList) < 6) {
        $purposeList[] = ["label" => $label, "visits" => $count];
    } else {
        $other += $count;
    }
}
if ($other > 0) {
    $purposeList[] = ["label" => "Other reasons", "visits" => $other];
}

// Each office: visits, and how its requests were answered in the period.
$names = appointment_office_map();
$stmt = $conn->prepare(
    "SELECT a.office_code, a.created_at, a.status, a.unanswered_at,
            LEAST(
                COALESCE(CASE WHEN a.approved_by_user_id IS NOT NULL THEN a.approved_at END, '9999-12-31'),
                COALESCE(CASE WHEN a.rejected_by_user_id IS NOT NULL THEN a.rejected_at END, '9999-12-31'),
                COALESCE((SELECT MIN(p.created_at) FROM appointment_reschedule_proposals p WHERE p.appointment_id = a.id), '9999-12-31')
            ) AS answered_at
     FROM appointments a
     WHERE a.created_at BETWEEN ? AND ? OR a.unanswered_at BETWEEN ? AND ?"
);
$stmt->bind_param("ssss", $startSql, $nowSql, $startSql, $nowSql);
$stmt->execute();
$answers = [];
foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
    $code = (string) $row["office_code"];
    $answers[$code] = $answers[$code] ?? ["minutes" => [], "expired" => 0];
    if ($row["status"] === "unanswered" && $row["unanswered_at"] >= $startSql) {
        $answers[$code]["expired"]++;
    } elseif (!str_starts_with((string) $row["answered_at"], "9999") && $row["answered_at"] >= $startSql) {
        $answers[$code]["minutes"][] = admin_working_minutes(strtotime((string) $row["created_at"]), strtotime((string) $row["answered_at"]));
    }
}
$stmt->close();
$staffCount = [];
foreach ($conn->query("SELECT office_code, COUNT(*) FROM app_users WHERE role = 'offices' AND is_active = 1 GROUP BY office_code")->fetch_all() as [$code, $count]) {
    $staffCount[(string) $code] = (int) $count;
}
$offices = [];
foreach ($conn->query("SELECT code, name, is_active FROM offices ORDER BY sort_order, name")->fetch_all(MYSQLI_ASSOC) as $office) {
    $code = (string) $office["code"];
    $minutes = $answers[$code]["minutes"] ?? [];
    $expired = $answers[$code]["expired"] ?? 0;
    if ((int) $office["is_active"] !== 1 && empty($officeVisits[$code]) && !$minutes && !$expired) {
        continue;
    }
    $average = $minutes ? (int) round(array_sum($minutes) / count($minutes)) : null;
    $offices[] = [
        "code" => $code,
        "name" => (string) $office["name"],
        "visits" => $officeVisits[$code] ?? 0,
        "answered" => count($minutes),
        "expired" => $expired,
        "answered_in_time" => count($minutes) + $expired > 0 ? (int) round(count($minutes) * 100 / (count($minutes) + $expired)) : null,
        "answer_minutes" => $average,
        "answer_label" => $average !== null ? admin_duration_label($average) : "",
        "staff" => $staffCount[$code] ?? 0,
    ];
}
usort($offices, fn (array $a, array $b): int => $b["visits"] <=> $a["visits"]);

/* ---------- What stands out ---------- */
$findings = [];
$total = $current["visits"];
$change = function (int $now, int $before): ?int {
    return $before > 0 ? (int) round(($now - $before) * 100 / $before) : null;
};
$previousLabel = [
    "today" => "last " . $now->format("l") . " at this time",
    "week" => "the same days last week",
    "month" => "the same days of " . $previousStart->format("F"),
    "quarter" => "the same weeks of last quarter",
][$period];
if ($total > 0) {
    $visitChange = $change($total, $previous["visits"]);
    if ($visitChange !== null && abs($visitChange) >= 10) {
        $findings[] = ["icon" => "trend", "tone" => $visitChange > 0 ? "" : "is-warn",
            "html" => "<b>Visits are " . ($visitChange > 0 ? "up" : "down") . " " . abs($visitChange) . "%</b> from " . $previousLabel . " (" . $total . " against " . $previous["visits"] . ")."];
    }
    $best = [0, 0, 0];
    foreach ($heat as $day => $hours) {
        foreach ($hours as $hour => $count) {
            if ($count > $best[2]) {
                $best = [$day, $hour, $count];
            }
        }
    }
    if ($best[2] >= 2 && $period !== "today") {
        $dayName = ["Mondays", "Tuesdays", "Wednesdays", "Thursdays", "Fridays", "Saturdays", "Sundays"][$best[0]];
        $from = date("g", mktime($best[1] + 8, 0));
        $to = date("g A", mktime($best[1] + 9, 0));
        $share = $best[2] / $total;
        $findings[] = ["icon" => "trend", "tone" => "",
            "html" => "<b>Busiest time: " . $dayName . ", " . $from . " to " . $to . ".</b> " . ($share >= 0.1 ? "1 in " . max(2, (int) round(1 / $share)) . " visits starts then." : $best[2] . " visits started then.")];
    }
}
$timed = array_values(array_filter($offices, fn (array $office): bool => $office["answer_minutes"] !== null && $office["answered"] >= 2));
if (count($timed) >= 2) {
    usort($timed, fn (array $a, array $b): int => $b["answer_minutes"] <=> $a["answer_minutes"]);
    $slow = $timed[0];
    $fast = $timed[count($timed) - 1];
    if ($slow["answer_minutes"] >= 60 && $slow["answer_minutes"] >= 2 * max(1, $fast["answer_minutes"])) {
        $times = (int) round($slow["answer_minutes"] / max(1, $fast["answer_minutes"]));
        $findings[] = ["icon" => "clock", "tone" => "is-warn",
            "html" => "<b>" . htmlspecialchars($slow["name"]) . " takes " . $slow["answer_label"] . " to answer</b>, about " . $times . " times longer than " . htmlspecialchars($fast["name"]) . "."
                . ($slow["expired"] > 0 ? " " . admin_plural($slow["expired"], "of its requests", "of its requests") . " expired." : "")];
    }
} elseif ($current["expired"] > 0) {
    $findings[] = ["icon" => "clock", "tone" => "is-warn",
        "html" => "<b>" . admin_plural($current["expired"], "request", "requests") . " expired</b> without an answer from the office."];
}
if ($total >= 5 && $offices) {
    $top = $offices[0];
    $share = (int) round($top["visits"] * 100 / $total);
    $topPurpose = $purposeList[0] ?? null;
    if ($share >= 40 && count($offices) > 1) {
        $findings[] = ["icon" => "office", "tone" => "",
            "html" => "<b>" . htmlspecialchars($top["name"]) . " gets " . $share . "% of visits.</b>"
                . ($topPurpose && $topPurpose["label"] !== "Other reasons" ? " " . htmlspecialchars($topPurpose["label"]) . " is the most common reason (" . (int) round($topPurpose["visits"] * 100 / $total) . "%)." : "")];
    } elseif ($topPurpose && $topPurpose["label"] !== "Other reasons") {
        $findings[] = ["icon" => "office", "tone" => "",
            "html" => "<b>" . htmlspecialchars($topPurpose["label"]) . " is the most common reason</b> to visit (" . (int) round($topPurpose["visits"] * 100 / $total) . "%)."];
    }
}
if ($current["no_shows"] >= 3) {
    $base = max($current["approved"], $current["no_shows"]);
    $findings[] = ["icon" => "warn", "tone" => "is-warn",
        "html" => "<b>" . admin_plural($current["no_shows"], "approved visitor", "approved visitors") . " didn't come</b>" . ($base > 0 ? " (" . (int) round($current["no_shows"] * 100 / $base) . "% of those approved)." : ".")];
}

auth_json([
    "success" => true,
    "period" => $period,
    "range" => ["start" => $startSql, "end" => $nowSql],
    "previous_range" => ["start" => $previousStart->format("Y-m-d H:i:s"), "end" => $previousEnd->format("Y-m-d H:i:s")],
    "previous_label" => $previousLabel,
    "current" => $current,
    "previous" => $previous,
    "series" => array_values($buckets),
    "offices" => $offices,
    "purposes" => $purposeList,
    "heat" => [
        "days" => ["Mon", "Tue", "Wed", "Thu", "Fri", "Sat", "Sun"],
        "hours" => ["8", "9", "10", "11", "12", "1", "2", "3", "4", "5"],
        "values" => $heat,
    ],
    "findings" => array_slice($findings, 0, 4),
]);
