<?php
header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store");

require_once __DIR__ . "/session_bootstrap.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/gate_service.php";

// What the "Register at the gate" form needs (GATE_REGISTRATION.md): the offices a visitor can be
// registered for right now, the purposes, the ID types and the notice the guard reads out.

require_permission_json("visits.scan");

echo json_encode([
    "success" => true,
    "ready" => gate_schema_ready($conn),
    "offices" => gate_office_states($conn),
    "purposes" => GATE_PURPOSES,
    "id_types" => GATE_ID_TYPES,
    "notice" => ["version" => GATE_NOTICE_VERSION, "text" => GATE_NOTICE_TEXT],
]);
$conn->close();
