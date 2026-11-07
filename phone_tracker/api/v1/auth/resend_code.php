<?php
declare(strict_types=1);
require_once __DIR__ . "/_signup.php";

// Sends a new code for a sign-up that is still waiting for one. The old code stops working.
// POST {request_id}  →  200 {verification_required, request_id, email, expires_at, resend_after_seconds}

api_require_method("POST");
api_require_school_schema($conn);
$input = api_body();
$requestId = (int) ($input["request_id"] ?? 0);
if ($requestId <= 0) {
    api_fail("Start the sign-up again", 422);
}
api_rate_limit($conn, "resend_code_network", "", 30, 3600);
$request = school_request_load($conn, $requestId);
if (!$request || !in_array($request["account_type"], ["visitor", "student"], true)) {
    api_fail("Start the sign-up again", 410);
}
try {
    $sent = school_send_code($conn, $requestId);
} catch (SchoolAccountError $error) {
    api_signup_fail($error);
} catch (Throwable $error) {
    api_fail("Could not send a new code. Please try again.", 500, [], $error);
}
api_success(api_signup_code_reply($sent), 200, "We sent a new code to " . $sent["email"] . ".");
