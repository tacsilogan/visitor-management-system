<?php
declare(strict_types=1);
require_once dirname(__DIR__) . "/_bootstrap.php";

api_require_method("POST");
$input = api_body();
$email = strtolower(api_text($input, "email", 190));
$fullName = api_text($input, "full_name", 100);
$contactNumber = api_text($input, "contact_number", 32);
$password = (string) ($input["password"] ?? "");
$trackingConsent = !empty($input["tracking_consent"]);
$consentVersion = api_text($input, "consent_version", 32);

api_rate_limit($conn, "register", $email, 5, 3600);
$errors = [];
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors["email"] = "Enter a valid email address";
}
if (strlen($fullName) < 2) {
    $errors["full_name"] = "Enter your full name";
}
$passwordError = api_password_validation_error($password);
if ($passwordError !== "") {
    $errors["password"] = $passwordError;
}
if (!$trackingConsent || $consentVersion === "") {
    $errors["tracking_consent"] = "Agree to the visitor tracking consent before creating an account";
}
if ($errors) {
    api_fail("Check the highlighted account details", 422, $errors);
}

$existing = $conn->prepare("SELECT id FROM app_users WHERE email = ? LIMIT 1");
if (!$existing) {
    api_fail("Mobile API database migration is required", 503);
}
$existing->bind_param("s", $email);
$existing->execute();
$duplicate = $existing->get_result()->fetch_assoc();
$existing->close();
if ($duplicate) {
    api_fail("An account already uses this email address", 409, ["email" => "Email is already registered"]);
}

$passwordHash = password_hash($password, PASSWORD_DEFAULT);
if (!$passwordHash) {
    api_fail("Could not secure the password", 500);
}

$conn->begin_transaction();
try {
    do {
        $username = "visitor_" . substr(bin2hex(random_bytes(10)), 0, 16);
        $check = $conn->prepare("SELECT id FROM app_users WHERE username = ? LIMIT 1");
        $check->bind_param("s", $username);
        $check->execute();
        $taken = (bool) $check->get_result()->fetch_assoc();
        $check->close();
    } while ($taken);

    $insert = $conn->prepare(
        "INSERT INTO app_users
         (username, email, password_hash, display_name, contact_number, email_verified_at, role, office_code, is_active)
         VALUES (?, ?, ?, ?, ?, NOW(), 'visitor', '', 1)"
    );
    $insert->bind_param("sssss", $username, $email, $passwordHash, $fullName, $contactNumber);
    $insert->execute();
    $userId = (int) $conn->insert_id;
    $insert->close();
    api_audit($conn, $userId, "mobile.account_registered", "app_user", (string) $userId, [
        "email" => $email,
        "tracking_consent" => true,
        "consent_version" => $consentVersion,
    ]);
    $conn->commit();
} catch (Throwable $error) {
    $conn->rollback();
    api_fail("Could not create the visitor account", 500, [], $error);
}

$data = [
    "user" => [
        "id" => $userId,
        "email" => $email,
        "full_name" => $fullName,
        "contact_number" => $contactNumber,
        "email_verified" => true,
        "role" => "visitor",
    ],
    "email_verification_required" => false,
];
api_success($data, 201, "Account created. You can now sign in.");
