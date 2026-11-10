<?php
declare(strict_types=1);
require_once __DIR__ . "/_signup.php";

// Starts a new visitor account and emails a 6-digit code to the address. Nothing is created until the
// code is entered (confirm_signup.php), so an address nobody can open never becomes an account.
// POST {email, full_name, password, contact_number?, consent_version, tracking_consent}
// 202 {verification_required, request_id, email, expires_at, resend_after_seconds}

api_require_method("POST");
api_require_signup_schema($conn);
$input = api_body();
// Apps from 0.8.0 to 0.10.x also send account_type, and could ask for an ISATU student account.
$accountType = strtolower(api_text($input, "account_type", 16)) ?: "visitor";
if ($accountType !== "visitor") {
    $message = "Only visitor accounts can be created. Update the ISATU Visits app, then sign up as a visitor.";
    api_fail($message, 422, ["account_type" => $message]);
}
$email = strtolower(api_text($input, "email", 190));
$fullName = api_text($input, "full_name", 100);
$contactNumber = api_text($input, "contact_number", 32);
$password = (string) ($input["password"] ?? "");
$trackingConsent = !empty($input["tracking_consent"]);
$consentVersion = api_text($input, "consent_version", 32);

// Per address, and per network so one phone can't start hundreds of sign-ups.
api_rate_limit($conn, "register", $email, 5, 3600);
api_rate_limit($conn, "register_network", "", 30, 3600);

$errors = [];
$emailProblem = signup_email_problem($email);
if ($emailProblem !== "") {
    $errors["email"] = $emailProblem;
}
if (mb_strlen($fullName) < 2) {
    $errors["full_name"] = "Enter your full name";
}
$passwordError = api_password_validation_error($password);
if ($passwordError !== "") {
    $errors["password"] = $passwordError;
}
if ($consentVersion === "") {
    $errors["consent_version"] = "Agree to the terms and privacy notice first";
}
if (!$trackingConsent) {
    $errors["tracking_consent"] = "Agree to the visitor tracking consent before creating an account";
}
if ($errors) {
    api_fail("Check the highlighted account details", 422, $errors);
}

$passwordHash = password_hash($password, PASSWORD_DEFAULT);
if (!$passwordHash) {
    api_fail("Could not secure the password", 500);
}

try {
    $requestId = signup_save_request($conn, [
        "email" => $email,
        "password_hash" => $passwordHash,
        "full_name" => $fullName,
        "contact_number" => $contactNumber,
        "consent_version" => $consentVersion,
        "requested_ip" => api_client_ip(),
    ]);
    $sent = signup_send_code($conn, $requestId);
} catch (SignupError $error) {
    api_signup_fail($error);
} catch (Throwable $error) {
    api_fail("Could not start the account. Please try again.", 500, [], $error);
}

api_success(api_signup_code_reply($sent), 202, "We sent a 6-digit code to " . $email . ".");
