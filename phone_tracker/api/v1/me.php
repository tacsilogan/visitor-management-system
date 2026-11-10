<?php
declare(strict_types=1);
require_once __DIR__ . "/_bootstrap.php";

api_require_method("GET", "PATCH");
$user = api_require_visitor();
if ($_SERVER["REQUEST_METHOD"] === "GET") {
    api_success(["user" => api_user_payload($user)]);
}

$input = api_body();
$fullName = api_text($input, "full_name", 100);
$contactNumber = api_text($input, "contact_number", 32);
if (strlen($fullName) < 2) {
    api_fail("Enter your full name", 422, ["full_name" => "Full name is required"]);
}
$userId = (int) $user["id"];
$stmt = $conn->prepare("UPDATE app_users SET display_name = ?, contact_number = ? WHERE id = ? AND role = 'visitor'");
$stmt->bind_param("ssi", $fullName, $contactNumber, $userId);
$stmt->execute();
$stmt->close();
api_audit($conn, $userId, "mobile.profile_updated", "app_user", (string) $userId);
$user["display_name"] = $fullName;
$user["contact_number"] = $contactNumber;
api_success(["user" => api_user_payload($user)], 200, "Profile updated");

