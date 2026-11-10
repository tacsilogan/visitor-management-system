<?php
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/session_bootstrap.php";
require_once __DIR__ . "/personnel.php";

require_permission_json("users.manage");
auth_require_schema($conn);
personnel_require_schema($conn);

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode(["success" => false, "message" => "Method not allowed"]);
    exit;
}

$input = json_decode(file_get_contents("php://input"), true);
if (!is_array($input)) {
    echo json_encode(["success" => false, "message" => "Invalid body"]);
    exit;
}

$username = isset($input["username"]) ? trim((string) $input["username"]) : "";
$firstName = personnel_clean_text((string) ($input["first_name"] ?? ""), 60);
$lastName = personnel_clean_text((string) ($input["last_name"] ?? ""), 60);
$position = personnel_clean_text((string) ($input["position"] ?? ""), 100);
$email = strtolower(trim((string) ($input["email"] ?? "")));
$contactNumber = trim((string) ($input["contact_number"] ?? ""));
$role = isset($input["role"]) ? (string) $input["role"] : "";
$officeCode = isset($input["office_code"]) ? strtoupper(trim((string) $input["office_code"])) : "";

// Visitors sign up in the app themselves, with a code sent to their email.
$allowed = ["security", "offices", "admin"];
if ($username === "" || !in_array($role, $allowed, true)) {
    echo json_encode(["success" => false, "message" => "Username and a valid role are required"]);
    exit;
}

$problem = personnel_name_error($firstName, "first name")
    ?: personnel_name_error($lastName, "last name")
    ?: personnel_email_error($email)
    ?: personnel_contact_error($contactNumber);
if ($problem !== "") {
    echo json_encode(["success" => false, "message" => $problem]);
    exit;
}

if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._@-]{2,63}$/', $username)) {
    echo json_encode(["success" => false, "message" => "Username must be 3–64 characters: letters, numbers, dots, dashes, underscores, or @"]);
    exit;
}

if ($role === "offices") {
    $offices = appointment_office_active_map();
    if (!isset($offices[$officeCode])) {
        echo json_encode(["success" => false, "message" => "Select an active office for this person"]);
        exit;
    }
} else {
    $officeCode = "";
}

// Creating another administrator is a sensitive action.
if ($role === "admin") {
    require_step_up_json();
}

// The admin never chooses or sees a lasting password: the user receives a one-time
// password that expires and must replace it at their first sign-in.
$temporaryPassword = auth_generate_temporary_password();
$hash = password_hash($temporaryPassword, PASSWORD_DEFAULT);
$hours = AUTH_TEMP_PASSWORD_HOURS;
$displayName = mb_substr($firstName . " " . $lastName, 0, 100, "UTF-8");
$emailValue = $email !== "" ? $email : null;

$stmt = $conn->prepare(
    "INSERT INTO app_users
     (username, email, password_hash, display_name, first_name, last_name, position, contact_number,
      role, office_code, must_change_password, temp_password_expires_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW() + INTERVAL {$hours} HOUR)"
);
$stmt->bind_param("ssssssssss", $username, $emailValue, $hash, $displayName, $firstName, $lastName, $position, $contactNumber, $role, $officeCode);
try {
    $stmt->execute();
} catch (mysqli_sql_exception $error) {
    $stmt->close();
    $message = "Could not create user";
    if ($error->getCode() === 1062) {
        $message = str_contains($error->getMessage(), "email") ? "Another account already uses this email" : "Username already exists";
    }
    echo json_encode(["success" => false, "message" => $message]);
    exit;
}

$newId = (int) $conn->insert_id;
$stmt->close();

$adminId = (int) $_SESSION["user_id"];
auth_audit($conn, $adminId, "user.created", "app_user", (string) $newId, [
    "username" => $username,
    "name" => $displayName,
    "role" => $role,
    "office_code" => $officeCode,
]);
$expires = $conn->query("SELECT temp_password_expires_at FROM app_users WHERE id = " . $newId)->fetch_row()[0];

echo json_encode([
    "success" => true,
    "user" => [
        "id" => $newId,
        "username" => $username,
        "display_name" => $displayName,
        "first_name" => $firstName,
        "last_name" => $lastName,
        "role" => $role,
        "office_code" => $officeCode,
        "is_active" => 1,
    ],
    "temporary_password" => $temporaryPassword,
    "expires_at" => $expires,
    "two_factor_required" => auth_two_factor_required_for($role),
]);
