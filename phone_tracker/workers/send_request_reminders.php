<?php
declare(strict_types=1);

if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}

/*
 * Automatic request reminders (Admin dashboard, Menu > Request reminders; admin_service.php).
 * When the administrator turned them on, each office's staff get one notification when a request
 * has waited longer than the answer target (counted in working time), and another 1 hour before a
 * request expires. One notification per office and stage covers every request it applies to, and
 * each is written to the activity log as an automatic action. Nothing happens while they are off.
 *
 *   php workers/send_request_reminders.php   (run_scheduled_jobs.php runs it every minute)
 */

require_once dirname(__DIR__) . "/db.php";
require_once dirname(__DIR__) . "/auth_security.php";
require_once dirname(__DIR__) . "/admin_service.php";

date_default_timezone_set("Asia/Manila");
$settings = admin_reminder_settings($conn);
if (!$settings["on"]) {
    echo "Request reminders are off.\n";
    exit(0);
}

$now = new DateTimeImmutable("now");
$reminded = admin_reminded_requests($conn);
$sent = 0;
foreach (admin_waiting_requests($conn, $now) as $code => $requests) {
    $due = ["target" => [], "expiry" => []];
    foreach ($requests as $request) {
        $id = (int) $request["id"];
        $expiresIn = (strtotime((string) $request["scheduled_end_at"]) - $now->getTimestamp()) / 60;
        if ($settings["before_expiry"] && $expiresIn <= ADMIN_EXPIRY_WARNING_MINUTES && !isset($reminded[$id]["expiry"])) {
            $due["expiry"][] = $request;
            continue;
        }
        if ($settings["at_target"] && !isset($reminded[$id]["target"]) && !isset($reminded[$id]["expiry"])
            && admin_working_minutes(strtotime((string) $request["created_at"]), $now->getTimestamp()) >= $settings["target_minutes"]) {
            $due["target"][] = $request;
        }
    }
    if (!$due["target"] && !$due["expiry"]) {
        continue;
    }
    // One notification per office, even when both reminders fall due in the same minute.
    $stage = $due["expiry"] ? "expiry" : "target";
    $expiryIds = array_map("intval", array_column($due["expiry"], "id"));
    $targetIds = array_map("intval", array_column($due["target"], "id"));
    $title = $stage === "expiry" ? "A request expires soon" : "Requests are waiting for your answer";
    $people = admin_notify_office_staff($conn, (string) $code, "office.request_reminder", $title, admin_reminder_message($requests, $now), [
        "by" => "system",
        "stage" => $stage,
        "appointment_ids" => array_merge($expiryIds, $targetIds),
    ]);
    // Recorded even when the office has no active staff, so it is not retried every minute.
    auth_audit($conn, null, "appointment.office_reminded", "office", (string) $code, [
        "office_code" => (string) $code,
        "stage" => $stage,
        "appointment_ids" => $stage === "expiry" ? $expiryIds : $targetIds,
        "target_ids" => $stage === "expiry" ? $targetIds : [],
        "requests" => count($requests),
        "staff" => $people,
        "target_minutes" => $settings["target_minutes"],
    ]);
    $sent += $people;
}
echo $sent > 0 ? $sent . " sent reminder notification(s).\n" : "No reminders were due.\n";
