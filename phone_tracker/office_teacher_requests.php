<?php
/*
 * For the Office Personnel of a department's Dean's Office: the teacher account requests of their own
 * department, and turning a teacher's account off (they left) or back on (SCHOOL_ACCOUNTS.md).
 * Any other office gets {approver: false} and can change nothing.
 *
 * GET                                          {approver, department, courses, requests}
 * POST {action: "approve", request_id, course_code}
 * POST {action: "reject", request_id, reason}
 * POST {action: "turn_off" | "turn_on", user_id}
 */
header("Content-Type: application/json; charset=utf-8");
require_once __DIR__ . "/session_bootstrap.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/school_accounts_service.php";

require_permission_json("office.dashboard");

$officeCode = strtoupper(trim((string) ($_SESSION["office_code"] ?? "")));
$actorId = (int) $_SESSION["user_id"];
$departmentCode = school_approver_department($conn, $officeCode);
if ($departmentCode === "") {
    if (strtoupper((string) $_SERVER["REQUEST_METHOD"]) !== "GET") {
        http_response_code(403);
        echo json_encode(["success" => false, "message" => "Only the Dean's Office approves teachers."]);
        exit;
    }
    echo json_encode(["success" => true, "approver" => false]);
    exit;
}

if (strtoupper((string) $_SERVER["REQUEST_METHOD"]) === "GET") {
    $courses = [];
    foreach (appointment_office_directory()["rows"] as $row) {
        if ($row["kind"] === "course" && $row["parent_code"] === $departmentCode && $row["is_active"]) {
            $courses[] = $row["code"];
        }
    }
    echo json_encode([
        "success" => true,
        "approver" => true,
        "department" => $departmentCode,
        "department_name" => appointment_office_label($departmentCode),
        "courses" => $courses,
        "requests" => school_pending_teacher_requests($conn, $departmentCode),
    ]);
    exit;
}

$input = json_decode((string) file_get_contents("php://input"), true);
$input = is_array($input) ? $input : [];
$action = (string) ($input["action"] ?? "");

$conn->begin_transaction();
try {
    switch ($action) {
        case "approve":
            $made = school_approve_teacher_request(
                $conn,
                (int) ($input["request_id"] ?? 0),
                $departmentCode,
                (string) ($input["course_code"] ?? ""),
                $actorId
            );
            $message = "Approved. " . $made["name"] . " can sign in now with the password they chose.";
            $reply = ["user_id" => $made["user_id"], "username" => $made["username"]];
            break;
        case "reject":
            school_reject_teacher_request(
                $conn,
                (int) ($input["request_id"] ?? 0),
                $departmentCode,
                mb_substr(trim((string) ($input["reason"] ?? "")), 0, 255, "UTF-8"),
                $actorId
            );
            $message = "Rejected. They'll get an email with the reason.";
            $reply = [];
            break;
        case "turn_off":
        case "turn_on":
            $result = school_set_teacher_active($conn, (int) ($input["user_id"] ?? 0), $departmentCode, $action === "turn_on", $actorId);
            $message = $action === "turn_on"
                ? "Turned on. Students and visitors can book them again."
                : "Turned off." . ($result["cancelled_visits"] > 0
                    ? " " . $result["cancelled_visits"] . ($result["cancelled_visits"] === 1 ? " open visit was" : " open visits were") . " cancelled, and each person was told."
                    : "");
            $reply = $result;
            break;
        default:
            throw new SchoolAccountError("Unknown action.", "bad_action", 400);
    }
    $conn->commit();
} catch (SchoolAccountError $error) {
    $conn->rollback();
    http_response_code($error->status);
    echo json_encode(["success" => false, "message" => $error->getMessage(), "code" => $error->reason]);
    exit;
} catch (Throwable $error) {
    $conn->rollback();
    error_log("office_teacher_requests " . $action . " failed: " . $error->getMessage());
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Could not save. Please try again."]);
    exit;
}
// After the commit, so the approval email goes out now if email is set up.
if ($action === "approve" || $action === "reject") {
    try {
        if (mail_configuration_problem() === null) {
            mail_process_queue($conn, mail_settings(), 5);
        }
    } catch (Throwable $error) {
        // It stays queued for the next run.
    }
}
echo json_encode(["success" => true, "message" => $message] + $reply);
