<?php
declare(strict_types=1);
require_once __DIR__ . "/_signup.php";

// Finishes a sign-up with the emailed code: the visitor account is created and signed in at once.
// POST {request_id, code, installation_id?, device_name?}
// 201 {access_token, token_type, expires_at, user}

api_require_method("POST");
api_require_signup_schema($conn);
$input = api_body();
$requestId = (int) ($input["request_id"] ?? 0);
$code = preg_replace('/\D/', "", api_text($input, "code", 12)) ?? "";
$installationId = api_text($input, "installation_id", 191);
$deviceName = api_text($input, "device_name", 100);
if ($requestId <= 0) {
    api_fail("Start the sign-up again", 422);
}
api_rate_limit($conn, "confirm_signup", (string) $requestId, 20, 3600);

$conn->begin_transaction();
try {
    $request = signup_verify_code($conn, $requestId, $code);
    $userId = signup_create_account($conn, $request);
    api_audit($conn, $userId, "mobile.account_registered", "app_user", (string) $userId, [
        "email" => (string) $request["email"],
        "consent_version" => (string) $request["consent_version"],
        "email_confirmed" => true,
    ]);
    $conn->commit();
} catch (SignupError $error) {
    try {
        $conn->rollback();
    } catch (Throwable) {
        // signup_verify_code() already rolled back.
    }
    api_signup_fail($error);
} catch (Throwable $error) {
    $conn->rollback();
    api_fail("Could not create the account. Please try again.", 500, [], $error);
}

$stmt = $conn->prepare(
    "SELECT id, username, email, display_name, contact_number, email_verified_at, role FROM app_users WHERE id = ?"
);
$stmt->bind_param("i", $userId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();
$access = api_issue_access_token($conn, $userId, $installationId, $deviceName);
api_audit($conn, $userId, "mobile.signed_in", "api_access_token", (string) $access["id"], ["installation_id" => $installationId]);
api_success([
    "access_token" => $access["token"],
    "token_type" => "Bearer",
    "expires_at" => $access["expires_at"],
    "user" => api_user_payload($user),
], 201, "Account created");
