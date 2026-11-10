<?php
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/session_bootstrap.php";
require_once __DIR__ . "/personnel.php";

require_permission_json("users.manage");
auth_require_schema($conn);
personnel_require_schema($conn);

$stmt = $conn->prepare(
    "SELECT u.id, u.username, u.display_name, u.first_name, u.last_name, u.position, u.email, u.contact_number,
            u.role, u.office_code, u.is_active, u.created_at, u.last_seen_at,
            u.last_login_at, u.last_login_ip, u.must_change_password, u.temp_password_expires_at,
            (u.temp_password_expires_at IS NOT NULL AND u.temp_password_expires_at < NOW()) AS temp_password_expired,
            (u.totp_enabled_at IS NOT NULL) AS two_factor_enabled,
            (SELECT COUNT(*) FROM auth_lockouts l
             WHERE l.user_id = u.id AND l.released_at IS NULL AND l.locked_until > NOW()
               AND l.scope IN ('account_device', 'account')) AS active_lockouts,
            (SELECT COUNT(*) FROM auth_login_attempts a
             WHERE a.user_id = u.id AND a.outcome = 'failure' AND a.created_at > NOW() - INTERVAL 24 HOUR) AS failed_24h,
            TIMESTAMPDIFF(SECOND, u.last_seen_at, NOW()) AS seen_seconds_ago
     FROM app_users u
     ORDER BY u.id ASC"
);
$stmt->execute();
$result = $stmt->get_result();
$rows = [];
while ($row = $result->fetch_assoc()) {
    $role = (string) $row["role"];
    $officeCode = (string) $row["office_code"];
    $rows[] = [
        "id" => (int) $row["id"],
        "username" => $row["username"],
        "display_name" => personnel_full_name($row),
        "first_name" => (string) $row["first_name"],
        "last_name" => (string) $row["last_name"],
        "has_name" => personnel_has_name($row),
        "position" => (string) $row["position"],
        "email" => (string) ($row["email"] ?? ""),
        "contact_number" => (string) $row["contact_number"],
        "role" => $role,
        "office_code" => $officeCode,
        "department" => personnel_department_label($role, $officeCode),
        "is_active" => (int) $row["is_active"],
        "created_at" => $row["created_at"],
        "last_seen_at" => $row["last_seen_at"],
        "online" => $row["seen_seconds_ago"] !== null && (int) $row["seen_seconds_ago"] <= 300,
        "last_login_at" => $row["last_login_at"],
        "last_login_ip" => $row["last_login_ip"],
        "must_change_password" => (int) $row["must_change_password"] === 1,
        "temp_password_expires_at" => $row["temp_password_expires_at"],
        "temp_password_expired" => (int) $row["temp_password_expired"] === 1,
        "two_factor_enabled" => (int) $row["two_factor_enabled"] === 1,
        "two_factor_required" => auth_two_factor_required_for($role),
        "locked" => (int) $row["active_lockouts"] > 0,
        "failed_24h" => (int) $row["failed_24h"],
    ];
}
$stmt->close();

echo json_encode(["success" => true, "data" => $rows]);
