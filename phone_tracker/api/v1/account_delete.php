<?php
declare(strict_types=1);
require_once __DIR__ . "/_bootstrap.php";
require_once dirname(__DIR__, 2) . "/account_removal_service.php";

// The visitor deletes their own account (Profile → Delete my account; AUTH_SECURITY.md, "Visitors'
// own accounts"). They type their password again first. Upcoming visits are cancelled and each
// office is told; past visits stay, no longer linked to the account, until the 1-year cleanup.
// Their sign-in on every phone ends with the account. Refused while they are inside the campus.

api_require_method("POST");
$user = api_require_visitor();
$userId = (int) $user["id"];
// A wrong password counts toward this limit, so the password can't be guessed from an unlocked phone.
api_rate_limit($conn, "account_delete", (string) $userId, 5, 900);

$input = api_body();
$password = (string) ($input["password"] ?? "");
$stmt = $conn->prepare("SELECT password_hash FROM app_users WHERE id = ? AND role = 'visitor' LIMIT 1");
$stmt->bind_param("i", $userId);
$stmt->execute();
$hash = (string) ($stmt->get_result()->fetch_assoc()["password_hash"] ?? "");
$stmt->close();
if ($password === "" || $hash === "" || !password_verify($password, $hash)) {
    api_fail("That password isn't right.", 403, ["password" => "That password isn't right."]);
}

try {
    $removed = account_remove_own($conn, $userId);
} catch (AccountRemovalError $error) {
    api_fail($error->getMessage(), 409);
} catch (Throwable $error) {
    api_fail("Could not delete the account. Please try again.", 500, [], $error);
}
api_success(["deleted" => true, "cancelled_visits" => $removed["cancelled_visits"]], 200, "Your account was deleted.");
