<?php
/*
 * What Security sees on the live map: one entry per visitor on campus (or who left
 * today) with the details shown on the visitor card, their latest position inside the
 * campus, and the direction they are walking. Also builds a visitor's walked trail.
 *
 * Positions outside the campus boundary are never stored (presence_service.php); older
 * readings that were stored before this rule existed are filtered out here as well.
 */

require_once __DIR__ . "/presence_service.php";
require_once __DIR__ . "/arrival_service.php";
require_once __DIR__ . "/personnel.php";

// A position older than these is shown as stale / offline.
const MONITOR_LIVE_SECONDS = 45;
const MONITOR_STALE_SECONDS = 180;
// Direction comes from movement of at least this many metres within this many seconds.
const MONITOR_HEADING_MIN_METERS = 6;
const MONITOR_HEADING_MAX_SECONDS = 120;
// Standing still for longer than this shows a dot instead of an arrow.
const MONITOR_STILL_SECONDS = 30;
// Trails: a jump longer than this, or silence longer than this, is lost signal and is
// not drawn as a straight line (the same jump limit as the admin's route analytics).
const MONITOR_TRAIL_MAX_STEP_METERS = 120;
const MONITOR_TRAIL_MAX_GAP_SECONDS = 300;
// A walked path this close to an office pin "passed near" it (route analytics uses the
// same 30 m). Only the visitor's phone confirms an arrival (arrival_service.php).
const MONITOR_OFFICE_RADIUS_METERS = 30;
const MONITOR_TRAIL_MAX_POINTS = 4000;

/**
 * Visitors for the live map: "active" (checked in now) or "finished" (checked out today).
 *
 * @return array<int, array<string, mixed>>
 */
function monitor_visitors(mysqli $conn, string $scope): array
{
    $entries = array_merge(monitor_single_entries($conn, $scope, 0), monitor_visit_entries($conn, $scope, 0));
    usort($entries, function (array $a, array $b) use ($scope): int {
        $keyA = (string) ($scope === "finished" ? $a["completed_at"] : $a["checked_in_at"]);
        $keyB = (string) ($scope === "finished" ? $b["completed_at"] : $b["checked_in_at"]);
        return strcmp($keyB, $keyA);
    });
    return monitor_finish_entries($conn, $entries, $scope === "active");
}

/** One visitor who has been checked in, by any of their appointment ids. */
function monitor_visitor(mysqli $conn, int $appointmentId): ?array
{
    $visit = visit_for_appointment($conn, $appointmentId);
    $entries = $visit
        ? monitor_visit_entries($conn, "one", (int) $visit["id"])
        : monitor_single_entries($conn, "one", $appointmentId);
    if (!$entries) {
        return null;
    }
    return monitor_finish_entries($conn, $entries, true)[0] ?? null;
}

function monitor_single_entries(mysqli $conn, string $scope, int $appointmentId): array
{
    $checkoutColumn = presence_schema_ready($conn) ? "a.checkout_method" : "NULL AS checkout_method";
    $singleOnly = visit_schema_ready($conn) ? "a.visit_id IS NULL" : "1 = 1";
    $scopes = [
        "active" => "a.status = 'checked_in'",
        "finished" => "a.status = 'completed' AND a.checked_in_at IS NOT NULL AND a.completed_at >= CURDATE()",
        "one" => "a.id = ? AND a.checked_in_at IS NOT NULL",
    ];
    $stmt = $conn->prepare(
        "SELECT a.id, a.visitor_full_name, a.registration_code, a.created_at, a.office_code, a.visit_type,
                a.purpose, a.subject, a.scheduled_start_at, a.scheduled_end_at, a.status,
                a.checked_in_at, a.checked_in_by_user_id, a.completed_at, a.completed_by_user_id, {$checkoutColumn}
         FROM appointments a
         WHERE {$singleOnly} AND {$scopes[$scope]}
         ORDER BY a.id DESC
         LIMIT 200"
    );
    if ($scope === "one") {
        $stmt->bind_param("i", $appointmentId);
    }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $arrivals = arrival_lookup($conn, array_column($rows, "id"));

    $entries = [];
    foreach ($rows as $row) {
        $id = (int) $row["id"];
        $year = $row["created_at"] ? date("Y", strtotime((string) $row["created_at"])) : date("Y");
        $officeCode = (string) ($row["office_code"] ?? "");
        $entries[] = [
            "id" => $id,
            "visit_id" => null,
            "status" => $row["status"] === "checked_in" ? "checked_in" : "completed",
            "visitor_full_name" => (string) $row["visitor_full_name"],
            "registration_id" => $row["registration_code"] ?: ("V-" . $year . "-" . str_pad((string) $id, 6, "0", STR_PAD_LEFT)),
            "visit_type" => str_replace("-", "_", strtolower((string) ($row["visit_type"] ?: "appointment"))),
            "purpose" => (string) ($row["purpose"] ?? ""),
            "subject" => (string) ($row["subject"] ?? ""),
            "office_label" => appointment_office_label($officeCode),
            "stops" => [[
                "appointment_id" => $id,
                "office_code" => $officeCode,
                "office_label" => appointment_office_label($officeCode),
                "status" => (string) $row["status"],
                "scheduled_start_at" => $row["scheduled_start_at"],
                "scheduled_end_at" => $row["scheduled_end_at"],
            ] + ($arrivals[$id] ?? arrival_public(null))],
            "scheduled_start_at" => $row["scheduled_start_at"],
            "scheduled_end_at" => $row["scheduled_end_at"],
            "checked_in_at" => $row["checked_in_at"],
            "checked_in_by_user_id" => $row["checked_in_by_user_id"] !== null ? (int) $row["checked_in_by_user_id"] : null,
            "completed_at" => $row["status"] === "checked_in" ? null : $row["completed_at"],
            "completed_by_user_id" => $row["completed_by_user_id"] !== null ? (int) $row["completed_by_user_id"] : null,
            "checkout_method" => $row["checkout_method"],
        ];
    }
    return $entries;
}

function monitor_visit_entries(mysqli $conn, string $scope, int $visitId): array
{
    if (!visit_schema_ready($conn)) {
        return [];
    }
    $checkoutColumn = presence_schema_ready($conn) ? "v.checkout_method" : "NULL AS checkout_method";
    $scopes = [
        "active" => "v.status = 'checked_in'",
        "finished" => "v.status = 'completed' AND v.checked_in_at IS NOT NULL AND v.completed_at >= CURDATE()",
        "one" => "v.id = ? AND v.checked_in_at IS NOT NULL",
    ];
    $stmt = $conn->prepare(
        "SELECT v.id, v.visit_code, v.status, v.tracking_appointment_id, v.checked_in_at, v.checked_in_by_user_id,
                v.completed_at, v.completed_by_user_id, {$checkoutColumn}
         FROM visits v
         WHERE {$scopes[$scope]}
         ORDER BY v.id DESC
         LIMIT 200"
    );
    if ($scope === "one") {
        $stmt->bind_param("i", $visitId);
    }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $stopsByVisit = [];
    $stopIds = [];
    foreach ($rows as $row) {
        $stopsByVisit[(int) $row["id"]] = visit_stops($conn, (int) $row["id"]);
        $stopIds = array_merge($stopIds, array_column($stopsByVisit[(int) $row["id"]], "id"));
    }
    $arrivals = arrival_lookup($conn, $stopIds);

    $entries = [];
    foreach ($rows as $row) {
        $stops = $stopsByVisit[(int) $row["id"]];
        if (!$stops) {
            continue;
        }
        $first = $stops[0];
        $endTimes = array_filter(array_column($stops, "scheduled_end_at"));
        $entries[] = [
            "id" => !empty($row["tracking_appointment_id"]) ? (int) $row["tracking_appointment_id"] : (int) $first["id"],
            "visit_id" => (int) $row["id"],
            "status" => $row["status"] === "checked_in" ? "checked_in" : "completed",
            "visitor_full_name" => (string) $first["visitor_full_name"],
            "registration_id" => (string) $row["visit_code"],
            "visit_type" => "multi_stop",
            "purpose" => implode(", ", array_values(array_unique(array_filter(array_column($stops, "purpose"))))),
            "subject" => implode(", ", array_values(array_unique(array_filter(array_column($stops, "subject"))))),
            "office_label" => visit_route_label($stops),
            "stops" => array_map(function (array $stop) use ($arrivals): array {
                return [
                    "appointment_id" => (int) $stop["id"],
                    "office_code" => (string) $stop["office_code"],
                    "office_label" => appointment_office_label((string) $stop["office_code"]),
                    "status" => (string) $stop["status"],
                    "scheduled_start_at" => $stop["scheduled_start_at"],
                    "scheduled_end_at" => $stop["scheduled_end_at"],
                ] + ($arrivals[(int) $stop["id"]] ?? arrival_public(null));
            }, $stops),
            "scheduled_start_at" => $first["scheduled_start_at"],
            "scheduled_end_at" => $endTimes ? max($endTimes) : null,
            "checked_in_at" => $row["checked_in_at"],
            "checked_in_by_user_id" => $row["checked_in_by_user_id"] !== null ? (int) $row["checked_in_by_user_id"] : null,
            "completed_at" => $row["status"] === "checked_in" ? null : $row["completed_at"],
            "completed_by_user_id" => $row["completed_by_user_id"] !== null ? (int) $row["completed_by_user_id"] : null,
            "checkout_method" => $row["checkout_method"],
        ];
    }
    return $entries;
}

/** Adds staff names, inside/outside state, and (for visitors on campus) their position. */
function monitor_finish_entries(mysqli $conn, array $entries, bool $withPosition): array
{
    if (!$entries) {
        return [];
    }
    $userIds = [];
    $trackingIds = [];
    foreach ($entries as $entry) {
        foreach (["checked_in_by_user_id", "completed_by_user_id"] as $key) {
            if ($entry[$key]) {
                $userIds[$entry[$key]] = true;
            }
        }
        $trackingIds[] = (int) $entry["id"];
    }

    $names = [];
    if ($userIds) {
        $ids = implode(",", array_map("intval", array_keys($userIds)));
        $result = $conn->query("SELECT id, username, display_name, first_name, last_name FROM app_users WHERE id IN ({$ids})");
        foreach ($result->fetch_all(MYSQLI_ASSOC) as $user) {
            $names[(int) $user["id"]] = personnel_full_name($user);
        }
    }

    $presence = [];
    $idList = implode(",", array_map("intval", $trackingIds));
    if (presence_schema_ready($conn)) {
        $result = $conn->query("SELECT * FROM visitor_presence WHERE appointment_id IN ({$idList})");
        foreach ($result->fetch_all(MYSQLI_ASSOC) as $row) {
            $presence[(int) $row["appointment_id"]] = $row;
        }
    }

    $pointCounts = [];
    $result = $conn->query("SELECT appointment_id, COUNT(*) AS total FROM locations WHERE appointment_id IN ({$idList}) GROUP BY appointment_id");
    foreach ($result->fetch_all(MYSQLI_ASSOC) as $row) {
        $pointCounts[(int) $row["appointment_id"]] = (int) $row["total"];
    }

    $campus = presence_campus($conn);
    $finished = [];
    foreach ($entries as $entry) {
        $id = (int) $entry["id"];
        $row = $presence[$id] ?? null;
        $onCampus = $entry["status"] === "checked_in";
        $entry["checked_in_by"] = $entry["checked_in_by_user_id"] ? ($names[$entry["checked_in_by_user_id"]] ?? null) : null;
        $entry["checked_out_by"] = $entry["completed_by_user_id"] ? ($names[$entry["completed_by_user_id"]] ?? null) : null;
        unset($entry["checked_in_by_user_id"], $entry["completed_by_user_id"]);
        $entry["presence"] = $row ? (string) $row["state"] : "unknown";
        $entry["outside_since"] = $row && $row["state"] === "outside" ? $row["outside_since"] : null;
        $entry["point_count"] = $pointCounts[$id] ?? 0;
        $entry["arrivals"] = monitor_confirmed_arrivals($campus, $entry["stops"]);
        $position = $withPosition && $onCampus
            ? monitor_position($conn, $id, (string) $entry["checked_in_at"], $row, $campus)
            : monitor_empty_position($onCampus ? "waiting" : "ended");
        $finished[] = array_merge($entry, $position);
    }
    return $finished;
}

function monitor_empty_position(string $state): array
{
    return [
        "has_location" => false,
        "latitude" => null,
        "longitude" => null,
        "accuracy" => null,
        "recorded_at" => null,
        "seconds_since_update" => null,
        "location_state" => $state,
        "heading" => null,
        "moving" => false,
    ];
}

/**
 * Latest position inside the campus, how fresh it is, and the direction of travel.
 * A visitor outside the boundary has no position at all.
 */
function monitor_position(mysqli $conn, int $trackingId, string $checkedInAt, ?array $presence, array $campus): array
{
    $now = time();
    $lastReport = $presence && $presence["last_report_at"] ? strtotime((string) $presence["last_report_at"]) : null;
    if ($presence && $presence["state"] === "outside") {
        $position = monitor_empty_position("outside");
        $position["seconds_since_update"] = $lastReport ? max(0, $now - $lastReport) : null;
        return $position;
    }

    $stmt = $conn->prepare(
        "SELECT latitude, longitude, accuracy, recorded_at FROM locations
         WHERE appointment_id = ? AND recorded_at >= ?
         ORDER BY recorded_at DESC, id DESC
         LIMIT 12"
    );
    $stmt->bind_param("is", $trackingId, $checkedInAt);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $points = [];
    $newest = $lastReport;
    foreach ($rows as $row) {
        $latitude = (float) $row["latitude"];
        $longitude = (float) $row["longitude"];
        if (!presence_is_inside($campus, $latitude, $longitude)) {
            continue;
        }
        $time = strtotime((string) $row["recorded_at"]);
        $newest = max((int) $newest, $time);
        if (presence_is_accurate($row["accuracy"])) {
            $points[] = [$latitude, $longitude, $row["accuracy"] === null ? null : (float) $row["accuracy"], $time];
        }
    }
    if (!$points) {
        return monitor_empty_position("waiting");
    }

    [$latitude, $longitude, $accuracy, $time] = $points[0];
    $age = max(0, $now - max($time, (int) $newest));
    $heading = null;
    $moving = false;
    // Walk back to the most recent earlier point far enough away to give a direction.
    // The points between are where the visitor has been standing since.
    $stillSince = $time;
    for ($index = 1; $index < count($points); $index++) {
        [$pastLat, $pastLng, , $pastTime] = $points[$index];
        if ($time - $pastTime > MONITOR_HEADING_MAX_SECONDS) {
            break;
        }
        if (campus_distance_meters($pastLat, $pastLng, $latitude, $longitude) >= MONITOR_HEADING_MIN_METERS) {
            $heading = round(monitor_bearing($pastLat, $pastLng, $latitude, $longitude), 1);
            $moving = $time - $stillSince <= MONITOR_STILL_SECONDS && $age <= MONITOR_STALE_SECONDS;
            break;
        }
        $stillSince = $pastTime;
    }

    return [
        "has_location" => true,
        "latitude" => $latitude,
        "longitude" => $longitude,
        "accuracy" => $accuracy,
        "recorded_at" => date("Y-m-d H:i:s", $time),
        "seconds_since_update" => $age,
        "location_state" => $age <= MONITOR_LIVE_SECONDS ? "live" : ($age <= MONITOR_STALE_SECONDS ? "stale" : "offline"),
        "heading" => $heading,
        "moving" => $moving,
    ];
}

/** Compass bearing in degrees (0 = north, 90 = east) from the first point to the second. */
function monitor_bearing(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $phi1 = deg2rad($lat1);
    $phi2 = deg2rad($lat2);
    $deltaLng = deg2rad($lng2 - $lng1);
    $y = sin($deltaLng) * cos($phi2);
    $x = cos($phi1) * sin($phi2) - sin($phi1) * cos($phi2) * cos($deltaLng);
    return fmod(rad2deg(atan2($y, $x)) + 360.0, 360.0);
}

/**
 * The visitor's walked path inside the campus, oldest first. With $afterId > 0 only the
 * points stored after that id are returned (live updates), and "brk" of the first one is
 * null because only the browser knows the point before it.
 *
 * Each point: id, lat, lng, acc, t, brk (true = lost signal before this point, so no line
 * is drawn to it). Readings that are inaccurate, outside the campus, or repeat the same
 * spot are left out.
 */
function monitor_trail(mysqli $conn, array $visitor, int $afterId): array
{
    $campus = presence_campus($conn);
    $end = $visitor["status"] === "completed" ? $visitor["completed_at"] : null;
    $stmt = $conn->prepare(
        "SELECT id, latitude, longitude, accuracy, recorded_at FROM locations
         WHERE appointment_id = ? AND recorded_at >= ? AND (? IS NULL OR recorded_at <= ?) AND id > ?
         ORDER BY recorded_at ASC, id ASC
         LIMIT 20000"
    );
    $trackingId = (int) $visitor["id"];
    $checkedInAt = (string) $visitor["checked_in_at"];
    $stmt->bind_param("isssi", $trackingId, $checkedInAt, $end, $end, $afterId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $points = [];
    $lastId = $afterId;
    $previous = null;
    $distance = 0.0;
    foreach ($rows as $row) {
        $lastId = max($lastId, (int) $row["id"]);
        $latitude = (float) $row["latitude"];
        $longitude = (float) $row["longitude"];
        if (!presence_is_accurate($row["accuracy"]) || !presence_is_inside($campus, $latitude, $longitude)) {
            continue;
        }
        $time = strtotime((string) $row["recorded_at"]);
        $break = $afterId > 0 ? null : false;
        if ($previous) {
            $step = campus_distance_meters($previous["lat"], $previous["lng"], $latitude, $longitude);
            $gap = $time - $previous["seen"];
            if ($step > MONITOR_TRAIL_MAX_STEP_METERS || $gap > MONITOR_TRAIL_MAX_GAP_SECONDS) {
                $break = true;
            } elseif ($step < 1.0) {
                // Same spot again (the phone repeats its position while standing still).
                $previous["seen"] = $time;
                continue;
            } else {
                $break = false;
                $distance += $step;
            }
        }
        $points[] = [
            "id" => (int) $row["id"],
            "lat" => $latitude,
            "lng" => $longitude,
            "acc" => $row["accuracy"] === null ? null : (float) $row["accuracy"],
            "t" => date("Y-m-d H:i:s", $time),
            "brk" => $break,
        ];
        $previous = ["lat" => $latitude, "lng" => $longitude, "seen" => $time];
    }

    if (count($points) > MONITOR_TRAIL_MAX_POINTS) {
        $every = (int) ceil(count($points) / MONITOR_TRAIL_MAX_POINTS);
        $last = count($points) - 1;
        $points = array_values(array_filter($points, function (array $point, int $index) use ($every, $last): bool {
            return $index % $every === 0 || $index === $last || $point["brk"] === true;
        }, ARRAY_FILTER_USE_BOTH));
    }

    $trail = ["points" => $points, "last_id" => $lastId];
    if ($afterId === 0) {
        $trail["stats"] = [
            "distance_meters" => (int) round($distance),
            "first_at" => $points ? $points[0]["t"] : null,
            "last_at" => $points ? $points[count($points) - 1]["t"] : null,
            "near_offices" => monitor_near_offices($campus, $visitor["stops"], $points),
        ];
    }
    return $trail;
}

/**
 * Confirmed arrivals (arrival_service.php): when the visitor's phone confirmed they reached
 * each office, and how. Positions are the office pins (null without a pin).
 */
function monitor_confirmed_arrivals(array $campus, array $stops): array
{
    $pins = [];
    foreach ($campus["offices"] ?? [] as $pin) {
        $pins[$pin["code"]] = $pin;
    }
    $arrivals = [];
    foreach ($stops as $stop) {
        if (empty($stop["arrived_at"])) {
            continue;
        }
        $pin = $pins[(string) $stop["office_code"]] ?? null;
        $arrivals[] = [
            "office_code" => (string) $stop["office_code"],
            "office_label" => (string) $stop["office_label"],
            "at" => $stop["arrived_at"],
            "method" => $stop["arrival_method"],
            "distance_meters" => $stop["arrival_distance_meters"],
            "accuracy_meters" => $stop["arrival_accuracy_meters"],
            "latitude" => $pin ? (float) $pin["latitude"] : null,
            "longitude" => $pin ? (float) $pin["longitude"] : null,
        ];
    }
    return $arrivals;
}

/**
 * When the walked path first came within MONITOR_OFFICE_RADIUS_METERS of each office pin.
 * This only means the visitor passed near the office; arrivals come from
 * monitor_confirmed_arrivals().
 */
function monitor_near_offices(array $campus, array $stops, array $points): array
{
    $pins = [];
    foreach ($campus["offices"] ?? [] as $pin) {
        $pins[$pin["code"]] = $pin;
    }
    $arrivals = [];
    foreach ($stops as $stop) {
        $code = (string) $stop["office_code"];
        if (!isset($pins[$code]) || isset($arrivals[$code])) {
            continue;
        }
        $pin = $pins[$code];
        foreach ($points as $point) {
            if (campus_distance_meters($point["lat"], $point["lng"], $pin["latitude"], $pin["longitude"]) <= MONITOR_OFFICE_RADIUS_METERS) {
                $arrivals[$code] = [
                    "office_code" => $code,
                    "office_label" => (string) $pin["label"],
                    "at" => $point["t"],
                    "latitude" => (float) $pin["latitude"],
                    "longitude" => (float) $pin["longitude"],
                ];
                break;
            }
        }
    }
    return array_values($arrivals);
}

/** The limits the browser needs to draw trails and badges exactly as the server does. */
function monitor_rules(): array
{
    return [
        "live_seconds" => MONITOR_LIVE_SECONDS,
        "stale_seconds" => MONITOR_STALE_SECONDS,
        "trail_max_step_meters" => MONITOR_TRAIL_MAX_STEP_METERS,
        "trail_max_gap_seconds" => MONITOR_TRAIL_MAX_GAP_SECONDS,
        "overstay_grace_minutes" => GATE_OVERSTAY_GRACE_MINUTES,
        "exit_confirm_seconds" => PRESENCE_EXIT_CONFIRM_SECONDS,
        "near_office_meters" => MONITOR_OFFICE_RADIUS_METERS,
    ];
}

/* ---------- Scanner: second scan of a pass ---------- */

function gate_duration_text(int $seconds): string
{
    $seconds = max(0, $seconds);
    if ($seconds < 60) {
        return $seconds . " second" . ($seconds === 1 ? "" : "s");
    }
    $minutes = intdiv($seconds, 60);
    if ($minutes < 60) {
        return $minutes . " minute" . ($minutes === 1 ? "" : "s");
    }
    $hours = intdiv($minutes, 60);
    $rest = $minutes % 60;
    return $hours . " hour" . ($hours === 1 ? "" : "s") . ($rest ? " " . $rest . " min" : "");
}

/** How a finished visit ended, for the scanner and the dashboard. */
function gate_method_phrase(?string $method): string
{
    return [
        "scan" => "checked out at the gate",
        "guard" => "ended by Security",
        "left_campus" => "left the campus without checking out",
        "end_of_day" => "ended automatically at the end of the day",
    ][(string) $method] ?? "ended";
}

/** What the guard sees before confirming a check-out. */
function gate_checkout_preview(array $visitor): array
{
    $now = time();
    $checkedIn = strtotime((string) $visitor["checked_in_at"]);
    $slotEnd = $visitor["scheduled_end_at"] ? strtotime((string) $visitor["scheduled_end_at"]) : null;
    $warnings = [];
    foreach ($visitor["stops"] as $stop) {
        if (in_array($stop["status"], ["approved", "pending_approval", "reschedule_proposed"], true)) {
            $warnings[] = $stop["office_label"] . " has not seen this visitor yet. That stop will be cancelled and the office told the visitor left.";
        }
    }
    if ($visitor["presence"] === "outside") {
        $warnings[] = "Their phone already reports them outside the campus.";
    }
    return [
        "appointment_id" => (int) $visitor["id"],
        "visitor_full_name" => $visitor["visitor_full_name"],
        "registration_id" => $visitor["registration_id"],
        "visit_type" => $visitor["visit_type"],
        "office_label" => $visitor["office_label"],
        "stops" => $visitor["stops"],
        "checked_in_at" => $visitor["checked_in_at"],
        "checked_in_by" => $visitor["checked_in_by"],
        "scheduled_end_at" => $visitor["scheduled_end_at"],
        "seconds_on_campus" => max(0, $now - $checkedIn),
        "minutes_past_slot" => $slotEnd && $now > $slotEnd ? intdiv($now - $slotEnd, 60) : 0,
        "warnings" => $warnings,
    ];
}

/**
 * Scanner response for a pass whose visitor is on campus: a repeated scan right after
 * check-in (never a check-out), or the second scan, which offers a one-tap check-out.
 */
function gate_scan_on_campus(mysqli $conn, int $appointmentId, array $details): array
{
    $visitor = monitor_visitor($conn, $appointmentId);
    if (!$visitor || $visitor["status"] !== "checked_in") {
        return gate_scan_completed($conn, $appointmentId, $details);
    }
    $since = time() - strtotime((string) $visitor["checked_in_at"]);
    if ($since < GATE_RESCAN_GRACE_SECONDS) {
        return array_merge($details, [
            "success" => true,
            "action" => "already_checked_in",
            "already_checked_in" => true,
            "checked_in_at" => $visitor["checked_in_at"],
            "seconds_since_check_in" => max(0, $since),
            "message" => "Checked in " . gate_duration_text($since) . " ago. Scan this pass again when the visitor leaves to check them out.",
        ]);
    }
    return array_merge($details, [
        "success" => true,
        "action" => "checkout_ready",
        "checked_in_at" => $visitor["checked_in_at"],
        "checkout" => gate_checkout_preview($visitor),
        "message" => "This visitor is on campus. Confirm to check them out.",
    ]);
}

/** Scanner response for a pass whose visit has already ended. */
function gate_scan_completed(mysqli $conn, int $appointmentId, array $details): array
{
    $visitor = monitor_visitor($conn, $appointmentId);
    $completedAt = $visitor["completed_at"] ?? ($details["completed_at"] ?? null);
    $method = $visitor["checkout_method"] ?? null;
    $when = $completedAt ? date("M j, g:i A", strtotime((string) $completedAt)) : "";
    $who = $visitor && $visitor["checked_out_by"] && in_array($method, ["scan", "guard"], true)
        ? " by " . $visitor["checked_out_by"] : "";
    return array_merge($details, [
        "success" => false,
        "action" => "already_checked_out",
        "status" => "completed",
        "completed_at" => $completedAt,
        "checkout_method" => $method,
        "message" => "This visit already ended" . ($when !== "" ? " on " . $when : "") . ": "
            . gate_method_phrase($method) . $who . ". The pass can't be used again.",
    ]);
}
