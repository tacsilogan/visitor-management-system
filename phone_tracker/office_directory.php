<?php
/*
 * Offices people can choose from. A visitor gets the offices they can book. Staff get the offices
 * and courses people can be assigned to. Administrators can add ?all=1 for everything that can have
 * a map pin or directions: departments, offices and courses, archived ones included. A teacher is
 * never listed here, because a teacher uses their course's pin and directions.
 */
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/session_bootstrap.php";
require_once __DIR__ . "/personnel.php";

require_login_json();

$directory = appointment_office_directory();
$role = (string) $_SESSION["role"];
$includeAll = !empty($_GET["all"]) && auth_role_can($role, "departments.manage");
if ($includeAll) {
    $map = array_filter($directory["all"], fn(string $code): bool => ($directory["rows"][$code]["kind"] ?? "office") !== "teacher", ARRAY_FILTER_USE_KEY);
} else {
    $map = $role === "visitor" ? $directory["bookable"] : $directory["active"];
}

$offices = [];
foreach ($map as $code => $name) {
    $row = $directory["rows"][$code] ?? [];
    $offices[] = [
        "code" => $code,
        "name" => $name,
        "kind" => (string) ($row["kind"] ?? "office"),
        "parent_code" => $row["parent_code"] ?? null,
        "location" => (string) ($row["location"] ?? ""),
        "is_active" => (bool) ($row["is_active"] ?? true),
        // Office Personnel can be assigned here. A department only holds its offices and courses.
        "assignable" => isset($directory["active"][$code]),
    ];
}
echo json_encode(["success" => true, "offices" => $offices]);
