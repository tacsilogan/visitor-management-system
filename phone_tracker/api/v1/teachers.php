<?php
declare(strict_types=1);
require_once __DIR__ . "/_bootstrap.php";
require_once dirname(__DIR__, 2) . "/appointment_offices.php";
require_once dirname(__DIR__, 2) . "/teacher_service.php";

// The teachers a visitor can find: only those who saved their hours. Each one says whether they can
// be seen now, or their next free time, and never why. Their room is not here: it comes with a pass.
// GET            every teacher
// GET ?code=T12  one teacher, with their next free times and where else the visitor can go

api_require_method("GET");
api_require_app_user();
$now = new DateTimeImmutable("now");
$teachers = appointment_teacher_bookable_map();

$code = strtoupper(trim((string) ($_GET["code"] ?? "")));
if ($code !== "") {
    if (!isset($teachers[$code])) {
        api_fail("This teacher can't be booked right now", 404);
    }
    api_success([
        "teacher" => teacher_public($conn, $code, $now, max(1, min(8, (int) ($_GET["slots"] ?? 4)))),
        "timezone" => "Asia/Manila",
        "server_time" => $now->format("Y-m-d H:i:s"),
    ]);
}

$list = [];
foreach (array_keys($teachers) as $teacherCode) {
    $list[] = teacher_public($conn, $teacherCode, $now);
}
usort($list, fn(array $a, array $b): int => [$a["course"], $a["name"]] <=> [$b["course"], $b["name"]]);
api_success([
    "teachers" => $list,
    "courses" => array_values(array_unique(array_column($list, "course"))),
    "timezone" => "Asia/Manila",
    "server_time" => $now->format("Y-m-d H:i:s"),
]);
