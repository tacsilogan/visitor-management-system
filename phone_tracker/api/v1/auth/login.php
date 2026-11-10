<?php
declare(strict_types=1);
require_once dirname(__DIR__) . "/_bootstrap.php";
require_once dirname(__DIR__, 3) . "/auth_security.php";

api_require_method("POST");
$input = api_body();
$identifier = strtolower(api_text($input, "identifier", 190));
$password = (string) ($input["password"] ?? "");
$installationId = api_text($input, "installation_id", 191);
$deviceName = api_text($input, "device_name", 100);
if ($identifier === "" || $password === "") {
    api_fail("Email and password are required", 422);
}
if (!auth_schema_ready($conn)) {
    api_fail("Mobile API database migration is required", 503);
}

$stmt = $conn->prepare(
    "SELECT id, username, email, password_hash, display_name, contact_number,
            email_verified_at, role, is_active, must_change_password
     FROM app_users
     WHERE (LOWER(email) = ? OR LOWER(username) = ?) AND role = 'visitor'
     LIMIT 1"
);
$stmt->bind_param("ss", $identifier, $identifier);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc() ?: null;
$stmt->close();

// Same lockout rules as the website (see auth_security.php).
$context = auth_attempt_context($user, $identifier, "mobile", "password");
$lock = auth_active_lock($conn, $context["subject_key"], $context["ip"]);
if ($lock) {
    auth_note_blocked_attempt($conn, (int) $lock["id"]);
    mobile_login_locked($lock);
}

// Always run one bcrypt check so unknown emails take as long as wrong passwords.
$passwordMatches = password_verify($password, $user ? (string) $user["password_hash"] : AUTH_DUMMY_PASSWORD_HASH);
if (!$user || !$passwordMatches || !(int) $user["is_active"]) {
    $reason = !$user ? "unknown_account" : (!$passwordMatches ? "wrong_password" : "suspended");
    $result = auth_register_failure($conn, $context, $reason);
    if ($result["lock"]) {
        mobile_login_locked($result["lock"]);
    }
    api_fail("Invalid email or password", 401);
}
if ((int) $user["must_change_password"] === 1) {
    api_fail("An administrator reset your password. Sign in once on the visitor website to choose a new password, then sign in here.", 403);
}
if (password_needs_rehash((string) $user["password_hash"], PASSWORD_DEFAULT)) {
    $rehash = password_hash($password, PASSWORD_DEFAULT);
    $update = $conn->prepare("UPDATE app_users SET password_hash = ? WHERE id = ?");
    $userId = (int) $user["id"];
    $update->bind_param("si", $rehash, $userId);
    $update->execute();
    $update->close();
}
$access = api_issue_access_token($conn, (int) $user["id"], $installationId, $deviceName);
auth_register_success($conn, $context, "password");
$lastLogin = $conn->prepare("UPDATE app_users SET last_login_at = NOW(), last_login_ip = ? WHERE id = ?");
$ip = api_client_ip();
$signedInId = (int) $user["id"];
$lastLogin->bind_param("si", $ip, $signedInId);
$lastLogin->execute();
$lastLogin->close();
api_audit($conn, (int) $user["id"], "mobile.signed_in", "api_access_token", (string) $access["id"], ["installation_id" => $installationId]);
api_success([
    "access_token" => $access["token"],
    "token_type" => "Bearer",
    "expires_at" => $access["expires_at"],
    "user" => api_user_payload($user),
]);

function mobile_login_locked(array $lock): never
{
    $retryAfter = max(1, (int) $lock["retry_after"]);
    header("Retry-After: " . $retryAfter);
    $minutes = (int) ceil($retryAfter / 60);
    api_fail(auth_lock_message((string) $lock["scope"]) . " Try again in " . $minutes . ($minutes === 1 ? " minute." : " minutes."), 429);
}
