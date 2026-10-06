<?php
declare(strict_types=1);
require_once dirname(__DIR__) . "/_bootstrap.php";

api_require_method("POST");
$input = api_body();
$email = strtolower(api_text($input, "email", 190));
$token = strtolower(api_text($input, "token", 128));
$password = (string) ($input["new_password"] ?? "");
$passwordError = api_password_validation_error($password);
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/^[a-f0-9]{64}$/', $token) || $passwordError !== "") {
    $errors = [];
    if ($passwordError !== "") {
        $errors["new_password"] = $passwordError;
    }
    api_fail("Invalid password reset request", 422, $errors);
}
api_rate_limit($conn, "password_reset_confirm", $email, 10, 3600);
$hash = hash("sha256", $token);
$newHash = password_hash($password, PASSWORD_DEFAULT);
$conn->begin_transaction();
try {
    $stmt = $conn->prepare(
        "SELECT t.id, t.user_id
         FROM account_action_tokens t
         INNER JOIN app_users u ON u.id = t.user_id
         WHERE t.token_hash = ? AND t.purpose = 'password_reset' AND t.consumed_at IS NULL
           AND t.expires_at > NOW() AND u.email = ? AND u.role = 'visitor'
         FOR UPDATE"
    );
    $stmt->bind_param("ss", $hash, $email);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        $conn->rollback();
        api_fail("Invalid or expired password reset token", 422);
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
    $actionTokenId = (int) $row["id"];
    $consume->bind_param("i", $actionTokenId);
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
