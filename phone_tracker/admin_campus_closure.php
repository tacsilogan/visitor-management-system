<?php
/*
 * Closing the whole campus to visitors (admin.js, Menu or Offices > Close campus): a class
 * suspension, a holiday, bad weather. Every active office gets an "Office closed" change for that
 * time (office_availability_exceptions.campus_closure_id), so every booking and walk-in check
 * refuses it and the office staff see it on their Hours page. Visits booked in that time are
 * cancelled, and each visitor gets the administrator's message in the app.
 *
 *   GET                                              closures in effect or coming, and recent ones
 *   POST {"action": "preview", ...when}               what closing would affect
 *   POST {"action": "close", ...when, "reason", "message", "closure_id"?}   close, or change a closure
 *   POST {"action": "reopen", "closure_id"}           reopen; cancelled visits stay cancelled
 * when: {"from": "2026-11-25", "to": "2026-11-25", "all_day": true} or
 *       {"from": "2026-11-25", "all_day": false, "start_time": "13:00", "end_time": "17:00"}
 */
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/session_bootstrap.php";
require_once __DIR__ . "/visit_service.php";
require_once __DIR__ . "/admin_service.php";

auth_json_exception_guard();
auth_require_method("GET", "POST");
auth_require_csrf();
require_admin_json();
date_default_timezone_set("Asia/Manila");

if (!admin_table_ready($conn, "campus_closures")) {
    auth_json_error(503, "migration_required", "Run campus_closure_migration.sql (php tools/setup_database.php) first.");
}

const CLOSURE_MAX_DAYS = 31;
const CLOSURE_REASONS_MAX = 120;
const CLOSURE_MESSAGE_MAX = 500;
const CLOSURE_OPEN_STATUSES = ["pending_approval", "approved", "reschedule_proposed"];

$adminId = (int) $_SESSION["user_id"];
$now = new DateTimeImmutable("now");

/** @return array{0: DateTimeImmutable, 1: DateTimeImmutable, 2: bool} start, end, all day */
function closure_read_when(array $input, DateTimeImmutable $now): array
{
    $date = fn (string $value): ?DateTimeImmutable => ($parsed = DateTimeImmutable::createFromFormat("!Y-m-d", $value)) && $parsed->format("Y-m-d") === $value ? $parsed : null;
    $from = $date((string) ($input["from"] ?? ""));
    if (!$from) {
        auth_json_error(422, "invalid_date", "Choose the day the campus closes.");
    }
    $allDay = !array_key_exists("all_day", $input) || !empty($input["all_day"]);
    if ($allDay) {
        $to = $date((string) ($input["to"] ?? "")) ?: $from;
        if ($to < $from) {
            auth_json_error(422, "invalid_range", "The last day can't be before the first day.");
        }
        if ((int) $from->diff($to)->format("%a") >= CLOSURE_MAX_DAYS) {
            auth_json_error(422, "too_long", "Close the campus for at most " . CLOSURE_MAX_DAYS . " days at a time.");
        }
        $start = $from;
        $end = $to->modify("+1 day");
    } else {
        $time = function (string $value) use ($from): ?DateTimeImmutable {
            if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $value, $match)) {
                return null;
            }
            return $from->setTime((int) $match[1], (int) $match[2]);
        };
        $start = $time((string) ($input["start_time"] ?? ""));
        $end = $time((string) ($input["end_time"] ?? ""));
        if (!$start || !$end) {
            auth_json_error(422, "invalid_time", "Choose the hours the campus is closed.");
        }
        if ($end <= $start) {
            auth_json_error(422, "invalid_range", "The closing time must be after the opening time.");
        }
    }
    if ($end <= $now) {
        auth_json_error(422, "in_the_past", "That time has already passed.");
    }
    if ($start > $now->modify("+1 year")) {
        auth_json_error(422, "too_far", "Choose a day within the next year.");
    }
    return [$start, $end, $allDay];
}

/** Booked visits that closing would cancel: not yet started, starting in the closed time. */
function closure_affected(mysqli $conn, DateTimeImmutable $start, DateTimeImmutable $end, DateTimeImmutable $now): array
{
    $from = max($start, $now)->format("Y-m-d H:i:s");
    $to = $end->format("Y-m-d H:i:s");
    $stmt = $conn->prepare(
        "SELECT a.id, a.office_code, a.status, a.visitor_user_id, a.visitor_full_name, a.visit_id,
                COALESCE(a.scheduled_start_at, a.appointment_at) AS starts_at
         FROM appointments a
         WHERE a.status IN ('pending_approval', 'approved', 'reschedule_proposed')
           AND COALESCE(a.scheduled_start_at, a.appointment_at) >= ? AND COALESCE(a.scheduled_start_at, a.appointment_at) < ?
         ORDER BY starts_at ASC
         FOR UPDATE"
    );
    $stmt->bind_param("ss", $from, $to);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function closure_summary(array $affected, int $offices, string $when): array
{
    $names = appointment_office_map();
    $byOffice = [];
    foreach ($affected as $row) {
        $name = $names[(string) $row["office_code"]] ?? (string) $row["office_code"];
        $byOffice[$name] = ($byOffice[$name] ?? 0) + 1;
    }
    arsort($byOffice);
    $visitors = count(array_unique(array_map(
        fn (array $row): string => $row["visitor_user_id"] !== null ? "u" . $row["visitor_user_id"] : "n" . mb_strtolower((string) $row["visitor_full_name"]),
        $affected
    )));
    return [
        "when" => $when,
        "offices" => $offices,
        "visits" => count($affected),
        "visitors" => $visitors,
        "with_app" => count(array_unique(array_filter(array_map(fn (array $row) => $row["visitor_user_id"], $affected)))),
        "by_office" => array_map(fn ($name, $count) => ["office" => $name, "visits" => $count], array_keys($byOffice), array_values($byOffice)),
    ];
}

function closure_load(mysqli $conn, int $id): ?array
{
    $stmt = $conn->prepare("SELECT * FROM campus_closures WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function closure_active_offices(mysqli $conn): array
{
    return array_column($conn->query("SELECT code FROM offices WHERE is_active = 1 ORDER BY sort_order, name")->fetch_all(), 0);
}

/** Every active office closed for [$start, $end), linked to the closure. */
function closure_write_exceptions(mysqli $conn, int $closureId, DateTimeImmutable $start, DateTimeImmutable $end, string $reason, int $adminId): void
{
    $remove = $conn->prepare("DELETE FROM office_availability_exceptions WHERE campus_closure_id = ?");
    $remove->bind_param("i", $closureId);
    $remove->execute();
    $remove->close();
    $startSql = $start->format("Y-m-d H:i:s");
    $endSql = $end->format("Y-m-d H:i:s");
    $label = mb_substr("Campus closed: " . $reason, 0, 500, "UTF-8");
    $insert = $conn->prepare(
        "INSERT INTO office_availability_exceptions
         (office_code, starts_at, ends_at, is_available, capacity_override, reason, campus_closure_id, created_by_user_id)
         VALUES (?, ?, ?, 0, NULL, ?, ?, ?)"
    );
    foreach (closure_active_offices($conn) as $code) {
        $insert->bind_param("ssssii", $code, $startSql, $endSql, $label, $closureId, $adminId);
        $insert->execute();
    }
    $insert->close();
}

/** Cancels the visits, tells each visitor (with the admin's message) and each office once. */
function closure_cancel(mysqli $conn, array $affected, string $reason, string $message, string $when, int $adminId): int
{
    $perOffice = [];
    $visitIds = [];
    foreach ($affected as $row) {
        $appointmentId = (int) $row["id"];
        $from = (string) $row["status"];
        $cancel = $conn->prepare(
            "UPDATE appointments SET status = 'cancelled', status_updated_at = NOW(), cancelled_at = NOW(), cancelled_by_user_id = ?
             WHERE id = ? AND status = ?"
        );
        $cancel->bind_param("iis", $adminId, $appointmentId, $from);
        $cancel->execute();
        $changed = $cancel->affected_rows === 1;
        $cancel->close();
        if (!$changed) {
            continue;
        }
        visit_withdraw_open_proposals($conn, $appointmentId);
        visit_history($conn, $appointmentId, $from, "cancelled", $adminId, "Cancelled because the campus is closed to visitors: " . $reason);
        $office = appointment_office_label((string) $row["office_code"]);
        $at = date("D, M j, g:i A", strtotime((string) $row["starts_at"]));
        visit_notify_visitor(
            $conn,
            (int) ($row["visitor_user_id"] ?? 0),
            $appointmentId,
            "appointment.cancelled",
            "Visit cancelled: campus closed",
            trim($message) . " Your " . ($from === "pending_approval" ? "request" : "visit") . " to " . $office . " on " . $at . " is cancelled. You can book another day.",
            ["reason" => "campus_closed"]
        );
        $perOffice[(string) $row["office_code"]] = ($perOffice[(string) $row["office_code"]] ?? 0) + 1;
        if (!empty($row["visit_id"])) {
            $visitIds[(int) $row["visit_id"]] = true;
        }
    }
    // A multi-stop visit with every stop cancelled is closed.
    foreach (array_keys($visitIds) as $visitId) {
        $close = $conn->prepare(
            "UPDATE visits SET status = 'closed', closed_at = NOW()
             WHERE id = ? AND status = 'open'
               AND NOT EXISTS (SELECT 1 FROM appointments WHERE visit_id = ? AND status NOT IN ('cancelled', 'rejected', 'unanswered'))"
        );
        $close->bind_param("ii", $visitId, $visitId);
        $close->execute();
        $close->close();
    }
    foreach ($perOffice as $code => $count) {
        admin_notify_office_staff(
            $conn,
            (string) $code,
            "office.campus_closed",
            "Campus closed to visitors",
            "The administrator closed the campus " . $when . " (" . $reason . "). " . admin_plural($count, "visit", "visits")
                . " to your office " . ($count === 1 ? "was" : "were") . " cancelled and " . ($count === 1 ? "the visitor was" : "the visitors were") . " told."
        );
    }
    return array_sum($perOffice);
}

if (strtoupper((string) $_SERVER["REQUEST_METHOD"]) === "GET") {
    $recent = $conn->query(
        "SELECT id, starts_at, ends_at, all_day, reason, cancelled_visits, reopened_at FROM campus_closures
         WHERE reopened_at IS NOT NULL OR ends_at <= NOW() ORDER BY starts_at DESC LIMIT 5"
    )->fetch_all(MYSQLI_ASSOC);
    auth_json([
        "success" => true,
        "closures" => admin_closures($conn, $now),
        "recent" => array_map(fn (array $row): array => [
            "id" => (int) $row["id"],
            "when" => admin_closure_when((string) $row["starts_at"], (string) $row["ends_at"], (int) $row["all_day"] === 1),
            "reason" => (string) $row["reason"],
            "cancelled_visits" => (int) $row["cancelled_visits"],
            "reopened" => $row["reopened_at"] !== null,
        ], $recent),
    ]);
}

$input = auth_json_body();
$action = (string) ($input["action"] ?? "");

if ($action === "preview") {
    [$start, $end, $allDay] = closure_read_when($input, $now);
    $conn->begin_transaction();
    $affected = closure_affected($conn, $start, $end, $now);
    $conn->rollback();
    auth_json(["success" => true, "preview" => closure_summary(
        $affected,
        count(closure_active_offices($conn)),
        admin_closure_when($start->format("Y-m-d H:i:s"), $end->format("Y-m-d H:i:s"), $allDay)
    )]);
}

if ($action === "close") {
    [$start, $end, $allDay] = closure_read_when($input, $now);
    $reason = personnel_clean_text((string) ($input["reason"] ?? ""), CLOSURE_REASONS_MAX);
    $message = personnel_clean_text((string) ($input["message"] ?? ""), CLOSURE_MESSAGE_MAX);
    if (mb_strlen($reason, "UTF-8") < 3) {
        auth_json_error(422, "invalid_reason", "Choose or write the reason visitors see.");
    }
    if (mb_strlen($message, "UTF-8") < 5) {
        auth_json_error(422, "invalid_message", "Write the message the visitors get.");
    }
    $closureId = (int) ($input["closure_id"] ?? 0);
    $existing = $closureId > 0 ? closure_load($conn, $closureId) : null;
    if ($closureId > 0 && (!$existing || $existing["reopened_at"] !== null)) {
        auth_json_error(404, "not_found", "That closure was already reopened.");
    }
    require_step_up_json();
    $when = admin_closure_when($start->format("Y-m-d H:i:s"), $end->format("Y-m-d H:i:s"), $allDay);
    $startSql = $start->format("Y-m-d H:i:s");
    $endSql = $end->format("Y-m-d H:i:s");
    $all = $allDay ? 1 : 0;
    $conn->begin_transaction();
    try {
        $affected = closure_affected($conn, $start, $end, $now);
        if ($existing) {
            $stmt = $conn->prepare("UPDATE campus_closures SET starts_at = ?, ends_at = ?, all_day = ?, reason = ?, message = ? WHERE id = ?");
            $stmt->bind_param("ssissi", $startSql, $endSql, $all, $reason, $message, $closureId);
            $stmt->execute();
            $stmt->close();
        } else {
            $stmt = $conn->prepare(
                "INSERT INTO campus_closures (starts_at, ends_at, all_day, reason, message, created_by_user_id) VALUES (?, ?, ?, ?, ?, ?)"
            );
            $stmt->bind_param("ssissi", $startSql, $endSql, $all, $reason, $message, $adminId);
            $stmt->execute();
            $closureId = (int) $conn->insert_id;
            $stmt->close();
        }
        closure_write_exceptions($conn, $closureId, $start, $end, $reason, $adminId);
        $cancelled = closure_cancel($conn, $affected, $reason, $message, $when, $adminId);
        $stmt = $conn->prepare("UPDATE campus_closures SET cancelled_visits = cancelled_visits + ? WHERE id = ?");
        $stmt->bind_param("ii", $cancelled, $closureId);
        $stmt->execute();
        $stmt->close();
        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }
    auth_audit($conn, $adminId, $existing ? "campus.closure_changed" : "campus.closed", "campus_closure", (string) $closureId, [
        "when" => $when,
        "reason" => $reason,
        "cancelled_visits" => $cancelled,
    ]);
    auth_json([
        "success" => true,
        "closure_id" => $closureId,
        "message" => ($existing ? "Closure changed: " : "Campus closed ") . $when . "."
            . ($cancelled ? " " . admin_plural($cancelled, "booked visit was", "booked visits were") . " cancelled and " . ($cancelled === 1 ? "the visitor was" : "the visitors were") . " told." : " No booked visits were affected."),
    ]);
}

if ($action === "reopen") {
    $closureId = (int) ($input["closure_id"] ?? 0);
    $closure = closure_load($conn, $closureId);
    if (!$closure || $closure["reopened_at"] !== null) {
        auth_json_error(404, "not_found", "That closure was already reopened.");
    }
    $when = admin_closure_when((string) $closure["starts_at"], (string) $closure["ends_at"], (int) $closure["all_day"] === 1);
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("DELETE FROM office_availability_exceptions WHERE campus_closure_id = ?");
        $stmt->bind_param("i", $closureId);
        $stmt->execute();
        $stmt->close();
        $stmt = $conn->prepare("UPDATE campus_closures SET reopened_at = NOW(), reopened_by_user_id = ? WHERE id = ?");
        $stmt->bind_param("ii", $adminId, $closureId);
        $stmt->execute();
        $stmt->close();
        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }
    auth_audit($conn, $adminId, "campus.reopened", "campus_closure", (string) $closureId, ["when" => $when, "reason" => (string) $closure["reason"]]);
    auth_json(["success" => true, "message" => "The campus is open again for " . $when . ". Visitors can book that time."]);
}

auth_json_error(400, "invalid_action", "Unknown action.");
