<?php
declare(strict_types=1);
// Shared by the sign-up endpoints: register.php, confirm_signup.php and resend_code.php.
require_once dirname(__DIR__) . "/_bootstrap.php";
require_once dirname(__DIR__, 3) . "/signup_service.php";

function api_signup_fail(SignupError $error): never
{
    $payload = ["success" => false, "message" => $error->getMessage(), "code" => $error->reason];
    if ($error->field !== "") {
        $payload["errors"] = [$error->field => $error->getMessage()];
    }
    if (isset($error->extra["retry_after"])) {
        header("Retry-After: " . (int) $error->extra["retry_after"]);
        $payload["retry_after"] = (int) $error->extra["retry_after"];
    }
    if (isset($error->extra["tries_left"])) {
        $payload["tries_left"] = (int) $error->extra["tries_left"];
    }
    api_json($payload, $error->status);
}

/** What the app needs after a code is sent. The code itself only in development mode. */
function api_signup_code_reply(array $sent): array
{
    $data = [
        "verification_required" => true,
        "request_id" => $sent["request_id"],
        "email" => $sent["email"],
        "expires_at" => $sent["expires_at"],
        "resend_after_seconds" => $sent["resend_after_seconds"],
    ];
    if (api_is_development()) {
        $data["development_code"] = $sent["code"];
    }
    return $data;
}

function api_require_signup_schema(mysqli $conn): void
{
    if (!signup_schema_ready($conn)) {
        api_fail("Database update required: run php phone_tracker/tools/setup_database.php", 503);
    }
}
