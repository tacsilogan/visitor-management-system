<?php
declare(strict_types=1);

if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}

/*
 * Creates or updates the database tables, so a new server needs no manual SQL imports.
 *
 *   php tools/setup_database.php            apply whatever is missing (safe to repeat)
 *   php tools/setup_database.php --status   show what is applied and change nothing
 *   php tools/setup_database.php --wait     first wait up to 90 seconds for the database
 *   php tools/setup_database.php --secure-default-accounts
 *        give the seeded staff accounts that still use the published password "password"
 *        a temporary password (printed once, valid 7 days, replaced at first sign-in) and
 *        turn off the demo visitor account
 *
 * The Docker image runs it with --wait --secure-default-accounts on every start
 * (DEPLOYMENT.md). It imports the files TEAMMATE_SETUP.md lists, in the same order, with
 * the mysql command-line client (which understands their DELIMITER blocks), and records each
 * one in schema_migrations. The first three create the original tables and are only imported
 * when those tables are missing; the migrations after them are safe to run on a database that
 * already has them. An empty database also receives the ISATU campus map.
 */

require_once dirname(__DIR__) . "/runtime.php";

/** File => the table an original-schema file creates (null for a repeatable migration). */
const SETUP_FILES = [
    "phone_tracker.sql" => "locations",
    "login_users.sql" => "app_users",
    "appointments.sql" => "appointments",
    "office_catalog_migration.sql" => null,
    "app_users_profile_migration.sql" => null,
    "mobile_api_migration.sql" => null,
    "multi_stop_visits_migration.sql" => null,
    "campus_map_migration.sql" => null,
    "auth_security_migration.sql" => null,
    "personnel_directory_migration.sql" => null,
    "live_monitoring_migration.sql" => null,
    "visitor_arrival_migration.sql" => null,
    "campus_routes_migration.sql" => null,
];
const SETUP_CAMPUS_SEED = "campus_map_isatu_seed.sql";
const SETUP_PUBLISHED_PASSWORD = "password";
const SETUP_TEMPORARY_PASSWORD_DAYS = 7;

function setup_say(string $message): void
{
    fwrite(STDOUT, $message . "\n");
}

function setup_fail(string $message): never
{
    fwrite(STDERR, "Database setup stopped: " . $message . "\n");
    exit(1);
}

function setup_connect(array $db, int $waitSeconds): mysqli
{
    $deadline = time() + $waitSeconds;
    $announced = false;
    while (true) {
        try {
            $conn = new mysqli($db["host"], $db["user"], $db["password"], $db["name"], $db["port"]);
            $conn->set_charset("utf8mb4");
            $conn->query("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci, time_zone = '+08:00'"); // as db.php
            return $conn;
        } catch (Throwable $error) {
            if (time() >= $deadline) {
                setup_fail("cannot reach the database " . $db["name"] . " at " . $db["host"] . ":" . $db["port"]
                    . " as " . $db["user"] . " (" . $error->getMessage() . ").");
            }
            if (!$announced) {
                setup_say("Waiting for the database at " . $db["host"] . ":" . $db["port"] . " ...");
                $announced = true;
            }
            sleep(3);
        }
    }
}

function setup_table_exists(mysqli $conn, string $table): bool
{
    $stmt = $conn->prepare("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1");
    $stmt->bind_param("s", $table);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $exists;
}

/** The mysql (or mariadb) command-line client: ISATU_MYSQL_CLIENT, the PATH, or XAMPP's. */
function setup_find_client(): ?string
{
    $configured = isatu_env("ISATU_MYSQL_CLIENT");
    if ($configured !== null) {
        return is_file($configured) ? $configured : null;
    }
    $windows = PHP_OS_FAMILY === "Windows";
    $names = $windows ? ["mariadb.exe", "mysql.exe"] : ["mariadb", "mysql"];
    foreach (explode(PATH_SEPARATOR, (string) getenv("PATH")) as $folder) {
        foreach ($names as $name) {
            $path = rtrim($folder, "/\\") . DIRECTORY_SEPARATOR . $name;
            if ($folder !== "" && is_file($path) && ($windows || is_executable($path))) {
                return $path;
            }
        }
    }
    $xampp = "C:\\xampp\\mysql\\bin\\mysql.exe";
    return $windows && is_file($xampp) ? $xampp : null;
}

/** Imports one SQL file with the command-line client; stops the setup if it fails. */
function setup_import(string $client, array $db, string $file): void
{
    $path = dirname(__DIR__) . DIRECTORY_SEPARATOR . $file;
    if (!is_file($path)) {
        setup_fail($file . " is missing from phone_tracker.");
    }
    // The password goes in a private option file, never on the command line.
    $options = tempnam(sys_get_temp_dir(), "isatu_db_");
    file_put_contents($options, "[client]\npassword=\"" . addcslashes($db["password"], "\\\"") . "\"\n");
    @chmod($options, 0600);
    $outputFile = tempnam(sys_get_temp_dir(), "isatu_db_");
    $errorFile = tempnam(sys_get_temp_dir(), "isatu_db_");
    $command = [
        $client,
        "--defaults-extra-file=" . $options,
        "--protocol=TCP",
        "--host=" . $db["host"],
        "--port=" . $db["port"],
        "--user=" . $db["user"],
        "--default-character-set=utf8mb4",
        "--database=" . $db["name"],
    ];
    $process = proc_open($command, [0 => ["file", $path, "r"], 1 => ["file", $outputFile, "w"], 2 => ["file", $errorFile, "w"]], $pipes);
    $exitCode = is_resource($process) ? proc_close($process) : -1;
    $errors = trim((string) @file_get_contents($errorFile));
    @unlink($options);
    @unlink($outputFile);
    @unlink($errorFile);
    if ($exitCode !== 0) {
        setup_fail($file . " could not be imported (exit code " . $exitCode . "): " . ($errors !== "" ? $errors : "no details"));
    }
}

function setup_record(mysqli $conn, string $file, string $outcome): void
{
    $stmt = $conn->prepare(
        "INSERT INTO schema_migrations (filename, outcome) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE outcome = VALUES(outcome), applied_at = NOW()"
    );
    $stmt->bind_param("ss", $file, $outcome);
    $stmt->execute();
    $stmt->close();
}

function setup_temporary_password(): string
{
    // No look-alike characters (0/O, 1/l/I), grouped so it is easy to type.
    $alphabet = "abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789";
    $groups = [];
    for ($group = 0; $group < 3; $group++) {
        $text = "";
        for ($index = 0; $index < 4; $index++) {
            $text .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $groups[] = $text;
    }
    return implode("-", $groups);
}

function setup_audit(mysqli $conn, int $userId, string $action): void
{
    $stmt = $conn->prepare(
        "INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, details_json, ip_address)
         VALUES (NULL, ?, 'app_user', ?, ?, '')"
    );
    $entityId = (string) $userId;
    $details = json_encode(["by" => "command_line", "reason" => "published_default_password"]);
    $stmt->bind_param("sss", $action, $entityId, $details);
    $stmt->execute();
    $stmt->close();
}

/**
 * The seeded accounts (admin, security, offices, visitor) share the published password
 * "password", which anyone could use on a public server before the real owner signs in.
 */
function setup_secure_default_accounts(mysqli $conn): void
{
    $result = $conn->query(
        "SELECT id, username, role, password_hash FROM app_users
         WHERE is_active = 1 AND role IN ('admin', 'security', 'offices', 'visitor')
         ORDER BY id"
    );
    $issued = [];
    while ($user = $result->fetch_assoc()) {
        if (!password_verify(SETUP_PUBLISHED_PASSWORD, (string) $user["password_hash"])) {
            continue;
        }
        $userId = (int) $user["id"];
        if ($user["role"] === "visitor") {
            // Also replace the password, so turning the account back on does not revive it.
            $unusable = password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE app_users SET is_active = 0, password_hash = ?, session_version = session_version + 1 WHERE id = ?");
            $stmt->bind_param("si", $unusable, $userId);
            $stmt->execute();
            $stmt->close();
            setup_audit($conn, $userId, "user.suspended");
            setup_say("Turned off the demo visitor account \"" . $user["username"] . "\"; visitors create their own accounts in the app.");
            continue;
        }
        $chosen = (string) isatu_env("ISATU_ADMIN_PASSWORD", "");
        $password = $user["role"] === "admin" && strlen($chosen) >= 10 ? $chosen : setup_temporary_password();
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $days = SETUP_TEMPORARY_PASSWORD_DAYS;
        $stmt = $conn->prepare(
            "UPDATE app_users
             SET password_hash = ?, must_change_password = 1,
                 temp_password_expires_at = NOW() + INTERVAL {$days} DAY,
                 session_version = session_version + 1
             WHERE id = ?"
        );
        $stmt->bind_param("si", $hash, $userId);
        $stmt->execute();
        $stmt->close();
        setup_audit($conn, $userId, "user.password_reset");
        $issued[] = [(string) $user["username"], $password === $chosen ? "(the ISATU_ADMIN_PASSWORD value)" : $password];
    }
    if (!$issued) {
        return;
    }
    $expires = date("F j, Y g:i A", time() + SETUP_TEMPORARY_PASSWORD_DAYS * 86400);
    setup_say(str_repeat("=", 68));
    setup_say(" FIRST SIGN-IN: these accounts no longer accept the password \"password\".");
    setup_say(" Temporary passwords (shown only this once, valid until " . $expires . "):");
    foreach ($issued as [$username, $password]) {
        setup_say(sprintf("   %-12s %s", $username, $password));
    }
    setup_say(" Each account chooses its own password at its first sign-in.");
    setup_say(str_repeat("=", 68));
}

$arguments = array_slice($argv, 1);
$known = ["--wait", "--status", "--secure-default-accounts"];
if (array_diff($arguments, $known)) {
    fwrite(STDERR, "Usage: php tools/setup_database.php [--status] [--wait] [--secure-default-accounts]\n");
    exit(1);
}
$statusOnly = in_array("--status", $arguments, true);

try {
    $db = isatu_db_settings();
} catch (Throwable $error) {
    setup_fail($error->getMessage());
}
$conn = setup_connect($db, in_array("--wait", $arguments, true) ? 90 : 0);

// Two servers starting at once must not import the same file twice.
if ((int) $conn->query("SELECT GET_LOCK('isatu_vms_setup', 300)")->fetch_row()[0] !== 1) {
    setup_fail("another setup did not finish within 5 minutes.");
}

$tableCount = (int) $conn->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()")->fetch_row()[0];
$recorded = [];
if (setup_table_exists($conn, "schema_migrations")) {
    $result = $conn->query("SELECT filename, outcome, applied_at FROM schema_migrations");
    while ($row = $result->fetch_assoc()) {
        $recorded[$row["filename"]] = $row["outcome"] . " " . $row["applied_at"];
    }
}

if ($statusOnly) {
    setup_say("Database " . $db["name"] . " at " . $db["host"] . ":" . $db["port"] . " (" . $tableCount . " tables)");
    foreach (array_merge(array_keys(SETUP_FILES), [SETUP_CAMPUS_SEED]) as $file) {
        setup_say(sprintf("  %-36s %s", $file, $recorded[$file] ?? "not recorded"));
    }
    exit(0);
}

if ($tableCount === 0) {
    // Match the text rules every table uses, whatever the server's default.
    $conn->query("ALTER DATABASE `" . str_replace("`", "``", $db["name"]) . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    setup_say("The database is empty: creating the tables.");
}
$conn->query(
    "CREATE TABLE IF NOT EXISTS `schema_migrations` (
       `filename` varchar(190) NOT NULL,
       `outcome` varchar(16) NOT NULL DEFAULT 'applied',
       `applied_at` datetime NOT NULL DEFAULT current_timestamp(),
       PRIMARY KEY (`filename`)
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
);

$client = null;
$imported = 0;
foreach (SETUP_FILES as $file => $originalTable) {
    if (isset($recorded[$file])) {
        continue;
    }
    if ($originalTable !== null && setup_table_exists($conn, $originalTable)) {
        // Imported before this tool kept records; importing it again would fail.
        setup_record($conn, $file, "existing");
        continue;
    }
    $client = $client ?? setup_find_client();
    if ($client === null) {
        setup_fail("the mysql command-line client was not found. Install it (mariadb-client) or set ISATU_MYSQL_CLIENT to its path.");
    }
    setup_say("Importing " . $file . " ...");
    setup_import($client, $db, $file);
    setup_record($conn, $file, "applied");
    $imported++;
}

if (!isset($recorded[SETUP_CAMPUS_SEED])) {
    $placeCount = (int) $conn->query("SELECT COUNT(*) FROM campus_places")->fetch_row()[0];
    if ($placeCount === 0) {
        $client = $client ?? setup_find_client();
        if ($client === null) {
            setup_fail("the mysql command-line client was not found. Install it (mariadb-client) or set ISATU_MYSQL_CLIENT to its path.");
        }
        setup_say("Loading the ISATU campus map ...");
        setup_import($client, $db, SETUP_CAMPUS_SEED);
        setup_record($conn, SETUP_CAMPUS_SEED, "applied");
        $imported++;
    } else {
        // A map already exists; never replace it.
        setup_record($conn, SETUP_CAMPUS_SEED, "skipped");
    }
}

if (in_array("--secure-default-accounts", $arguments, true)) {
    setup_secure_default_accounts($conn);
}

$conn->query("SELECT RELEASE_LOCK('isatu_vms_setup')");
setup_say($imported > 0
    ? "Database is up to date (" . $imported . " file(s) imported)."
    : "Database is up to date.");
