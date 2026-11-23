<?php
/*
 * Home's one search box (admin.js): visitors by name, email or visit code (V-2026-000123).
 * Staff and offices are already on the page, so the browser finds those itself.
 *   GET admin_search.php?q=...
 */
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/session_bootstrap.php";
require_once __DIR__ . "/admin_service.php";

auth_json_exception_guard();
auth_require_method("GET");
require_admin_json();
date_default_timezone_set("Asia/Manila");

$query = personnel_clean_text((string) ($_GET["q"] ?? ""), 80);
if (mb_strlen($query, "UTF-8") < 2) {
    auth_json(["success" => true, "visitors" => []]);
}
$like = "%" . addcslashes($query, "%_\\") . "%";
$code = strtoupper(preg_replace('/\s+/', "", $query));

// The newest visit of each matching visitor: accounts by user id, gate visitors by name.
$stmt = $conn->prepare(
    "SELECT a.id, a.visitor_user_id, a.visitor_full_name, a.office_code, a.status, a.registration_code,
            COALESCE(a.scheduled_start_at, a.appointment_at) AS starts_at, u.email
     FROM appointments a
     LEFT JOIN app_users u ON u.id = a.visitor_user_id
     WHERE a.visitor_full_name LIKE ? OR u.email LIKE ? OR a.registration_code = ?
     ORDER BY COALESCE(a.scheduled_start_at, a.appointment_at) DESC
     LIMIT 120"
);
$stmt->bind_param("sss", $like, $like, $code);
$stmt->execute();
$visitors = [];
foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
    $key = $row["visitor_user_id"] !== null ? "u" . $row["visitor_user_id"] : "n" . mb_strtolower((string) $row["visitor_full_name"]);
    if (isset($visitors[$key])) {
        $visitors[$key]["visits"]++;
        continue;
    }
    $visitors[$key] = [
        "user_id" => $row["visitor_user_id"] !== null ? (int) $row["visitor_user_id"] : null,
        "appointment_id" => (int) $row["id"],
        "name" => (string) $row["visitor_full_name"],
        "email" => (string) ($row["email"] ?? ""),
        "code" => (string) $row["registration_code"],
        "matched_code" => (string) $row["registration_code"] === $code,
        "last" => $row["starts_at"] ? date("M j, Y", strtotime((string) $row["starts_at"])) . " · " . appointment_office_label((string) $row["office_code"]) : "",
        "visits" => 1,
    ];
}
$stmt->close();

// Visitor accounts that never booked a visit.
$stmt = $conn->prepare(
    "SELECT id, username, email, first_name, last_name, display_name FROM app_users
     WHERE role = 'visitor' AND (CONCAT_WS(' ', first_name, last_name) LIKE ? OR display_name LIKE ? OR email LIKE ? OR username LIKE ?)
     LIMIT 20"
);
$stmt->bind_param("ssss", $like, $like, $like, $like);
$stmt->execute();
foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
    $key = "u" . $row["id"];
    if (!isset($visitors[$key])) {
        $visitors[$key] = [
            "user_id" => (int) $row["id"],
            "appointment_id" => null,
            "name" => personnel_full_name($row),
            "email" => (string) ($row["email"] ?? ""),
            "code" => "",
            "matched_code" => false,
            "last" => "No visits yet",
            "visits" => 0,
        ];
    }
}
$stmt->close();

$visitors = array_values($visitors);
usort($visitors, fn (array $a, array $b): int => $b["matched_code"] <=> $a["matched_code"]);
auth_json(["success" => true, "visitors" => array_slice($visitors, 0, 8)]);
