<?php
declare(strict_types=1);

if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}

/*
 * The morning summary email (Admin dashboard, Menu > Morning summary email; admin_settings.php).
 * On weekdays, once the hour an administrator chose has come (and before noon), each administrator
 * who turned it on gets one email: what needs them, the last working day's numbers, and the office
 * slowest to answer. It never includes visitors' contact details or where they went. It is skipped
 * on days nothing needs them, unless they asked for it every day.
 *
 *   php workers/send_admin_digest.php   (run_scheduled_jobs.php runs it every minute)
 *   php workers/send_admin_digest.php --now="2026-11-23 07:05"   as if it were that time (to check it)
 */

require_once dirname(__DIR__) . "/db.php";
require_once dirname(__DIR__) . "/auth_security.php";
require_once dirname(__DIR__) . "/admin_service.php";
require_once dirname(__DIR__) . "/mail_service.php";

date_default_timezone_set("Asia/Manila");
$nowArgument = "";
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, "--now=")) {
        $nowArgument = substr($argument, 6);
    }
}
$now = new DateTimeImmutable($nowArgument !== "" ? $nowArgument : "now");
$hour = (int) $now->format("G");
if ((int) $now->format("N") >= 6 || $hour >= 12) {
    echo "No summaries are due now.\n";
    exit(0);
}

$admins = $conn->query(
    "SELECT id, username, email, first_name, last_name, display_name FROM app_users
     WHERE role = 'admin' AND is_active = 1 AND email IS NOT NULL AND email <> ''"
)->fetch_all(MYSQLI_ASSOC);
$due = [];
foreach ($admins as $admin) {
    $settings = admin_digest_settings($conn, (int) $admin["id"]);
    if ($settings["on"] && $hour >= $settings["hour"]
        && admin_setting_text($conn, "admin_digest_sent_" . $admin["id"]) !== $now->format("Y-m-d")
        && filter_var((string) $admin["email"], FILTER_VALIDATE_EMAIL)) {
        $due[] = [$admin, $settings];
    }
}
if (!$due) {
    echo "No summaries are due now.\n";
    exit(0);
}

// The same for every administrator: what needs attention, the last working day, the slowest office.
$staff = admin_staff($conn);
$offices = admin_offices($conn, $now, $staff);
$attention = array_map(fn (array $item): array => ["title" => $item["title"], "text" => $item["text"]], admin_attention($conn, $offices, $staff, $now));

$day = $now->modify("-1 day");
while ((int) $day->format("N") >= 6) {
    $day = $day->modify("-1 day");
}
$dayStart = $day->setTime(0, 0)->format("Y-m-d H:i:s");
$dayEnd = $day->setTime(23, 59, 59)->format("Y-m-d H:i:s");
$stmt = $conn->prepare(
    "SELECT SUM(checked_in_at BETWEEN ? AND ?) AS visits,
            SUM(status = 'window_closed' AND window_closed_at BETWEEN ? AND ?) AS no_shows,
            SUM(status = 'unanswered' AND unanswered_at BETWEEN ? AND ?) AS expired,
            SUM((approved_by_user_id IS NOT NULL AND approved_at BETWEEN ? AND ?) OR (rejected_by_user_id IS NOT NULL AND rejected_at BETWEEN ? AND ?)) AS answered,
            AVG(CASE WHEN checked_in_at BETWEEN ? AND ? AND completed_at > checked_in_at AND TIMESTAMPDIFF(MINUTE, checked_in_at, completed_at) <= 600
                THEN TIMESTAMPDIFF(MINUTE, checked_in_at, completed_at) END) AS average_minutes
     FROM appointments"
);
$stmt->bind_param("ssssssssssss", $dayStart, $dayEnd, $dayStart, $dayEnd, $dayStart, $dayEnd, $dayStart, $dayEnd, $dayStart, $dayEnd, $dayStart, $dayEnd);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();
$answered = (int) $row["answered"];
$expired = (int) $row["expired"];
$numbers = [
    "day_label" => "last working day (" . $day->format("l, M j") . ")",
    "visits" => (string) (int) $row["visits"],
    "answered" => $answered + $expired > 0 ? $answered . " of " . ($answered + $expired) : "—",
    "no_shows" => (string) (int) $row["no_shows"],
    "average" => $row["average_minutes"] !== null ? admin_duration_label((int) round((float) $row["average_minutes"])) : "—",
];
$timed = array_values(array_filter($offices, fn (array $office): bool => $office["is_active"] && $office["answer_minutes"] !== null && $office["answered"] >= 2));
usort($timed, fn (array $a, array $b): int => $b["answer_minutes"] <=> $a["answer_minutes"]);
$slowest = count($timed) >= 2 ? ["name" => $timed[0]["name"], "label" => $timed[0]["answer_label"]] : null;
$base = mail_site_base_url();

$queued = 0;
$skipped = 0;
foreach ($due as [$admin, $settings]) {
    $userId = (int) $admin["id"];
    // Marked first, so a failing email is not queued again every minute (the email queue retries it).
    admin_setting_save($conn, "admin_digest_sent_" . $userId, $now->format("Y-m-d"));
    if (!$attention && !$settings["always"]) {
        $skipped++;
        continue;
    }
    $count = count($attention);
    $subject = "ISATU Visitor Management: " . ($settings["attention"] && $count > 0
        ? ($count === 1 ? "1 thing needs you today" : $count . " things need you today")
        : "your morning summary");
    $payload = [
        "first_name" => trim((string) $admin["first_name"]) ?: personnel_full_name($admin),
        "date_label" => $now->format("l, F j"),
        "attention" => $settings["attention"] ? $attention : null,
        "numbers" => $settings["numbers"] ? $numbers : null,
        "slowest" => $settings["slowest"] ? $slowest : null,
        "dashboard_url" => $base !== "" ? $base . "/admin.html" : "",
        "settings_note" => "You get this on weekdays at " . date("g:i A", mktime($settings["hour"], 0)) . " because you turned it on in the dashboard (Menu, Morning summary email).",
    ];
    $email = strtolower(trim((string) $admin["email"]));
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $stmt = $conn->prepare(
        "INSERT INTO outbound_emails (user_id, recipient_email, template_key, subject, payload_json) VALUES (?, ?, 'admin_digest', ?, ?)"
    );
    $stmt->bind_param("isss", $userId, $email, $subject, $json);
    $stmt->execute();
    $emailId = (int) $stmt->insert_id;
    $stmt->close();
    try {
        mail_send_now($conn, $emailId);
    } catch (Throwable $error) {
        // The email job sends it on its next pass.
    }
    $queued++;
}
echo ($queued > 0 ? $queued . " sent morning summary email(s)" : "No summaries needed sending") . ($skipped ? "; " . $skipped . " skipped (nothing needs them)" : "") . ".\n";
