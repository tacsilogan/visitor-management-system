<?php
/*
 * Admin security console: sign-in statistics, active lockouts, sign-in history,
 * security events, and account recovery actions (unlock, sign out, reset password,
 * reset two-step verification).
 */
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/auth_flow.php";

auth_json_exception_guard();
auth_require_method("GET", "POST");
require_permission_json("security.manage");
auth_require_schema($conn);

$adminId = (int) $_SESSION["user_id"];

function admin_security_target(mysqli $conn, array $input, int $adminId): array
{
    $targetId = (int) ($input["user_id"] ?? 0);
    $target = $targetId > 0 ? auth_load_user($conn, $targetId) : null;
    if (!$target) {
        auth_json_error(404, "not_found", "User not found.");
    }
    if ($targetId === $adminId) {
        auth_json_error(409, "self_action", "Use Account security in your profile menu to manage your own account.");
    }
    return $target;
}

function admin_security_overview(mysqli $conn, string $filter): array
{
    $stats = $conn->query(
        "SELECT
            (SELECT COUNT(*) FROM auth_login_attempts WHERE outcome = 'failure' AND created_at > NOW() - INTERVAL 24 HOUR) AS failed_24h,
            (SELECT COUNT(*) FROM auth_login_attempts WHERE outcome = 'success' AND created_at > NOW() - INTERVAL 24 HOUR) AS success_24h,
            (SELECT COUNT(*) FROM auth_lockouts WHERE released_at IS NULL AND locked_until > NOW()) AS active_lockouts,
            (SELECT COUNT(*) FROM app_users WHERE role IN ('admin', 'security', 'offices') AND is_active = 1) AS staff_total,
            (SELECT COUNT(*) FROM app_users WHERE role IN ('admin', 'security', 'offices') AND is_active = 1 AND totp_enabled_at IS NOT NULL) AS staff_two_factor,
            (SELECT COUNT(*) FROM app_users WHERE is_active = 1 AND must_change_password = 1) AS must_change"
    )->fetch_assoc();

    $lockouts = [];
    $result = $conn->query(
        "SELECT l.id, l.scope, l.identifier, l.ip_address, l.level, l.failure_count, l.blocked_attempts,
                l.created_at, l.locked_until, TIMESTAMPDIFF(SECOND, NOW(), l.locked_until) AS retry_after,
                u.id AS user_id, u.display_name, u.role
         FROM auth_lockouts l
         LEFT JOIN app_users u ON u.id = l.user_id
         WHERE l.released_at IS NULL AND l.locked_until > NOW()
         ORDER BY l.created_at DESC
         LIMIT 100"
    );
    foreach ($result->fetch_all(MYSQLI_ASSOC) as $row) {
        $lockouts[] = [
            "id" => (int) $row["id"],
            "scope" => (string) $row["scope"],
            "identifier" => (string) $row["identifier"],
            "user_id" => $row["user_id"] !== null ? (int) $row["user_id"] : null,
            "display_name" => (string) ($row["display_name"] ?? ""),
            "role" => (string) ($row["role"] ?? ""),
            "ip" => (string) $row["ip_address"],
            "level" => (int) $row["level"],
            "failure_count" => (int) $row["failure_count"],
            "blocked_attempts" => (int) $row["blocked_attempts"],
            "created_at" => $row["created_at"],
            "locked_until" => $row["locked_until"],
            "retry_after" => max(0, (int) $row["retry_after"]),
        ];
    }

    $where = "";
    if ($filter === "failure" || $filter === "success") {
        $where = "WHERE a.outcome = '" . $filter . "'";
    }
    $attempts = [];
    $result = $conn->query(
        "SELECT a.id, a.created_at, a.identifier, a.outcome, a.reason, a.stage, a.channel, a.ip_address, a.user_agent,
                u.id AS user_id, u.display_name, u.role
         FROM auth_login_attempts a
         LEFT JOIN app_users u ON u.id = a.user_id
         {$where}
         ORDER BY a.id DESC
         LIMIT 100"
    );
    foreach ($result->fetch_all(MYSQLI_ASSOC) as $row) {
        $attempts[] = [
            "id" => (int) $row["id"],
            "created_at" => $row["created_at"],
            "identifier" => (string) $row["identifier"],
            "user_id" => $row["user_id"] !== null ? (int) $row["user_id"] : null,
            "display_name" => (string) ($row["display_name"] ?? ""),
            "role" => (string) ($row["role"] ?? ""),
            "outcome" => (string) $row["outcome"],
            "reason" => (string) $row["reason"],
            "stage" => (string) $row["stage"],
            "channel" => (string) $row["channel"],
            "ip" => (string) $row["ip_address"],
            "device" => auth_device_label((string) $row["user_agent"]),
        ];
    }

    $events = [];
    $result = $conn->query(
        "SELECT g.id, g.action, g.entity_id, g.details_json, g.ip_address, g.created_at,
                g.actor_user_id, actor.display_name AS actor_name, target.display_name AS target_name
         FROM audit_logs g
         LEFT JOIN app_users actor ON actor.id = g.actor_user_id
         LEFT JOIN app_users target ON g.entity_type = 'app_user' AND target.id = g.entity_id
         WHERE (g.action LIKE 'auth.%' OR g.action LIKE 'user.%') AND g.action NOT IN ('auth.signed_in', 'auth.signed_out')
         ORDER BY g.id DESC
         LIMIT 40"
    );
    foreach ($result->fetch_all(MYSQLI_ASSOC) as $row) {
        $details = json_decode((string) $row["details_json"], true);
        $events[] = [
            "id" => (int) $row["id"],
            "action" => (string) $row["action"],
            "actor_name" => (string) ($row["actor_name"] ?? ""),
            "target_name" => (string) ($row["target_name"] ?? ""),
            "details" => is_array($details) ? $details : [],
            "ip" => (string) $row["ip_address"],
            "created_at" => $row["created_at"],
        ];
    }

    return [
        "success" => true,
        "stats" => array_map("intval", $stats),
        "lockouts" => $lockouts,
        "attempts" => $attempts,
        "events" => $events,
        "policy" => [
            "threshold" => AUTH_LOCK_THRESHOLD,
            "base_minutes" => AUTH_LOCK_BASE_MINUTES,
            "account_alarm_failures" => AUTH_ACCOUNT_ALARM_FAILURES,
            "account_alarm_ips" => AUTH_ACCOUNT_ALARM_MIN_IPS,
            "ip_failures" => AUTH_IP_FAILURES,
            "idle_minutes" => intdiv(auth_idle_timeout_seconds(), 60),
            "session_max_hours" => intdiv(auth_session_max_seconds(), 3600),
            "two_factor_required_roles" => auth_config()["two_factor_required_roles"],
        ],
    ];
}

if (strtoupper((string) $_SERVER["REQUEST_METHOD"]) === "GET") {
    auth_json(admin_security_overview($conn, (string) ($_GET["filter"] ?? "all")));
}

$input = auth_json_body();
$action = (string) ($input["action"] ?? "");

switch ($action) {
    case "unlock":
        $lockId = (int) ($input["lockout_id"] ?? 0);
        $stmt = $conn->prepare(
            "UPDATE auth_lockouts SET released_at = NOW(), released_by_user_id = ?
             WHERE id = ? AND released_at IS NULL AND locked_until > NOW()"
        );
        $stmt->bind_param("ii", $adminId, $lockId);
        $stmt->execute();
        $released = $stmt->affected_rows;
        $stmt->close();
        if ($released !== 1) {
            auth_json_error(404, "not_found", "That lock already ended.");
        }
        auth_audit($conn, $adminId, "auth.lockout_released", "auth_lockout", (string) $lockId);
        auth_json(["success" => true, "message" => "Sign-in unlocked."]);

    case "unlock_user":
        $target = admin_security_target($conn, $input, $adminId);
        $released = auth_release_user_locks($conn, (int) $target["id"], $adminId);
        auth_audit($conn, $adminId, "user.unlocked", "app_user", (string) $target["id"], ["locks" => $released]);
        auth_json(["success" => true, "message" => $released > 0 ? "Sign-in unlocked." : "This account had no active lock."]);

    case "force_logout":
        $target = admin_security_target($conn, $input, $adminId);
        auth_bump_session_version($conn, (int) $target["id"]);
        auth_audit($conn, $adminId, "user.signed_out_by_admin", "app_user", (string) $target["id"]);
        auth_json(["success" => true, "message" => "The user was signed out on every device."]);

    case "reset_password":
        $target = admin_security_target($conn, $input, $adminId);
        require_step_up_json();
        $targetId = (int) $target["id"];
        $temporaryPassword = auth_generate_temporary_password();
        $hash = password_hash($temporaryPassword, PASSWORD_DEFAULT);
        $hours = AUTH_TEMP_PASSWORD_HOURS;
        $stmt = $conn->prepare(
            "UPDATE app_users
             SET password_hash = ?, must_change_password = 1,
                 temp_password_expires_at = NOW() + INTERVAL {$hours} HOUR,
                 password_changed_at = NOW(), session_version = session_version + 1
             WHERE id = ?"
        );
        $stmt->bind_param("si", $hash, $targetId);
        $stmt->execute();
        $stmt->close();
        auth_revoke_trusted_devices($conn, $targetId);
        auth_release_user_locks($conn, $targetId, $adminId);
        $revoke = $conn->prepare("UPDATE api_access_tokens SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL");
        if ($revoke) {
            $revoke->bind_param("i", $targetId);
            $revoke->execute();
            $revoke->close();
        }
        auth_audit($conn, $adminId, "user.password_reset", "app_user", (string) $targetId);
        $expires = $conn->query("SELECT temp_password_expires_at FROM app_users WHERE id = " . $targetId)->fetch_row()[0];
        auth_json([
            "success" => true,
            "temporary_password" => $temporaryPassword,
            "expires_at" => $expires,
            "username" => (string) $target["username"],
            "display_name" => (string) $target["display_name"],
        ]);

    case "reset_two_factor":
        $target = admin_security_target($conn, $input, $adminId);
        require_step_up_json();
        $targetId = (int) $target["id"];
        $stmt = $conn->prepare(
            "UPDATE app_users
             SET totp_secret = NULL, totp_enabled_at = NULL, totp_last_used_step = NULL,
                 session_version = session_version + 1
             WHERE id = ?"
        );
        $stmt->bind_param("i", $targetId);
        $stmt->execute();
        $stmt->close();
        auth_store_backup_codes($conn, $targetId, []);
        auth_revoke_trusted_devices($conn, $targetId);
        auth_audit($conn, $adminId, "auth.two_factor_disabled", "app_user", (string) $targetId, ["by" => "admin"]);
        auth_json([
            "success" => true,
            "message" => auth_two_factor_required_for((string) $target["role"])
                ? "Two-step verification was reset. They will set it up again at their next sign-in."
                : "Two-step verification was turned off for this account.",
        ]);

    default:
        auth_json_error(400, "invalid_action", "Unknown action.");
}
