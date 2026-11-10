<?php
declare(strict_types=1);
require_once __DIR__ . "/_password_reset.php";

api_require_method("POST");
$input = api_body();
$email = strtolower(api_text($input, "email", 190));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    api_fail("Enter a valid email address", 422);
}
api_rate_limit($conn, "password_reset", $email, 5, 3600);
$stmt = $conn->prepare(
    "SELECT id FROM app_users WHERE email = ? AND role = 'visitor' AND is_active = 1 LIMIT 1"
);
if (!$stmt) {
    api_fail("Mobile API database migration is required", 503);
}
$stmt->bind_param("s", $email);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();
// The reply is the same whether or not an account uses the email.
$data = ["code_minutes" => API_RESET_CODE_MINUTES, "resend_after_seconds" => API_RESET_RESEND_SECONDS];
if ($user) {
    try {
        $code = api_send_reset_code($conn, (int) $user["id"], $email);
    } catch (Throwable $error) {
        api_fail("Could not send the code. Try again in a moment.", 500, [], $error);
    }
    if ($code !== null) {
        api_audit($conn, (int) $user["id"], "mobile.password_reset_requested", "app_user", (string) $user["id"]);
        if (api_is_development()) {
            // A server on a developer's computer sends the code back, so it can be tried without email.
            $data["development_reset_token"] = $code;
        }
    }
}
api_success($data, 200, "If an account uses this email, a 6-digit code is on its way.");
