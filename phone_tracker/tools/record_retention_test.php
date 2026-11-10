<?php
/**
 * Checks the 1-year cleanup the Terms and Privacy Notice promises (record_retention_service.php)
 * on the database, with throwaway people, visits and records dated in the past, which it removes
 * again:
 *
 *   php phone_tracker/tools/record_retention_test.php
 *
 * It deletes whatever is a year old, so it refuses to run where real records already are.
 * Exits with 1 and lists what failed when a check does not hold.
 */
declare(strict_types=1);

// It writes to the database: never from a browser (tools/.htaccess also blocks the folder).
if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . "/db.php";
require_once dirname(__DIR__) . "/record_retention_service.php";

$checks = 0;
$failures = 0;

function check(bool $holds, string $what): void
{
    global $checks, $failures;
    $checks++;
    if (!$holds) {
        $failures++;
        fwrite(STDERR, "FAIL: {$what}\n");
    }
}

function same(mixed $actual, mixed $expected, string $what): void
{
    check($actual === $expected, $what . "\n      expected " . var_export($expected, true) . "\n      got      " . var_export($actual, true));
}

const ZZ_OFFICE = "ZZRETAIN";

/** A time $days ago (a negative number is that many days ahead). */
function zz_ago(float $days): string
{
    return date("Y-m-d H:i:s", (int) (time() - $days * 86400));
}

function zz_insert(mysqli $conn, string $table, array $row): int
{
    $columns = array_keys($row);
    $stmt = $conn->prepare("INSERT INTO {$table} (" . implode(", ", $columns) . ") VALUES (" . implode(", ", array_fill(0, count($columns), "?")) . ")");
    $types = implode("", array_map(fn($value) => is_int($value) ? "i" : "s", $row));
    $values = array_values($row);
    $stmt->bind_param($types, ...$values);
    $stmt->execute();
    $id = (int) $conn->insert_id;
    $stmt->close();
    return $id;
}

function zz_has(mysqli $conn, string $table, int $id): bool
{
    return (int) $conn->query("SELECT COUNT(*) FROM {$table} WHERE id = {$id}")->fetch_row()[0] === 1;
}

/** Someone who signed up $createdDays ago and last signed in $loginDays ago (null: never). */
function zz_person(mysqli $conn, string $username, string $role, float $createdDays, ?float $loginDays): int
{
    return zz_insert($conn, "app_users", [
        "username" => $username, "email" => $username . "@example.com", "password_hash" => "not-a-password",
        "display_name" => "Zzretain " . $username, "role" => $role, "office_code" => $role === "offices" ? ZZ_OFFICE : "",
        "is_active" => 1, "created_at" => zz_ago($createdDays), "last_login_at" => $loginDays === null ? null : zz_ago($loginDays),
    ]);
}

/** A visit that starts $startDays ago (negative: still to come) and lasts 30 minutes. */
function zz_appointment(mysqli $conn, int $visitor, float $startDays, string $status, ?int $group = null): int
{
    return zz_insert($conn, "appointments", [
        "public_token" => bin2hex(random_bytes(32)), "visitor_user_id" => $visitor, "office_code" => ZZ_OFFICE,
        "visitor_full_name" => "Zzretain Visitor", "visitor_email" => "zzretain.visitor@example.com", "device_name" => "Check phone",
        "appointment_at" => zz_ago($startDays), "scheduled_start_at" => zz_ago($startDays),
        "scheduled_end_at" => zz_ago($startDays - 1 / 48), "status" => $status, "purpose" => "Retention check",
        "visit_id" => $group, "created_at" => zz_ago(max($startDays, 0) + 2),
    ]);
}

function zz_group(mysqli $conn, int $visitor, float $days): int
{
    return zz_insert($conn, "visits", [
        "visit_code" => "ZZRETAIN" . strtoupper(bin2hex(random_bytes(4))), "public_token" => bin2hex(random_bytes(32)),
        "visitor_user_id" => $visitor, "visit_date" => substr(zz_ago($days), 0, 10), "status" => "completed",
        "created_at" => zz_ago($days),
    ]);
}

function zz_activity(mysqli $conn, ?int $actor, string $action, float $days, ?int $appointment = null): int
{
    return zz_insert($conn, "audit_logs", [
        "actor_user_id" => $actor, "appointment_id" => $appointment, "action" => $action,
        "entity_type" => "zzretain", "entity_id" => "check", "created_at" => zz_ago($days),
    ]);
}

// It deletes everything a year old: only where nothing real is, so all it can delete is its own.
$due = retention_run($conn, false);
if (array_sum($due) > 0) {
    echo "Skipped: this database already has records over a year old (" . retention_summary($due) . "), "
        . "which the check would delete with its own. Run it on a copy.\n";
    exit(0);
}

$activities = [];
$notifications = [];
try {
    zz_insert($conn, "offices", ["code" => ZZ_OFFICE, "kind" => "office", "name" => "Zzretain Office", "is_active" => 1]);

    /* ---------- Who ---------- */
    $gone = zz_person($conn, "zzretain_gone", "visitor", 800, 700);
    zz_insert($conn, "api_access_tokens", ["user_id" => $gone, "token_hash" => hash("sha256", random_bytes(16)),
        "expires_at" => zz_ago(670), "last_used_at" => zz_ago(700), "created_at" => zz_ago(700)]);
    // Signed in long ago, but used the app last week: kept.
    $active = zz_person($conn, "zzretain_active", "student", 800, 700);
    zz_insert($conn, "api_access_tokens", ["user_id" => $active, "token_hash" => hash("sha256", random_bytes(16)),
        "expires_at" => zz_ago(-20), "last_used_at" => zz_ago(7), "created_at" => zz_ago(10)]);
    // Never signs in, but has a visit next week: kept.
    $planned = zz_person($conn, "zzretain_planned", "visitor", 800, null);
    $fresh = zz_person($conn, "zzretain_fresh", "visitor", 30, null);
    // Staff are never removed by this, however long ago they signed in.
    $staff = zz_person($conn, "zzretain_staff", "offices", 800, 700);

    /* ---------- Visits ---------- */
    $oldGroup = zz_group($conn, $active, 500);
    $stuckGroup = zz_group($conn, $active, 400);
    $goneVisit = zz_appointment($conn, $gone, 400, "completed");
    $oldVisit = zz_appointment($conn, $active, 500, "completed", $oldGroup);
    $recentVisit = zz_appointment($conn, $active, 20, "completed");
    $plannedVisit = zz_appointment($conn, $planned, -7, "approved");
    // Still marked inside the campus, however old: never deleted from under the guards.
    $stuckVisit = zz_appointment($conn, $active, 400, "checked_in", $stuckGroup);
    $notifications[] = $visitNotice = zz_insert($conn, "app_notifications", ["recipient_user_id" => $staff, "appointment_id" => $goneVisit,
        "notification_type" => "zzretain", "title" => "Old visit", "created_at" => zz_ago(400)]);
    $notifications[] = $recentNotice = zz_insert($conn, "app_notifications", ["recipient_user_id" => $active, "appointment_id" => $recentVisit,
        "notification_type" => "zzretain", "title" => "Recent visit", "created_at" => zz_ago(20)]);
    $notifications[] = $oldNotice = zz_insert($conn, "app_notifications", ["recipient_user_id" => $staff,
        "notification_type" => "zzretain", "title" => "Old notice", "created_at" => zz_ago(400)]);

    /* ---------- Activity records ---------- */
    $activities[] = $aboutOldVisit = zz_activity($conn, $staff, "appointment.approved", 400, $goneVisit);
    $activities[] = $staffOld = zz_activity($conn, $staff, "office.updated", 400);
    $activities[] = $activeOld = zz_activity($conn, $active, "mobile.profile_updated", 400);
    $activities[] = $activeNew = zz_activity($conn, $active, "mobile.profile_updated", 10);
    $activities[] = $nobodyOld = zz_activity($conn, null, "auth.sign_in_failed", 400);
    $activities[] = $goneOwn = zz_activity($conn, $gone, "mobile.appointment_created", 700);

    /* ---------- Emails and sign-up requests ---------- */
    $oldEmail = zz_insert($conn, "outbound_emails", ["user_id" => $staff, "recipient_email" => "zzretain.staff@example.com",
        "template_key" => "zzretain", "subject" => "Old", "status" => "sent", "created_at" => zz_ago(400)]);
    $newEmail = zz_insert($conn, "outbound_emails", ["user_id" => $active, "recipient_email" => "zzretain.active@example.com",
        "template_key" => "zzretain", "subject" => "New", "status" => "sent", "created_at" => zz_ago(5)]);
    // Younger than a year, but to someone whose account goes: goes with it.
    $goneEmail = zz_insert($conn, "outbound_emails", ["user_id" => $gone, "recipient_email" => "zzretain.gone@example.com",
        "template_key" => "zzretain", "subject" => "To gone", "status" => "sent", "created_at" => zz_ago(300)]);
    $oldRequest = zz_insert($conn, "account_requests", ["account_type" => "visitor", "email" => "zzretain.old@example.com",
        "password_hash" => "", "full_name" => "Zzretain Old", "created_at" => zz_ago(400)]);
    $goneRequest = zz_insert($conn, "account_requests", ["account_type" => "visitor", "email" => "zzretain.gone@example.com",
        "password_hash" => "", "full_name" => "Zzretain Gone", "status" => "approved", "user_id" => $gone, "created_at" => zz_ago(200)]);
    $teacherRequest = zz_insert($conn, "account_requests", ["account_type" => "teacher", "email" => "zzretain.teacher@example.com",
        "password_hash" => "", "full_name" => "Zzretain Teacher", "created_at" => zz_ago(400)]);
    $newRequest = zz_insert($conn, "account_requests", ["account_type" => "student", "email" => "zzretain.new@example.com",
        "password_hash" => "", "full_name" => "Zzretain New", "created_at" => zz_ago(10)]);

    /* ---------- The cleanup ---------- */
    same(retention_run($conn, false), [
        "visits" => 2, "multi-stop visits" => 0, "accounts" => 1, "activity records" => 4,
        "notifications" => 2, "emails" => 1, "sign-up requests" => 1,
    ], "a dry run counts what is due now, and deletes nothing");
    check(zz_has($conn, "appointments", $goneVisit), "a dry run keeps everything");

    same(retention_run($conn, true), [
        "visits" => 2, "multi-stop visits" => 1, "accounts" => 1, "activity records" => 2,
        "notifications" => 1, "emails" => 1, "sign-up requests" => 1,
    ], "the cleanup removes each thing once, under the first rule that reaches it");

    check(!zz_has($conn, "appointments", $goneVisit) && !zz_has($conn, "appointments", $oldVisit), "a visit goes 1 year after it ended");
    check(zz_has($conn, "appointments", $recentVisit) && zz_has($conn, "appointments", $plannedVisit), "recent and coming visits stay");
    check(zz_has($conn, "appointments", $stuckVisit), "a visit still marked inside the campus stays");
    check(!zz_has($conn, "app_notifications", $visitNotice), "a visit's notifications go with it");
    check(!zz_has($conn, "audit_logs", $aboutOldVisit), "the activity records about a visit go with it, not just unlinked");
    check(!zz_has($conn, "visits", $oldGroup) && zz_has($conn, "visits", $stuckGroup), "a multi-stop visit goes once none of its stops is left");

    check(!zz_has($conn, "app_users", $gone), "an account unused for a year goes");
    check(!zz_has($conn, "audit_logs", $goneOwn) && !zz_has($conn, "outbound_emails", $goneEmail) && !zz_has($conn, "account_requests", $goneRequest),
        "with its own activity, its emails and its sign-up, even the ones younger than a year");
    check(zz_has($conn, "app_users", $active), "using the app counts as use, even without signing in again");
    check(zz_has($conn, "app_users", $planned), "a visit still to come counts as use");
    check(zz_has($conn, "app_users", $fresh), "a new account stays");
    check(zz_has($conn, "app_users", $staff), "staff accounts are never removed by this");

    check(!zz_has($conn, "audit_logs", $activeOld) && !zz_has($conn, "audit_logs", $nobodyOld), "a visitor's or student's activity goes after a year, and so does activity with no one behind it");
    check(zz_has($conn, "audit_logs", $activeNew), "recent activity stays");
    check(zz_has($conn, "audit_logs", $staffOld), "what staff did stays when it isn't about a visit");
    check(!zz_has($conn, "app_notifications", $oldNotice) && zz_has($conn, "app_notifications", $recentNotice), "notifications go after a year");
    check(!zz_has($conn, "outbound_emails", $oldEmail) && zz_has($conn, "outbound_emails", $newEmail), "emails go after a year");
    check(!zz_has($conn, "account_requests", $oldRequest) && zz_has($conn, "account_requests", $newRequest), "visitors' and students' sign-up requests go after a year");
    check(zz_has($conn, "account_requests", $teacherRequest), "a teacher's account request stays");

    same(array_sum(retention_run($conn, false)), 0, "afterwards nothing more is due");
} finally {
    if ($activities) {
        $conn->query("DELETE FROM audit_logs WHERE id IN (" . implode(",", $activities) . ")");
    }
    if ($notifications) {
        $conn->query("DELETE FROM app_notifications WHERE id IN (" . implode(",", $notifications) . ")");
    }
    foreach ([
        "DELETE FROM appointments WHERE office_code = '" . ZZ_OFFICE . "'",
        "DELETE FROM visits WHERE visit_code LIKE 'ZZRETAIN%'",
        "DELETE FROM outbound_emails WHERE recipient_email LIKE 'zzretain%'",
        "DELETE FROM account_requests WHERE email LIKE 'zzretain%'",
        "DELETE FROM app_users WHERE username LIKE 'zzretain\\_%'",
        "DELETE FROM offices WHERE code = '" . ZZ_OFFICE . "'",
    ] as $sql) {
        $conn->query($sql);
    }
}

if ($failures > 0) {
    fwrite(STDERR, "{$failures} of {$checks} checks failed.\n");
    exit(1);
}
echo "All {$checks} checks passed.\n";
