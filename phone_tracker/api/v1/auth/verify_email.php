<?php
declare(strict_types=1);
require_once dirname(__DIR__) . "/_bootstrap.php";

api_require_method("POST");
$input = api_body();
$token = strtolower(api_text($input, "token", 128));
if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
    api_fail("Invalid or expired verification token", 422);
}
$hash = hash("sha256", $token);
$conn->begin_transaction();
try {
    $stmt = $conn->prepare(
        "SELECT id, user_id FROM account_action_tokens
         WHERE token_hash = ? AND purpose = 'verify_email' AND consumed_at IS NULL AND expires_at > NOW()
         FOR UPDATE"
    );
    if (!$stmt) {
        throw new RuntimeException("Migration required");
    }
    $stmt->bind_param("s", $hash);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        $conn->rollback();
        api_fail("Invalid or expired verification token", 422);
    }
    $userId = (int) $row["user_id"];
    $update = $conn->prepare("UPDATE app_users SET email_verified_at = COALESCE(email_verified_at, NOW()) WHERE id = ? AND role = 'visitor'");
    $update->bind_param("i", $userId);
    $update->execute();
    $update->close();
    $consume = $conn->prepare("UPDATE account_action_tokens SET consumed_at = NOW() WHERE id = ?");
    $tokenId = (int) $row["id"];
    $consume->bind_param("i", $tokenId);
    $consume->execute();
    $consume->close();
    api_audit($conn, $userId, "mobile.email_verified", "app_user", (string) $userId);
    $conn->commit();
} catch (Throwable $error) {
    $conn->rollback();
    api_fail("Could not verify the email address", 500, [], $error);
}
api_success(["email_verified" => true], 200, "Email address verified");
