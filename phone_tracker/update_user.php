<?php
/*
 * Admin edits who is behind an account: real name, position, contact details, and
 * (for Office Personnel) the department. Every change is written to the activity log
 * with its old and new value.
 */
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/session_bootstrap.php";
require_once __DIR__ . "/personnel.php";

auth_json_exception_guard();
auth_require_method("POST");
require_permission_json("users.manage");
auth_require_schema($conn);
personnel_require_schema($conn);

$input = auth_json_body();
$userId = (int) ($input["user_id"] ?? 0);
$teacherColumns = teacher_schema_ready($conn);
$stmt = $conn->prepare(
    "SELECT id, username, role, office_code, display_name, first_name, last_name, position, email, contact_number"
    . ($teacherColumns ? ", name_title" : "") . "
     FROM app_users WHERE id = ? LIMIT 1"
);
$stmt->bind_param("i", $userId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$user) {
    auth_json_error(404, "not_found", "User not found.");
}

$firstName = personnel_clean_text((string) ($input["first_name"] ?? ""), 60);
$lastName = personnel_clean_text((string) ($input["last_name"] ?? ""), 60);
$position = personnel_clean_text((string) ($input["position"] ?? ""), 100);
$email = strtolower(trim((string) ($input["email"] ?? "")));
$contactNumber = trim((string) ($input["contact_number"] ?? ""));
$officeCode = (string) $user["office_code"];
if ($user["role"] === "offices" && array_key_exists("office_code", $input)) {
    $officeCode = strtoupper(trim((string) $input["office_code"]));
}

// A teacher also has a title, a course and a room, which live on their calendar row.
$isTeacher = $user["role"] === "teacher";
$calendar = $isTeacher ? appointment_office_row((string) $user["office_code"]) : null;
$nameTitle = (string) ($user["name_title"] ?? "");
$courseCode = (string) ($calendar["parent_code"] ?? "");
$room = (string) ($calendar["location"] ?? "");
if ($isTeacher) {
    if (array_key_exists("name_title", $input)) {
        $nameTitle = trim((string) $input["name_title"]);
    }
    if (array_key_exists("course_code", $input)) {
        $courseCode = strtoupper(trim((string) $input["course_code"]));
    }
    if (array_key_exists("room", $input)) {
        $room = personnel_clean_text((string) $input["room"], 150);
    }
    if ($nameTitle !== "" && !in_array($nameTitle, TEACHER_TITLES, true)) {
        auth_json_error(422, "invalid_details", "Choose a title from the list.");
    }
    if (!isset(teacher_course_map()[$courseCode])) {
        auth_json_error(422, "invalid_details", "Select the course this teacher belongs to.");
    }
    if ($email === "") {
        auth_json_error(422, "invalid_details", "Enter the teacher's email. New visit requests are sent there.");
    }
}

$problem = personnel_name_error($firstName, "first name")
    ?: personnel_name_error($lastName, "last name")
    ?: personnel_email_error($email)
    ?: personnel_contact_error($contactNumber);
if ($problem !== "") {
    auth_json_error(422, "invalid_details", $problem);
}

$departmentChanged = $user["role"] === "offices" && $officeCode !== (string) $user["office_code"];
if ($departmentChanged) {
    if (!isset(appointment_office_active_map()[$officeCode])) {
        auth_json_error(422, "invalid_department", "Choose an active department.");
    }
    // Moving someone changes what they can see, so confirm it is really the admin.
    require_step_up_json();
}

$changes = [];
$before = [
    "first_name" => (string) $user["first_name"],
    "last_name" => (string) $user["last_name"],
    "position" => (string) $user["position"],
    "email" => strtolower((string) ($user["email"] ?? "")),
    "contact_number" => (string) $user["contact_number"],
    "office_code" => (string) $user["office_code"],
];
$after = [
    "first_name" => $firstName,
    "last_name" => $lastName,
    "position" => $position,
    "email" => $email,
    "contact_number" => $contactNumber,
    "office_code" => $officeCode,
];
if ($isTeacher) {
    $before += [
        "name_title" => (string) ($user["name_title"] ?? ""),
        "course_code" => (string) ($calendar["parent_code"] ?? ""),
        "room" => (string) ($calendar["location"] ?? ""),
    ];
    $after += ["name_title" => $nameTitle, "course_code" => $courseCode, "room" => $room];
}
foreach ($after as $field => $value) {
    if ($value !== $before[$field]) {
        $changes[$field] = ["from" => $before[$field], "to" => $value];
    }
}
if (!$changes) {
    auth_json(["success" => true, "message" => "Nothing changed."]);
}

$displayName = mb_substr($firstName . " " . $lastName, 0, 100, "UTF-8");
$emailValue = $email !== "" ? $email : null;
$sql = "UPDATE app_users
        SET first_name = ?, last_name = ?, position = ?, email = ?, contact_number = ?,
            display_name = ?, office_code = ?" . ($departmentChanged ? ", session_version = session_version + 1" : "") . "
        WHERE id = ?";
$update = $conn->prepare($sql);
$update->bind_param("sssssssi", $firstName, $lastName, $position, $emailValue, $contactNumber, $displayName, $officeCode, $userId);
$conn->begin_transaction();
try {
    $update->execute();
    if ($isTeacher) {
        $saveTitle = $conn->prepare("UPDATE app_users SET name_title = ? WHERE id = ?");
        $saveTitle->bind_param("si", $nameTitle, $userId);
        $saveTitle->execute();
        $saveTitle->close();
        teacher_update_calendar($conn, $officeCode, $nameTitle, $firstName, $lastName, $courseCode, $room);
    }
    $conn->commit();
} catch (mysqli_sql_exception $error) {
    $conn->rollback();
    if ($error->getCode() === 1062) {
        auth_json_error(409, "email_taken", "Another account already uses this email.");
    }
    throw $error;
}
$update->close();

if (isset($changes["office_code"])) {
    $changes["office_code"]["from_label"] = appointment_office_label($changes["office_code"]["from"]);
    $changes["office_code"]["to_label"] = appointment_office_label($changes["office_code"]["to"]);
}
auth_audit($conn, (int) $_SESSION["user_id"], "user.updated", "app_user", (string) $userId, [
    "username" => (string) $user["username"],
    "name" => $displayName,
    "changes" => $changes,
]);

auth_json([
    "success" => true,
    "message" => $departmentChanged
        ? "Saved. " . $displayName . " was moved to " . appointment_office_label($officeCode) . " and must sign in again."
        : "Saved.",
]);
