<?php
declare(strict_types=1);
require_once __DIR__ . "/_password_reset.php";

api_require_method("POST");
$input = api_body();
$email = strtolower(api_text($input, "email", 190));
// The 6-digit code from the email, sent as "token" by every version of the app.
$code = (string) preg_replace('/\s+/', '', api_text($input, "token", 32));
$password = (string) ($input["new_password"] ?? "");
$passwordError = api_password_validation_error($password);
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $passwordError !== "") {
    $errors = [];
    if ($passwordError !== "") {
        $errors["new_password"] = $passwordError;
    }
    api_fail("Invalid password reset request", 422, $errors);
}
if (!preg_match('/^\d{6}$/', $code)) {
    api_fail("Enter the 6-digit code from the email.", 422);
}
api_rate_limit($conn, "password_reset_confirm", $email, 10, 3600);
$newHash = password_hash($password, PASSWORD_DEFAULT);
$conn->begin_transaction();
try {
    // Only the newest code works: sending a code ends the one before it.
    $stmt = $conn->prepare(
        "SELECT t.id, t.user_id, t.token_hash, t.failed_attempts, t.expires_at > NOW() AS still_valid
         FROM account_action_tokens t
         INNER JOIN app_users u ON u.id = t.user_id
         WHERE u.email = ? AND u.role IN ('visitor', 'student') AND t.purpose = 'password_reset'
           AND t.consumed_at IS NULL
         ORDER BY t.id DESC
         LIMIT 1
         FOR UPDATE"
    );
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row || !(int) $row["still_valid"]) {
        $conn->rollback();
        api_fail("This code no longer works. Send a new code.", 410);
    }
    $tries = (int) $row["failed_attempts"];
    if ($tries >= API_RESET_CODE_TRIES) {
        $conn->rollback();
        api_fail("Too many wrong codes. Send a new code.", 429);
    }
    $tokenId = (int) $row["id"];
    if (!hash_equals((string) $row["token_hash"], api_reset_code_hash($tokenId, $code))) {
        // Counted after the rollback, on its own, so the count stays.
        $conn->rollback();
        $count = $conn->prepare("UPDATE account_action_tokens SET failed_attempts = failed_attempts + 1 WHERE id = ?");
        $count->bind_param("i", $tokenId);
        $count->execute();
        $count->close();
        $left = API_RESET_CODE_TRIES - $tries - 1;
        if ($left <= 0) {
            api_fail("Too many wrong codes. Send a new code.", 429);
        }
        api_fail("That code is not right. " . $left . ($left === 1 ? " try" : " tries") . " left.", 422);
    }
    $userId = (int) $row["user_id"];
    // Also ends website sessions and clears any temporary password an administrator issued.
    $update = $conn->prepare(
        "UPDATE app_users
         SET password_hash = ?, password_changed_at = NOW(), must_change_password = 0,
             temp_password_expires_at = NULL, session_version = session_version + 1
         WHERE id = ?"
    );
    $update->bind_param("si", $newHash, $userId);
    $update->execute();
    $update->close();
    $consume = $conn->prepare("UPDATE account_action_tokens SET consumed_at = NOW() WHERE id = ?");
    $consume->bind_param("i", $tokenId);
    $consume->execute();
    $consume->close();
    $revoke = $conn->prepare("UPDATE api_access_tokens SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL");
    $revoke->bind_param("i", $userId);
    $revoke->execute();
    $revoke->close();
    api_audit($conn, $userId, "mobile.password_reset_completed", "app_user", (string) $userId);
    $conn->commit();
} catch (Throwable $error) {
    $conn->rollback();
    api_fail("Could not reset the password", 500, [], $error);
}
api_success([], 200, "Password updated. Sign in again on your devices.");
