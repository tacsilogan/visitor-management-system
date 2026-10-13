<?php
/*
 * The Visitor Analytics report as a formatted Excel workbook (admin_analytics.php,
 * format=xlsx): a Summary sheet (key figures, visits by status, destination, purpose and
 * hour) and a Visits sheet with every visit in the period.
 */
require_once __DIR__ . "/xlsx_writer.php";

/** Status label and colour, as the admin dashboard shows them. */
const ANALYTICS_EXPORT_STATUSES = [
    "pending_approval" => ["Pending approval", "warn"],
    "approved" => ["Approved", "info"],
    "reschedule_proposed" => ["Reschedule proposed", "warn"],
    "checked_in" => ["On campus", "info"],
    "completed" => ["Completed", "good"],
    "window_closed" => ["Appointment done", "good"],
    "rejected" => ["Declined", "danger"],
    "cancelled" => ["Cancelled", "muted"],
    "unanswered" => ["Office did not respond", "muted"],
];

const ANALYTICS_EXPORT_PERIODS = [
    "today" => "Today",
    "week" => "This week",
    "month" => "This month",
    "quarter" => "This quarter",
    "all" => "All records",
];

/** "walk_in" → "Walk-in", "appointment" → "Appointment". */
function analytics_export_visit_type(string $type): string
{
    $labels = ["walk_in" => "Walk-in", "appointment" => "Appointment"];
    return $labels[strtolower($type)] ?? ucfirst(str_replace("_", " ", $type));
}

/** @return array{0: string, 1: string} */
function analytics_export_status(string $status): array
{
    return ANALYTICS_EXPORT_STATUSES[$status] ?? [ucfirst(str_replace("_", " ", $status)), "muted"];
}

/**
 * @param array<int, array<string, mixed>> $rows from admin_analytics.php: registration, visitor,
 *        visit_type, purpose, destination, subject, status, appointment_at, checked_in_at, completed_at, duration
 */
function analytics_send_workbook(array $rows, string $period, DateTimeImmutable $start, DateTimeImmutable $now, string $generatedBy): never
{
    $periodLabel = ANALYTICS_EXPORT_PERIODS[$period] ?? "Selected period";
    $rangeText = $period === "all"
        ? "Every visit record up to " . $now->format("M j, Y")
        : $start->format("M j, Y") . ($start->format("Y-m-d") === $now->format("Y-m-d") ? "" : " – " . $now->format("M j, Y"));
    $subtitle = $periodLabel . " · " . $rangeText . " · Generated " . $now->format("M j, Y, g:i A") . " by " . $generatedBy;

    // Figures for the summary.
    $durations = [];
    $statuses = [];
    $destinations = [];
    $purposes = [];
    $hours = [];
    $checkedIn = 0;
    $onCampus = 0;
    foreach ($rows as $row) {
        $statuses[$row["status"]] = ($statuses[$row["status"]] ?? 0) + 1;
        $place = $row["destination"] !== "" ? $row["destination"] : "No destination";
        $destinations[$place] ??= ["visits" => 0, "checked_in" => 0, "durations" => []];
        $destinations[$place]["visits"]++;
        if ($row["checked_in_at"]) {
            $checkedIn++;
            $destinations[$place]["checked_in"]++;
            $hour = (int) date("G", strtotime((string) $row["checked_in_at"]));
            $hours[$hour] = ($hours[$hour] ?? 0) + 1;
        }
        if ($row["status"] === "checked_in") {
            $onCampus++;
        }
        if ($row["duration"] !== null) {
            $durations[] = $row["duration"];
            $destinations[$place]["durations"][] = $row["duration"];
        }
        $purpose = trim((string) $row["purpose"]);
        if ($purpose !== "") {
            $purposes[$purpose] = ($purposes[$purpose] ?? 0) + 1;
        }
    }
    $total = count($rows);
    $share = fn (int $count): string => $total > 0 ? round($count * 100 / $total) . "%" : "—";
    $average = fn (array $values): ?int => $values ? (int) round(array_sum($values) / count($values)) : null;

    $book = new XlsxBook("Visitor Analytics Report");
    $summary = $book->sheet("Summary");
    $summary->withoutTableTools();
    $summary->title("Visitor Analytics Report", $subtitle);
    $summary->section("Overview");
    $summary->facts([
        ["Visit records", $total, "number"],
        ["Visitors checked in", $checkedIn, "number"],
        ["Completed visits", count($durations), "number"],
        ["On campus now", $onCampus, "number"],
        ["Average visit", $average($durations), "minutes"],
        ["Longest visit", $durations ? max($durations) : null, "minutes"],
    ]);

    if ($statuses) {
        arsort($statuses);
        $summary->section("By status");
        $summary->columns([["Status", "status"], ["Visits", "number"], ["Share", "text"]]);
        foreach ($statuses as $status => $count) {
            $summary->row([analytics_export_status((string) $status), $count, $share($count)]);
        }
    }
    if ($destinations) {
        uasort($destinations, fn ($a, $b) => $b["visits"] <=> $a["visits"]);
        $summary->section("By destination");
        $summary->columns([["Destination", "text"], ["Visits", "number"], ["Checked in", "number"], ["Completed", "number"], ["Average visit", "minutes"]]);
        foreach ($destinations as $place => $data) {
            $summary->row([$place, $data["visits"], $data["checked_in"], count($data["durations"]), $average($data["durations"])]);
        }
    }
    if ($purposes) {
        arsort($purposes);
        $summary->section("By purpose");
        $summary->columns([["Purpose", "wrap"], ["Visits", "number"], ["Share", "text"]]);
        foreach ($purposes as $purpose => $count) {
            $summary->row([$purpose, $count, $share($count)]);
        }
    }
    if ($hours) {
        arsort($hours);
        $summary->section("Busiest check-in hours");
        $summary->columns([["Hour", "text"], ["Check-ins", "number"]]);
        foreach (array_slice($hours, 0, 5, true) as $hour => $count) {
            $summary->row([date("g A", mktime((int) $hour, 0)) . " – " . date("g A", mktime(((int) $hour + 1) % 24, 0)), $count]);
        }
    }

    $sheet = $book->sheet("Visits");
    $sheet->title("Visitor Analytics Report", $subtitle);
    $sheet->columns([
        ["Registration ID", "text"], ["Visitor", "text"], ["Visit type", "text"], ["Purpose", "wrap"],
        ["Destination", "text"], ["Subject", "wrap"], ["Status", "status"], ["Appointment", "datetime"],
        ["Check in", "datetime"], ["Check out", "datetime"], ["Duration", "minutes"],
    ]);
    foreach ($rows as $row) {
        $sheet->row([
            $row["registration"],
            $row["visitor"],
            analytics_export_visit_type((string) $row["visit_type"]),
            $row["purpose"],
            $row["destination"],
            $row["subject"],
            analytics_export_status((string) $row["status"]),
            $row["appointment_at"],
            $row["checked_in_at"],
            $row["completed_at"],
            $row["duration"],
        ]);
    }
    if (!$rows) {
        $sheet->note("No visits were recorded in this period.");
    }
    $book->send("visitor-analytics-" . $period . "-" . $now->format("Y-m-d") . ".xlsx");
}
