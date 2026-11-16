<?php
/**
 * Checks a visitor's rights over their own account (AUTH_SECURITY.md, "Visitors' own accounts") and
 * that watching visitors is Security's job alone, on the database, with throwaway people and visits
 * that it removes again:
 *
 *   php phone_tracker/tools/visitor_rights_test.php
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
require_once dirname(__DIR__) . "/permissions.php";
require_once dirname(__DIR__) . "/account_removal_service.php";
require_once dirname(__DIR__) . "/signup_service.php";
require_once dirname(__DIR__) . "/visitor_rights_service.php";
require_once dirname(__DIR__) . "/activity_service.php";

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

const ZZ_OFFICE = "ZZRIGHTS";

function zz_insert(mysqli $conn, string $table, array $row): int
{
    $columns = array_keys($row);
    $stmt = $conn->prepare("INSERT INTO {$table} (" . implode(", ", $columns) . ") VALUES (" . implode(", ", array_fill(0, count($columns), "?")) . ")");
    $types = implode("", array_map(fn ($value) => is_int($value) ? "i" : "s", $row));
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

function zz_person(mysqli $conn, string $username, string $role, string $office = ""): int
{
    return zz_insert($conn, "app_users", [
        "username" => $username, "email" => $username . "@example.com", "password_hash" => password_hash("Zzrights-pass-1", PASSWORD_DEFAULT),
        "display_name" => "Zzrights " . ucfirst(substr($username, 9)), "role" => $role, "office_code" => $office, "is_active" => 1,
    ]);
}

function zz_appointment(mysqli $conn, ?int $visitor, string $status, int $startMinutes, array $extra = []): int
{
    $at = fn (int $minutes) => date("Y-m-d H:i:s", time() + $minutes * 60);
    return zz_insert($conn, "appointments", array_merge([
        "public_token" => bin2hex(random_bytes(32)), "visitor_user_id" => $visitor, "office_code" => ZZ_OFFICE,
        "visitor_full_name" => "Zzrights Visitor", "visitor_email" => "zzrights.visitor@example.com", "device_name" => "Check phone",
        "appointment_at" => $at($startMinutes), "scheduled_start_at" => $at($startMinutes), "scheduled_end_at" => $at($startMinutes + 30),
        "status" => $status, "purpose" => "Document Request",
    ], $extra));
}

function zz_forget(mysqli $conn): void
{
    $ids = [];
    foreach ($conn->query("SELECT id FROM appointments WHERE office_code = '" . ZZ_OFFICE . "'") as $row) {
        $ids[] = (int) $row["id"];
    }
    $users = [];
    foreach ($conn->query("SELECT id FROM app_users WHERE username LIKE 'zzrights.%' OR email LIKE 'zzrights.%'") as $row) {
        $users[] = (int) $row["id"];
    }
    $appointments = implode(",", $ids) ?: "0";
    $people = implode(",", $users) ?: "0";
    foreach ([
        "DELETE FROM appointment_status_history WHERE appointment_id IN ({$appointments})",
        "DELETE FROM app_notifications WHERE appointment_id IN ({$appointments}) OR recipient_user_id IN ({$people})",
        "DELETE FROM audit_logs WHERE appointment_id IN ({$appointments}) OR actor_user_id IN ({$people}) OR details_json LIKE '%zzrights%'",
        "DELETE FROM appointment_reschedule_proposals WHERE appointment_id IN ({$appointments})",
        "DELETE FROM appointments WHERE id IN ({$appointments})",
        "DELETE FROM account_requests WHERE email LIKE 'zzrights.%'",
        "DELETE FROM outbound_emails WHERE recipient_email LIKE 'zzrights.%'",
        "DELETE FROM app_users WHERE id IN ({$people})",
        "DELETE FROM office_availability_settings WHERE office_code = '" . ZZ_OFFICE . "'",
        "DELETE FROM offices WHERE code = '" . ZZ_OFFICE . "'",
    ] as $sql) {
        try {
            $conn->query($sql);
        } catch (mysqli_sql_exception $ignored) {
            // A table from a later migration that this database doesn't have.
        }
    }
}

zz_forget($conn);
try {
    if (!visitor_rights_schema_ready($conn)) {
        fwrite(STDERR, "FAIL: visitor_rights_migration.sql is not installed\n");
        exit(1);
    }

    // Watching visitors is Security's job alone.
    foreach (["visits.scan", "visits.complete", "visits.qr_override", "visits.monitor", "locations.view_live", "routes.view"] as $permission) {
        same(auth_role_can("admin", $permission), false, "the Admin no longer has {$permission}");
        same(auth_role_can("security", $permission), true, "Security still has {$permission}");
    }
    same(auth_role_can("admin", "analytics.view"), true, "the Admin keeps the reports");
    same(auth_role_can("admin", "activity.view"), true, "the Admin keeps the activity log");

    zz_insert($conn, "offices", ["code" => ZZ_OFFICE, "name" => "Zzrights Check Office", "is_active" => 1, "sort_order" => 997]);
    $staff = zz_person($conn, "zzrights.staff", "offices", ZZ_OFFICE);
    $guard = zz_person($conn, "zzrights.guard", "security");

    // The notice a visitor agreed to is recorded on the server.
    $visitor = zz_person($conn, "zzrights.visitor", "visitor");
    same(visitor_record_notice_agreement($conn, $visitor, "terms-privacy-v5"), true, "an agreement to a notice version is recorded");
    same(zz_value($conn, "SELECT notice_version FROM app_users WHERE id = {$visitor}"), "terms-privacy-v5", "with the version");
    check(zz_value($conn, "SELECT notice_agreed_at FROM app_users WHERE id = {$visitor}") !== null, "and the time");
    same(visitor_record_notice_agreement($conn, $visitor, "anything-else"), false, "a made-up version is refused");
    same(zz_value($conn, "SELECT notice_version FROM app_users WHERE id = {$visitor}"), "terms-privacy-v5", "and changes nothing");

    // Sign-up records the version agreed to on the form, at the time of the sign-up.
    $requestAt = date("Y-m-d H:i:s", time() - 600);
    $requestId = zz_insert($conn, "account_requests", [
        "status" => "unconfirmed", "email" => "zzrights.signup@example.com", "password_hash" => password_hash("Zzrights-pass-1", PASSWORD_DEFAULT),
        "full_name" => "Zzrights Signup", "contact_number" => "", "consent_version" => "terms-privacy-v5", "created_at" => $requestAt,
    ]);
    $conn->begin_transaction();
    $signedUp = signup_create_account($conn, signup_request_load($conn, $requestId));
    $conn->commit();
    same(zz_value($conn, "SELECT notice_version FROM app_users WHERE id = {$signedUp}"), "terms-privacy-v5", "a new account records the notice version agreed to at sign-up");
    same(zz_value($conn, "SELECT notice_agreed_at FROM app_users WHERE id = {$signedUp}"), $requestAt, "at the time they signed up");

    // Deleting their own account: upcoming visits are cancelled, past ones stay without the account.
    $upcoming = zz_appointment($conn, $visitor, "approved", 24 * 60);
    $past = zz_appointment($conn, $visitor, "completed", -3 * 24 * 60, ["checked_in_at" => date("Y-m-d H:i:s", time() - 3 * 86400)]);
    $token = zz_insert($conn, "api_access_tokens", [
        "user_id" => $visitor, "token_hash" => hash("sha256", bin2hex(random_bytes(16))), "installation_id" => "zzrights-phone",
        "device_name" => "Check phone", "user_agent" => "", "expires_at" => date("Y-m-d H:i:s", time() + 86400),
    ]);
    $removed = account_remove_own($conn, $visitor);
    same($removed["cancelled_visits"], 1, "the one upcoming visit is cancelled");
    same(zz_value($conn, "SELECT COUNT(*) FROM app_users WHERE id = {$visitor}"), "0", "the account is gone");
    same(zz_value($conn, "SELECT COUNT(*) FROM api_access_tokens WHERE id = {$token}"), "0", "and every sign-in of it with it");
    same(zz_value($conn, "SELECT status FROM appointments WHERE id = {$upcoming}"), "cancelled", "the upcoming visit is cancelled");
    same(zz_value($conn, "SELECT note FROM appointment_status_history WHERE appointment_id = {$upcoming} ORDER BY id DESC LIMIT 1"),
        "Cancelled because the visitor deleted their account", "its history says why");
    $notice = (string) zz_value($conn, "SELECT message FROM app_notifications WHERE appointment_id = {$upcoming} AND recipient_user_id = {$staff} ORDER BY id DESC LIMIT 1");
    check(str_contains($notice, "deleted their account"), "the office is told: {$notice}");
    same(zz_value($conn, "SELECT status FROM appointments WHERE id = {$past}"), "completed", "the past visit stays in the records");
    same(zz_value($conn, "SELECT visitor_user_id FROM appointments WHERE id = {$past}"), null, "no longer linked to the account");
    same(zz_value($conn, "SELECT visitor_full_name FROM appointments WHERE id = {$past}"), "Zzrights Visitor", "under the name it was booked with");
    $audit = $conn->query("SELECT actor_user_id, details_json FROM audit_logs WHERE action = 'user.deleted' AND entity_id = '{$visitor}' ORDER BY id DESC LIMIT 1")->fetch_assoc();
    check(is_array($audit), "the deletion is in the activity log");
    check(is_array($audit) && array_key_exists("actor_user_id", $audit) && $audit["actor_user_id"] === null,
        "the activity log has no staff actor for it");
    $details = json_decode((string) ($audit["details_json"] ?? ""), true) ?: [];
    same($details["by_self"] ?? null, true, "the activity log says the visitor did it");
    same(activity_describe(["action" => "user.deleted", "entity_type" => "app_user", "details_json" => $audit["details_json"] ?? ""])[0],
        "Deleted their own account", "the activity log names it in plain words");

    // Not while they are inside the campus: nothing changes, and they are told why.
    $inside = zz_person($conn, "zzrights.inside", "visitor");
    $onCampus = zz_appointment($conn, $inside, "checked_in", -20, ["checked_in_at" => date("Y-m-d H:i:s", time() - 1200)]);
    try {
        account_remove_own($conn, $inside);
        check(false, "a visitor inside the campus can't delete their account");
    } catch (AccountRemovalError $error) {
        check(str_starts_with($error->getMessage(), "You're checked in on campus right now."), "they are told why: " . $error->getMessage());
    }
    same(zz_value($conn, "SELECT COUNT(*) FROM app_users WHERE id = {$inside}"), "1", "the account stays");
    same(zz_value($conn, "SELECT status FROM appointments WHERE id = {$onCampus}"), "checked_in", "and so does the visit");

    // Only visitors delete their own account from the app; staff accounts are removed by an Admin.
    try {
        account_remove_own($conn, $guard);
        check(false, "a staff account can't delete itself from the app");
    } catch (AccountRemovalError $error) {
        same($error->getMessage(), "Only a visitor account can be deleted from the app.", "a staff account is refused");
    }
    try {
        account_remove($conn, $guard, $guard);
        check(false, "an administrator still can't remove their own account");
    } catch (AccountRemovalError $error) {
        same($error->getMessage(), "You cannot remove your own account.", "an administrator still can't remove their own account");
    }
} finally {
    zz_forget($conn);
}

$left = (int) zz_value($conn, "SELECT COUNT(*) FROM appointments WHERE office_code = '" . ZZ_OFFICE . "'")
    + (int) zz_value($conn, "SELECT COUNT(*) FROM app_users WHERE username LIKE 'zzrights.%' OR email LIKE 'zzrights.%'");
same($left, 0, "the check removes everything it made");

if ($failures > 0) {
    fwrite(STDERR, "{$failures} of {$checks} checks failed\n");
    exit(1);
}
echo "Visitor rights: all {$checks} checks passed\n";
