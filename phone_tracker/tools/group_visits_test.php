<?php
/**
 * Checks visits with other people, the ID check at the gate, and publishing the visitor app
 * (GROUP_VISITS.md) on the database, with a throwaway office, users, visits and app files that it
 * removes again:
 *
 *   php phone_tracker/tools/group_visits_test.php
 *
 * The app files are small fake APKs built here (a manifest, a dex entry and a signing block), so
 * no real app is needed. Exits with 1 and lists what failed when a check does not hold.
 */
declare(strict_types=1);

// It writes to the database: never from a browser (tools/.htaccess also blocks the folder).
if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . "/db.php";
require_once dirname(__DIR__) . "/visit_people_service.php";
require_once dirname(__DIR__) . "/visit_service.php";
require_once dirname(__DIR__) . "/gate_service.php";
require_once dirname(__DIR__) . "/activity_service.php";
require_once dirname(__DIR__) . "/app_release_service.php";

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

/** The message of what [$run] throws, or null when it throws nothing. */
function zz_throws(callable $run, string $class = Throwable::class): ?string
{
    try {
        $run();
    } catch (Throwable $error) {
        return $error instanceof $class ? $error->getMessage() : get_class($error) . ": " . $error->getMessage();
    }
    return null;
}

const ZZ_OFFICE = "ZZGRP";

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

/** An appointment row for the throwaway office, starting [$startMinutes] from now. */
function zz_appointment(mysqli $conn, string $name, string $status, int $startMinutes = 5, array $extra = []): int
{
    $start = date("Y-m-d H:i:s", time() + $startMinutes * 60);
    $end = date("Y-m-d H:i:s", time() + ($startMinutes + 30) * 60);
    return zz_insert($conn, "appointments", $extra + [
        "public_token" => bin2hex(random_bytes(32)), "office_code" => ZZ_OFFICE, "visitor_full_name" => $name,
        "device_name" => "", "visit_type" => "appointment", "purpose" => "Office Transaction", "subject" => "Zz",
        "appointment_at" => $start, "scheduled_start_at" => $start, "scheduled_end_at" => $end, "status" => $status,
    ]);
}

function zz_forget(mysqli $conn): void
{
    $appointments = [];
    foreach ($conn->query("SELECT id FROM appointments WHERE office_code = '" . ZZ_OFFICE . "'") as $row) {
        $appointments[] = (int) $row["id"];
    }
    $users = [];
    foreach ($conn->query("SELECT id FROM app_users WHERE username LIKE 'zzgroup.%'") as $row) {
        $users[] = (int) $row["id"];
    }
    $a = implode(",", $appointments) ?: "0";
    $u = implode(",", $users) ?: "0";
    foreach ([
        "DELETE FROM app_release_downloads WHERE release_id IN (SELECT id FROM app_releases WHERE uploaded_by_user_id IN ({$u}))",
        "DELETE FROM app_releases WHERE uploaded_by_user_id IN ({$u})",
        "DELETE FROM visit_people WHERE appointment_id IN ({$a}) OR visit_id IN (SELECT id FROM visits WHERE visitor_user_id IN ({$u}))",
        "DELETE FROM appointment_status_history WHERE appointment_id IN ({$a})",
        "DELETE FROM app_notifications WHERE appointment_id IN ({$a}) OR recipient_user_id IN ({$u})",
        "DELETE FROM audit_logs WHERE appointment_id IN ({$a}) OR actor_user_id IN ({$u})",
        "UPDATE visits SET tracking_appointment_id = NULL WHERE visitor_user_id IN ({$u})",
        "DELETE FROM appointments WHERE id IN ({$a})",
        "DELETE FROM visits WHERE visitor_user_id IN ({$u})",
        "DELETE FROM office_availability_rules WHERE office_code = '" . ZZ_OFFICE . "'",
        "DELETE FROM office_availability_settings WHERE office_code = '" . ZZ_OFFICE . "'",
        "DELETE FROM app_users WHERE id IN ({$u})",
        "DELETE FROM offices WHERE code = '" . ZZ_OFFICE . "'",
    ] as $sql) {
        try {
            $conn->query($sql);
        } catch (mysqli_sql_exception $ignored) {
            // A table from a later migration that this database doesn't have.
        }
    }
}

/* ---------- Fake APKs ---------- */

/** A compiled AndroidManifest.xml with only <manifest package versionCode versionName>. */
function zz_manifest(string $package, int $versionCode, string $versionName): string
{
    $strings = ["versionCode", "versionName", "package", "manifest", "android", "http://schemas.android.com/apk/res/android", $package, $versionName];
    $offsets = "";
    $data = "";
    foreach ($strings as $string) {
        $offsets .= pack("V", strlen($data));
        $data .= pack("v", mb_strlen($string)) . mb_convert_encoding($string, "UTF-16LE", "UTF-8") . "\0\0";
    }
    while (strlen($data) % 4) {
        $data .= "\0";
    }
    $stringsStart = 28 + 4 * count($strings);
    $pool = pack("vvVVVVVV", 0x0001, 28, $stringsStart + strlen($data), count($strings), 0, 0, $stringsStart, 0) . $offsets . $data;
    $resources = pack("vvV", 0x0180, 8, 16) . pack("VV", 0x0101021b, 0x0101021c);
    $attribute = fn (int $ns, int $name, int $raw, int $type, int $value): string => pack("VVVvCCV", $ns, $name, $raw, 8, 0, $type, $value);
    $attributes = $attribute(5, 0, 0xFFFFFFFF, 0x10, $versionCode) . $attribute(5, 1, 7, 0x03, 7) . $attribute(0xFFFFFFFF, 2, 6, 0x03, 6);
    $ext = pack("VVvvvvvv", 0xFFFFFFFF, 3, 20, 20, 3, 0, 0, 0);
    $element = pack("vvVVV", 0x0102, 16, 16 + strlen($ext) + strlen($attributes), 1, 0xFFFFFFFF) . $ext . $attributes;
    $body = $pool . $resources . $element;
    return pack("vvV", 0x0003, 8, 8 + strlen($body)) . $body;
}

/** An APK Signing Block with one signer and one certificate, for scheme v2 (or v3). */
function zz_signing_block(string $certificate, int $scheme = 0x7109871a): string
{
    $prefixed = fn (string $bytes): string => pack("V", strlen($bytes)) . $bytes;
    $signedData = $prefixed($prefixed("zz-digest")) . $prefixed($prefixed($certificate)) . $prefixed("");
    $signer = $prefixed($signedData) . $prefixed("zz-signature") . $prefixed("zz-public-key");
    $value = $prefixed($prefixed($signer));
    $pair = pack("P", 4 + strlen($value)) . pack("V", $scheme) . $value;
    $size = strlen($pair) + 24;
    return pack("P", $size) . $pair . pack("P", $size) . "APK Sig Block 42";
}

/** A stored (uncompressed) ZIP with the signing block before the central directory. */
function zz_apk(string $package, int $versionCode, string $versionName, ?string $key, bool $withDex = true, int $scheme = 0x7109871a): string
{
    $files = ["AndroidManifest.xml" => zz_manifest($package, $versionCode, $versionName)];
    if ($withDex) {
        $files["classes.dex"] = "dex\n035\0" . str_repeat("z", 64);
    }
    $local = "";
    $central = "";
    foreach ($files as $name => $content) {
        $offset = strlen($local);
        $crc = crc32($content);
        $size = strlen($content);
        $local .= pack("VvvvvvVVVvv", 0x04034b50, 20, 0, 0, 0, 0, $crc, $size, $size, strlen($name), 0) . $name . $content;
        $central .= pack("VvvvvvvVVVvvvvvVV", 0x02014b50, 20, 20, 0, 0, 0, 0, $crc, $size, $size, strlen($name), 0, 0, 0, 0, 0, $offset) . $name;
    }
    $block = $key === null ? "" : zz_signing_block(str_repeat($key, 16), $scheme);
    $eocd = pack("VvvvvVVv", 0x06054b50, 0, 0, count($files), count($files), strlen($central), strlen($local) + strlen($block), 0);
    return $local . $block . $central . $eocd;
}

/** Writes a fake APK to a temporary file and returns its path. */
function zz_apk_file(string $dir, string $content): string
{
    $path = $dir . "/upload-" . bin2hex(random_bytes(4)) . ".apk";
    file_put_contents($path, $content);
    return $path;
}

$dataDir = sys_get_temp_dir() . "/zzgroup-" . bin2hex(random_bytes(4));
mkdir($dataDir . "/private/apps", 0700, true);
putenv("ISATU_DATA_DIR=" . $dataDir);

zz_forget($conn);
try {
    if (!visit_people_schema_ready($conn) || !app_release_schema_ready($conn)) {
        fwrite(STDERR, "FAIL: group_visits_migration.sql is not installed\n");
        exit(1);
    }

    // An office with Office Personnel and no weekly hours saved, so it is open at any time.
    zz_insert($conn, "offices", ["code" => ZZ_OFFICE, "name" => "Zzgroup Office", "is_active" => 1, "sort_order" => 999]);
    zz_insert($conn, "app_users", [
        "username" => "zzgroup.staff", "email" => "zzgroup.staff@example.com", "password_hash" => "not-a-password",
        "display_name" => "Zzgroup Staff", "role" => "offices", "office_code" => ZZ_OFFICE, "is_active" => 1,
    ]);
    $guard = zz_insert($conn, "app_users", [
        "username" => "zzgroup.guard", "email" => "zzgroup.guard@example.com", "password_hash" => "not-a-password",
        "display_name" => "Zzgroup Guard", "role" => "security", "office_code" => "", "is_active" => 1,
    ]);
    $admin = zz_insert($conn, "app_users", [
        "username" => "zzgroup.admin", "email" => "zzgroup.admin@example.com", "password_hash" => "not-a-password",
        "display_name" => "Zzgroup Admin", "role" => "admin", "office_code" => "", "is_active" => 1,
    ]);
    $visitor = zz_insert($conn, "app_users", [
        "username" => "zzgroup.visitor", "email" => "zzgroup.visitor@example.com", "password_hash" => "not-a-password",
        "display_name" => "Zzgroup Juan", "role" => "visitor", "office_code" => "", "is_active" => 1,
    ]);
    appointment_office_directory(true);

    /* ---------- Booking: who's coming ---------- */

    same(visit_people_clean_booking(null), [], "a booking from today's app has nobody with the visitor");
    same(
        visit_people_clean_booking([["full_name" => "  Elena   Dela Cruz ", "is_adult" => true], ["full_name" => "Miguel Dela Cruz", "is_adult" => "0"]]),
        [["full_name" => "Elena Dela Cruz", "is_adult" => true], ["full_name" => "Miguel Dela Cruz", "is_adult" => false]],
        "each person keeps a cleaned name and whether they are 18 or older"
    );
    $tooMany = array_fill(0, VISIT_PEOPLE_MAX + 1, ["full_name" => "Zz Person", "is_adult" => true]);
    check(zz_throws(fn () => visit_people_clean_booking($tooMany), InvalidArgumentException::class) !== null, "more than " . VISIT_PEOPLE_MAX . " people are refused");
    $errors = json_decode((string) zz_throws(fn () => visit_people_clean_booking([["full_name" => "1"], ["full_name" => "Ana Reyes", "is_adult" => "maybe"]])), true);
    $fields = array_keys((array) $errors);
    sort($fields);
    same($fields, ["people.0.full_name", "people.0.is_adult", "people.1.is_adult"], "every wrong name and age is pointed out");
    check(zz_throws(fn () => visit_people_clean_booking(["a" => ["full_name" => "Ana Reyes", "is_adult" => true]])) !== null, "the people must come as a list");

    /* ---------- At the gate: one office ---------- */

    $juan = zz_appointment($conn, "Zzgroup Juan Dela Cruz", "approved");
    $pass = ["appointment_id" => $juan, "visit_id" => null];
    visit_people_insert($conn, $pass, [
        ["full_name" => "Elena Dela Cruz", "is_adult" => true],
        ["full_name" => "Miguel Dela Cruz", "is_adult" => false],
        ["full_name" => "Kristine Tan", "is_adult" => true],
        ["full_name" => "Paolo Villar", "is_adult" => true],
    ]);
    $people = visit_people_list($conn, $pass);
    same(array_column($people, "full_name"), ["Elena Dela Cruz", "Miguel Dela Cruz", "Kristine Tan", "Paolo Villar"], "the people keep the order the visitor added them in");
    same(array_unique(array_column($people, "gate_status")), ["expected"], "before the gate, everyone is expected");
    [$elena, $miguel, $kristine, $paolo] = array_map(fn (array $person): int => (int) $person["id"], $people);

    $check = visit_people_check_response($conn, ["visitor_full_name" => "Zzgroup Juan Dela Cruz"], $pass);
    same($check["action"], "check_ids", "the scan first asks for the IDs, checking nobody in");
    same(count($check["people"]), 4, "the guard sees everyone on the pass");
    same($check["id_types"], GATE_ID_TYPES, "the guard picks from the same ID types as at registration");

    check(zz_throws(fn () => visit_people_clean_check(["people" => []], $people), InvalidArgumentException::class) !== null,
        "the visitor's own ID is needed");
    check(zz_throws(fn () => visit_people_clean_check(["holder" => ["id_type" => "Library card"]], $people)) !== null,
        "only known ID types are taken");
    check(str_contains((string) zz_throws(fn () => visit_people_clean_check(["holder" => ["id_type" => "Passport"], "people" => [["id" => 999999999, "result" => "in"]]], $people)), "changed"),
        "someone not on this pass can't be checked in with it");
    check(str_contains((string) zz_throws(fn () => visit_people_clean_check(["holder" => ["id_type" => "Passport"], "people" => [["id" => $elena, "result" => "in"]]], $people)), "Elena"),
        "an adult coming in needs their ID");
    same(visit_people_clean_check(["holder" => ["id_type" => "none", "no_id_reason" => "Lost wallet"]], $people)["holder"],
        ["id_type" => "", "no_id_reason" => "Lost wallet"], "No ID is allowed, with its reason");

    $answer = visit_people_clean_check([
        "holder" => ["id_type" => "Driver's license"],
        "people" => [
            ["id" => $elena, "result" => "in", "id_type" => "PhilSys national ID", "full_name" => "Elena Ramos Dela Cruz"],
            ["id" => $miguel, "result" => "in"],
            ["id" => $paolo, "result" => "refused"],
            ["full_name" => "Lito Torres", "is_adult" => true, "result" => "in", "id_type" => "School ID"],
        ],
    ], $people);
    $byName = array_column($answer["people"], null, "full_name");
    same($byName["Kristine Tan"]["result"] ?? null, "absent", "someone the guard didn't answer for didn't come");
    same($byName["Elena Ramos Dela Cruz"]["rename_from"] ?? null, "Elena Dela Cruz", "a name fixed to match the ID keeps the booked one");
    same($byName["Miguel Dela Cruz"]["id_type"] ?? null, "", "a child needs no ID");

    $conn->query("UPDATE appointments SET status = 'checked_in', checked_in_at = NOW() WHERE id = {$juan}");
    $conn->begin_transaction();
    $recorded = visit_people_record_check($conn, $pass, [$juan], $answer, $guard);
    $conn->commit();
    same(zz_value($conn, "SELECT id_type_shown FROM appointments WHERE id = {$juan}"), "Driver's license", "the visitor's ID type is saved on the visit, never a number");
    $after = array_column(visit_people_list($conn, $pass), null, "full_name");
    same($after["Elena Ramos Dela Cruz"]["gate_status"] ?? null, "in", "an adult whose ID matched is checked in");
    same($after["Elena Ramos Dela Cruz"]["booked_name"] ?? null, "Elena Dela Cruz", "the booked name is kept beside the fixed one");
    same($after["Elena Ramos Dela Cruz"]["id_type_shown"] ?? null, "PhilSys national ID", "each adult's ID type is saved");
    same($after["Miguel Dela Cruz"]["gate_status"] ?? null, "in", "the child is checked in with the group");
    same($after["Kristine Tan"]["gate_status"] ?? null, "absent", "someone who didn't come is marked so");
    same($after["Paolo Villar"]["gate_status"] ?? null, "refused", "someone whose name didn't match is turned away");
    same((int) ($after["Lito Torres"]["added_at_gate"] ?? 0), 1, "someone added at the gate is marked so");
    same($after["Lito Torres"]["id_type_shown"] ?? null, "School ID", "the added person's ID type is saved");
    same((int) zz_value($conn, "SELECT COUNT(*) FROM audit_logs WHERE action = 'visit_person.refused' AND appointment_id = {$juan}"), 1,
        "the person turned away gets an entry of their own in the activity log");
    same(count($recorded["people"]), 5, "the activity log keeps everyone's result with the check-in");
    $lito = (int) $after["Lito Torres"]["id"];

    $counts = visit_people_inside_counts($conn);
    check($counts["people"] >= 3, "Security counts the people inside with a visitor");

    // Left early, and came later.
    $left = visit_people_mark_left($conn, $lito, $guard);
    check($left["left_at"] !== null, "someone can leave before the rest of the group");
    check(zz_throws(fn () => visit_people_mark_left($conn, $lito, $guard), DomainException::class) !== null, "nobody leaves twice");
    check(zz_throws(fn () => visit_people_mark_left($conn, $paolo, $guard), DomainException::class) !== null, "someone turned away was never inside");
    same(visit_people_inside_counts($conn)["people"], $counts["people"] - 1, "someone who left is no longer counted inside");
    check(zz_throws(fn () => visit_people_late_arrival($conn, $kristine, "", "", $guard), DomainException::class) !== null,
        "an adult who comes later still needs their ID");
    $late = visit_people_late_arrival($conn, $kristine, "School ID", "", $guard);
    same($late["gate_status"], "in", "someone who missed the group at the gate is checked in when they come");
    check(zz_throws(fn () => visit_people_late_arrival($conn, $kristine, "School ID", "", $guard), DomainException::class) !== null,
        "someone already checked can't be checked in again");

    // Security's search finds the people with a visitor, by their name or the visitor's.
    $found = array_column(visit_people_search($conn, "Ramos Dela"), "full_name");
    same($found, ["Elena Ramos Dela Cruz"], "Security finds someone with a visitor by name");
    $withJuan = array_column(visit_people_search($conn, "Zzgroup Juan"), "full_name");
    check(count($withJuan) === 5, "searching the visitor's name finds everyone with them");
    $searched = visit_people_search_payload(visit_people_find($conn, $elena));
    same([$searched["inside"], $searched["can_leave"], $searched["can_join"]], [true, true, false], "the search says what the guard can do for each person");

    // The activity log, in plain words.
    $details = json_decode((string) zz_value($conn, "SELECT details_json FROM audit_logs WHERE action = 'visit_person.refused' AND appointment_id = {$juan}"), true);
    $refused = activity_describe(["entity_type" => "visit_person", "action" => "visit_person.refused", "details_json" => json_encode($details), "visitor_full_name" => "Zzgroup Juan Dela Cruz", "appointment_office_code" => ZZ_OFFICE]);
    same($refused[1], "Didn't let Paolo Villar in with Zzgroup Juan Dela Cruz's group: the name on the ID didn't match. The others came in", "a refusal reads plainly");
    same($refused[2], "danger", "a refusal stands out");
    $checkedIn = activity_describe([
        "entity_type" => "appointment", "action" => "appointment.checked_in", "details_json" => json_encode($recorded),
        "visitor_full_name" => "Zzgroup Juan Dela Cruz", "appointment_office_code" => ZZ_OFFICE,
    ]);
    same($checkedIn[0], "Checked in a group", "a group's check-in is named as one");
    same(
        $checkedIn[1],
        "Checked in Zzgroup Juan Dela Cruz's group at the gate for Zzgroup Office: Zzgroup Juan Dela Cruz (driver's license), "
            . "Elena Ramos Dela Cruz (PhilSys ID, booked as Elena Dela Cruz), Miguel Dela Cruz (under 18) and Lito Torres (school ID, added at the gate). Kristine Tan didn't come",
        "the check-in names everyone who came in, with their ID"
    );
    $passRefused = activity_describe(["entity_type" => "appointment", "action" => "appointment.pass_refused", "details_json" => "{}", "visitor_full_name" => "Zzgroup Maria", "appointment_office_code" => ZZ_OFFICE]);
    same($passRefused[1], "Didn't let in someone using Zzgroup Maria's pass: the name on their ID didn't match. The pass still works for Zzgroup Maria", "a refused pass reads plainly");
    same(activity_category("visit_person.left", $guard), "gate", "the people's entries are filed with the gate's");

    /* ---------- A visit to several offices: one list for every stop ---------- */

    $visitId = zz_insert($conn, "visits", ["visitor_user_id" => $visitor, "public_token" => bin2hex(random_bytes(32)), "visit_date" => date("Y-m-d"), "status" => "open"]);
    $stop1 = zz_appointment($conn, "Zzgroup Juan", "approved", 5, ["visit_id" => $visitId, "stop_number" => 1, "visitor_user_id" => $visitor]);
    $stop2 = zz_appointment($conn, "Zzgroup Juan", "approved", 40, ["visit_id" => $visitId, "stop_number" => 2, "visitor_user_id" => $visitor]);
    $trip = ["appointment_id" => null, "visit_id" => $visitId];
    visit_people_insert($conn, $trip, [["full_name" => "Rhea Gonzaga", "is_adult" => true], ["full_name" => "Jomar Abella", "is_adult" => true]]);
    $map = visit_people_for_rows($conn, [["id" => $stop1, "visit_id" => $visitId], ["id" => $stop2, "visit_id" => $visitId], ["id" => $juan, "visit_id" => null]]);
    same([count($map[$stop1]), count($map[$stop2]), count($map[$juan])], [2, 2, 5], "every stop of a visit shows the visit's one list");

    $tripCheck = visit_handle_scan($conn, $visitId, $guard, "check");
    same([$tripCheck["action"] ?? null, count($tripCheck["people"] ?? [])], ["check_ids", 2], "a visit's pass first shows everyone for the ID check");
    same(zz_value($conn, "SELECT status FROM visits WHERE id = {$visitId}"), "open", "checking the IDs changes nothing yet");
    $tripPeople = visit_people_list($conn, $trip);
    $tripAnswer = visit_people_clean_check([
        "holder" => ["id_type" => "School ID"],
        "people" => [
            ["id" => (int) $tripPeople[0]["id"], "result" => "in", "id_type" => "School ID"],
            ["id" => (int) $tripPeople[1]["id"], "result" => "in", "id_type" => "School ID"],
        ],
    ], $tripPeople);
    $tripDone = visit_handle_scan($conn, $visitId, $guard, "confirm", $tripAnswer);
    same($tripDone["action"] ?? null, "checked_in", "the visit is checked in with the guard's answers");
    same((int) zz_value($conn, "SELECT COUNT(*) FROM appointments WHERE visit_id = {$visitId} AND id_type_shown = 'School ID'"), 2,
        "the visitor's ID type is saved on every stop");
    same((int) zz_value($conn, "SELECT COUNT(*) FROM visit_people WHERE visit_id = {$visitId} AND gate_status = 'in'"), 2, "the people come in with the visit");
    $tripAudit = json_decode((string) zz_value($conn, "SELECT details_json FROM audit_logs WHERE action = 'visit.checked_in' AND appointment_id = {$stop1}"), true);
    same(count($tripAudit["people"] ?? []), 2, "the visit's check-in in the activity log names the people");

    /* ---------- Registering a group at the gate ---------- */

    $registered = gate_register_visitor($conn, $guard, [
        "full_name" => "Zzgroup Liza Gomez", "id_type" => "PhilSys national ID", "office_code" => ZZ_OFFICE,
        "purpose" => "Document Request", "consent" => true,
        "people" => [["full_name" => "Ramon Gomez", "is_adult" => true, "id_type" => "Driver's license"], ["full_name" => "Tala Gomez", "is_adult" => false]],
    ]);
    same($registered["people_count"], 2, "a visitor registered at the gate can bring people");
    $gatePeople = visit_people_list($conn, ["appointment_id" => $registered["appointment_id"], "visit_id" => null]);
    same(array_column($gatePeople, "gate_status"), ["in", "in"], "they are checked in with the visitor");
    same(array_column($gatePeople, "id_type_shown"), ["Driver's license", ""], "each adult's ID type is saved; a child needs none");
    $gateErrors = json_decode((string) zz_throws(fn () => gate_register_visitor($conn, $guard, [
        "full_name" => "Zzgroup Ana", "id_type" => "Passport", "office_code" => ZZ_OFFICE, "purpose" => "Other", "consent" => true,
        "people" => [["full_name" => "Lea Cruz", "is_adult" => true]],
    ]), InvalidArgumentException::class), true);
    same(array_keys((array) $gateErrors), ["people.0.id_type"], "an adult registered with a visitor needs their ID");
    check(str_contains(GATE_NOTICE_TEXT, "anyone with you"), "the notice read aloud covers the people with the visitor");

    // Deleted with the visit (record_retention_service.php deletes visits after a year).
    $conn->query("DELETE FROM audit_logs WHERE appointment_id = {$juan}");
    $conn->query("DELETE FROM appointment_status_history WHERE appointment_id = {$juan}");
    $conn->query("DELETE FROM appointments WHERE id = {$juan}");
    same((int) zz_value($conn, "SELECT COUNT(*) FROM visit_people WHERE appointment_id = {$juan}"), 0, "the people go when their visit is deleted");

    /* ---------- The visitor app on the website ---------- */

    $keyA = "ISATU-KEY-AAAAAA";
    $keyB = "OTHER-KEY-BBBBBB";
    $tmp = $dataDir;
    $info = app_release_inspect(zz_apk_file($tmp, zz_apk(APP_RELEASE_PACKAGE, 18, "0.13.0", $keyA)));
    same([$info["package"], $info["version_code"], $info["version_name"], $info["signature_scheme"]], [APP_RELEASE_PACKAGE, 18, "0.13.0", "v2"],
        "the app's package and version are read from its manifest");
    same($info["signing_sha256"], hash("sha256", str_repeat($keyA, 16)), "the signing certificate is read from the APK Signing Block");
    same(app_release_inspect(zz_apk_file($tmp, zz_apk(APP_RELEASE_PACKAGE, 18, "0.13.0", $keyA, true, 0xf05368c0)))["signature_scheme"], "v3",
        "a v3 signature is read too");
    check(str_contains((string) zz_throws(fn () => app_release_inspect(zz_apk_file($tmp, zz_apk(APP_RELEASE_PACKAGE, 18, "0.13.0", null)))), "signed"),
        "an unsigned app is refused");
    check(str_contains((string) zz_throws(fn () => app_release_inspect(zz_apk_file($tmp, zz_apk(APP_RELEASE_PACKAGE, 18, "0.13.0", $keyA, false)))), "Android app"),
        "a ZIP without app code is refused");
    check(zz_throws(fn () => app_release_inspect(zz_apk_file($tmp, "<?php echo 'not an app';")), InvalidArgumentException::class) !== null,
        "something that isn't an app is refused");

    $existing = (int) zz_value($conn, "SELECT COUNT(*) FROM app_releases WHERE status IN ('current', 'previous', 'checked')");
    if ($existing > 0) {
        fwrite(STDOUT, "Note: this database already has published app versions, so publishing isn't tried here.\n");
    } else {
        $upload = fn (string $apk) => app_release_check_upload($conn, $path = zz_apk_file($tmp, $apk), (int) filesize($path), $admin);
        $first = $upload(zz_apk(APP_RELEASE_PACKAGE, 18, "0.13.0", $keyA));
        same($first["release"]["status"], "checked", "a good file waits to be published");
        check(str_contains($first["checks"][2], "Every later version must use this same key"), "the first version sets the key every later one must have");
        check(is_file(app_release_dir() . "/" . zz_value($conn, "SELECT file_name FROM app_releases WHERE id = " . $first["release"]["id"])),
            "the file is kept in the private folder");
        same(app_release_current($conn), null, "nothing changes for visitors until it is published");
        $published = app_release_publish($conn, $first["release"]["id"], "  Bring people   with you. ", $admin);
        same([$published["version_name"], $published["whats_new"]], ["0.13.0", "Bring people with you."], "publishing makes it the version visitors get");
        check(str_contains((string) zz_throws(fn () => $upload(zz_apk(APP_RELEASE_PACKAGE, 17, "0.12.9", $keyA)), InvalidArgumentException::class), "isn't newer"),
            "an older version is refused");
        check(str_contains((string) zz_throws(fn () => $upload(zz_apk(APP_RELEASE_PACKAGE, 19, "0.14.0", $keyB)), InvalidArgumentException::class), "different key"),
            "a version signed with another key is refused");
        check(str_contains((string) zz_throws(fn () => $upload(zz_apk("com.example.other", 19, "0.14.0", $keyA)), InvalidArgumentException::class), "different app"),
            "another app is refused");
        $second = $upload(zz_apk(APP_RELEASE_PACKAGE, 19, "0.14.0", $keyA));
        check(str_contains($second["checks"][2], "same key"), "a newer version with the same key passes");
        app_release_publish($conn, $second["release"]["id"], "", $admin);
        same([app_release_current($conn)["version_name"], app_release_by_status($conn, "previous")["version_name"] ?? null], ["0.14.0", "0.13.0"],
            "the version before is kept, to go back to");
        app_release_go_back($conn, $admin);
        same([app_release_current($conn)["version_name"], app_release_by_status($conn, "previous")["version_name"] ?? null], ["0.13.0", "0.14.0"],
            "going back makes the earlier version current again");
        check(str_contains((string) zz_throws(fn () => $upload(zz_apk(APP_RELEASE_PACKAGE, 19, "0.14.0", $keyA)), InvalidArgumentException::class), "already here"),
            "the same file can't be uploaded twice");
        $third = $upload(zz_apk(APP_RELEASE_PACKAGE, 20, "0.15.0", $keyA));
        $thirdFile = app_release_dir() . "/" . zz_value($conn, "SELECT file_name FROM app_releases WHERE id = " . $third["release"]["id"]);
        app_release_discard($conn, $third["release"]["id"]);
        check(!is_file($thirdFile) && app_release_by_status($conn, "checked") === null, "a checked file can be dropped");
        app_release_count_download($conn, (int) app_release_current($conn)["id"]);
        same(app_release_stats($conn)["downloads"], 1, "downloads are counted");
        same(zz_value($conn, "SELECT COUNT(*) FROM audit_logs WHERE actor_user_id = {$admin} AND action IN ('app_release.published', 'app_release.went_back')"), "3",
            "publishing and going back are in the activity log");
        app_release_go_back($conn, $admin);
        same([app_release_current($conn)["version_name"], app_release_by_status($conn, "previous")["version_name"] ?? null], ["0.14.0", "0.13.0"],
            "the same button brings the newer version back");
        $wording = fn (string $version, string $from) => activity_describe([
            "entity_type" => "app_release", "entity_id" => "1", "action" => "app_release.went_back",
            "details_json" => json_encode(["version_name" => $version, "from_version" => $from]),
        ]);
        same($wording("0.13.0", "0.14.0")[1], "Went back to ISATU Visits 0.13.0 on the download page, instead of 0.14.0", "going back reads plainly");
        same($wording("0.14.0", "0.13.0"), ["Used the newer app again", "Brought back ISATU Visits 0.14.0 on the download page, instead of 0.13.0", "info"],
            "bringing the newer version back isn't called going back");
    }
} finally {
    zz_forget($conn);
    foreach (glob($dataDir . "/{,*/,*/*/}*", GLOB_BRACE) ?: [] as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }
    @rmdir($dataDir . "/private/apps");
    @rmdir($dataDir . "/private");
    @rmdir($dataDir);
    foreach (glob($dataDir . "/upload-*") ?: [] as $file) {
        @unlink($file);
    }
}

if ($failures > 0) {
    fwrite(STDERR, "Group visits: {$failures} of {$checks} checks failed\n");
    exit(1);
}
echo "Group visits: all {$checks} checks passed\n";
