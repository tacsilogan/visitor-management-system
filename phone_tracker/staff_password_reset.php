<?php
/*
 * "Forgot password?" on the staff sign-in page (login.html): Office Personnel, Security and
 * administrators with an email on file get a 6-digit code by email and choose a new password.
 * Staff without an email still ask an administrator. The reply never says whether an account
 * exists. Two-step sign-in is still asked afterwards: trusted browsers are forgotten.
 *
 *   POST {"action": "request", "identifier": "username or email"}
 *   POST {"action": "reset", "identifier": "...", "code": "123456", "new_password": "...", "confirm_password": "..."}
 */
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/auth_flow.php";
require_once __DIR__ . "/auth_totp.php";
require_once __DIR__ . "/mail_service.php";

auth_json_exception_guard();
auth_require_method("POST");
auth_require_csrf();
auth_require_schema($conn);

const STAFF_RESET_CODE_MINUTES = 15;
const STAFF_RESET_CODE_TRIES = 5;
const STAFF_RESET_CODES_PER_HOUR = 5;
const STAFF_RESET_ROLES = ["admin", "security", "offices"];

/** Counts a try from this network for [$identity]; refuses once there were [$limit] in [$windowSeconds]. */
function staff_reset_rate_limit(mysqli $conn, string $scope, string $identity, int $limit, int $windowSeconds): void
{
    $key = hash("sha256", $scope . "|" . strtolower(trim($identity)) . "|" . auth_client_ip());
    $stmt = $conn->prepare("SELECT attempt_count, window_started_at, blocked_until FROM api_rate_limits WHERE bucket_key = ? LIMIT 1");
    $stmt->bind_param("s", $key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $now = time();
    if ($row && $row["blocked_until"] && strtotime((string) $row["blocked_until"]) > $now) {
        auth_json_error(429, "too_many", "Too many tries. Wait a while, or ask an administrator to reset your password.");
    }
    $windowStart = $row ? strtotime((string) $row["window_started_at"]) : 0;
    if (!$row || !$windowStart || $windowStart + $windowSeconds <= $now) {
        $stmt = $conn->prepare(
            "INSERT INTO api_rate_limits (bucket_key, attempt_count, window_started_at, blocked_until) VALUES (?, 1, NOW(), NULL)
             ON DUPLICATE KEY UPDATE attempt_count = 1, window_started_at = NOW(), blocked_until = NULL"
        );
        $stmt->bind_param("s", $key);
        $stmt->execute();
        $stmt->close();
        return;
    }
    $attempts = (int) $row["attempt_count"] + 1;
    $blockedUntil = $attempts > $limit ? date("Y-m-d H:i:s", $windowStart + $windowSeconds) : null;
    $stmt = $conn->prepare("UPDATE api_rate_limits SET attempt_count = ?, blocked_until = ? WHERE bucket_key = ?");
    $stmt->bind_param("iss", $attempts, $blockedUntil, $key);
    $stmt->execute();
    $stmt->close();
    if ($blockedUntil !== null) {
        auth_json_error(429, "too_many", "Too many tries. Wait a while, or ask an administrator to reset your password.");
    }
}

/** Kept only as a hash keyed by the server's secret and tied to its row (as api/v1/auth/_password_reset.php). */
function staff_reset_code_hash(int $tokenId, string $code): string
{
    return hash_hmac("sha256", "password_reset:" . $tokenId . ":" . $code, auth_secret_key());
}

/** The staff account behind a username or email, or null (visitors use the app instead). */
function staff_reset_account(mysqli $conn, string $identifier): ?array
{
    if ($identifier === "" || mb_strlen($identifier, "UTF-8") > 190) {
        return null;
    }
    $user = auth_find_login_user($conn, $identifier);
    if (!$user || !in_array((string) $user["role"], STAFF_RESET_ROLES, true) || (int) $user["is_active"] !== 1) {
        return null;
    }
    return $user;
}

/** "m•••••r@example.com" */
function staff_reset_mask_email(string $email): string
{
    [$local, $domain] = array_pad(explode("@", $email, 2), 2, "");
    $length = mb_strlen($local, "UTF-8");
    $masked = $length <= 2 ? mb_substr($local, 0, 1, "UTF-8") . "•" : mb_substr($local, 0, 1, "UTF-8") . str_repeat("•", min(6, $length - 2)) . mb_substr($local, -1, 1, "UTF-8");
    return $masked . "@" . $domain;
}

$input = auth_json_body();
$action = (string) ($input["action"] ?? "");
$identifier = trim((string) ($input["identifier"] ?? ""));
$generic = "If this account has an email on file, a 6-digit code is on its way. It works for " . STAFF_RESET_CODE_MINUTES . " minutes. "
    . "No email on file? Ask an administrator to reset your password.";

if ($action === "request") {
    if ($identifier === "") {
        auth_json_error(422, "missing_identifier", "Enter your username or email.");
    }
    staff_reset_rate_limit($conn, "staff_password_reset", $identifier, 5, 3600);
    $user = staff_reset_account($conn, $identifier);
    $email = $user ? strtolower(trim((string) ($user["email"] ?? ""))) : "";
    $payload = ["success" => true, "message" => $generic, "code_minutes" => STAFF_RESET_CODE_MINUTES, "resend_after_seconds" => 60];
    if (!$user || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        auth_json($payload);
    }
    $userId = (int) $user["id"];
    $recent = $conn->prepare(
        "SELECT COUNT(*) FROM account_action_tokens WHERE user_id = ? AND purpose = 'password_reset' AND created_at > NOW() - INTERVAL 1 HOUR"
    );
    $recent->bind_param("i", $userId);
    $recent->execute();
    $sentThisHour = (int) $recent->get_result()->fetch_row()[0];
    $recent->close();
    if ($sentThisHour >= STAFF_RESET_CODES_PER_HOUR) {
        auth_json($payload);
    }

    $code = str_pad((string) random_int(0, 999999), 6, "0", STR_PAD_LEFT);
    $minutes = STAFF_RESET_CODE_MINUTES;
    $expires = (new DateTimeImmutable("now"))->modify("+{$minutes} minutes")->format("Y-m-d H:i:s");
    $ip = auth_client_ip();
    $emailId = 0;
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("UPDATE account_action_tokens SET consumed_at = NOW() WHERE user_id = ? AND purpose = 'password_reset' AND consumed_at IS NULL");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $stmt->close();
        $standIn = bin2hex(random_bytes(32));
        $stmt = $conn->prepare(
            "INSERT INTO account_action_tokens (user_id, purpose, token_hash, expires_at, requested_ip) VALUES (?, 'password_reset', ?, ?, ?)"
        );
        $stmt->bind_param("isss", $userId, $standIn, $expires, $ip);
        $stmt->execute();
        $tokenId = (int) $stmt->insert_id;
        $stmt->close();
        $hash = staff_reset_code_hash($tokenId, $code);
        $stmt = $conn->prepare("UPDATE account_action_tokens SET token_hash = ? WHERE id = ?");
        $stmt->bind_param("si", $hash, $tokenId);
        $stmt->execute();
        $stmt->close();
        $subject = "Reset your ISATU Visitor Management password";
        $mailPayload = json_encode(["code" => $code, "minutes" => $minutes, "expires_at" => $expires, "username" => (string) $user["username"]], JSON_UNESCAPED_SLASHES);
        $stmt = $conn->prepare(
            "INSERT INTO outbound_emails (user_id, recipient_email, template_key, subject, payload_json) VALUES (?, ?, 'staff_password_reset', ?, ?)"
        );
        $stmt->bind_param("isss", $userId, $email, $subject, $mailPayload);
        $stmt->execute();
        $emailId = (int) $stmt->insert_id;
        $stmt->close();
        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }
    auth_audit($conn, $userId, "auth.password_reset_requested", "app_user", (string) $userId, ["by" => "email"]);
    // Send it now rather than at the next background pass; the queue retries if this fails.
    try {
        mail_send_now($conn, $emailId);
    } catch (Throwable $error) {
        // The background job sends it.
    }
    if (strtolower((string) (getenv("VISITOR_APP_ENV") ?: "")) === "development") {
        // A server on a developer's computer sends the code back, as the app's reset does.
        $payload["development_code"] = $code;
    }
    auth_json($payload);
}

if ($action === "reset") {
    $code = (string) preg_replace('/\s+/', "", (string) ($input["code"] ?? ""));
    $password = (string) ($input["new_password"] ?? "");
    $confirm = (string) ($input["confirm_password"] ?? "");
    if (!preg_match('/^\d{6}$/', $code)) {
        auth_json_error(422, "invalid_code", "Enter the 6-digit code from the email.");
    }
    if ($password !== $confirm) {
        auth_json_error(422, "password_mismatch", "The two passwords don't match.");
    }
    staff_reset_rate_limit($conn, "staff_password_reset_confirm", $identifier, 10, 3600);
    $user = staff_reset_account($conn, $identifier);
    if (!$user) {
        auth_json_error(410, "code_expired", "This code no longer works. Send a new code.");
    }
    $problems = auth_password_problems($password, $user);
    if ($problems) {
        auth_json_error(422, "weak_password", $problems[0], ["problems" => $problems]);
    }
    $userId = (int) $user["id"];
    $conn->begin_transaction();
    $stmt = $conn->prepare(
        "SELECT id, token_hash, failed_attempts, expires_at > NOW() AS still_valid FROM account_action_tokens
         WHERE user_id = ? AND purpose = 'password_reset' AND consumed_at IS NULL ORDER BY id DESC LIMIT 1 FOR UPDATE"
    );
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $token = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$token || !(int) $token["still_valid"]) {
        $conn->rollback();
        auth_json_error(410, "code_expired", "This code no longer works. Send a new code.");
    }
    $tries = (int) $token["failed_attempts"];
    if ($tries >= STAFF_RESET_CODE_TRIES) {
        $conn->rollback();
        auth_json_error(429, "too_many", "Too many wrong codes. Send a new code.");
    }
    $tokenId = (int) $token["id"];
    if (!hash_equals((string) $token["token_hash"], staff_reset_code_hash($tokenId, $code))) {
        $conn->rollback();
        $count = $conn->prepare("UPDATE account_action_tokens SET failed_attempts = failed_attempts + 1 WHERE id = ?");
        $count->bind_param("i", $tokenId);
        $count->execute();
        $count->close();
        $left = STAFF_RESET_CODE_TRIES - $tries - 1;
        if ($left <= 0) {
            auth_json_error(429, "too_many", "Too many wrong codes. Send a new code.");
        }
        auth_json_error(422, "wrong_code", "That code is not right. " . $left . ($left === 1 ? " try" : " tries") . " left.");
    }
    if (password_verify($password, (string) $user["password_hash"])) {
        $conn->rollback();
        auth_json_error(422, "same_password", "Choose a different password from your old one.");
    }
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $conn->prepare(
        "UPDATE app_users SET password_hash = ?, password_changed_at = NOW(), must_change_password = 0,
                temp_password_expires_at = NULL, session_version = session_version + 1
         WHERE id = ?"
    );
    $stmt->bind_param("si", $hash, $userId);
    $stmt->execute();
    $stmt->close();
    $stmt = $conn->prepare("UPDATE account_action_tokens SET consumed_at = NOW() WHERE id = ?");
    $stmt->bind_param("i", $tokenId);
    $stmt->execute();
    $stmt->close();
    $conn->commit();
    auth_release_user_locks($conn, $userId, null);
    auth_revoke_trusted_devices($conn, $userId);
    auth_audit($conn, $userId, "auth.password_reset_by_email", "app_user", (string) $userId, []);
    auth_json([
        "success" => true,
        "message" => "Password changed. Sign in with your new password" . (auth_user_has_two_factor($user) ? " and your two-step code." : "."),
    ]);
}

auth_json_error(400, "invalid_action", "Unknown action.");
