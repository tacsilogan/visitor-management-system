<?php
declare(strict_types=1);

header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store, max-age=0");
header("X-Content-Type-Options: nosniff");

require_once dirname(__DIR__, 2) . "/db.php";

const MOBILE_API_VERSION = "1.0";
const MOBILE_API_MAX_BODY_BYTES = 1048576;

$GLOBALS["mobile_api_request_id"] = bin2hex(random_bytes(8));
$GLOBALS["mobile_api_user"] = null;
$GLOBALS["mobile_api_access_token"] = null;

function api_config(): array
{
    static $config = null;
    if (is_array($config)) {
        return $config;
    }
    $config = [
        "environment" => getenv("VISITOR_APP_ENV") ?: "production",
        "public_app_url" => getenv("VISITOR_APP_PUBLIC_URL") ?: "",
        "firebase_project_id" => getenv("FIREBASE_PROJECT_ID") ?: "",
        "firebase_service_account_file" => getenv("FIREBASE_SERVICE_ACCOUNT_FILE") ?: "",
    ];
    $localFile = dirname(__DIR__, 2) . "/config/mobile_api.php";
    if (is_file($localFile)) {
        $local = require $localFile;
        if (is_array($local)) {
            $config = array_replace($config, $local);
        }
    }
    return $config;
}

function api_is_development(): bool
{
    return strtolower((string) (api_config()["environment"] ?? "production")) === "development";
}

function api_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    $payload["meta"] = array_merge(
        ["api_version" => MOBILE_API_VERSION, "request_id" => $GLOBALS["mobile_api_request_id"]],
        isset($payload["meta"]) && is_array($payload["meta"]) ? $payload["meta"] : []
    );
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function api_success(array $data = [], int $status = 200, string $message = ""): never
{
    $payload = ["success" => true, "data" => $data];
    if ($message !== "") {
        $payload["message"] = $message;
    }
    api_json($payload, $status);
}

/**
 * Ends the request with an error. Pass the [cause] of an unexpected failure: the app only
 * sees [message], and the real reason goes to the server log (Railway's log) with the
 * request id the app also receives.
 */
function api_fail(string $message, int $status = 400, array $errors = [], ?Throwable $cause = null): never
{
    if ($cause !== null) {
        error_log(sprintf(
            "[api %s] %s %s failed: %s: %s at %s:%d",
            $GLOBALS["mobile_api_request_id"] ?? "-",
            $_SERVER["REQUEST_METHOD"] ?? "",
            strtok((string) ($_SERVER["REQUEST_URI"] ?? ""), "?") ?: "",
            get_class($cause),
            $cause->getMessage(),
            basename($cause->getFile()),
            $cause->getLine()
        ));
    }
    $payload = ["success" => false, "message" => $message];
    if ($errors) {
        $payload["errors"] = $errors;
    }
    api_json($payload, $status);
}

function api_require_method(string ...$methods): void
{
    $method = strtoupper((string) ($_SERVER["REQUEST_METHOD"] ?? "GET"));
    $allowed = array_map("strtoupper", $methods);
    if (!in_array($method, $allowed, true)) {
        header("Allow: " . implode(", ", $allowed));
        api_fail("Method not allowed", 405);
    }
}

function api_body(): array
{
    $contentLength = (int) ($_SERVER["CONTENT_LENGTH"] ?? 0);
    if ($contentLength > MOBILE_API_MAX_BODY_BYTES) {
        api_fail("Request body is too large", 413);
    }
    $raw = file_get_contents("php://input");
    if ($raw === false || trim($raw) === "") {
        return [];
    }
    if (strlen($raw) > MOBILE_API_MAX_BODY_BYTES) {
        api_fail("Request body is too large", 413);
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        api_fail("Request body must be valid JSON", 400);
    }
    return $data;
}

function api_text(array $input, string $key, int $maxLength = 0): string
{
    $value = trim((string) ($input[$key] ?? ""));
    return $maxLength > 0 && strlen($value) > $maxLength ? substr($value, 0, $maxLength) : $value;
}

function api_client_ip(): string
{
    return substr((string) ($_SERVER["REMOTE_ADDR"] ?? ""), 0, 45);
}

function api_bearer_token(): string
{
    $header = (string) ($_SERVER["HTTP_AUTHORIZATION"] ?? "");
    if ($header === "" && function_exists("getallheaders")) {
        $headers = getallheaders();
        $header = (string) ($headers["Authorization"] ?? $headers["authorization"] ?? "");
    }
    if (!preg_match('/^Bearer\s+([A-Za-z0-9_-]{43,128})$/i', trim($header), $match)) {
        return "";
    }
    return $match[1];
}

function api_require_visitor(): array
{
    global $conn;
    $rawToken = api_bearer_token();
    if ($rawToken === "") {
        api_fail("Authentication required", 401);
    }
    $hash = hash("sha256", $rawToken);
    $stmt = $conn->prepare(
        "SELECT t.id AS access_token_id, t.installation_id, t.device_name, t.expires_at,
                u.id, u.username, u.email, u.display_name, u.contact_number,
                u.email_verified_at, u.role, u.is_active
         FROM api_access_tokens t
         INNER JOIN app_users u ON u.id = t.user_id
         WHERE t.token_hash = ? AND t.revoked_at IS NULL AND t.expires_at > NOW()
         LIMIT 1"
    );
    if (!$stmt) {
        api_fail("Mobile API database migration is required", 503);
    }
    $stmt->bind_param("s", $hash);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row || !(int) $row["is_active"] || $row["role"] !== "visitor") {
        api_fail("Invalid or expired access token", 401);
    }
    $tokenId = (int) $row["access_token_id"];
    $touch = $conn->prepare("UPDATE api_access_tokens SET last_used_at = NOW() WHERE id = ?");
    if ($touch) {
        $touch->bind_param("i", $tokenId);
        $touch->execute();
        $touch->close();
    }
    $row["id"] = (int) $row["id"];
    $row["access_token_id"] = $tokenId;
    $GLOBALS["mobile_api_user"] = $row;
    $GLOBALS["mobile_api_access_token"] = $rawToken;
    return $row;
}

function api_issue_access_token(mysqli $conn, int $userId, string $installationId, string $deviceName): array
{
    $raw = rtrim(strtr(base64_encode(random_bytes(48)), "+/", "-_"), "=");
    $hash = hash("sha256", $raw);
    $days = 30;
    $setting = $conn->query("SELECT setting_value FROM mobile_api_settings WHERE setting_key = 'access_token_days' LIMIT 1");
    if ($setting && ($row = $setting->fetch_assoc())) {
        $days = max(1, min(90, (int) $row["setting_value"]));
    }
    $expires = (new DateTimeImmutable("now"))->modify("+{$days} days")->format("Y-m-d H:i:s");
    $userAgent = substr((string) ($_SERVER["HTTP_USER_AGENT"] ?? ""), 0, 255);
    $stmt = $conn->prepare(
        "INSERT INTO api_access_tokens
         (user_id, token_hash, installation_id, device_name, user_agent, last_used_at, expires_at)
         VALUES (?, ?, ?, ?, ?, NOW(), ?)"
    );
    if (!$stmt) {
        api_fail("Mobile API database migration is required", 503);
    }
    $stmt->bind_param("isssss", $userId, $hash, $installationId, $deviceName, $userAgent, $expires);
    $stmt->execute();
    $id = (int) $conn->insert_id;
    $stmt->close();
    return ["id" => $id, "token" => $raw, "expires_at" => $expires];
}

function api_revoke_token(mysqli $conn, int $tokenId): void
{
    $stmt = $conn->prepare("UPDATE api_access_tokens SET revoked_at = NOW() WHERE id = ? AND revoked_at IS NULL");
    if ($stmt) {
        $stmt->bind_param("i", $tokenId);
        $stmt->execute();
        $stmt->close();
    }
}

function api_rate_limit(mysqli $conn, string $scope, string $identity, int $limit, int $windowSeconds): void
{
    $key = hash("sha256", $scope . "|" . strtolower(trim($identity)) . "|" . api_client_ip());
    $stmt = $conn->prepare(
        "SELECT attempt_count, window_started_at, blocked_until
         FROM api_rate_limits WHERE bucket_key = ? LIMIT 1"
    );
    if (!$stmt) {
        api_fail("Mobile API database migration is required", 503);
    }
    $stmt->bind_param("s", $key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $now = time();
    if ($row && $row["blocked_until"] && strtotime((string) $row["blocked_until"]) > $now) {
        $retry = max(1, strtotime((string) $row["blocked_until"]) - $now);
        header("Retry-After: " . $retry);
        api_fail("Too many attempts. Try again later.", 429);
    }
    $windowStart = $row ? strtotime((string) $row["window_started_at"]) : 0;
    if (!$row || !$windowStart || $windowStart + $windowSeconds <= $now) {
        $upsert = $conn->prepare(
            "INSERT INTO api_rate_limits (bucket_key, attempt_count, window_started_at, blocked_until)
             VALUES (?, 1, NOW(), NULL)
             ON DUPLICATE KEY UPDATE attempt_count = 1, window_started_at = NOW(), blocked_until = NULL"
        );
        $upsert->bind_param("s", $key);
        $upsert->execute();
        $upsert->close();
        return;
    }
    $attempts = (int) $row["attempt_count"] + 1;
    $blockedUntil = $attempts > $limit ? date("Y-m-d H:i:s", $windowStart + $windowSeconds) : null;
    $update = $conn->prepare("UPDATE api_rate_limits SET attempt_count = ?, blocked_until = ? WHERE bucket_key = ?");
    $update->bind_param("iss", $attempts, $blockedUntil, $key);
    $update->execute();
    $update->close();
    if ($blockedUntil !== null) {
        header("Retry-After: " . max(1, strtotime($blockedUntil) - $now));
        api_fail("Too many attempts. Try again later.", 429);
    }
}

function api_password_validation_error(string $password): string
{
    if (strlen($password) < 10) {
        return "Password must contain at least 10 characters";
    }
    if (strlen($password) > 128) {
        return "Password is too long";
    }
    if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
        return "Password must include at least one letter and one number";
    }
    return "";
}

function api_create_account_action_token(
    mysqli $conn,
    int $userId,
    string $email,
    string $purpose,
    int $validMinutes
): string {
    $raw = bin2hex(random_bytes(32));
    $hash = hash("sha256", $raw);
    $expires = (new DateTimeImmutable("now"))->modify("+{$validMinutes} minutes")->format("Y-m-d H:i:s");
    $invalidate = $conn->prepare(
        "UPDATE account_action_tokens SET consumed_at = NOW()
         WHERE user_id = ? AND purpose = ? AND consumed_at IS NULL"
    );
    $invalidate->bind_param("is", $userId, $purpose);
    $invalidate->execute();
    $invalidate->close();
    $insert = $conn->prepare(
        "INSERT INTO account_action_tokens (user_id, purpose, token_hash, expires_at, requested_ip)
         VALUES (?, ?, ?, ?, ?)"
    );
    $ip = api_client_ip();
    $insert->bind_param("issss", $userId, $purpose, $hash, $expires, $ip);
    $insert->execute();
    $insert->close();
    $template = $purpose === "verify_email" ? "verify_email" : "password_reset";
    $subject = $purpose === "verify_email" ? "Verify your visitor account" : "Reset your visitor account password";
    $payload = json_encode(["token" => $raw, "expires_at" => $expires], JSON_UNESCAPED_SLASHES);
    $emailStmt = $conn->prepare(
        "INSERT INTO outbound_emails (user_id, recipient_email, template_key, subject, payload_json)
         VALUES (?, ?, ?, ?, ?)"
    );
    $emailStmt->bind_param("issss", $userId, $email, $template, $subject, $payload);
    $emailStmt->execute();
    $emailStmt->close();
    return $raw;
}

function api_user_payload(array $user): array
{
    return [
        "id" => (int) $user["id"],
        "email" => $user["email"] ?: null,
        "full_name" => (string) $user["display_name"],
        "contact_number" => (string) ($user["contact_number"] ?? ""),
        "email_verified" => !empty($user["email_verified_at"]),
        "role" => "visitor",
    ];
}

function api_setting(mysqli $conn, string $key, int $default, int $minimum, int $maximum): int
{
    $stmt = $conn->prepare("SELECT setting_value FROM mobile_api_settings WHERE setting_key = ? LIMIT 1");
    if (!$stmt) {
        return $default;
    }
    $stmt->bind_param("s", $key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $value = $row ? (int) $row["setting_value"] : $default;
    return max($minimum, min($maximum, $value));
}

function api_audit(mysqli $conn, int $userId, string $action, string $entityType, string $entityId, array $details = [], ?int $appointmentId = null): void
{
    $stmt = $conn->prepare(
        "INSERT INTO audit_logs
         (actor_user_id, appointment_id, action, entity_type, entity_id, details_json, ip_address)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    );
    if (!$stmt) {
        return;
    }
    $json = json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $ip = api_client_ip();
    $stmt->bind_param("iisssss", $userId, $appointmentId, $action, $entityType, $entityId, $json, $ip);
    $stmt->execute();
    $stmt->close();
}
