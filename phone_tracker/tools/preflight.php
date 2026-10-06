<?php
declare(strict_types=1);

if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}

/*
 * Checks that this server is ready for real visitors and prints what still needs doing.
 *
 *   php tools/preflight.php
 *
 * The Docker image runs it on every start, so the report is at the end of each deploy log.
 * Exit code 1 means at least one FAIL (something that would break the system or expose it);
 * WARN items work but deserve attention.
 */

require_once dirname(__DIR__) . "/runtime.php";
require_once dirname(__DIR__) . "/mail_service.php";

$failures = 0;
$warnings = 0;

function preflight_line(string $level, string $message): void
{
    global $failures, $warnings;
    if ($level === "FAIL") {
        $failures++;
    } elseif ($level === "WARN") {
        $warnings++;
    }
    fwrite(STDOUT, str_pad("[" . $level . "]", 7) . " " . $message . "\n");
}

function preflight_scalar(mysqli $conn, string $sql): string
{
    $row = $conn->query($sql)->fetch_row();
    return $row ? (string) $row[0] : "";
}

echo "ISATU Visitor Management readiness check, " . date("F j, Y g:i A") . "\n";

// PHP itself.
if (PHP_VERSION_ID < 80100) {
    preflight_line("FAIL", "PHP " . PHP_VERSION . " is too old; 8.1 or newer is required.");
} else {
    preflight_line("OK", "PHP " . PHP_VERSION);
}
foreach (["mysqli" => true, "openssl" => true, "mbstring" => true, "json" => true, "session" => true, "curl" => false] as $extension => $required) {
    if (!extension_loaded($extension)) {
        preflight_line($required ? "FAIL" : "WARN", "The PHP " . $extension . " extension is missing"
            . ($required ? "." : " (needed for push notifications and HTTPS email)."));
    }
}

// Settings that change how the server behaves in public.
$appEnvironment = strtolower((string) isatu_env("VISITOR_APP_ENV", "production"));
$mobileConfig = dirname(__DIR__) . "/config/mobile_api.php";
if (is_file($mobileConfig)) {
    $values = require $mobileConfig;
    if (is_array($values) && isset($values["environment"])) {
        $appEnvironment = strtolower((string) $values["environment"]);
    }
}
if ($appEnvironment === "development") {
    preflight_line("FAIL", "The mobile API is in development mode, which shows password-reset codes in its replies. Set VISITOR_APP_ENV=production.");
} else {
    preflight_line("OK", "Mobile API in production mode");
}
$inContainer = isatu_env_flag("ISATU_CONTAINER");
if (isatu_env_flag("ISATU_TRUST_PROXY")) {
    preflight_line("OK", "Visitor addresses and HTTPS are read from the hosting proxy (ISATU_TRUST_PROXY=1)");
} elseif ($inContainer) {
    preflight_line("WARN", "Behind a hosting proxy but ISATU_TRUST_PROXY is not 1: every visitor shares the proxy's address, so one person's failed sign-ins can lock out everyone.");
}

// In the Docker image, sign-ins, staff photos, and the two-step key live under /data, which
// only survives a redeploy when a volume is mounted there.
$volumeMounted = true;
if ($inContainer) {
    $dataDirectory = (string) isatu_env("ISATU_DATA_DIR", "/data");
    $dataInfo = @stat($dataDirectory);
    $rootInfo = @stat("/");
    $volumeMounted = is_array($dataInfo) && is_array($rootInfo) && $dataInfo["dev"] !== $rootInfo["dev"];
    if ($volumeMounted) {
        preflight_line("OK", "Persistent volume mounted at " . $dataDirectory);
    } else {
        preflight_line("WARN", "No volume is mounted at " . $dataDirectory . ", so every deploy signs everyone out and deletes staff photos. Add a volume with mount path " . $dataDirectory . ".");
    }
}

// The two-step verification key must survive redeploys.
$secretFromEnvironment = isatu_env("ISATU_AUTH_SECRET");
if ($secretFromEnvironment !== null) {
    if (preg_match('/^[a-fA-F0-9]{64}$/', trim($secretFromEnvironment))) {
        preflight_line("OK", "Two-step verification key set in ISATU_AUTH_SECRET");
    } else {
        preflight_line("FAIL", "ISATU_AUTH_SECRET must be exactly 64 hexadecimal characters.");
    }
} else {
    $secretFile = (string) isatu_env("ISATU_AUTH_SECRET_FILE", dirname(__DIR__) . "/config/auth_secret.php");
    if ($inContainer && (!$volumeMounted || str_starts_with($secretFile, dirname(__DIR__)))) {
        preflight_line("FAIL", "The two-step verification key would be lost on the next deploy, breaking every authenticator app. Set ISATU_AUTH_SECRET (DEPLOYMENT.md) or mount the /data volume.");
    } elseif (is_file($secretFile)) {
        preflight_line("OK", "Two-step verification key file: " . $secretFile);
    } elseif (is_writable(dirname($secretFile))) {
        preflight_line("OK", "Two-step verification key will be created at " . $secretFile);
    } else {
        preflight_line("FAIL", "The two-step verification key cannot be created: " . dirname($secretFile) . " is not writable.");
    }
}

// Email for visitor password resets.
$mailProblem = mail_configuration_problem();
if ($mailProblem === null) {
    $transport = mail_settings()["transport"];
    preflight_line("OK", "Email sends through " . $transport . " as " . mail_settings()["from_address"]
        . " (test it: php workers/send_outbound_emails.php --test you@example.com)");
} else {
    preflight_line("WARN", "Visitors cannot receive password-reset codes: " . $mailProblem);
}

// Push notifications are optional (the app also checks for news while open).
if (isatu_env("FIREBASE_SERVICE_ACCOUNT_JSON") === null && isatu_env("FIREBASE_SERVICE_ACCOUNT_FILE") === null && !is_file($mobileConfig)) {
    preflight_line("INFO", "Push notifications are not set up (optional; see DEPLOYMENT.md).");
}

// Folders the web server writes to.
$uploads = dirname(__DIR__) . "/uploads/profile_pictures";
if (!is_dir($uploads) || !is_writable($uploads)) {
    preflight_line("WARN", "Staff profile photos cannot be saved: " . $uploads . " is missing or not writable.");
}

// The database.
try {
    $db = isatu_db_settings();
    $conn = new mysqli($db["host"], $db["user"], $db["password"], $db["name"], $db["port"]);
    $conn->set_charset("utf8mb4");
    $conn->query("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci, time_zone = '+08:00'"); // as db.php
} catch (Throwable $error) {
    preflight_line("FAIL", "Cannot connect to the database: " . $error->getMessage());
    echo "\nResult: " . $failures . " problem(s) to fix, " . $warnings . " warning(s).\n";
    exit(1);
}
$server = preflight_scalar($conn, "SELECT VERSION()");
preflight_line("OK", "Database " . $db["name"] . " on " . $db["host"] . " (" . $server . ")");
if ($db["user"] === "root" && $db["host"] !== "localhost" && $db["host"] !== "127.0.0.1") {
    preflight_line("WARN", "The system connects to the database as root; a separate database user is safer.");
}
$clockDrift = abs((int) preflight_scalar($conn, "SELECT TIMESTAMPDIFF(SECOND, NOW(), '" . date("Y-m-d H:i:s") . "')"));
if ($clockDrift > 120) {
    preflight_line("FAIL", "The database clock and the web server clock differ by " . $clockDrift . " seconds; visit times would be wrong.");
}

$schemaChecks = [
    "app_users" => ["email", "session_version", "totp_secret", "first_name", "last_seen_at", "profile_image"],
    "appointments" => ["visit_id", "checkout_method", "arrived_at", "arrival_method"],
    "visitor_presence" => ["appointment_id"],
    "campus_places" => ["place_type", "placed_by"],
    "campus_routes" => ["office_code", "points_json"],
    "offices" => ["code"],
    "outbound_emails" => ["status"],
    "api_access_tokens" => ["id"],
    "user_trusted_devices" => ["id"],
    "mobile_api_settings" => ["setting_key"],
];
$missing = [];
foreach ($schemaChecks as $table => $columns) {
    try {
        $conn->query("SELECT `" . implode("`, `", $columns) . "` FROM `" . $table . "` LIMIT 0");
    } catch (Throwable $error) {
        $missing[] = $table;
    }
}
if ($missing) {
    preflight_line("FAIL", "The database is missing recent changes (" . implode(", ", $missing) . "). Run php tools/setup_database.php.");
    echo "\nResult: " . $failures . " problem(s) to fix, " . $warnings . " warning(s).\n";
    exit(1);
}
preflight_line("OK", "Database tables are up to date");

// Accounts.
$admins = (int) preflight_scalar($conn, "SELECT COUNT(*) FROM app_users WHERE role = 'admin' AND is_active = 1");
if ($admins === 0) {
    preflight_line("FAIL", "There is no active administrator account. Use php tools/auth_recovery.php to restore one.");
}
$published = [];
$result = $conn->query("SELECT username, password_hash FROM app_users WHERE is_active = 1 AND role IN ('admin', 'security', 'offices')");
while ($user = $result->fetch_assoc()) {
    if (password_verify("password", (string) $user["password_hash"])) {
        $published[] = $user["username"];
    }
}
if ($published) {
    preflight_line("FAIL", "These staff accounts still accept the published password \"password\": " . implode(", ", $published)
        . ". Run php tools/setup_database.php --secure-default-accounts.");
} else {
    preflight_line("OK", "No staff account uses the published default password");
}
$adminsWithTwoStep = (int) preflight_scalar($conn, "SELECT COUNT(*) FROM app_users WHERE role = 'admin' AND is_active = 1 AND totp_enabled_at IS NOT NULL");
if ($admins > 0 && $adminsWithTwoStep === 0) {
    preflight_line("INFO", "No administrator has two-step verification yet; it is set up at the first sign-in.");
}
$guards = (int) preflight_scalar($conn, "SELECT COUNT(*) FROM app_users WHERE role = 'security' AND is_active = 1");
if ($guards === 0) {
    preflight_line("WARN", "There is no active Security account, so nobody can scan visitor passes.");
}
$officesWithoutStaff = [];
$result = $conn->query(
    "SELECT o.name FROM offices o
     WHERE o.is_active = 1
       AND NOT EXISTS (SELECT 1 FROM app_users u WHERE u.role = 'offices' AND u.is_active = 1 AND u.office_code = o.code)
     ORDER BY o.sort_order, o.name"
);
while ($office = $result->fetch_assoc()) {
    $officesWithoutStaff[] = $office["name"];
}
if ($officesWithoutStaff) {
    preflight_line("WARN", "No Office Personnel account for: " . implode(", ", $officesWithoutStaff)
        . ". Their appointment requests and arrival notices reach nobody.");
}

// The campus map.
$boundary = (int) preflight_scalar($conn, "SELECT COUNT(*) FROM campus_places WHERE place_type = 'boundary'");
$officePins = (int) preflight_scalar($conn, "SELECT COUNT(*) FROM campus_places WHERE place_type = 'office'");
$gates = (int) preflight_scalar($conn, "SELECT COUNT(*) FROM campus_places WHERE place_type = 'gate'");
if ($boundary < 3) {
    preflight_line("WARN", "The campus boundary is not drawn, so visitor location sharing has no privacy limit and campus exits are not detected.");
} else {
    preflight_line("OK", "Campus map: " . $boundary . " boundary corners, " . $officePins . " office pin(s), " . $gates . " gate(s)");
}
$unpinned = [];
$result = $conn->query(
    "SELECT o.name FROM offices o
     WHERE o.is_active = 1
       AND NOT EXISTS (SELECT 1 FROM campus_places p WHERE p.place_type = 'office' AND p.office_code = o.code)
     ORDER BY o.sort_order, o.name"
);
while ($office = $result->fetch_assoc()) {
    $unpinned[] = $office["name"];
}
if ($unpinned) {
    preflight_line("WARN", "Not on the campus map yet: " . implode(", ", $unpinned) . ". Visitors to them get no walking directions.");
}
$activeDepartments = (int) preflight_scalar($conn, "SELECT COUNT(*) FROM offices WHERE is_active = 1");
$routedDepartments = (int) preflight_scalar(
    $conn,
    "SELECT COUNT(DISTINCT r.office_code) FROM campus_routes r INNER JOIN offices o ON o.code = r.office_code AND o.is_active = 1"
);
preflight_line("INFO", "Walking routes: " . $routedDepartments . " of " . $activeDepartments
    . " department(s) have one (record them in Campus Map > Routes).");

// Background jobs: anything left waiting means the scheduler is not running.
$stuckEmails = (int) preflight_scalar($conn, "SELECT COUNT(*) FROM outbound_emails WHERE status = 'queued' AND created_at < NOW() - INTERVAL 10 MINUTE");
$stuckPush = (int) preflight_scalar($conn, "SELECT COUNT(*) FROM notification_deliveries WHERE status = 'queued' AND created_at < NOW() - INTERVAL 10 MINUTE");
if ($stuckEmails > 0 && $mailProblem === null) {
    preflight_line("WARN", $stuckEmails . " email(s) have waited over 10 minutes: is workers/run_scheduled_jobs.php running?");
}
if ($stuckPush > 0 && (isatu_env("FIREBASE_SERVICE_ACCOUNT_JSON") !== null || isatu_env("FIREBASE_SERVICE_ACCOUNT_FILE") !== null)) {
    preflight_line("WARN", $stuckPush . " push notification(s) have waited over 10 minutes: is workers/run_scheduled_jobs.php running?");
}

echo "\nResult: " . ($failures === 0 ? "ready" : $failures . " problem(s) to fix") . ", " . $warnings . " warning(s).\n";
exit($failures > 0 ? 1 : 0);
