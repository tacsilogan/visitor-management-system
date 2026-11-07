<?php
/**
 * Checks the rules for visits to a teacher without a database:
 *
 *   php phone_tracker/tools/teacher_visits_test.php
 *
 * Exits with 1 and lists what failed when a check does not hold.
 */
declare(strict_types=1);

date_default_timezone_set("Asia/Manila");
require_once dirname(__DIR__) . "/appointment_offices.php";
require_once dirname(__DIR__) . "/office_availability_service.php";
require_once dirname(__DIR__) . "/teacher_service.php";
require_once dirname(__DIR__) . "/web_push_service.php";
require_once dirname(__DIR__) . "/mail_service.php";

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

/** @return array one directory row */
function row(string $code, string $kind, ?string $parent, array $extra = []): array
{
    return appointment_office_row_defaults(["code" => $code, "name" => $extra["name"] ?? $code, "kind" => $kind, "parent_code" => $parent] + $extra);
}

/* ---------- Who a visitor can choose ---------- */
$directory = appointment_office_arrange([
    row("CCI", "department", null, ["has_staff" => 0]),
    row("IT", "course", "CCI", ["has_staff" => 1]),
    row("IS", "course", "CCI", ["has_staff" => 0]),
    row("DEANS", "office", "CCI", ["has_staff" => 1]),
    row("TECH", "office", "CCI", ["has_staff" => 0]),
    row("REG", "office", null, ["has_staff" => 1]),
    row("OLD", "office", null, ["has_staff" => 1, "is_active" => 0]),
    row("T1", "teacher", "IT", ["owner_active" => 1, "has_hours" => 1]),
    row("T2", "teacher", "IT", ["owner_active" => 1, "has_hours" => 0]),
    row("T3", "teacher", "IT", ["owner_active" => 0, "has_hours" => 1]),
]);
same(array_keys($directory["bookable"]), ["DEANS", "REG", "IT"], "visitors see staffed offices first, then a course with its own staff");
same(array_keys($directory["active"]), ["IT", "IS", "DEANS", "TECH", "REG"], "staff can be assigned to any office or course, staffed or not");
same(array_keys($directory["teachers"]), ["T1"], "only an active teacher with saved hours is listed");
same(isset($directory["all"]["OLD"]) && isset($directory["all"]["T2"]), true, "old records keep their names");
$archivedParent = appointment_office_arrange([
    row("CCI", "department", null, ["is_active" => 0]),
    row("DEANS", "office", "CCI", ["has_staff" => 1]),
    row("T1", "teacher", "DEANS", ["owner_active" => 1, "has_hours" => 1]),
]);
same($archivedParent["bookable"], [], "an archived department takes its offices out of use");
same($archivedParent["teachers"], [], "and its teachers too");

/* ---------- A teacher's week, in the shape the office rules store ---------- */
$schedules = teacher_schedules_from_windows([
    ["day_of_week" => 1, "windows" => [["start" => "13:30", "end" => "15:30"], ["start" => "09:00", "end" => "11:30"]]],
    ["day_of_week" => 2, "windows" => []],
]);
same(count($schedules), 1, "a day with no times is closed");
same($schedules[0]["start_time"], "09:00", "the day starts at its first time, in any order");
same($schedules[0]["end_time"], "15:30", "and ends at its last");
same($schedules[0]["breaks"], [["start_time" => "11:30", "end_time" => "13:30", "label" => "Break"]], "the gap is a break");
$week = office_normalize_week($schedules);
same($week[1]["windows"], [[540, 690], [810, 930]], "the office rules give back the same two times");

$threw = function (array $days) {
    try {
        teacher_schedules_from_windows($days);
        return "";
    } catch (RuntimeException $error) {
        return $error->getMessage();
    }
};
check(str_contains($threw([["day_of_week" => 1, "windows" => [["start" => "09:00", "end" => "11:00"], ["start" => "10:00", "end" => "12:00"]]]]), "overlap"), "overlapping times are refused");
check(str_contains($threw([["day_of_week" => 3, "windows" => [["start" => "11:00", "end" => "09:00"]]]]), "Wednesday"), "an end before its start names the day");
check($threw([["day_of_week" => 9, "windows" => []]]) !== "", "an unknown day is refused");

/* ---------- Slots ---------- */
same(teacher_day_slots([[540, 660]], 30), [[540, 570], [570, 600], [600, 630], [630, 660]], "slots start on the window's boundaries");
same(teacher_day_slots([[540, 600]], 60), [[540, 600]], "a slot can fill a window");
same(teacher_day_slots([[540, 590]], 60), [], "a window too short for a slot gives none");

$slots = teacher_day_slots([[540, 720]], 30);
same(teacher_walk_in_candidates($slots, 550)[0], [540, 570], "a slot with enough time left can be used");
same(teacher_walk_in_candidates($slots, 560)[0], [570, 600], "one about to end is too late to walk over for");
same(teacher_walk_in_candidates($slots, 480), [[540, 570]], "before hours, only what starts within the hour");
same(teacher_walk_in_candidates($slots, 730), [], "after hours there is nothing");
same(teacher_walk_in_candidates(teacher_day_slots([[540, 600]], 15), 595), [], "a short slot still needs half its time left");
same(teacher_walk_in_candidates(teacher_day_slots([[540, 600]], 15), 588)[0], [585, 600], "and half is enough");

/* ---------- Names ---------- */
same(teacher_display_name("Prof.", "Elena", "Reyes"), "Prof. Elena Reyes", "a title comes first");
same(teacher_display_name("", "Elena", "Reyes"), "Elena Reyes", "no title leaves no gap");

/* ---------- Phone alerts ---------- */
check(web_push_allowed_endpoint("https://fcm.googleapis.com/fcm/send/abc"), "Chrome's push service is allowed");
check(web_push_allowed_endpoint("https://web.push.apple.com/abc"), "Safari's is allowed");
check(web_push_allowed_endpoint("https://updates.push.services.mozilla.com/wpush/v2/abc"), "Firefox's is allowed");
check(!web_push_allowed_endpoint("http://fcm.googleapis.com/fcm/send/abc"), "plain http is refused");
check(!web_push_allowed_endpoint("https://localhost/admin"), "this server can't be made to call itself");
check(!web_push_allowed_endpoint("https://fcm.googleapis.com.evil.example/x"), "a look-alike host is refused");
check(!web_push_allowed_endpoint("https://evilpush.apple.com.example/x"), "another look-alike is refused");

try {
    $keys = web_push_generate_keys();
    $token = web_push_token("https://fcm.googleapis.com/fcm/send/abc", $keys, 1_000_000);
    $parts = explode(".", $token);
    same(count($parts), 3, "the token has three parts");
    same(strlen(web_push_decode($parts[2])), 64, "its signature is two 32-byte numbers");
    same(strlen(web_push_decode($keys["public"])), 65, "the public key is one uncompressed point");
    $claims = json_decode(web_push_decode($parts[1]), true);
    same($claims["aud"], "https://fcm.googleapis.com", "it is only good for that push service");
    same($claims["exp"], 1_000_000 + 43200, "and for 12 hours");
    // A real check of the signature, the way a push service does it.
    $publicRaw = web_push_decode($keys["public"]);
    $publicDer = hex2bin("3059301306072a8648ce3d020106082a8648ce3d030107034200") . $publicRaw;
    $publicPem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($publicDer), 64, "\n") . "-----END PUBLIC KEY-----\n";
    $raw = web_push_decode($parts[2]);
    $number = function (string $bytes): string {
        $bytes = ltrim($bytes, "\0");
        if ($bytes === "" || (ord($bytes[0]) & 0x80)) {
            $bytes = "\0" . $bytes;
        }
        return "\x02" . chr(strlen($bytes)) . $bytes;
    };
    $body = $number(substr($raw, 0, 32)) . $number(substr($raw, 32));
    $der = "\x30" . chr(strlen($body)) . $body;
    same(openssl_verify($parts[0] . "." . $parts[1], $der, $publicPem, OPENSSL_ALGO_SHA256), 1, "the signature verifies against the public key");
} catch (RuntimeException $error) {
    check(false, "phone alert keys: " . $error->getMessage());
}

/* ---------- Emails ---------- */
$mail = mail_render("teacher_walk_in", ["teacher" => "Prof. Reyes", "visitor" => "Juan <b>Cruz</b>", "purpose" => "Academic Concern", "subject" => "Thesis", "link" => "https://x.test/teacher.html"], "ignored");
check(str_contains($mail["text"], "Hello Prof. Reyes,"), "the email greets the teacher");
check(str_contains($mail["text"], "closes in 10 minutes"), "a walk-in says how long there is");
check(str_contains($mail["text"], "https://x.test/teacher.html"), "and how to answer");
check(!str_contains($mail["html"], "<b>Cruz"), "visitor text can't add HTML");
$request = mail_render("teacher_request", ["teacher" => "Prof. Reyes", "visitor" => "Maria", "when" => "Friday, November 6 at 10:00 AM", "purpose" => "", "subject" => ""], "");
check(str_contains($request["text"], "Maria asked to see you on Friday, November 6 at 10:00 AM."), "a request says when");
check(!str_contains($request["text"], "Reason:"), "no reason line without a reason");
check(!str_contains($request["text"], ": \n"), "no empty link line without a link");

echo "{$checks} checks, {$failures} failed\n";
exit($failures > 0 ? 1 : 0);
