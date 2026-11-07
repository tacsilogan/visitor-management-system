<?php
/*
 * A read-only list for Office Personnel in a department that has teachers: is a teacher available,
 * for when someone asks at the office. It never says why, shows no visitors, and changes nothing:
 * each teacher manages their own hours and visitors.
 */
header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store, max-age=0");

require_once __DIR__ . "/session_bootstrap.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/teacher_service.php";
require_once __DIR__ . "/school_accounts_service.php";

require_permission_json("office.dashboard");

$code = strtoupper(trim((string) ($_SESSION["office_code"] ?? "")));
$chain = appointment_office_chain($code);
$departmentCode = $chain[count($chain) - 1];
$department = appointment_office_row($departmentCode);
if (!teacher_schema_ready($conn) || ($department["kind"] ?? "") !== "department") {
    echo json_encode(["success" => true, "applies" => false, "teachers" => []]);
    exit;
}

$now = new DateTimeImmutable("now");
// The Dean's Office approves the department's teachers, so it also turns their accounts off and on.
$approver = school_approver_department($conn, $code) === $departmentCode;
$teachers = [];
foreach (appointment_office_directory()["rows"] as $row) {
    if ($row["kind"] !== "teacher" || !$row["is_active"] || (!$row["owner_active"] && !$approver)) {
        continue;
    }
    $teacherChain = appointment_office_chain($row["code"]);
    if ($teacherChain[count($teacherChain) - 1] !== $departmentCode) {
        continue;
    }
    $status = "no_hours";
    $until = null;
    $nextFree = null;
    if (!$row["owner_active"]) {
        $status = "off";
    } elseif ($row["has_hours"]) {
        $state = office_open_state($conn, $row["code"], $now);
        $status = $state["state"] === "open" ? "available" : "not_available";
        $until = $status === "available" ? $state["until"] : null;
        if ($status === "not_available") {
            $nextFree = teacher_free_slots($conn, $row["code"], $now, 1)[0]["start"] ?? null;
        }
    }
    $teachers[] = [
        "name" => $row["name"],
        "course" => $teacherChain[1] ?? "",
        "status" => $status,
        "until" => $until,
        "next_free_at" => $nextFree,
    ] + ($approver ? ["user_id" => $row["owner_user_id"], "account_active" => $row["owner_active"]] : []);
}
usort($teachers, fn(array $a, array $b): int => [$a["course"], $a["name"]] <=> [$b["course"], $b["name"]]);

echo json_encode([
    "success" => true,
    "applies" => true,
    "approver" => $approver,
    "department" => (string) $department["name"],
    "teachers" => $teachers,
    "server_time" => $now->format("Y-m-d H:i:s"),
]);
