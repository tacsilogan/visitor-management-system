<?php
/**
 * Checks registering visitors at the gate and checking booked visitors in without their QR code
 * (GATE_REGISTRATION.md) on the database, with a throwaway office, guard and visits that it
 * removes again:
 *
 *   php phone_tracker/tools/gate_registration_test.php
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
require_once dirname(__DIR__) . "/gate_service.php";
require_once dirname(__DIR__) . "/monitoring_service.php";
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

const ZZ_OFFICE = "ZZGATE";

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

function zz_forget(mysqli $conn): void
{
    $ids = [];
    foreach ($conn->query("SELECT id FROM appointments WHERE office_code = '" . ZZ_OFFICE . "'") as $row) {
        $ids[] = (int) $row["id"];
    }
    $users = [];
    foreach ($conn->query("SELECT id FROM app_users WHERE username LIKE 'zzgate.%'") as $row) {
        $users[] = (int) $row["id"];
    }
    $appointments = implode(",", $ids) ?: "0";
    $people = implode(",", $users) ?: "0";
    foreach ([
        "DELETE FROM appointment_status_history WHERE appointment_id IN ({$appointments})",
        "DELETE FROM app_notifications WHERE appointment_id IN ({$appointments}) OR recipient_user_id IN ({$people})",
        "DELETE FROM audit_logs WHERE appointment_id IN ({$appointments}) OR actor_user_id IN ({$people})",
        "DELETE FROM visitor_presence WHERE appointment_id IN ({$appointments})",
        "DELETE FROM location_tracking_sessions WHERE appointment_id IN ({$appointments})",
        "DELETE FROM appointments WHERE id IN ({$appointments})",
        "DELETE FROM office_availability_rules WHERE office_code = '" . ZZ_OFFICE . "'",
        "DELETE FROM office_availability_settings WHERE office_code = '" . ZZ_OFFICE . "'",
        "DELETE FROM app_users WHERE id IN ({$people})",
        "DELETE FROM offices WHERE code = '" . ZZ_OFFICE . "'",
    ] as $sql) {
        try {
            $conn->query($sql);
        } catch (mysqli_sql_exception $ignored) {
            // A table from a later migration that this database doesn't have.
        }
    }
}

/** @return array{code:string, name:string, can_register:bool, message:string}|null */
function zz_office_state(mysqli $conn): ?array
{
    foreach (gate_office_states($conn) as $office) {
        if ($office["code"] === ZZ_OFFICE) {
            return $office;
        }
    }
    return null;
}

function zz_valid_input(array $changes = []): array
{
    return array_merge([
        "full_name" => "  Zzgate   Juan Dela Cruz ",
        "id_type" => "Driver's license",
        "contact_number" => "0917 123 4567",
        "office_code" => strtolower(ZZ_OFFICE),
        "purpose" => "Office Transaction",
        "concern" => "Enrollment papers",
        "consent" => true,
    ], $changes);
}

/** The field names a rejected registration reports, sorted. */
function zz_rejected_fields(callable $register): ?array
{
    try {
        $register();
    } catch (InvalidArgumentException $error) {
        $fields = array_keys((array) json_decode($error->getMessage(), true));
        sort($fields);
        return $fields;
    }
    return null;
}

zz_forget($conn);
try {
    if (!gate_schema_ready($conn)) {
        fwrite(STDERR, "FAIL: gate_registration_migration.sql is not installed\n");
        exit(1);
    }

    // An office with Office Personnel and no weekly hours saved, so it is open at any time.
    zz_insert($conn, "offices", ["code" => ZZ_OFFICE, "name" => "Zzgate Check Office", "is_active" => 1, "sort_order" => 999]);
    $staff = zz_insert($conn, "app_users", [
        "username" => "zzgate.staff", "email" => "zzgate.staff@example.com", "password_hash" => "not-a-password",
        "display_name" => "Zzgate Staff", "role" => "offices", "office_code" => ZZ_OFFICE, "is_active" => 1,
    ]);
    $guard = zz_insert($conn, "app_users", [
        "username" => "zzgate.guard", "email" => "zzgate.guard@example.com", "password_hash" => "not-a-password",
        "display_name" => "Zzgate Guard", "role" => "security", "office_code" => "", "is_active" => 1,
    ]);
    appointment_office_directory(true);

    $office = zz_office_state($conn);
    same($office["can_register"] ?? null, true, "an open office with Office Personnel takes gate registrations");

    // Every wrong field is named, so the form can point at each one.
    same(
        zz_rejected_fields(fn () => gate_register_visitor($conn, $guard, [
            "full_name" => " 1 ", "id_type" => "Library card", "contact_number" => "call me", "office_code" => "NOPE",
            "purpose" => "Shopping", "concern" => str_repeat("x", 151), "consent" => "yes",
        ])),
        ["concern", "consent", "contact_number", "full_name", "id_type", "office_code", "purpose"],
        "a registration with every field wrong names every field"
    );
    same(zz_rejected_fields(fn () => gate_register_visitor($conn, $guard, zz_valid_input(["consent" => false]))), ["consent"],
        "the visitor's agreement is required");
    same((int) zz_value($conn, "SELECT COUNT(*) FROM appointments WHERE office_code = '" . ZZ_OFFICE . "'"), 0,
        "a rejected registration saves nothing");

    // A visitor registered at the gate is checked in at once, with no account and no tracking.
    $now = new DateTimeImmutable("now");
    $visit = gate_register_visitor($conn, $guard, zz_valid_input(), $now);
    $id = $visit["appointment_id"];
    $row = $conn->query("SELECT * FROM appointments WHERE id = {$id}")->fetch_assoc();
    same($row["status"], "checked_in", "a gate visitor is checked in at once");
    same($row["registration_source"], "gate", "the visit is marked as registered at the gate");
    same($row["visit_type"], "walk_in", "it counts as a walk-in");
    same($row["visitor_user_id"], null, "no visitor account is made");
    same($row["visitor_full_name"], "Zzgate Juan Dela Cruz", "the name's extra spaces are removed");
    same($row["id_type_shown"], "Driver's license", "the type of ID is saved, never a number");
    same($row["contact_number"], "0917 123 4567", "the optional contact number is saved");
    check($row["gate_consent_at"] !== null, "the visitor's agreement is saved with the visit");
    same((int) $row["checked_in_by_user_id"], $guard, "the guard who registered them is recorded");
    same($row["registration_code"], "V-" . $now->format("Y") . "-" . str_pad((string) $id, 6, "0", STR_PAD_LEFT),
        "the visit gets the usual reference");
    same($visit["registration_code"], $row["registration_code"], "the guard is given the reference to tell the visitor");
    same(strtotime((string) $row["scheduled_end_at"]) - strtotime((string) $row["checked_in_at"]), GATE_VISIT_HOURS * 3600,
        "overstay starts " . GATE_VISIT_HOURS . " hours after check-in, as for an app walk-in");
    same((int) zz_value($conn, "SELECT COUNT(*) FROM appointment_status_history WHERE appointment_id = {$id} AND from_status IS NULL AND to_status = 'checked_in'"), 1,
        "the visit's history starts at the gate");
    same((int) zz_value($conn, "SELECT COUNT(*) FROM app_notifications WHERE appointment_id = {$id} AND recipient_user_id = {$staff} AND notification_type = 'walk_in_created'"), 1,
        "the office's personnel are told a walk-in is coming");

    $audit = $conn->query("SELECT action, details_json FROM audit_logs WHERE appointment_id = {$id} ORDER BY id LIMIT 1")->fetch_assoc();
    same($audit["action"] ?? null, "appointment.checked_in", "it counts as a check-in in the activity log, under the guard");
    $details = json_decode((string) ($audit["details_json"] ?? ""), true) ?: [];
    same($details["registered_at_gate"] ?? null, true, "the activity log says it was registered at the gate");
    same($details["notice_version"] ?? null, GATE_NOTICE_VERSION, "the activity log keeps which notice was read out");
    check(!str_contains((string) ($audit["details_json"] ?? ""), "0917"), "the activity log keeps no contact number");
    $description = activity_describe([
        "action" => "appointment.checked_in", "details_json" => $audit["details_json"] ?? "",
        "visitor_full_name" => "Zzgate Juan Dela Cruz", "appointment_office_code" => ZZ_OFFICE,
    ]);
    same($description[0], "Registered a visitor at the gate", "the activity log names it in plain words");

    $active = array_values(array_filter(monitor_visitors($conn, "active"), fn (array $entry): bool => (int) $entry["id"] === $id));
    same($active[0]["location_state"] ?? null, "not_tracked", "Security sees a gate visitor as not tracked, not waiting for GPS");
    same($active[0]["has_location"] ?? null, false, "a gate visitor never has a position");
    same($active[0]["registration_source"] ?? null, "gate", "Security can tell a gate visitor apart");

    // The same rule as an app walk-in: an office that isn't taking visitors refuses.
    $conn->query("UPDATE office_availability_settings SET accepting_visitors = 0, unavailable_reason = 'Zzgate stock count' WHERE office_code = '" . ZZ_OFFICE . "'");
    same(zz_office_state($conn)["can_register"] ?? null, false, "an office not taking visitors is shown as closed on the form");
    try {
        gate_register_visitor($conn, $guard, zz_valid_input(["full_name" => "Zzgate Closed Office"]));
        check(false, "an office not taking visitors refuses gate registrations");
    } catch (DomainException $error) {
        check(str_contains($error->getMessage(), "not accepting visitors"), "the guard is told why: " . $error->getMessage());
    }
    $conn->query("UPDATE office_availability_settings SET accepting_visitors = 1, unavailable_reason = '' WHERE office_code = '" . ZZ_OFFICE . "'");

    // An office with no Office Personnel can't be chosen: nobody would receive the visitor.
    $conn->query("UPDATE app_users SET is_active = 0 WHERE id = {$staff}");
    appointment_office_directory(true);
    same(zz_office_state($conn), null, "an office with no Office Personnel is not offered");
    same(zz_rejected_fields(fn () => gate_register_visitor($conn, $guard, zz_valid_input())), ["office_code"],
        "an office with no Office Personnel is refused");
    $conn->query("UPDATE app_users SET is_active = 1 WHERE id = {$staff}");
    appointment_office_directory(true);

    // End visit (the guard's button) is how a gate visitor checks out.
    $ended = gate_checkout($conn, $id, $guard, "guard");
    check($ended !== null, "End visit works for a gate visitor");
    same(zz_value($conn, "SELECT status FROM appointments WHERE id = {$id}"), "completed", "the visit is completed");
    same(zz_value($conn, "SELECT checkout_method FROM appointments WHERE id = {$id}"), "guard", "it ended by the guard");
    $finished = array_values(array_filter(monitor_visitors($conn, "finished"), fn (array $entry): bool => (int) $entry["id"] === $id));
    same($finished[0]["registration_source"] ?? null, "gate", "Left today still shows it was registered at the gate");

    // Check in without the QR code: the dashboard sends the booking's id, the scan uses its own pass.
    $token = bin2hex(random_bytes(32));
    $booked = zz_insert($conn, "appointments", [
        "public_token" => $token, "office_code" => ZZ_OFFICE, "visitor_full_name" => "Zzgate Rosa Lim",
        "device_name" => "Check phone", "appointment_at" => $now->format("Y-m-d H:i:s"),
        "scheduled_start_at" => $now->format("Y-m-d H:i:s"), "scheduled_end_at" => $now->modify("+30 minutes")->format("Y-m-d H:i:s"),
        "status" => "approved", "purpose" => "Document Request",
    ]);
    same(gate_token_for_appointment($conn, $booked), $token, "a booking found on the dashboard is checked in with its own pass");
    same(gate_token_for_appointment($conn, 0), null, "no booking, no pass");
    same(gate_token_for_appointment($conn, PHP_INT_MAX), null, "an unknown booking has no pass");
    same(zz_value($conn, "SELECT registration_source FROM appointments WHERE id = {$booked}"), "app", "app bookings stay marked as app visits");
} finally {
    zz_forget($conn);
}

$left = (int) zz_value($conn, "SELECT COUNT(*) FROM appointments WHERE office_code = '" . ZZ_OFFICE . "'")
    + (int) zz_value($conn, "SELECT COUNT(*) FROM app_users WHERE username LIKE 'zzgate.%'");
same($left, 0, "the check removes everything it made");

if ($failures > 0) {
    fwrite(STDERR, "{$failures} of {$checks} checks failed\n");
    exit(1);
}
echo "Gate registration: all {$checks} checks passed\n";
