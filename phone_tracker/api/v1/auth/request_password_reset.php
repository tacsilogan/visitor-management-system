<?php
declare(strict_types=1);
require_once dirname(__DIR__) . "/_bootstrap.php";

api_require_method("POST");
$input = api_body();
$email = strtolower(api_text($input, "email", 190));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    api_fail("Enter a valid email address", 422);
}
api_rate_limit($conn, "password_reset", $email, 5, 3600);
$stmt = $conn->prepare(
    "SELECT id FROM app_users WHERE email = ? AND role IN ('visitor', 'student') AND is_active = 1 LIMIT 1"
);
if (!$stmt) {
    api_fail("Mobile API database migration is required", 503);
}
$stmt->bind_param("s", $email);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();
$data = [];
if ($user) {
    $token = api_create_account_action_token($conn, (int) $user["id"], $email, "password_reset", 30);
    api_audit($conn, (int) $user["id"], "mobile.password_reset_requested", "app_user", (string) $user["id"]);
    if (api_is_development()) {
        $data["development_reset_token"] = $token;
    }
}
api_success($data, 200, "If the account exists, password reset instructions have been queued.");

