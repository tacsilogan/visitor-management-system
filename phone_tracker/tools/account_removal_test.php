<?php
/**
 * Checks removing accounts that have history (AUTH_SECURITY.md, "Removing an account") on the
 * database, with throwaway people and visits that it removes again:
 *
 *   php phone_tracker/tools/account_removal_test.php
 *
 * Exits with 1 and lists what failed when a check does not hold.
 */
declare(strict_types=1);

// It writes to the database: never from a browser (tools/.htaccess also blocks the folder).
if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . "/db.php";
require_once dirname(__DIR__) . "/account_removal_service.php";
require_once dirname(__DIR__) . "/appointment_maintenance.php";

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

const ZZ_OFFICE = "ZZREMOVE";
const ZZ_PURPOSE = "Account removal check";

function zz_at(int $minutes): string
{
    return date("Y-m-d H:i:s", time() + $minutes * 60);
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

function zz_value(mysqli $conn, string $sql): mixed
{
    $row = $conn->query($sql)->fetch_row();
    return $row ? $row[0] : null;
}

/** @var list<int> everyone the check made, for the cleanup */
$people = [];

function zz_person(mysqli $conn, string $username, string $role, string $name, string $office = ""): int
{
    global $people;
    $id = zz_insert($conn, "app_users", [
        "username" => $username, "email" => $username . "@example.com", "password_hash" => "not-a-password",
        "display_name" => $name, "role" => $role, "office_code" => $office, "is_active" => 1,
    ]);
    $people[] = $id;
    return $id;
}

function zz_visit(mysqli $conn, ?int $visitor, string $status, array $extra = []): int
{
    return zz_insert($conn, "visits", array_merge([
        "visit_code" => "ZZREMOVE" . strtoupper(bin2hex(random_bytes(4))), "public_token" => bin2hex(random_bytes(32)),
        "visitor_user_id" => $visitor, "visit_date" => date("Y-m-d"), "status" => $status,
    ], $extra));
}

function zz_appointment(mysqli $conn, ?int $visitor, string $office, string $name, string $status, int $startMinutes, array $extra = []): int
{
    return zz_insert($conn, "appointments", array_merge([
        "public_token" => bin2hex(random_bytes(32)), "visitor_user_id" => $visitor, "office_code" => $office,
        "visitor_full_name" => $name, "visitor_email" => "zzremove.visitor@example.com", "device_name" => "Check phone",
        "appointment_at" => zz_at($startMinutes), "scheduled_start_at" => zz_at($startMinutes),
        "scheduled_end_at" => zz_at($startMinutes + 30), "status" => $status, "purpose" => ZZ_PURPOSE,
    ], $extra));
}

function zz_forget(mysqli $conn, array $people): void
{
    $users = implode(",", array_map("intval", $people)) ?: "0";
    $userTexts = "'" . implode("','", array_map("intval", $people ?: [0])) . "'";
    $offices = ["'" . ZZ_OFFICE . "'"];
    foreach ($conn->query("SELECT code FROM offices WHERE name LIKE '%Zzremove%'") as $row) {
        $offices[] = "'" . $conn->real_escape_string((string) $row["code"]) . "'";
    }
    $officeList = implode(",", $offices);
    $ids = [];
    foreach ($conn->query("SELECT id FROM appointments WHERE office_code IN ({$officeList}) OR purpose = '" . ZZ_PURPOSE . "'") as $row) {
        $ids[] = (int) $row["id"];
    }
    $appointments = implode(",", $ids) ?: "0";
    foreach ([
        "DELETE FROM locations WHERE appointment_id IN ({$appointments})",
        "DELETE FROM location_tracking_sessions WHERE appointment_id IN ({$appointments})",
        "DELETE FROM visitor_consents WHERE appointment_id IN ({$appointments})",
        "DELETE FROM appointment_status_history WHERE appointment_id IN ({$appointments})",
        "DELETE FROM app_notifications WHERE appointment_id IN ({$appointments}) OR recipient_user_id IN ({$users})",
        "DELETE FROM audit_logs WHERE appointment_id IN ({$appointments}) OR actor_user_id IN ({$users})
            OR (entity_type = 'app_user' AND entity_id IN ({$userTexts})) OR details_json LIKE '%zzremove%'",
        "DELETE FROM appointment_reschedule_proposals WHERE appointment_id IN ({$appointments})",
        "DELETE FROM appointment_qr_overrides WHERE appointment_id IN ({$appointments})",
        "UPDATE visits SET tracking_appointment_id = NULL WHERE visit_code LIKE 'ZZREMOVE%'",
        "DELETE FROM appointments WHERE id IN ({$appointments})",
        "DELETE FROM visits WHERE visit_code LIKE 'ZZREMOVE%'",
        "DELETE FROM outbound_emails WHERE recipient_email LIKE 'zzremove%'",
        "DELETE FROM office_availability_rules WHERE office_code IN ({$officeList})",
        "DELETE FROM office_availability_settings WHERE office_code IN ({$officeList})",
        "DELETE FROM app_users WHERE id IN ({$users}) OR username LIKE 'zzremove%'",
        "DELETE FROM offices WHERE code IN ({$officeList})",
    ] as $sql) {
        $conn->query($sql);
    }
}

zz_forget($conn, []);
try {
    $restrict = (int) zz_value($conn,
        "SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = 'app_users' AND DELETE_RULE NOT IN ('CASCADE', 'SET NULL')");
    same($restrict, 0, "no record keeps an account from being removed");

    /* ---------- Who and what ---------- */
    zz_insert($conn, "offices", ["code" => ZZ_OFFICE, "name" => "Zzremove Office", "is_active" => 1]);
    $staff = zz_person($conn, "zzremove_staff", "offices", "Zzremove Staff", ZZ_OFFICE);
    $guard = zz_person($conn, "zzremove_guard", "security", "Zzremove Guard");
    $admin = zz_person($conn, "zzremove_admin", "admin", "Zzremove Admin");

    // A visitor with a finished visit, three still to come, a phone, a sign-in, a notice and emails.
    $visitor = zz_person($conn, "zzremove_visitor", "visitor", "Zzremove Visitor");
    $done = zz_visit($conn, $visitor, "completed", ["checked_in_by_user_id" => $guard]);
    $past = zz_appointment($conn, $visitor, ZZ_OFFICE, "Zzremove Visitor", "completed", -2880, ["visit_id" => $done, "approved_by_user_id" => $staff]);
    $override = zz_insert($conn, "appointment_qr_overrides", ["appointment_id" => $past, "authorized_by_user_id" => $guard, "reason" => "Late bus",
        "original_valid_from" => zz_at(-2890), "original_valid_until" => zz_at(-2850), "override_valid_until" => zz_at(-2800)]);
    $open = zz_visit($conn, $visitor, "open");
    $approved = zz_appointment($conn, $visitor, ZZ_OFFICE, "Zzremove Visitor", "approved", 120, ["visit_id" => $open]);
    $pending = zz_appointment($conn, $visitor, ZZ_OFFICE, "Zzremove Visitor", "pending_approval", 180, ["visit_id" => $open]);
    $proposed = zz_appointment($conn, $visitor, ZZ_OFFICE, "Zzremove Visitor", "reschedule_proposed", 240, ["visit_id" => $open]);
    $proposal = zz_insert($conn, "appointment_reschedule_proposals", ["appointment_id" => $proposed, "proposed_by_user_id" => $staff, "status" => "pending", "response_deadline" => zz_at(60)]);
    $consent = zz_insert($conn, "visitor_consents", ["appointment_id" => $approved, "visitor_user_id" => $visitor, "consent_version" => "check"]);
    $session = zz_insert($conn, "location_tracking_sessions", ["appointment_id" => $approved, "visitor_user_id" => $visitor, "consent_id" => $consent]);
    $position = zz_insert($conn, "locations", ["device_name" => "Check phone", "latitude" => "10.7", "longitude" => "122.5",
        "visitor_user_id" => $visitor, "appointment_id" => $approved, "tracking_session_id" => $session]);
    zz_insert($conn, "mobile_devices", ["visitor_user_id" => $visitor, "installation_id" => "zzremove-phone", "fcm_token" => "zzremove", "fcm_token_hash" => hash("sha256", "zzremove")]);
    zz_insert($conn, "api_access_tokens", ["user_id" => $visitor, "token_hash" => hash("sha256", "zzremove-token"), "expires_at" => zz_at(600)]);
    zz_insert($conn, "app_notifications", ["recipient_user_id" => $visitor, "notification_type" => "zzremove.check", "title" => "Check"]);
    $waiting = zz_insert($conn, "outbound_emails", ["user_id" => $visitor, "recipient_email" => "zzremove_visitor@example.com", "template_key" => "check", "subject" => "Waiting", "status" => "queued"]);
    $sent = zz_insert($conn, "outbound_emails", ["user_id" => $visitor, "recipient_email" => "zzremove_visitor@example.com", "template_key" => "check", "subject" => "Sent", "status" => "sent"]);

    // A visitor inside the campus.
    $inside = zz_person($conn, "zzremove_inside", "visitor", "Zzremove Inside");
    $insideVisit = zz_visit($conn, $inside, "checked_in", ["checked_in_at" => zz_at(-30)]);
    zz_appointment($conn, $inside, ZZ_OFFICE, "Zzremove Inside", "checked_in", -40, ["visit_id" => $insideVisit, "checked_in_at" => zz_at(-30)]);

    /* ---------- A visitor with history ---------- */
    $result = account_remove($conn, $visitor, $admin);
    same($result["cancelled_visits"], 3, "a visitor with history is removed, cancelling the 3 visits still to come");
    same((int) zz_value($conn, "SELECT COUNT(*) FROM app_users WHERE id = {$visitor}"), 0, "the account is gone");
    same(zz_value($conn, "SELECT CONCAT(status, '/', visitor_full_name, '/', COALESCE(visitor_user_id, 'none'), '/', approved_by_user_id) FROM appointments WHERE id = {$past}"),
        "completed/Zzremove Visitor/none/{$staff}", "the finished visit stays, with the visitor's name");
    same((int) zz_value($conn, "SELECT COUNT(*) FROM appointments WHERE id IN ({$approved}, {$pending}, {$proposed}) AND status = 'cancelled' AND cancelled_by_user_id = {$admin}"),
        3, "each visit still to come is cancelled by the administrator");
    same(zz_value($conn, "SELECT status FROM appointment_reschedule_proposals WHERE id = {$proposal}"), "withdrawn", "an open suggested time is withdrawn");
    same(zz_value($conn, "SELECT status FROM visits WHERE id = {$open}"), "closed", "the open visit is closed, so its pass stops working");
    same(zz_value($conn, "SELECT status FROM visits WHERE id = {$done}"), "completed", "the finished visit keeps its gate record");
    same((int) zz_value($conn, "SELECT COUNT(*) FROM app_notifications WHERE recipient_user_id = {$staff} AND title = 'Visit cancelled'"), 3, "the office is told about each one");
    same((int) zz_value($conn, "SELECT COUNT(*) FROM mobile_devices WHERE installation_id = 'zzremove-phone'")
        + (int) zz_value($conn, "SELECT COUNT(*) FROM api_access_tokens WHERE token_hash = '" . hash("sha256", "zzremove-token") . "'")
        + (int) zz_value($conn, "SELECT COUNT(*) FROM app_notifications WHERE notification_type = 'zzremove.check'")
        + (int) zz_value($conn, "SELECT COUNT(*) FROM location_tracking_sessions WHERE id = {$session}"), 0, "the phone, sign-in, notices and location sharing go with it");
    same(zz_value($conn, "SELECT COALESCE(visitor_user_id, 'none') FROM visitor_consents WHERE id = {$consent}"), "none", "the consent record stays");
    same((int) zz_value($conn, "SELECT appointment_id FROM locations WHERE id = {$position}"), $approved, "positions stay with their visit until the 90-day cleanup");
    same((int) zz_value($conn, "SELECT COUNT(*) FROM outbound_emails WHERE id = {$waiting}"), 0, "an email still waiting is not sent");
    same(zz_value($conn, "SELECT status FROM outbound_emails WHERE id = {$sent}"), "sent", "a sent email stays in the log");
    same((int) zz_value($conn, "SELECT authorized_by_user_id FROM appointment_qr_overrides WHERE id = {$override}"), $guard, "the guard's pass override stays");
    $audit = json_decode((string) zz_value($conn, "SELECT details_json FROM audit_logs WHERE action = 'user.deleted' AND entity_id = '{$visitor}' ORDER BY id DESC LIMIT 1"), true);
    same([$audit["username"] ?? null, $audit["cancelled_visits"] ?? null], ["zzremove_visitor", 3], "the activity log records the removal");

    /* ---------- Who waits, and who can't be removed ---------- */
    $refused = "";
    try {
        account_remove($conn, $inside, $admin);
    } catch (AccountRemovalError $error) {
        $refused = $error->getMessage();
    }
    check(str_starts_with($refused, "Zzremove Inside is inside the campus now"), "a visitor inside the campus waits for the guard: " . $refused);
    same(zz_value($conn, "SELECT status FROM visits WHERE id = {$insideVisit}"), "checked_in", "and nothing changed");
    $refused = "";
    try {
        account_remove($conn, $admin, $admin);
    } catch (AccountRemovalError $error) {
        $refused = $error->getMessage();
    }
    same($refused, "You cannot remove your own account.", "nobody removes their own account");


    /* ---------- Staff ---------- */
    account_remove($conn, $staff, $admin);
    account_remove($conn, $guard, $admin);
    same(zz_value($conn, "SELECT CONCAT(COALESCE(approved_by_user_id, 'none'), '/', status) FROM appointments WHERE id = {$past}"), "none/completed", "what office staff did stays without the link");
    same(zz_value($conn, "SELECT CONCAT(COALESCE(authorized_by_user_id, 'none'), '/', reason) FROM appointment_qr_overrides WHERE id = {$override}"), "none/Late bus", "a removed guard's pass override stays");

    /* ---------- The time-based updates, with visits that have no account behind them ---------- */
    $orphanAsked = zz_appointment($conn, null, ZZ_OFFICE, "Zzremove Gone", "pending_approval", -90);
    $orphanProposed = zz_appointment($conn, null, ZZ_OFFICE, "Zzremove Gone", "reschedule_proposed", 600);
    zz_insert($conn, "appointment_reschedule_proposals", ["appointment_id" => $orphanProposed, "status" => "pending", "response_deadline" => zz_at(-5)]);
    $problem = null;
    try {
        refresh_appointment_time_states($conn);
    } catch (Throwable $error) {
        $problem = $error->getMessage();
    }
    same($problem, null, "the time-based updates don't trip over a removed visitor");
    same(zz_value($conn, "SELECT status FROM appointments WHERE id = {$orphanAsked}") . "/" . zz_value($conn, "SELECT status FROM appointments WHERE id = {$orphanProposed}"),
        "unanswered/cancelled", "and still close their requests");
} finally {
    zz_forget($conn, $people);
}

if ($failures > 0) {
    fwrite(STDERR, "{$failures} of {$checks} checks failed.\n");
    exit(1);
}
echo "All {$checks} checks passed.\n";
