<?php
declare(strict_types=1);
/*
 * The Admin dashboard's Home and Offices pages (admin.html): each office right now, what needs
 * the administrator, the campus setup checklist, a closure in effect, and the settings of the
 * automatic request reminders and the morning summary email. Shared by admin_overview.php,
 * admin_remind_office.php and the two background jobs (workers/send_request_reminders.php,
 * workers/send_admin_digest.php).
 */

require_once __DIR__ . "/personnel.php";
require_once __DIR__ . "/appointment_offices.php";
require_once __DIR__ . "/office_availability_service.php";
require_once __DIR__ . "/office_directions_service.php";
require_once __DIR__ . "/campus_map_service.php";

/** Automatic reminders to an office about requests nobody has answered (off until the admin turns them on). */
const ADMIN_REMINDER_DEFAULTS = [
    "on" => false,
    // Counted in working time only: Monday to Friday, 8:00 AM to 5:00 PM.
    "target_minutes" => 240,
    "at_target" => true,
    "before_expiry" => true,
    "tell_admin" => true,
];
const ADMIN_REMINDER_TARGETS = [120, 240, 480, 540];
/** A request is "about to expire" this long before its time ends. */
const ADMIN_EXPIRY_WARNING_MINUTES = 60;
/** The morning summary email, per administrator (off until they turn it on). */
const ADMIN_DIGEST_DEFAULTS = [
    "on" => false,
    "hour" => 7,
    "attention" => true,
    "numbers" => true,
    "slowest" => true,
    "always" => false,
];
const ADMIN_DIGEST_HOURS = [6, 7, 8];
const ADMIN_WORK_START_MINUTE = 8 * 60;
const ADMIN_WORK_END_MINUTE = 17 * 60;
/** How far back "answers in" and "expired" look. */
const ADMIN_RECENT_DAYS = 30;

/* ---------- Settings (mobile_api_settings, one JSON value each) ---------- */

function admin_setting(mysqli $conn, string $key, array $defaults): array
{
    $stmt = $conn->prepare("SELECT setting_value FROM mobile_api_settings WHERE setting_key = ? LIMIT 1");
    $stmt->bind_param("s", $key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_row();
    $stmt->close();
    $saved = $row ? json_decode((string) $row[0], true) : null;
    $value = $defaults;
    if (is_array($saved)) {
        foreach ($defaults as $name => $default) {
            if (array_key_exists($name, $saved)) {
                $value[$name] = is_bool($default) ? (bool) $saved[$name] : (is_int($default) ? (int) $saved[$name] : $saved[$name]);
            }
        }
    }
    return $value;
}

function admin_setting_text(mysqli $conn, string $key): string
{
    $stmt = $conn->prepare("SELECT setting_value FROM mobile_api_settings WHERE setting_key = ? LIMIT 1");
    $stmt->bind_param("s", $key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_row();
    $stmt->close();
    return $row ? (string) $row[0] : "";
}

function admin_setting_save(mysqli $conn, string $key, $value): void
{
    $text = is_array($value) ? json_encode($value) : (string) $value;
    $stmt = $conn->prepare(
        "INSERT INTO mobile_api_settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
    );
    $stmt->bind_param("ss", $key, $text);
    $stmt->execute();
    $stmt->close();
}

function admin_reminder_settings(mysqli $conn): array
{
    $settings = admin_setting($conn, "admin_reminders", ADMIN_REMINDER_DEFAULTS);
    if (!in_array($settings["target_minutes"], ADMIN_REMINDER_TARGETS, true)) {
        $settings["target_minutes"] = ADMIN_REMINDER_DEFAULTS["target_minutes"];
    }
    return $settings;
}

function admin_digest_settings(mysqli $conn, int $userId): array
{
    $settings = admin_setting($conn, "admin_digest_" . $userId, ADMIN_DIGEST_DEFAULTS);
    if (!in_array($settings["hour"], ADMIN_DIGEST_HOURS, true)) {
        $settings["hour"] = ADMIN_DIGEST_DEFAULTS["hour"];
    }
    return $settings;
}

/* ---------- Time ---------- */

/** Minutes of working time (Monday to Friday, 8:00 AM to 5:00 PM) between two moments. */
function admin_working_minutes(int $from, int $to): int
{
    if ($to <= $from) {
        return 0;
    }
    $total = 0;
    $day = (new DateTimeImmutable("@" . $from))->setTimezone(new DateTimeZone(date_default_timezone_get()))->setTime(0, 0);
    for ($guard = 0; $guard < 400 && $day->getTimestamp() < $to; $guard++) {
        if ((int) $day->format("N") <= 5) {
            $start = max($from, $day->getTimestamp() + ADMIN_WORK_START_MINUTE * 60);
            $end = min($to, $day->getTimestamp() + ADMIN_WORK_END_MINUTE * 60);
            if ($end > $start) {
                $total += intdiv($end - $start, 60);
            }
        }
        $day = $day->modify("+1 day");
    }
    return $total;
}

/** 45 -> "45 min", 80 -> "1 h 20 min", 1560 -> "26 h", 4400 -> "3 days". */
function admin_duration_label(int $minutes): string
{
    if ($minutes < 60) {
        return max(1, $minutes) . " min";
    }
    if ($minutes < 600) {
        $rest = $minutes % 60;
        return intdiv($minutes, 60) . " h" . ($rest ? " " . $rest . " min" : "");
    }
    if ($minutes < 2880) {
        return (int) round($minutes / 60) . " h";
    }
    return (int) round($minutes / 1440) . " days";
}

/** "10:40 AM", "tomorrow, 8:00 AM", "Thu, 8:00 AM" or "Nov 30, 8:00 AM". */
function admin_when(?string $value, DateTimeImmutable $now): string
{
    if (!$value) {
        return "";
    }
    $moment = new DateTimeImmutable($value);
    $days = (int) $now->setTime(0, 0)->diff($moment->setTime(0, 0))->format("%r%a");
    $time = $moment->format("g:i A");
    if ($days === 0) {
        return $time;
    }
    if ($days === 1) {
        return "tomorrow, " . $time;
    }
    if ($days === -1) {
        return "yesterday, " . $time;
    }
    if ($days > 1 && $days < 7) {
        return $moment->format("D") . ", " . $time;
    }
    return $moment->format("M j") . ", " . $time;
}

function admin_plural(int $count, string $one, string $many): string
{
    return $count . " " . ($count === 1 ? $one : $many);
}

/** "A", "A and B", "A, B and C", "A, B, C and 2 more". */
function admin_list_names(array $names, int $show = 3): string
{
    $names = array_values($names);
    if (count($names) > $show + 1) {
        return implode(", ", array_slice($names, 0, $show)) . " and " . (count($names) - $show) . " more";
    }
    if (count($names) <= 1) {
        return (string) ($names[0] ?? "");
    }
    return implode(", ", array_slice($names, 0, -1)) . " and " . $names[count($names) - 1];
}

/* ---------- Staff ---------- */

/** Every staff account (not visitors), with what the dashboard flags about each. */
function admin_staff(mysqli $conn): array
{
    $result = $conn->query(
        "SELECT u.id, u.username, u.display_name, u.first_name, u.last_name, u.position, u.email, u.role, u.office_code,
                u.is_active, u.last_login_at, u.last_seen_at, u.must_change_password, u.temp_password_expires_at,
                (u.totp_enabled_at IS NOT NULL) AS two_factor,
                TIMESTAMPDIFF(SECOND, u.last_seen_at, NOW()) AS seen_seconds_ago,
                (SELECT MAX(l.locked_until) FROM auth_lockouts l
                 WHERE l.user_id = u.id AND l.released_at IS NULL AND l.locked_until > NOW()
                   AND l.scope IN ('account_device', 'account')) AS locked_until,
                (SELECT MIN(l.created_at) FROM auth_lockouts l
                 WHERE l.user_id = u.id AND l.released_at IS NULL AND l.locked_until > NOW()
                   AND l.scope IN ('account_device', 'account')) AS locked_at,
                (SELECT MAX(l.failure_count) FROM auth_lockouts l
                 WHERE l.user_id = u.id AND l.released_at IS NULL AND l.locked_until > NOW()
                   AND l.scope IN ('account_device', 'account')) AS locked_failures
         FROM app_users u
         WHERE u.role IN ('admin', 'security', 'offices')
         ORDER BY u.role, u.last_name, u.first_name, u.username"
    );
    $staff = [];
    foreach ($result->fetch_all(MYSQLI_ASSOC) as $row) {
        $staff[] = [
            "id" => (int) $row["id"],
            "name" => personnel_full_name($row),
            "username" => (string) $row["username"],
            "email" => (string) ($row["email"] ?? ""),
            "role" => (string) $row["role"],
            "office_code" => (string) $row["office_code"],
            "position" => (string) $row["position"],
            "is_active" => (int) $row["is_active"] === 1,
            "online" => $row["seen_seconds_ago"] !== null && (int) $row["seen_seconds_ago"] <= 300,
            "last_login_at" => $row["last_login_at"],
            "two_factor" => (int) $row["two_factor"] === 1,
            "temp_password" => (int) $row["must_change_password"] === 1 && $row["temp_password_expires_at"] !== null,
            "temp_expires_at" => $row["temp_password_expires_at"],
            "locked_until" => $row["locked_until"],
            "locked_at" => $row["locked_at"],
            "locked_failures" => (int) ($row["locked_failures"] ?? 0),
        ];
    }
    return $staff;
}

/* ---------- Offices ---------- */

function admin_table_ready(mysqli $conn, string $table): bool
{
    static $known = [];
    if (!isset($known[$table])) {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
        $stmt->bind_param("s", $table);
        $stmt->execute();
        $known[$table] = (int) $stmt->get_result()->fetch_row()[0] > 0;
        $stmt->close();
    }
    return $known[$table];
}

/** A short line for an office's state: "Open · Lunch break at 12:00 PM", "Paused until 10:40 AM · Staff meeting". */
function admin_office_state_line(array $state, DateTimeImmutable $now): string
{
    $until = $state["until"] ?? null;
    switch ($state["state"]) {
        case "open":
            if ($until && ($state["next"] ?? "") !== "" && $state["next"] !== "Closing") {
                return "Open · " . $state["next"] . " at " . admin_when($until, $now);
            }
            return $until ? "Open until " . admin_when($until, $now) : "Open";
        case "break":
            return $state["label"] . ($until ? " until " . admin_when($until, $now) : "");
        case "paused":
            return "Paused" . ($until ? " until " . admin_when($until, $now) : "") . " · " . $state["label"];
        case "not_accepting":
            return "Not accepting visitors · " . $state["label"];
        default:
            return "Closed" . ($until ? " · opens " . admin_when($until, $now) : "");
    }
}

/**
 * Each office (archived ones last) with its state, staff, waiting requests, answer times and
 * setup steps (hours, staff, map pin, walking route, directions inside).
 */
function admin_offices(mysqli $conn, DateTimeImmutable $now, ?array $staff = null): array
{
    $staff = $staff ?? admin_staff($conn);
    $nowSql = $now->format("Y-m-d H:i:s");
    $recentSql = $now->modify("-" . ADMIN_RECENT_DAYS . " days")->format("Y-m-d H:i:s");
    $monthSql = $now->modify("first day of this month")->setTime(0, 0)->format("Y-m-d H:i:s");
    $todayStart = $now->setTime(0, 0)->format("Y-m-d H:i:s");
    $todayEnd = $now->setTime(23, 59, 59)->format("Y-m-d H:i:s");

    $offices = [];
    $result = $conn->query(
        "SELECT o.code, o.name, o.location, o.description, o.is_active, o.created_at
         FROM offices o ORDER BY o.is_active DESC, o.sort_order ASC, o.name ASC"
    );
    foreach ($result->fetch_all(MYSQLI_ASSOC) as $row) {
        $offices[(string) $row["code"]] = [
            "code" => (string) $row["code"],
            "name" => (string) $row["name"],
            "location" => (string) $row["location"],
            "description" => (string) $row["description"],
            "is_active" => (int) $row["is_active"] === 1,
            "where" => (string) $row["location"],
            "staff" => [],
            "waiting" => 0,
            "oldest_waiting_at" => null,
            "next_expiry_at" => null,
            "today" => 0,
            "visits_month" => 0,
            "answer_minutes" => null,
            "answered" => 0,
            "expired_recent" => 0,
            "setup" => ["hours" => false, "staff" => false, "pin" => false, "route" => false, "inside" => false],
            "state" => "closed",
            "state_line" => "",
            "reminded_at" => [],
        ];
    }
    foreach ($staff as $person) {
        if ($person["role"] === "offices" && $person["is_active"] && isset($offices[$person["office_code"]])) {
            $offices[$person["office_code"]]["staff"][] = [
                "id" => $person["id"], "name" => $person["name"], "online" => $person["online"],
            ];
            $offices[$person["office_code"]]["setup"]["staff"] = true;
        }
    }

    $stmt = $conn->prepare(
        "SELECT office_code,
                SUM(status = 'pending_approval' AND scheduled_end_at > ?) AS waiting,
                MIN(CASE WHEN status = 'pending_approval' AND scheduled_end_at > ? THEN created_at END) AS oldest_waiting_at,
                MIN(CASE WHEN status = 'pending_approval' AND scheduled_end_at > ? THEN scheduled_end_at END) AS next_expiry_at,
                SUM(scheduled_start_at BETWEEN ? AND ? AND status IN ('approved', 'checked_in', 'completed')) AS today,
                SUM(checked_in_at >= ?) AS visits_month,
                SUM(status = 'unanswered' AND unanswered_at >= ?) AS expired_recent
         FROM appointments GROUP BY office_code"
    );
    $stmt->bind_param("sssssss", $nowSql, $nowSql, $nowSql, $todayStart, $todayEnd, $monthSql, $recentSql);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $code = (string) $row["office_code"];
        if (!isset($offices[$code])) {
            continue;
        }
        $offices[$code]["waiting"] = (int) $row["waiting"];
        $offices[$code]["oldest_waiting_at"] = $row["oldest_waiting_at"];
        $offices[$code]["next_expiry_at"] = $row["next_expiry_at"];
        $offices[$code]["today"] = (int) $row["today"];
        $offices[$code]["visits_month"] = (int) $row["visits_month"];
        $offices[$code]["expired_recent"] = (int) $row["expired_recent"];
    }
    $stmt->close();

    // How long a person at the office takes to answer: approve, decline, or offer other times.
    $stmt = $conn->prepare(
        "SELECT a.office_code, a.created_at,
                LEAST(
                    COALESCE(CASE WHEN a.approved_by_user_id IS NOT NULL THEN a.approved_at END, '9999-12-31'),
                    COALESCE(CASE WHEN a.rejected_by_user_id IS NOT NULL THEN a.rejected_at END, '9999-12-31'),
                    COALESCE((SELECT MIN(p.created_at) FROM appointment_reschedule_proposals p WHERE p.appointment_id = a.id), '9999-12-31')
                ) AS answered_at
         FROM appointments a
         WHERE a.created_at >= ?"
    );
    $stmt->bind_param("s", $recentSql);
    $stmt->execute();
    $answers = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        if ((string) $row["answered_at"] === "" || str_starts_with((string) $row["answered_at"], "9999")) {
            continue;
        }
        $minutes = admin_working_minutes(strtotime((string) $row["created_at"]), strtotime((string) $row["answered_at"]));
        $answers[(string) $row["office_code"]][] = $minutes;
    }
    $stmt->close();
    foreach ($answers as $code => $list) {
        if (isset($offices[$code])) {
            $offices[$code]["answered"] = count($list);
            $offices[$code]["answer_minutes"] = (int) round(array_sum($list) / count($list));
        }
    }

    foreach ($conn->query("SELECT DISTINCT office_code FROM office_availability_rules")->fetch_all() as [$code]) {
        if (isset($offices[$code])) {
            $offices[$code]["setup"]["hours"] = true;
        }
    }
    if (admin_table_ready($conn, "campus_places")) {
        foreach ($conn->query("SELECT office_code FROM campus_places WHERE place_type = 'office'")->fetch_all() as [$code]) {
            if (isset($offices[$code])) {
                $offices[$code]["setup"]["pin"] = true;
            }
        }
    }
    if (admin_table_ready($conn, "campus_routes")) {
        foreach ($conn->query("SELECT DISTINCT office_code FROM campus_routes")->fetch_all() as [$code]) {
            if (isset($offices[(string) $code])) {
                $offices[(string) $code]["setup"]["route"] = true;
            }
        }
    }
    foreach (office_directions_load($conn) as $code => $directions) {
        if (!isset($offices[$code])) {
            continue;
        }
        $where = office_directions_where($directions);
        if ($where !== "") {
            $offices[$code]["where"] = $where;
        }
        if ($where !== "" || $directions["visitor_message"] !== "" || $directions["steps"]) {
            $offices[$code]["setup"]["inside"] = true;
        }
    }

    // Reminders sent today, by hand or automatically (admin_remind_office.php, send_request_reminders.php).
    $stmt = $conn->prepare(
        "SELECT entity_id, created_at FROM audit_logs
         WHERE action IN ('office.reminded', 'appointment.office_reminded') AND entity_type = 'office' AND created_at >= ?
         ORDER BY created_at ASC"
    );
    $stmt->bind_param("s", $todayStart);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all() as [$code, $at]) {
        if (isset($offices[$code])) {
            $offices[$code]["reminded_at"][] = $at;
        }
    }
    $stmt->close();

    foreach ($offices as $code => &$office) {
        if (!$office["is_active"]) {
            $office["state"] = "archived";
            $office["state_line"] = "Archived · visitors can't book it";
            continue;
        }
        $state = office_open_state($conn, $code, $now);
        $office["state"] = $state["state"];
        $office["state_line"] = admin_office_state_line($state, $now);
        if (!$office["staff"]) {
            $office["state"] = "nostaff";
            $office["state_line"] = "Hidden from visitors until someone is assigned";
        }
        $office["oldest_minutes"] = $office["oldest_waiting_at"]
            ? (int) floor(($now->getTimestamp() - strtotime((string) $office["oldest_waiting_at"])) / 60) : null;
        $office["answer_label"] = $office["answer_minutes"] !== null ? admin_duration_label($office["answer_minutes"]) : "";
        $decided = $office["answered"] + $office["expired_recent"];
        $office["answered_in_time"] = $decided > 0 ? (int) round($office["answered"] * 100 / $decided) : null;
        $office["setup_done"] = count(array_filter($office["setup"]));
    }
    unset($office);
    return array_values($offices);
}

/* ---------- Right now and the last days ---------- */

function admin_right_now(mysqli $conn, DateTimeImmutable $now): array
{
    $visitsReady = admin_table_ready($conn, "visits");
    $inside = $visitsReady
        ? "(SELECT COUNT(*) FROM appointments WHERE status = 'checked_in' AND visit_id IS NULL)
           + (SELECT COUNT(*) FROM visits WHERE status = 'checked_in')"
        : "(SELECT COUNT(*) FROM appointments WHERE status = 'checked_in')";
    $nowSql = $now->format("Y-m-d H:i:s");
    $todayEnd = $now->setTime(23, 59, 59)->format("Y-m-d H:i:s");
    $todayStart = $now->setTime(0, 0)->format("Y-m-d H:i:s");
    $weekStart = $now->setTime(0, 0)->modify("-6 days")->format("Y-m-d H:i:s");
    $previousStart = $now->setTime(0, 0)->modify("-13 days")->format("Y-m-d H:i:s");
    $stmt = $conn->prepare(
        "SELECT {$inside} AS on_campus,
                (SELECT COUNT(*) FROM appointments WHERE status = 'approved' AND scheduled_end_at > ? AND scheduled_start_at <= ?) AS expected_today,
                (SELECT COUNT(*) FROM appointments WHERE status IN ('approved', 'checked_in', 'completed') AND scheduled_start_at BETWEEN ? AND ?) AS booked_today,
                (SELECT COUNT(*) FROM appointments WHERE status = 'pending_approval' AND scheduled_end_at > ?) AS waiting,
                (SELECT COUNT(*) FROM appointments WHERE checked_in_at >= ?) AS visits_7,
                (SELECT COUNT(*) FROM appointments WHERE checked_in_at >= ? AND checked_in_at < ?) AS visits_prev_7"
    );
    $stmt->bind_param("ssssssss", $nowSql, $todayEnd, $todayStart, $todayEnd, $nowSql, $weekStart, $previousStart, $weekStart);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return array_map("intval", $row);
}

/** Visits (check-ins) on each of the last [$days] days, oldest first. */
function admin_last_days(mysqli $conn, DateTimeImmutable $now, int $days = 7): array
{
    $start = $now->setTime(0, 0)->modify("-" . ($days - 1) . " days");
    $startSql = $start->format("Y-m-d H:i:s");
    $stmt = $conn->prepare("SELECT DATE(checked_in_at) AS day, COUNT(*) FROM appointments WHERE checked_in_at >= ? GROUP BY DATE(checked_in_at)");
    $stmt->bind_param("s", $startSql);
    $stmt->execute();
    $counts = [];
    foreach ($stmt->get_result()->fetch_all() as [$day, $count]) {
        $counts[(string) $day] = (int) $count;
    }
    $stmt->close();
    $list = [];
    for ($index = 0; $index < $days; $index++) {
        $day = $start->modify("+" . $index . " days");
        $key = $day->format("Y-m-d");
        $list[] = [
            "date" => $key,
            "label" => $index === $days - 1 ? "Today" : $day->format("D"),
            "weekend" => (int) $day->format("N") >= 6,
            "visits" => $counts[$key] ?? 0,
        ];
    }
    return $list;
}

/* ---------- The campus closure in effect or coming ---------- */

function admin_closures(mysqli $conn, DateTimeImmutable $now): array
{
    if (!admin_table_ready($conn, "campus_closures")) {
        return [];
    }
    $nowSql = $now->format("Y-m-d H:i:s");
    $stmt = $conn->prepare(
        "SELECT id, starts_at, ends_at, all_day, reason, message, cancelled_visits, created_at
         FROM campus_closures WHERE reopened_at IS NULL AND ends_at > ? ORDER BY starts_at ASC"
    );
    $stmt->bind_param("s", $nowSql);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return array_map(function (array $row) use ($now): array {
        return [
            "id" => (int) $row["id"],
            "starts_at" => $row["starts_at"],
            "ends_at" => $row["ends_at"],
            "all_day" => (int) $row["all_day"] === 1,
            "reason" => (string) $row["reason"],
            "message" => (string) $row["message"],
            "cancelled_visits" => (int) $row["cancelled_visits"],
            "in_effect" => strtotime((string) $row["starts_at"]) <= $now->getTimestamp(),
            "when" => admin_closure_when($row["starts_at"], $row["ends_at"], (int) $row["all_day"] === 1),
        ];
    }, $rows);
}

/** "Wed, Nov 25, all day", "Nov 25 to Nov 27, all day", "Wed, Nov 25, 1:00 PM to 5:00 PM". */
function admin_closure_when(string $startsAt, string $endsAt, bool $allDay): string
{
    $start = new DateTimeImmutable($startsAt);
    $end = new DateTimeImmutable($endsAt);
    if ($allDay) {
        $lastDay = $end->modify("-1 second");
        return $start->format("Y-m-d") === $lastDay->format("Y-m-d")
            ? $start->format("D, M j") . ", all day"
            : $start->format("D, M j") . " to " . $lastDay->format("D, M j") . ", all day";
    }
    return $start->format("D, M j") . ", " . $start->format("g:i A") . " to "
        . ($start->format("Y-m-d") === $end->format("Y-m-d") ? $end->format("g:i A") : $end->format("D, M j, g:i A"));
}

/* ---------- Campus setup checklist ---------- */

function admin_campus_setup(mysqli $conn, array $offices): array
{
    $active = array_values(array_filter($offices, fn (array $office): bool => $office["is_active"]));
    $count = fn (string $step): int => count(array_filter($active, fn (array $office): bool => $office["setup"][$step]));
    $boundary = 0;
    $gates = [];
    $boundarySaved = null;
    if (admin_table_ready($conn, "campus_places")) {
        $boundary = (int) $conn->query("SELECT COUNT(*) FROM campus_places WHERE place_type = 'boundary'")->fetch_row()[0];
        $boundarySaved = $conn->query("SELECT MAX(updated_at) FROM campus_places WHERE place_type = 'boundary'")->fetch_row()[0];
        foreach ($conn->query("SELECT name FROM campus_places WHERE place_type = 'gate' ORDER BY sort_order, id")->fetch_all() as [$name]) {
            $gates[] = (string) $name;
        }
    }
    $total = count($active);
    $steps = [
        ["key" => "boundary", "label" => "Campus boundary", "done" => $boundary >= 3,
            "detail" => $boundary >= 3 ? admin_plural($boundary, "corner", "corners") . ($boundarySaved ? " · saved " . date("M j", strtotime((string) $boundarySaved)) : "") : "Draw the campus outline first"],
        ["key" => "gates", "label" => "Gates", "done" => count($gates) > 0,
            "detail" => $gates ? admin_list_names($gates) : "Where visitors come in"],
        ["key" => "pins", "label" => "Office pins", "done" => $total > 0 && $count("pin") === $total, "count" => $count("pin"), "total" => $total,
            "detail" => $count("pin") === $total ? "Every office is on the map" : "Visitors to the other " . ($total - $count("pin")) . " get no walking directions"],
        ["key" => "routes", "label" => "Walking routes", "done" => $total > 0 && $count("route") === $total, "count" => $count("route"), "total" => $total,
            "detail" => $count("route") === $total ? "A route to every office" : "Record the path from the gate"],
        ["key" => "inside", "label" => "Directions inside", "done" => $total > 0 && $count("inside") === $total, "count" => $count("inside"), "total" => $total,
            "detail" => $count("inside") === $total ? "Every office has them" : "Floor, room and steps inside the building"],
    ];
    $done = count(array_filter($steps, fn (array $step): bool => $step["done"]));
    return ["steps" => $steps, "done" => $done, "total" => count($steps)];
}

/* ---------- Needs your attention ---------- */

/** Appointment ids each office was already reminded about, by stage ("target", "expiry"), in the last 30 days. */
function admin_reminded_requests(mysqli $conn): array
{
    $reminded = [];
    $result = $conn->query(
        "SELECT details_json FROM audit_logs
         WHERE action = 'appointment.office_reminded' AND created_at > NOW() - INTERVAL 30 DAY"
    );
    foreach ($result->fetch_all() as [$json]) {
        $details = json_decode((string) $json, true);
        if (!is_array($details)) {
            continue;
        }
        foreach ((array) ($details["appointment_ids"] ?? []) as $id) {
            $reminded[(int) $id][(string) ($details["stage"] ?? "target")] = true;
        }
        // Requests that passed the target in the same minute another one was about to expire.
        foreach ((array) ($details["target_ids"] ?? []) as $id) {
            $reminded[(int) $id]["target"] = true;
        }
    }
    return $reminded;
}

/** Waiting requests per office: id, created_at, scheduled_end_at. */
function admin_waiting_requests(mysqli $conn, DateTimeImmutable $now): array
{
    $nowSql = $now->format("Y-m-d H:i:s");
    $stmt = $conn->prepare(
        "SELECT id, office_code, created_at, scheduled_end_at FROM appointments
         WHERE status = 'pending_approval' AND scheduled_end_at > ? ORDER BY created_at ASC"
    );
    $stmt->bind_param("s", $nowSql);
    $stmt->execute();
    $byOffice = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $byOffice[(string) $row["office_code"]][] = $row;
    }
    $stmt->close();
    return $byOffice;
}

/**
 * What needs the administrator, most urgent first. Each item: id, severity (red, amber, blue),
 * icon, title, text, why, and actions [label, action, primary?].
 */
function admin_attention(mysqli $conn, array $offices, array $staff, DateTimeImmutable $now, ?array $reminders = null): array
{
    $reminders = $reminders ?? admin_reminder_settings($conn);
    $items = [];
    $active = array_values(array_filter($offices, fn (array $office): bool => $office["is_active"]));

    $noStaff = array_values(array_filter($active, fn (array $office): bool => !$office["staff"]));
    if ($noStaff) {
        $names = array_column($noStaff, "name");
        $items[] = [
            "id" => "nostaff", "severity" => "red", "icon" => "people",
            "title" => count($noStaff) === 1 ? $names[0] . " has no Office Personnel" : count($noStaff) . " offices have no Office Personnel",
            "text" => count($noStaff) === 1 ? "No one can answer its requests." : admin_list_names($names) . ".",
            "why" => "Visitors can't book " . (count($noStaff) === 1 ? "it" : "them") . " until someone is assigned.",
            "actions" => [["Add personnel", "add:" . $noStaff[0]["code"], true], ["See offices", "go:offices:nostaff", false]],
        ];
    }

    foreach ($staff as $person) {
        if (!$person["is_active"] || !$person["locked_until"]) {
            continue;
        }
        $role = $person["role"] === "security" ? "A guard who can't sign in can't scan passes at the gate."
            : ($person["role"] === "offices" ? "Their office's requests wait until someone else answers them." : "They can't manage the system until it unlocks.");
        $items[] = [
            "id" => "lock-" . $person["id"], "severity" => "red", "icon" => "lock",
            "title" => $person["name"] . "'s sign-in is locked",
            "text" => ($person["locked_failures"] > 0 ? admin_plural($person["locked_failures"], "wrong password", "wrong passwords") . " at " . admin_when($person["locked_at"], $now) . ". " : "")
                . "It unlocks by itself at " . admin_when($person["locked_until"], $now) . ".",
            "why" => $role,
            "actions" => [["Unlock now", "unlock:" . $person["id"], true], ["See " . strtok($person["name"], " "), "person:" . $person["id"], false]],
        ];
    }

    // Offices slow to answer. With automatic reminders on, only once those reminders didn't help.
    $waiting = admin_waiting_requests($conn, $now);
    $reminded = $reminders["on"] ? admin_reminded_requests($conn) : [];
    foreach ($active as $office) {
        $requests = $waiting[$office["code"]] ?? [];
        if (!$requests || !$office["staff"]) {
            continue;
        }
        $late = array_filter($requests, function (array $request) use ($now, $reminders, $reminded): bool {
            $expiresIn = (strtotime((string) $request["scheduled_end_at"]) - $now->getTimestamp()) / 60;
            if ($reminders["on"]) {
                $stages = $reminded[(int) $request["id"]] ?? [];
                $done = isset($stages["expiry"]) || (!$reminders["before_expiry"] && isset($stages["target"]));
                return $reminders["tell_admin"] && $done;
            }
            $age = admin_working_minutes(strtotime((string) $request["created_at"]), $now->getTimestamp());
            return $age >= $reminders["target_minutes"] || $expiresIn <= 120;
        });
        if (!$late) {
            continue;
        }
        $count = count($requests);
        $soonest = min(array_map(fn (array $request): int => strtotime((string) $request["scheduled_end_at"]), $requests));
        $expiresToday = date("Y-m-d", $soonest) === $now->format("Y-m-d");
        $expiry = ($expiresToday ? "one expires today at " . date("g:i A", $soonest) : "the first expires " . admin_when(date("Y-m-d H:i:s", $soonest), $now));
        $times = array_values(array_unique(array_map(fn (string $at): string => date("g:i A", strtotime($at)), $office["reminded_at"])));
        $text = $reminders["on"] && $times
            ? "Reminded automatically at " . admin_list_names($times, 4) . ", still no answer. " . ucfirst($expiry) . "."
            : "The oldest was sent " . admin_duration_label((int) $office["oldest_minutes"]) . " ago, and " . $expiry . ".";
        $items[] = [
            "id" => "slow-" . $office["code"], "severity" => "amber", "icon" => "clock",
            "title" => $office["name"] . " has " . admin_plural($count, "request", "requests") . " waiting",
            "text" => $text,
            "why" => $office["expired_recent"] > 0
                ? admin_plural($office["expired_recent"], "request", "requests") . " there expired without an answer in the last " . ADMIN_RECENT_DAYS . " days."
                : "A request nobody answers expires, and the visitor has to ask again.",
            "actions" => [["Remind the office", "remind:" . $office["code"], true], ["See the office", "office:" . $office["code"], false]],
        ];
    }

    $staffActive = array_values(array_filter($staff, fn (array $person): bool => $person["is_active"]));
    $noTwoStep = array_values(array_filter($staffActive, fn (array $person): bool => !$person["two_factor"]));
    if ($noTwoStep) {
        $items[] = [
            "id" => "twostep", "severity" => "amber", "icon" => "shield",
            "title" => count($noTwoStep) . " of " . count($staffActive) . " staff don't use two-step sign-in",
            "text" => admin_list_names(array_column($noTwoStep, "name")) . ".",
            "why" => "A stolen password alone is then enough to sign in.",
            "actions" => [["See who", "go:people:twostep", false]],
        ];
    }

    foreach ($staffActive as $person) {
        if (!$person["temp_password"]) {
            continue;
        }
        $expired = strtotime((string) $person["temp_expires_at"]) <= $now->getTimestamp();
        $first = strtok($person["name"], " ");
        $items[] = [
            "id" => "temp-" . $person["id"], "severity" => $expired ? "amber" : "blue", "icon" => "key",
            "title" => $expired ? $person["name"] . "'s temporary password expired"
                : ($person["last_login_at"] ? $person["name"] . " still has a temporary password" : $person["name"] . " hasn't signed in yet"),
            "text" => $expired ? "It can't be used anymore." : "Their temporary password expires " . admin_when($person["temp_expires_at"], $now) . ".",
            "why" => $expired ? "Make a new one and give it to them." : "After that you'll need to make a new one.",
            "actions" => $expired
                ? [["Make a new password", "reset:" . $person["id"], true], ["See " . $first, "person:" . $person["id"], false]]
                : [["See " . $first, "person:" . $person["id"], false]],
        ];
    }

    $noPin = array_values(array_filter($active, fn (array $office): bool => !$office["setup"]["pin"]));
    if ($noPin) {
        $names = array_column($noPin, "name");
        $items[] = [
            "id" => "map", "severity" => "blue", "icon" => "map",
            "title" => count($noPin) === 1 ? $names[0] . " isn't on the campus map" : count($noPin) . " offices aren't on the campus map",
            "text" => count($noPin) === 1 ? "It has no pin." : admin_list_names($names) . ".",
            "why" => "Their visitors get no walking directions in the app.",
            "actions" => [["Open campus map", "go:campus", false]],
        ];
    }

    $order = ["red" => 0, "amber" => 1, "blue" => 2];
    usort($items, fn (array $a, array $b): int => $order[$a["severity"]] <=> $order[$b["severity"]]);
    return $items;
}

/* ---------- Notifying an office ---------- */

/** Sends each active staff member of an office one notification; returns how many got it. */
function admin_notify_office_staff(mysqli $conn, string $officeCode, string $type, string $title, string $message, array $data = []): int
{
    $dataJson = $data ? json_encode($data) : null;
    $stmt = $conn->prepare(
        "INSERT INTO app_notifications (recipient_user_id, appointment_id, notification_type, title, message, data_json)
         SELECT id, NULL, ?, ?, ?, ? FROM app_users WHERE role = 'offices' AND office_code = ? AND is_active = 1"
    );
    $stmt->bind_param("sssss", $type, $title, $message, $dataJson, $officeCode);
    $stmt->execute();
    $sent = $stmt->affected_rows;
    $stmt->close();
    return max(0, $sent);
}

/** "4 requests are waiting for your answer. The oldest was sent 26 h ago; one expires today at 11:30 AM." */
function admin_reminder_message(array $requests, DateTimeImmutable $now): string
{
    $count = count($requests);
    $oldest = min(array_map(fn (array $request): int => strtotime((string) $request["created_at"]), $requests));
    $soonest = min(array_map(fn (array $request): int => strtotime((string) $request["scheduled_end_at"]), $requests));
    $expiry = date("Y-m-d", $soonest) === $now->format("Y-m-d")
        ? "one expires today at " . date("g:i A", $soonest)
        : "the first expires " . admin_when(date("Y-m-d H:i:s", $soonest), $now);
    return ($count === 1 ? "1 request is" : $count . " requests are") . " waiting for your answer. The oldest was sent "
        . admin_duration_label((int) floor(($now->getTimestamp() - $oldest) / 60)) . " ago; " . $expiry . ". Open Requests to answer.";
}
