<?php
/*
 * Two settings from the Admin dashboard's Menu (admin.js):
 *   the automatic request reminders, for every office (workers/send_request_reminders.php)
 *   the morning summary email, for the signed-in administrator (workers/send_admin_digest.php)
 *
 *   GET  admin_settings.php
 *   POST {"kind": "reminders", "on": true, "target_minutes": 240, "at_target": true, "before_expiry": true, "tell_admin": true}
 *   POST {"kind": "digest", "on": true, "hour": 7, "attention": true, "numbers": true, "slowest": true, "always": false}
 */
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/session_bootstrap.php";
require_once __DIR__ . "/admin_service.php";
require_once __DIR__ . "/mail_service.php";

auth_json_exception_guard();
auth_require_method("GET", "POST");
auth_require_csrf();
require_admin_json();
date_default_timezone_set("Asia/Manila");

$adminId = (int) $_SESSION["user_id"];

/** The first moment at least [$minutes] of working time have passed since [$from]. */
function admin_after_working_minutes(int $from, int $minutes): int
{
    $low = $from;
    $high = $from + 21 * 86400;
    while ($high - $low > 60) {
        $middle = intdiv($low + $high, 2);
        if (admin_working_minutes($from, $middle) >= $minutes) {
            $high = $middle;
        } else {
            $low = $middle;
        }
    }
    return $high;
}

/** What each answer target would do with the requests waiting now, in plain words. */
function admin_reminder_preview(mysqli $conn, DateTimeImmutable $now): array
{
    $waiting = admin_waiting_requests($conn, $now);
    $names = appointment_office_map();
    $preview = [];
    foreach (ADMIN_REMINDER_TARGETS as $target) {
        $lines = [];
        foreach ($waiting as $code => $requests) {
            $times = [];
            foreach ($requests as $request) {
                $times[] = admin_after_working_minutes(strtotime((string) $request["created_at"]), $target);
                $times[] = strtotime((string) $request["scheduled_end_at"]) - ADMIN_EXPIRY_WARNING_MINUTES * 60;
            }
            $first = min($times);
            $name = $names[$code] ?? $code;
            $lines[] = ($first <= $now->getTimestamp() ? $name . " would already have been reminded" : $name . " would be reminded " . admin_when(date("Y-m-d H:i:s", $first), $now))
                . " about its " . admin_plural(count($requests), "waiting request", "waiting requests") . ".";
        }
        $preview[(string) $target] = $lines ?: ["No requests are waiting right now, so no office would be reminded."];
    }
    return $preview;
}

if (strtoupper((string) $_SERVER["REQUEST_METHOD"]) === "GET") {
    $now = new DateTimeImmutable("now");
    $me = $conn->prepare("SELECT email FROM app_users WHERE id = ? LIMIT 1");
    $me->bind_param("i", $adminId);
    $me->execute();
    $email = (string) ($me->get_result()->fetch_row()[0] ?? "");
    $me->close();
    auth_json([
        "success" => true,
        "reminders" => admin_reminder_settings($conn),
        "reminder_targets" => ADMIN_REMINDER_TARGETS,
        "reminder_preview" => admin_reminder_preview($conn, $now),
        "digest" => admin_digest_settings($conn, $adminId),
        "digest_hours" => ADMIN_DIGEST_HOURS,
        "digest_email" => $email,
        "digest_last_sent" => admin_setting_text($conn, "admin_digest_sent_" . $adminId),
        "mail_ready" => mail_configuration_problem() === null,
    ]);
}

$input = auth_json_body();
$kind = (string) ($input["kind"] ?? "");
$flag = fn (string $name): bool => !empty($input[$name]);

if ($kind === "reminders") {
    $target = (int) ($input["target_minutes"] ?? 0);
    if (!in_array($target, ADMIN_REMINDER_TARGETS, true)) {
        auth_json_error(422, "invalid_target", "Choose how soon offices should answer.");
    }
    $settings = [
        "on" => $flag("on"),
        "target_minutes" => $target,
        "at_target" => $flag("at_target"),
        "before_expiry" => $flag("before_expiry"),
        "tell_admin" => $flag("tell_admin"),
    ];
    if ($settings["on"] && !$settings["at_target"] && !$settings["before_expiry"]) {
        auth_json_error(422, "nothing_chosen", "Choose at least one reminder, or turn automatic reminders off.");
    }
    admin_setting_save($conn, "admin_reminders", $settings);
    auth_audit($conn, $adminId, "admin.reminders_changed", "setting", "admin_reminders", $settings);
    auth_json([
        "success" => true,
        "reminders" => $settings,
        "message" => $settings["on"] ? "Automatic reminders are on." : "Automatic reminders are off.",
    ]);
}

if ($kind === "digest") {
    $hour = (int) ($input["hour"] ?? 0);
    if (!in_array($hour, ADMIN_DIGEST_HOURS, true)) {
        auth_json_error(422, "invalid_hour", "Choose when the email comes.");
    }
    $settings = [
        "on" => $flag("on"),
        "hour" => $hour,
        "attention" => $flag("attention"),
        "numbers" => $flag("numbers"),
        "slowest" => $flag("slowest"),
        "always" => $flag("always"),
    ];
    if ($settings["on"]) {
        $me = $conn->prepare("SELECT email FROM app_users WHERE id = ? LIMIT 1");
        $me->bind_param("i", $adminId);
        $me->execute();
        $email = (string) ($me->get_result()->fetch_row()[0] ?? "");
        $me->close();
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            auth_json_error(422, "no_email", "Your account has no email address. Add it in People (your own page, Edit details), then turn this on.");
        }
        if (!$settings["attention"] && !$settings["numbers"] && !$settings["slowest"]) {
            auth_json_error(422, "nothing_chosen", "Choose at least one thing to include, or turn the email off.");
        }
    }
    admin_setting_save($conn, "admin_digest_" . $adminId, $settings);
    auth_audit($conn, $adminId, "admin.digest_changed", "setting", "admin_digest", $settings);
    $label = date("g:i A", mktime($hour, 0));
    auth_json([
        "success" => true,
        "digest" => $settings,
        "message" => $settings["on"] ? "Saved. The summary comes on weekdays at " . $label . "." : "The morning summary email is off.",
    ]);
}

auth_json_error(400, "invalid_kind", "Unknown setting.");
