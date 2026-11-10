<?php
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/session_bootstrap.php";
require_once __DIR__ . "/account_removal_service.php";

// Removes any account, even one with visits. account_removal_service.php says what stays and what goes.
auth_json_exception_guard();
require_permission_json("users.manage");
auth_require_method("POST");

$input = auth_json_body();
$id = (int) ($input["id"] ?? 0);
$selfId = (int) $_SESSION["user_id"];

if ($id <= 0) {
    auth_json_error(400, "invalid_request", "Choose an account to remove.");
}
if ($id === $selfId) {
    auth_json_error(409, "cannot_remove", "You cannot remove your own account.");
}

// The quick refusals come before asking the administrator to confirm it's them.
$lookup = $conn->prepare("SELECT role, is_active FROM app_users WHERE id = ? LIMIT 1");
$lookup->bind_param("i", $id);
$lookup->execute();
$user = $lookup->get_result()->fetch_assoc();
$lookup->close();
if (!$user) {
    auth_json_error(404, "not_found", "This account was already removed.");
}
if ($user["role"] === "admin" && (int) $user["is_active"] === 1
    && auth_scalar($conn, "SELECT COUNT(*) FROM app_users WHERE role = 'admin' AND is_active = 1") <= 1) {
    auth_json_error(409, "cannot_remove", "You cannot remove the last active administrator.");
}

require_step_up_json();

try {
    $result = account_remove($conn, $id, $selfId);
} catch (AccountRemovalError $error) {
    auth_json_error(409, "cannot_remove", $error->getMessage());
} catch (Throwable $error) {
    error_log("Account removal failed for user {$id}: " . $error->getMessage() . " in " . $error->getFile() . ":" . $error->getLine());
    auth_json_error(500, "server_error", "The account could not be removed because of a server error. Nothing was changed.");
}

$count = $result["cancelled_visits"];
$message = "Removed " . $result["name"] . ".";
if ($count > 0) {
    $visits = $count === 1 ? "1 upcoming visit was" : "{$count} upcoming visits were";
    $message .= " {$visits} cancelled, and " . ($count === 1 ? "the office was" : "each office was") . " told.";
}
auth_json(["success" => true, "message" => $message, "cancelled_visits" => $count]);
