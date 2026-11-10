<?php
/*
 * Offices people can choose from (booking forms, account forms, map pins). A visitor gets the offices
 * they can book: those with someone to answer (appointment_office_bookable_map()). Administrators can
 * add ?all=1 to include archived offices.
 */
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/session_bootstrap.php";
require_once __DIR__ . "/personnel.php";

require_login_json();

$role = (string) $_SESSION["role"];
$includeArchived = !empty($_GET["all"]) && auth_role_can($role, "departments.manage");
$active = appointment_office_active_map();
$map = $includeArchived ? appointment_office_map() : ($role === "visitor" ? appointment_office_bookable_map() : $active);

$locations = [];
if (personnel_schema_ready($conn)) {
    $result = $conn->query("SELECT code, location FROM offices");
    while ($row = $result->fetch_assoc()) {
        $locations[(string) $row["code"]] = (string) $row["location"];
    }
}

$offices = [];
foreach ($map as $code => $name) {
    $offices[] = [
        "code" => $code,
        "name" => $name,
        "location" => $locations[$code] ?? "",
        "is_active" => isset($active[$code]),
    ];
}
echo json_encode(["success" => true, "offices" => $offices]);
