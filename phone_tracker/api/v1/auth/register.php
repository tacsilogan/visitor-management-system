<?php
declare(strict_types=1);
require_once __DIR__ . "/_signup.php";

// Starts a new app account and emails a 6-digit code to the address. Nothing is created until the
// code is entered (confirm_signup.php), so an address nobody can open never becomes an account.
// POST {account_type: "visitor" | "student", email, full_name, password, consent_version,
//       visitor: contact_number?, tracking_consent
//       student: student_number, course_code or course_name, year_level, section}
// 202 {verification_required, request_id, email, expires_at, resend_after_seconds}

api_require_method("POST");
api_require_school_schema($conn);
$input = api_body();
$accountType = strtolower(api_text($input, "account_type", 16)) ?: "visitor";
if (!in_array($accountType, ["visitor", "student"], true)) {
    api_fail("Choose visitor or ISATU student", 422, ["account_type" => "Choose visitor or ISATU student"]);
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
$emailProblem = school_email_problem($accountType, $email);
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
if ($accountType === "visitor" && !$trackingConsent) {
    $errors["tracking_consent"] = "Agree to the visitor tracking consent before creating an account";
}

$student = [];
if ($accountType === "student") {
    $studentNumber = api_text($input, "student_number", 20);
    $numberProblem = school_student_number_problem($studentNumber);
    if ($numberProblem !== "") {
        $errors["student_number"] = $numberProblem;
    }
    $courseCode = strtoupper(api_text($input, "course_code", 16));
    $courseName = api_text($input, "course_name", 100);
    if ($courseCode !== "") {
        $course = appointment_office_row($courseCode);
        if (!$course || $course["kind"] !== "course" || !$course["is_active"]) {
            $errors["course_code"] = "Choose your course from the list";
        }
        $courseName = "";
    } elseif (mb_strlen($courseName) < 2) {
        $errors["course_code"] = "Choose your course, or type it if it isn't listed";
    }
    $yearLevel = (int) ($input["year_level"] ?? 0);
    if (!in_array($yearLevel, SCHOOL_YEAR_LEVELS, true)) {
        $errors["year_level"] = "Choose your year";
    }
    $section = strtoupper(api_text($input, "section", 10));
    if ($section === "" || !preg_match('/^[A-Z0-9]{1,10}$/', $section)) {
        $errors["section"] = "Enter your section, like A or 1";
    }
    $student = [
        "student_number" => $studentNumber,
        "course_code" => $courseCode,
        "course_name" => $courseName,
        "year_level" => $yearLevel,
        "section" => $section,
    ];
}
if ($errors) {
    api_fail("Check the highlighted account details", 422, $errors);
}

$passwordHash = password_hash($password, PASSWORD_DEFAULT);
if (!$passwordHash) {
    api_fail("Could not secure the password", 500);
}

try {
    $requestId = school_save_request($conn, [
        "account_type" => $accountType,
        "email" => $email,
        "password_hash" => $passwordHash,
        "full_name" => $fullName,
        "contact_number" => $contactNumber,
        "consent_version" => $consentVersion,
        "requested_ip" => api_client_ip(),
    ] + $student);
    $sent = school_send_code($conn, $requestId);
} catch (SchoolAccountError $error) {
    api_signup_fail($error);
} catch (Throwable $error) {
    api_fail("Could not start the account. Please try again.", 500, [], $error);
}

api_success(api_signup_code_reply($sent), 202, "We sent a 6-digit code to " . $email . ".");
