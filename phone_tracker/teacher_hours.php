<?php
/*
 * What a teacher changes for themselves: their weekly hours, how they take visitors, their room, and
 * when they are away. Only the signed-in teacher's own calendar is ever touched.
 */
header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store, max-age=0");

require_once __DIR__ . "/session_bootstrap.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/personnel.php";
require_once __DIR__ . "/teacher_service.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode(["success" => false, "message" => "Method not allowed"]);
    exit;
}
require_permission_json("teacher.dashboard");

$input = json_decode(file_get_contents("php://input"), true);
if (!is_array($input)) {
    echo json_encode(["success" => false, "message" => "Invalid request body"]);
    exit;
}
$code = strtoupper(trim((string) ($_SESSION["office_code"] ?? "")));
$actorId = (int) $_SESSION["user_id"];
if (!teacher_schema_ready($conn) || !appointment_is_teacher_code($code)) {
    echo json_encode(["success" => false, "message" => "This account has no teacher calendar."]);
    exit;
}
$action = strtolower(trim((string) ($input["action"] ?? "")));
$now = new DateTimeImmutable("now");

/** The week as saved, with some days replaced, ready for office_normalize_week(). */
function teacher_week_with(mysqli $conn, string $code, array $changedDays): array
{
    $saved = office_windows_by_day(office_load_rule_rows($conn, $code));
    $days = [];
    foreach (OFFICE_DAY_NAMES as $day => $name) {
        $windows = $changedDays[$day] ?? null;
        if ($windows === null) {
            $windows = array_map(fn(array $window): array => [
                "start" => office_minutes_input($window[0]),
                "end" => office_minutes_input($window[1]),
            ], $saved[$day] ?? []);
        }
        $days[] = ["day_of_week" => $day, "windows" => $windows];
    }
    return office_normalize_week(teacher_schedules_from_windows($days));
}

/** How long [$input] says to be away from now: "30", "60" or "end_of_day". */
function teacher_pause_end(mysqli $conn, string $code, string $length, DateTimeImmutable $now): DateTimeImmutable
{
    if ($length === "end_of_day") {
        return $now->setTime(23, 59, 59);
    }
    $minutes = (int) $length;
    if ($minutes < 5 || $minutes > 720) {
        throw new RuntimeException("Choose how long you won't be available");
    }
    return $now->modify("+{$minutes} minutes");
}

/** "2026-11-19" as a date within the next year, or an error naming the field. */
function teacher_read_date(string $value, string $label, DateTimeImmutable $now): DateTimeImmutable
{
    $date = DateTimeImmutable::createFromFormat("!Y-m-d", trim($value));
    $errors = DateTimeImmutable::getLastErrors();
    if (!$date || ($errors !== false && ((int) $errors["warning_count"] > 0 || (int) $errors["error_count"] > 0))) {
        throw new RuntimeException("Choose the {$label} date");
    }
    if ($date < $now->setTime(0, 0) || $date > $now->modify("+1 year")) {
        throw new RuntimeException("The {$label} date must be from today up to a year from now");
    }
    return $date;
}

/** Cancels the chosen visits in [$from, $to] and tells their visitors. The caller owns the transaction. */
function teacher_cancel_chosen(mysqli $conn, string $code, array $ids, DateTimeInterface $from, DateTimeInterface $to, int $actorId): array
{
    $told = [];
    $inRange = [];
    foreach (teacher_visits_between($conn, $code, $from, $to) as $visit) {
        $inRange[$visit["id"]] = true;
    }
    foreach ($ids as $rawId) {
        $id = (int) $rawId;
        if (!isset($inRange[$id])) {
            continue;
        }
        $stmt = $conn->prepare(
            "SELECT id, office_code, visitor_user_id, visitor_full_name, status, scheduled_start_at
             FROM appointments WHERE id = ? AND office_code = ? FOR UPDATE"
        );
        $stmt->bind_param("is", $id, $code);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            teacher_cancel_visit($conn, $row, $actorId, "");
            $told[] = (string) $row["visitor_full_name"];
        }
    }
    return $told;
}

function teacher_told_message(string $start, array $told): string
{
    if (!$told) {
        return $start;
    }
    return $start . " " . implode(" and ", $told) . (count($told) === 1 ? " was" : " were") . " told.";
}

try {
    $response = ["success" => true];
    switch ($action) {
        case "save_day":
            // One day's times, and the other days that get the same times.
            $day = (int) ($input["day_of_week"] ?? 0);
            if (!isset(OFFICE_DAY_NAMES[$day])) {
                throw new RuntimeException("Choose a day");
            }
            $windows = is_array($input["windows"] ?? null) ? array_values($input["windows"]) : [];
            $changed = [$day => $windows];
            foreach ((array) ($input["copy_to"] ?? []) as $other) {
                if (isset(OFFICE_DAY_NAMES[(int) $other])) {
                    $changed[(int) $other] = $windows;
                }
            }
            $week = teacher_week_with($conn, $code, $changed);
            $conflicts = office_schedule_conflicts($conn, $code, $week, $now);
            if ($conflicts && empty($input["confirm_conflicts"])) {
                $count = count($conflicts);
                echo json_encode([
                    "success" => false,
                    "code" => "schedule_conflicts",
                    "message" => $count === 1 ? "1 booked visit falls outside these hours." : "{$count} booked visits fall outside these hours.",
                    "conflicts" => array_slice($conflicts, 0, 20),
                ]);
                exit;
            }
            $conn->begin_transaction();
            office_store_week($conn, $code, $week);
            teacher_audit($conn, $actorId, null, "teacher.hours_updated", ["office_code" => $code, "open_days" => count($week)]);
            $conn->commit();
            $response["message"] = OFFICE_DAY_NAMES[$day] . " saved.";
            break;

        case "save_settings":
            $sets = [];
            $types = "";
            $values = [];
            if (array_key_exists("slot_duration_minutes", $input)) {
                $length = (int) $input["slot_duration_minutes"];
                if (!in_array($length, TEACHER_VISIT_LENGTHS, true)) {
                    throw new RuntimeException("Choose how long each visit lasts");
                }
                $sets[] = "slot_duration_minutes = ?";
                $types .= "i";
                $values[] = $length;
            }
            foreach (["auto_accept", "accept_walk_ins", "email_alerts"] as $flag) {
                if (array_key_exists($flag, $input)) {
                    $sets[] = "{$flag} = ?";
                    $types .= "i";
                    $values[] = !empty($input[$flag]) ? 1 : 0;
                }
            }
            if ($sets) {
                office_availability_get_settings($conn, $code);
                $types .= "s";
                $values[] = $code;
                $stmt = $conn->prepare("UPDATE office_availability_settings SET " . implode(", ", $sets) . " WHERE office_code = ?");
                $stmt->bind_param($types, ...$values);
                $stmt->execute();
                $stmt->close();
            }
            if (array_key_exists("room", $input)) {
                $room = personnel_clean_text((string) $input["room"], 150);
                $stmt = $conn->prepare("UPDATE offices SET location = ? WHERE code = ? AND kind = 'teacher'");
                $stmt->bind_param("ss", $room, $code);
                $stmt->execute();
                $stmt->close();
            }
            teacher_audit($conn, $actorId, null, "teacher.settings_updated", ["office_code" => $code, "changed" => array_keys(array_diff_key($input, ["action" => 1]))]);
            $response["message"] = "Saved.";
            break;

        case "preview_away":
            // Which accepted visits fall in the time the teacher is about to be away.
            if (!empty($input["from"])) {
                $from = teacher_read_date((string) $input["from"], "first", $now);
                $to = teacher_read_date((string) ($input["to"] ?? $input["from"]), "last", $now)->setTime(23, 59, 59);
            } else {
                $from = $now;
                $to = teacher_pause_end($conn, $code, (string) ($input["length"] ?? ""), $now);
            }
            $response["visits"] = teacher_visits_between($conn, $code, $from, $to);
            break;

        case "pause":
            // "I'm not available": no new bookings or walk-ins from now until the chosen time.
            $ends = teacher_pause_end($conn, $code, (string) ($input["length"] ?? ""), $now);
            $startsSql = $now->format("Y-m-d H:i:s");
            $endsSql = $ends->format("Y-m-d H:i:s");
            $conn->begin_transaction();
            $stmt = $conn->prepare(
                "INSERT INTO office_availability_exceptions
                 (office_code, starts_at, ends_at, is_available, capacity_override, reason, created_by_user_id)
                 VALUES (?, ?, ?, 0, NULL, 'Not available', ?)"
            );
            $stmt->bind_param("sssi", $code, $startsSql, $endsSql, $actorId);
            $stmt->execute();
            $stmt->close();
            $told = teacher_cancel_chosen($conn, $code, (array) ($input["cancel_ids"] ?? []), $now, $ends, $actorId);
            teacher_audit($conn, $actorId, null, "teacher.paused", ["office_code" => $code, "until" => $endsSql, "visits_cancelled" => count($told)]);
            $conn->commit();
            $response["message"] = teacher_told_message("Visitors now see you as not available.", $told);
            break;

        case "resume":
            $stmt = $conn->prepare(
                "UPDATE office_availability_exceptions SET ends_at = NOW()
                 WHERE office_code = ? AND is_available = 0 AND starts_at <= NOW() AND ends_at > NOW()"
            );
            $stmt->bind_param("s", $code);
            $stmt->execute();
            $stmt->close();
            teacher_audit($conn, $actorId, null, "teacher.resumed", ["office_code" => $code]);
            $response["message"] = "You're available again.";
            break;

        case "time_off_add":
            $from = teacher_read_date((string) ($input["from"] ?? ""), "first", $now);
            $to = teacher_read_date((string) ($input["to"] ?? ($input["from"] ?? "")), "last", $now);
            if ($to < $from) {
                throw new RuntimeException("The last day can't be before the first day");
            }
            $until = $to->setTime(23, 59, 59);
            $startsSql = $from->format("Y-m-d H:i:s");
            $endsSql = $until->format("Y-m-d H:i:s");
            $conn->begin_transaction();
            $stmt = $conn->prepare(
                "INSERT INTO office_availability_exceptions
                 (office_code, starts_at, ends_at, is_available, capacity_override, reason, created_by_user_id)
                 VALUES (?, ?, ?, 0, NULL, 'Time off', ?)"
            );
            $stmt->bind_param("sssi", $code, $startsSql, $endsSql, $actorId);
            $stmt->execute();
            $stmt->close();
            $told = teacher_cancel_chosen($conn, $code, (array) ($input["cancel_ids"] ?? []), $from, $until, $actorId);
            teacher_audit($conn, $actorId, null, "teacher.time_off_added", ["office_code" => $code, "from" => $startsSql, "to" => $endsSql, "visits_cancelled" => count($told)]);
            $conn->commit();
            $response["message"] = teacher_told_message("Time off added.", $told);
            break;

        case "time_off_remove":
            $id = (int) ($input["id"] ?? 0);
            $stmt = $conn->prepare("DELETE FROM office_availability_exceptions WHERE id = ? AND office_code = ? AND reason = 'Time off'");
            $stmt->bind_param("is", $id, $code);
            $stmt->execute();
            $removed = $stmt->affected_rows === 1;
            $stmt->close();
            if (!$removed) {
                throw new RuntimeException("That time off was already removed");
            }
            teacher_audit($conn, $actorId, null, "teacher.time_off_removed", ["office_code" => $code]);
            $response["message"] = "Time off removed.";
            break;

        default:
            throw new RuntimeException("Unknown action");
    }
    echo json_encode($response);
} catch (Throwable $error) {
    try {
        $conn->rollback();
    } catch (Throwable $ignored) {
    }
    echo json_encode(["success" => false, "message" => $error->getMessage()]);
}
