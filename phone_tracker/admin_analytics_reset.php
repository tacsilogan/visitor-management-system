<?php
/*
 * Resetting Visitor Analytics (administrators; analytics_reset_service.php says what goes).
 *
 *   GET  admin_analytics_reset.php   what each kind of reset would delete, and whether the
 *                                    admin can confirm with an authenticator code
 *   POST {scope: "all" | "finished", confirm: "RESET",
 *         method: "authenticator" | "backup_code", code}
 *
 * A reset cannot be undone, so it always asks for a fresh code from the admin's
 * authenticator app (or a backup code), exactly as at sign-in, even when "Confirm it's
 * you" was answered a minute ago. Wrong codes count toward the sign-in lockout, and a
 * lockout here signs the admin out. The reset is recorded in the activity log.
 */
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/auth_flow.php";
require_once __DIR__ . "/analytics_reset_service.php";

auth_json_exception_guard();
require_permission_json("analytics.reset");
auth_require_schema($conn);

$user = auth_load_user($conn, (int) $_SESSION["user_id"]);
$userId = (int) $user["id"];
$twoFactor = auth_user_has_two_factor($user);

if (($_SERVER["REQUEST_METHOD"] ?? "GET") === "GET") {
    auth_json([
        "success" => true,
        "two_factor_enabled" => $twoFactor,
        "backup_codes_remaining" => $twoFactor ? auth_backup_codes_remaining($conn, $userId) : 0,
        "scopes" => [
            "all" => analytics_reset_counts($conn, "all"),
            "finished" => analytics_reset_counts($conn, "finished"),
        ],
    ]);
}

auth_require_method("POST");
$input = auth_json_body();
$scope = (string) ($input["scope"] ?? "");
if (!in_array($scope, ANALYTICS_RESET_SCOPES, true)) {
    auth_json_error(422, "invalid_scope", "Choose what to delete.");
}
if (trim((string) ($input["confirm"] ?? "")) !== "RESET") {
    auth_json_error(422, "not_confirmed", "Type RESET in capital letters to confirm.");
}
if (!$twoFactor) {
    auth_json_error(409, "two_factor_required",
        "A reset needs a code from your authenticator app. Turn on two-step verification in Account security first.");
}

$context = auth_attempt_context($user, (string) $user["username"], "web", "step_up");
$lock = auth_active_lock($conn, $context["subject_key"], $context["ip"]);
if ($lock) {
    auth_end_session("session_revoked");
    auth_lock_response($lock);
}

$useBackupCode = ($input["method"] ?? "") === "backup_code";
$code = trim((string) ($input["code"] ?? ""));
$verified = false;
if ($useBackupCode) {
    $verified = auth_consume_backup_code($conn, $userId, $code);
} else {
    $secret = auth_decrypt_secret($user["totp_secret"], $userId);
    $lastStep = $user["totp_last_used_step"] !== null ? (int) $user["totp_last_used_step"] : null;
    $step = $secret !== null ? auth_totp_verify($secret, $code, $lastStep) : null;
    if ($step !== null) {
        // Conditional update: the same code cannot be used twice.
        $stmt = $conn->prepare(
            "UPDATE app_users SET totp_last_used_step = ?
             WHERE id = ? AND (totp_last_used_step IS NULL OR totp_last_used_step < ?)"
        );
        $stmt->bind_param("iii", $step, $userId, $step);
        $stmt->execute();
        $verified = $stmt->affected_rows === 1;
        $stmt->close();
    }
}

if (!$verified) {
    $result = auth_register_failure($conn, $context, $useBackupCode ? "wrong_backup_code" : "wrong_code");
    if ($result["lock"]) {
        auth_end_session("session_revoked");
        auth_lock_response($result["lock"]);
    }
    auth_json_error(422, "invalid_code", $useBackupCode
        ? "That backup code didn't work or was already used."
        : "That code didn't work. Enter the newest 6-digit code from your authenticator app.", [
        "attempts_remaining" => $result["remaining"],
    ]);
}
auth_register_success($conn, $context, $useBackupCode ? "confirmed_backup_code" : "confirmed_authenticator");

$deleted = analytics_reset_run($conn, $scope);
auth_audit($conn, $userId, "analytics.reset", "visitor_data", $scope, [
    "scope" => $scope,
    "confirmed_with" => $useBackupCode ? "backup_code" : "authenticator",
    "appointments" => $deleted["appointments"],
    "upcoming" => $deleted["upcoming"],
    "on_campus" => $deleted["on_campus"],
    "visits" => $deleted["visits"],
    "gps_points" => $deleted["gps_points"],
    "tracking_sessions" => $deleted["tracking_sessions"],
    "notifications" => $deleted["notifications"],
]);

auth_json(["success" => true, "scope" => $scope, "deleted" => $deleted]);
