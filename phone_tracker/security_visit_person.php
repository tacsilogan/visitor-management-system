<?php
header("Content-Type: application/json; charset=utf-8");
require_once __DIR__ . "/session_bootstrap.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/visit_people_service.php";

// Security acts on one person who came with a visitor (GROUP_VISITS.md), from Security Home:
//   {"person_id": n, "action": "left"}: they left before the rest of the group, who stay checked in.
//   {"person_id": n, "action": "check_in", "id_type": "Driver's license" | "none", "no_id_reason": "…"}:
//       they missed the group at the gate and came later; the guard checked their ID.
//   {"person_id": n, "action": "refuse"}: they came later, but the name on their ID didn't match.

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode(["success" => false, "message" => "Method not allowed"]);
    exit;
}

require_permission_json("visits.scan");
$input = json_decode(file_get_contents("php://input"), true);
if (!is_array($input)) {
    echo json_encode(["success" => false, "message" => "Invalid request body"]);
    exit;
}
if (!visit_people_schema_ready($conn)) {
    echo json_encode(["success" => false, "message" => "Database update required: import phone_tracker/group_visits_migration.sql."]);
    exit;
}

$guardUserId = (int) $_SESSION["user_id"];
$personId = (int) ($input["person_id"] ?? 0);
$action = (string) ($input["action"] ?? "");

try {
    if ($action === "left") {
        $person = visit_people_mark_left($conn, $personId, $guardUserId);
        $message = $person["full_name"] . " left at " . date("g:i A", strtotime((string) $person["left_at"]))
            . ". " . $person["visitor_full_name"] . " and the others stay checked in.";
    } elseif ($action === "check_in") {
        $idType = (string) ($input["id_type"] ?? "");
        $person = visit_people_late_arrival($conn, $personId, $idType, (string) ($input["no_id_reason"] ?? ""), $guardUserId);
        $message = $person["full_name"] . " joined " . $person["visitor_full_name"] . "'s group at " . date("g:i A") . ".";
    } elseif ($action === "refuse") {
        $person = visit_people_late_arrival($conn, $personId, null, "", $guardUserId);
        $message = $person["full_name"] . " was not let in. Written in the activity log.";
    } else {
        throw new DomainException("Choose what to do.");
    }
} catch (DomainException $error) {
    $conn->close();
    echo json_encode(["success" => false, "message" => $error->getMessage()]);
    exit;
} catch (Throwable $error) {
    error_log("security_visit_person.php: " . $error->getMessage());
    $conn->close();
    echo json_encode(["success" => false, "message" => "Could not save that. Try again."]);
    exit;
}

$conn->close();
echo json_encode(["success" => true, "message" => $message, "person" => visit_people_search_payload($person)]);
