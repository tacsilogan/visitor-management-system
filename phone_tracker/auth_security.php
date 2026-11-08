<?php

/*
 * Shared sign-in protection for the website and the mobile API: attempt history,
 * layered lockouts, password rules, temporary passwords, backup codes, and trusted
 * devices. It has no session dependency so api/v1 can use it too.
 *
 * Lockout layers (thresholds in auth_config.php):
 *   1. account_device: 5 failures for one account from one IP -> 10 min, doubling
 *      for each repeat within 24 h. Resets after a successful sign-in from that IP.
 *   2. account: 15 failures for one account within 60 min from 3+ IPs -> 60 min.
 *   3. ip: 20 failures from one IP across any accounts within 10 min -> 10 min.
 * Unknown usernames are tracked exactly like real ones, so responses never reveal
 * whether an account exists.
 */

require_once __DIR__ . "/auth_config.php";

const AUTH_COMMON_PASSWORDS = [
    "password", "password1", "password12", "password123", "password1234", "passw0rd", "p@ssw0rd",
    "p@ssword", "pa55word", "123456", "1234567", "12345678", "123456789", "1234567890",
    "12345678910", "0123456789", "987654321", "9876543210", "111111", "1111111111", "000000",
    "0000000000", "123123", "123123123", "1231231234", "123321", "654321", "666666", "121212",
    "112233", "11223344", "qwerty", "qwerty1", "qwerty12", "qwerty123", "qwerty1234", "qwertyuiop",
    "1q2w3e4r", "1q2w3e4r5t", "1q2w3e4r5t6y", "1qaz2wsx", "1qaz2wsx3edc", "zaq12wsx", "zaq1zaq1",
    "asdfghjkl", "asdf1234", "zxcvbnm", "zxcvbnm123", "abc123", "abc12345", "abcd1234",
    "abcdef123", "a1b2c3d4", "a1b2c3d4e5", "aa123456", "iloveyou", "iloveyou1", "iloveyou123",
    "admin", "admin123", "admin1234", "admin12345", "administrator", "welcome", "welcome1",
    "welcome123", "letmein", "letmein123", "monkey", "dragon", "sunshine", "princess", "football",
    "baseball", "basketball", "superman", "batman", "master", "master123", "hello123", "freedom",
    "whatever", "trustno1", "starwars", "shadow", "michael", "charlie", "computer", "internet",
    "secret", "secret123", "changeme", "changeme123", "default", "guest", "guest123", "test1234",
    "testing123", "isatu", "isatu123", "isatu2024", "isatu2025", "isatu2026", "isatu12345",
    "iloilo", "iloilo123", "philippines", "pilipinas", "visitor", "visitor123", "security",
    "security123", "office", "office123", "student", "student123", "teacher", "teacher123",
    "ilovegod", "jesus123", "godisgood", "mahalkita", "iloveu123",
];

// Common words that stay guessable with numbers or symbols added (e.g. "Password2026!").
const AUTH_COMMON_WORDS = [
    "password", "passw0rd", "p@ssw0rd", "qwerty", "qwertyuiop", "asdf", "asdfgh", "asdfghjkl",
    "zxcvbn", "zxcvbnm", "admin", "administrator", "welcome", "letmein", "iloveyou", "monkey",
    "dragon", "sunshine", "princess", "football", "baseball", "basketball", "superman", "batman",
    "master", "hello", "freedom", "whatever", "starwars", "shadow", "computer", "internet",
    "secret", "changeme", "default", "guest", "test", "testing", "login", "user", "abc", "abcd",
    "abcdef", "isatu", "iloilo", "philippines", "pilipinas", "visitor", "security", "office",
    "student", "teacher", "mahalkita", "summer", "winter",
];

const AUTH_COMMON_SEQUENCES = [
    "abcdefghijklmnopqrstuvwxyz",
    "01234567890",
    "qwertyuiopasdfghjklzxcvbnm",
    "qazwsxedcrfvtgbyhnujmikolp",
];

function auth_client_ip(): string
{
    return substr((string) ($_SERVER["REMOTE_ADDR"] ?? ""), 0, 45);
}

function auth_user_agent(): string
{
    return substr(trim((string) ($_SERVER["HTTP_USER_AGENT"] ?? "")), 0, 255);
}

/** A short "Chrome on Windows" style label for history lists. */
function auth_device_label(string $userAgent): string
{
    if ($userAgent === "") {
        return "Unknown device";
    }
    if (stripos($userAgent, "okhttp") !== false || stripos($userAgent, "Dalvik") !== false) {
        return "ISATU Visits app on Android";
    }
    $browsers = [
        "Edg/" => "Edge", "OPR/" => "Opera", "SamsungBrowser/" => "Samsung Internet",
        "Firefox/" => "Firefox", "Chrome/" => "Chrome", "CriOS/" => "Chrome", "Safari/" => "Safari",
    ];
    $browser = "Browser";
    foreach ($browsers as $needle => $name) {
        if (stripos($userAgent, $needle) !== false) {
            $browser = $name;
            break;
        }
    }
    $systems = [
        "Windows" => "Windows", "Android" => "Android", "iPhone" => "iPhone", "iPad" => "iPad",
        "CrOS" => "ChromeOS", "Mac OS X" => "macOS", "Linux" => "Linux",
    ];
    $system = "";
    foreach ($systems as $needle => $name) {
        if (stripos($userAgent, $needle) !== false) {
            $system = $name;
            break;
        }
    }
    return $system === "" ? $browser : $browser . " on " . $system;
}

function auth_normalize_identifier(string $identifier): string
{
    return strtolower(trim($identifier));
}

/** Lockouts and history are keyed by account id, or by the typed name if no account matches. */
function auth_subject_key(?array $user, string $identifier): string
{
    return hash("sha256", $user ? "u:" . (int) $user["id"] : "n:" . auth_normalize_identifier($identifier));
}

/** Names typed for accounts that do not exist are stored masked, in case a password was typed there. */
function auth_mask_identifier(string $identifier): string
{
    $value = trim($identifier);
    $length = mb_strlen($value, "UTF-8");
    if ($length === 0) {
        return "";
    }
    if ($length <= 3) {
        return str_repeat("*", $length);
    }
    return mb_substr($value, 0, 2, "UTF-8") . str_repeat("*", min(6, $length - 3)) . mb_substr($value, -1, 1, "UTF-8");
}

function auth_schema_ready(mysqli $conn): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    $checks = [
        "SELECT must_change_password, temp_password_expires_at, session_version, totp_secret, totp_enabled_at, totp_last_used_step, last_login_at, last_login_ip, email, password_changed_at FROM app_users LIMIT 0",
        "SELECT id FROM auth_login_attempts LIMIT 0",
        "SELECT id FROM auth_lockouts LIMIT 0",
        "SELECT id FROM user_backup_codes LIMIT 0",
        "SELECT id FROM user_trusted_devices LIMIT 0",
    ];
    try {
        foreach ($checks as $sql) {
            $result = $conn->query($sql);
            if ($result === false) {
                return $ready = false;
            }
            $result->free();
        }
    } catch (mysqli_sql_exception $error) {
        return $ready = false;
    }
    return $ready = true;
}

function auth_scalar(mysqli $conn, string $sql, string $types = "", array $params = []): int
{
    $stmt = $conn->prepare($sql);
    if ($types !== "") {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_row();
    $stmt->close();
    return $row ? (int) $row[0] : 0;
}

function auth_audit(mysqli $conn, ?int $actorUserId, string $action, string $entityType, string $entityId, array $details = []): void
{
    $stmt = $conn->prepare(
        "INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, details_json, ip_address)
         VALUES (?, ?, ?, ?, ?, ?)"
    );
    if (!$stmt) {
        return;
    }
    $json = json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $ip = auth_client_ip();
    $stmt->bind_param("isssss", $actorUserId, $action, $entityType, $entityId, $json, $ip);
    $stmt->execute();
    $stmt->close();
}

/**
 * @param array{subject_key:string, identifier:string, user_id:?int, ip:string, user_agent:string, channel:string, stage:string} $context
 */
function auth_attempt_context(?array $user, string $identifier, string $channel, string $stage): array
{
    return [
        "subject_key" => auth_subject_key($user, $identifier),
        "identifier" => substr($user ? (string) $user["username"] : auth_mask_identifier($identifier), 0, 190),
        "user_id" => $user ? (int) $user["id"] : null,
        "ip" => auth_client_ip(),
        "user_agent" => auth_user_agent(),
        "channel" => $channel,
        "stage" => $stage,
    ];
}

function auth_record_attempt(mysqli $conn, array $context, string $outcome, string $reason): int
{
    $stmt = $conn->prepare(
        "INSERT INTO auth_login_attempts
         (subject_key, identifier, user_id, ip_address, user_agent, channel, stage, outcome, reason)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $reason = substr($reason, 0, 40);
    $stmt->bind_param(
        "ssissssss",
        $context["subject_key"],
        $context["identifier"],
        $context["user_id"],
        $context["ip"],
        $context["user_agent"],
        $context["channel"],
        $context["stage"],
        $outcome,
        $reason
    );
    $stmt->execute();
    $id = (int) $stmt->insert_id;
    $stmt->close();

    // Keep the history bounded without needing a scheduled job.
    if (random_int(1, 50) === 1) {
        $days = AUTH_ATTEMPT_RETENTION_DAYS;
        $conn->query("DELETE FROM auth_login_attempts WHERE created_at < NOW() - INTERVAL {$days} DAY LIMIT 2000");
        $conn->query("DELETE FROM auth_lockouts WHERE locked_until < NOW() - INTERVAL {$days} DAY LIMIT 2000");
    }
    return $id;
}

/** The longest active lock that applies to this account and IP, or null. */
function auth_active_lock(mysqli $conn, string $subjectKey, string $ip): ?array
{
    $stmt = $conn->prepare(
        "SELECT id, scope, level, locked_until, GREATEST(1, TIMESTAMPDIFF(SECOND, NOW(), locked_until)) AS retry_after
         FROM auth_lockouts
         WHERE released_at IS NULL AND locked_until > NOW()
           AND ((scope = 'account_device' AND subject_key = ? AND ip_address = ?)
             OR (scope = 'account' AND subject_key = ?)
             OR (scope = 'ip' AND ip_address = ?))
         ORDER BY locked_until DESC
         LIMIT 1"
    );
    $stmt->bind_param("ssss", $subjectKey, $ip, $subjectKey, $ip);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function auth_note_blocked_attempt(mysqli $conn, int $lockId): void
{
    $stmt = $conn->prepare("UPDATE auth_lockouts SET blocked_attempts = blocked_attempts + 1 WHERE id = ?");
    $stmt->bind_param("i", $lockId);
    $stmt->execute();
    $stmt->close();
}

/** Minutes the next account+device lock would last (10, 20, 40 ... up to 24 h). */
function auth_next_lock_minutes(mysqli $conn, string $subjectKey, string $ip): int
{
    $hours = AUTH_LOCK_ESCALATION_HOURS;
    $previous = auth_scalar(
        $conn,
        "SELECT COUNT(*) FROM auth_lockouts
         WHERE scope = 'account_device' AND subject_key = ? AND ip_address = ?
           AND created_at > NOW() - INTERVAL {$hours} HOUR",
        "ss",
        [$subjectKey, $ip]
    );
    $level = min(12, $previous + 1);
    return (int) min(AUTH_LOCK_MAX_MINUTES, AUTH_LOCK_BASE_MINUTES * (2 ** ($level - 1)));
}

function auth_create_lock(mysqli $conn, string $scope, array $context, int $level, int $failures, int $attemptId, int $minutes): void
{
    $subject = $scope === "ip" ? "" : $context["subject_key"];
    $userId = $scope === "ip" ? null : $context["user_id"];
    $identifier = $scope === "ip" ? "" : $context["identifier"];
    $ip = $scope === "account" ? "" : $context["ip"];
    $stmt = $conn->prepare(
        "INSERT INTO auth_lockouts
         (scope, subject_key, user_id, identifier, ip_address, level, failure_count, trigger_attempt_id, locked_until)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW() + INTERVAL ? MINUTE)"
    );
    $stmt->bind_param("ssissiiii", $scope, $subject, $userId, $identifier, $ip, $level, $failures, $attemptId, $minutes);
    $stmt->execute();
    $lockId = (int) $stmt->insert_id;
    $stmt->close();
    auth_audit($conn, null, $scope === "account" ? "auth.account_alarm" : "auth.lockout", "auth_lockout", (string) $lockId, [
        "scope" => $scope,
        "user_id" => $userId,
        "identifier" => $identifier,
        "ip" => $context["ip"],
        "channel" => $context["channel"],
        "level" => $level,
        "failures" => $failures,
        "minutes" => $minutes,
    ]);
}

/**
 * Records a failed attempt and applies the three lockout layers.
 *
 * @return array{remaining:int, next_lock_minutes:int, lock:?array}
 */
function auth_register_failure(mysqli $conn, array $context, string $reason): array
{
    $attemptId = auth_record_attempt($conn, $context, "failure", $reason);
    $subject = $context["subject_key"];
    $ip = $context["ip"];

    // 1. One account from one IP. Counting restarts after a success or a previous lock.
    $floor = max(
        auth_scalar($conn, "SELECT COALESCE(MAX(id), 0) FROM auth_login_attempts WHERE subject_key = ? AND ip_address = ? AND outcome = 'success'", "ss", [$subject, $ip]),
        auth_scalar($conn, "SELECT COALESCE(MAX(trigger_attempt_id), 0) FROM auth_lockouts WHERE scope = 'account_device' AND subject_key = ? AND ip_address = ?", "ss", [$subject, $ip])
    );
    $hours = AUTH_LOCK_ESCALATION_HOURS;
    $failures = auth_scalar(
        $conn,
        "SELECT COUNT(*) FROM auth_login_attempts
         WHERE subject_key = ? AND ip_address = ? AND outcome = 'failure' AND id > ?
           AND created_at > NOW() - INTERVAL {$hours} HOUR",
        "ssi",
        [$subject, $ip, $floor]
    );
    $nextLockMinutes = auth_next_lock_minutes($conn, $subject, $ip);
    if ($failures >= AUTH_LOCK_THRESHOLD) {
        $previous = auth_scalar(
            $conn,
            "SELECT COUNT(*) FROM auth_lockouts WHERE scope = 'account_device' AND subject_key = ? AND ip_address = ? AND created_at > NOW() - INTERVAL {$hours} HOUR",
            "ss",
            [$subject, $ip]
        );
        auth_create_lock($conn, "account_device", $context, min(12, $previous + 1), $failures, $attemptId, $nextLockMinutes);
    }

    // 2. One account attacked from several IPs.
    $alarmFloor = auth_scalar($conn, "SELECT COALESCE(MAX(trigger_attempt_id), 0) FROM auth_lockouts WHERE scope = 'account' AND subject_key = ?", "s", [$subject]);
    $window = AUTH_ACCOUNT_ALARM_WINDOW_MINUTES;
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS failures, COUNT(DISTINCT ip_address) AS ips
         FROM auth_login_attempts
         WHERE subject_key = ? AND outcome = 'failure' AND id > ? AND created_at > NOW() - INTERVAL {$window} MINUTE"
    );
    $stmt->bind_param("si", $subject, $alarmFloor);
    $stmt->execute();
    $alarm = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ((int) $alarm["failures"] >= AUTH_ACCOUNT_ALARM_FAILURES && (int) $alarm["ips"] >= AUTH_ACCOUNT_ALARM_MIN_IPS) {
        auth_create_lock($conn, "account", $context, 1, (int) $alarm["failures"], $attemptId, AUTH_ACCOUNT_ALARM_LOCK_MINUTES);
    }

    // 3. One IP trying many accounts (password spraying).
    $ipFloor = auth_scalar($conn, "SELECT COALESCE(MAX(trigger_attempt_id), 0) FROM auth_lockouts WHERE scope = 'ip' AND ip_address = ?", "s", [$ip]);
    $ipWindow = AUTH_IP_WINDOW_MINUTES;
    $ipFailures = auth_scalar(
        $conn,
        "SELECT COUNT(*) FROM auth_login_attempts WHERE ip_address = ? AND outcome = 'failure' AND id > ? AND created_at > NOW() - INTERVAL {$ipWindow} MINUTE",
        "si",
        [$ip, $ipFloor]
    );
    if ($ipFailures >= AUTH_IP_FAILURES) {
        auth_create_lock($conn, "ip", $context, 1, $ipFailures, $attemptId, AUTH_IP_LOCK_MINUTES);
    }

    return [
        "remaining" => max(0, AUTH_LOCK_THRESHOLD - $failures),
        "next_lock_minutes" => $nextLockMinutes,
        "lock" => auth_active_lock($conn, $subject, $ip),
    ];
}

function auth_register_success(mysqli $conn, array $context, string $method): void
{
    auth_record_attempt($conn, $context, "success", $method);
}

function auth_lock_message(string $scope): string
{
    if ($scope === "account") {
        return "This account is temporarily locked because of unusual sign-in activity. Try again later or contact an administrator.";
    }
    if ($scope === "ip") {
        return "Too many failed sign-in attempts from this network. Please wait before trying again.";
    }
    return "Too many failed attempts. For your security, sign-in is paused for this account on this device.";
}

/** Releases every active lock on a user's account (admin unlock or password reset). */
function auth_release_user_locks(mysqli $conn, int $userId, ?int $releasedBy): int
{
    $subject = hash("sha256", "u:" . $userId);
    $stmt = $conn->prepare(
        "UPDATE auth_lockouts SET released_at = NOW(), released_by_user_id = ?
         WHERE released_at IS NULL AND locked_until > NOW() AND scope IN ('account_device', 'account') AND subject_key = ?"
    );
    $stmt->bind_param("is", $releasedBy, $subject);
    $stmt->execute();
    $count = $stmt->affected_rows;
    $stmt->close();
    return $count;
}

function auth_bump_session_version(mysqli $conn, int $userId): void
{
    $stmt = $conn->prepare("UPDATE app_users SET session_version = session_version + 1 WHERE id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $stmt->close();
}

/* ---------- Passwords ---------- */

function auth_password_policy(): array
{
    return [
        "min_length" => AUTH_PASSWORD_MIN_LENGTH,
        "max_length" => AUTH_PASSWORD_MAX_LENGTH,
        "common_passwords" => AUTH_COMMON_PASSWORDS,
        "common_words" => AUTH_COMMON_WORDS,
        "sequences" => AUTH_COMMON_SEQUENCES,
    ];
}

function auth_password_is_common(string $password): bool
{
    $lower = mb_strtolower($password, "UTF-8");
    if (in_array($lower, AUTH_COMMON_PASSWORDS, true)) {
        return true;
    }
    // A common word with only digits or symbols around it.
    $core = preg_replace('/^[\d\W_]+|[\d\W_]+$/u', "", $lower);
    if ($core !== null && $core !== "" && in_array($core, AUTH_COMMON_WORDS, true)) {
        return true;
    }
    foreach (AUTH_COMMON_SEQUENCES as $sequence) {
        if (str_contains($sequence, $lower) || str_contains(strrev($sequence), $lower)) {
            return true;
        }
    }
    return count(array_unique(mb_str_split($lower, 1, "UTF-8"))) < 4;
}

function auth_password_contains_identity(string $password, array $user): bool
{
    $lower = mb_strtolower($password, "UTF-8");
    $parts = [];
    foreach (["username", "email"] as $field) {
        $value = mb_strtolower(trim((string) ($user[$field] ?? "")), "UTF-8");
        if ($value !== "") {
            $parts[] = explode("@", $value)[0];
        }
    }
    $displayName = mb_strtolower((string) ($user["display_name"] ?? ""), "UTF-8");
    foreach (preg_split('/[^\p{L}\p{N}]+/u', $displayName) ?: [] as $word) {
        if (mb_strlen($word, "UTF-8") >= 4) {
            $parts[] = $word;
        }
    }
    foreach ($parts as $part) {
        if (mb_strlen($part, "UTF-8") >= 3 && str_contains($lower, $part)) {
            return true;
        }
    }
    return false;
}

/**
 * @return string[] what is wrong with the password; empty when it is acceptable
 */
function auth_password_problems(string $password, array $user = []): array
{
    if (!mb_check_encoding($password, "UTF-8")) {
        return ["The password contains unsupported characters."];
    }
    $problems = [];
    $length = mb_strlen($password, "UTF-8");
    if ($length < AUTH_PASSWORD_MIN_LENGTH) {
        $problems[] = "Use at least " . AUTH_PASSWORD_MIN_LENGTH . " characters.";
    }
    if ($length > AUTH_PASSWORD_MAX_LENGTH || strlen($password) > 72) {
        $problems[] = "Use " . AUTH_PASSWORD_MAX_LENGTH . " characters or fewer.";
    }
    if (!preg_match('/\p{L}/u', $password) || !preg_match('/\p{N}/u', $password)) {
        $problems[] = "Include at least one letter and one number.";
    }
    if (auth_password_is_common($password)) {
        $problems[] = "This password is too common or easy to guess.";
    }
    if (auth_password_contains_identity($password, $user)) {
        $problems[] = "Don't use your username or name in your password.";
    }
    return $problems;
}

/** A readable one-time password such as "Kq7m-Xr4p-Tz9w" (no look-alike characters). */
function auth_generate_temporary_password(): string
{
    $alphabet = "ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789";
    do {
        $value = "";
        for ($i = 0; $i < 12; $i++) {
            $value .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
    } while (!preg_match('/[A-Za-z]/', $value) || !preg_match('/\d/', $value));
    return implode("-", str_split($value, 4));
}

/* ---------- Backup codes ---------- */

/** @return string[] codes formatted as "xxxxx-xxxxx" */
function auth_generate_backup_codes(): array
{
    $alphabet = "abcdefghjkmnpqrstuvwxyz23456789";
    $codes = [];
    while (count($codes) < AUTH_BACKUP_CODE_COUNT) {
        $value = "";
        for ($i = 0; $i < 10; $i++) {
            $value .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $codes[$value] = substr($value, 0, 5) . "-" . substr($value, 5);
    }
    return array_values($codes);
}

function auth_normalize_backup_code(string $code): string
{
    return strtolower(preg_replace('/[^A-Za-z0-9]/', "", $code));
}

function auth_store_backup_codes(mysqli $conn, int $userId, array $codes): void
{
    $delete = $conn->prepare("DELETE FROM user_backup_codes WHERE user_id = ?");
    $delete->bind_param("i", $userId);
    $delete->execute();
    $delete->close();
    $insert = $conn->prepare("INSERT INTO user_backup_codes (user_id, code_hash) VALUES (?, ?)");
    foreach ($codes as $code) {
        $hash = password_hash(auth_normalize_backup_code($code), PASSWORD_DEFAULT);
        $insert->bind_param("is", $userId, $hash);
        $insert->execute();
    }
    $insert->close();
}

function auth_consume_backup_code(mysqli $conn, int $userId, string $code): bool
{
    $normalized = auth_normalize_backup_code($code);
    if (strlen($normalized) !== 10) {
        return false;
    }
    $stmt = $conn->prepare("SELECT id, code_hash FROM user_backup_codes WHERE user_id = ? AND used_at IS NULL");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    foreach ($rows as $row) {
        if (password_verify($normalized, (string) $row["code_hash"])) {
            $use = $conn->prepare("UPDATE user_backup_codes SET used_at = NOW() WHERE id = ? AND used_at IS NULL");
            $id = (int) $row["id"];
            $use->bind_param("i", $id);
            $use->execute();
            $used = $use->affected_rows === 1;
            $use->close();
            return $used;
        }
    }
    return false;
}

function auth_backup_codes_remaining(mysqli $conn, int $userId): int
{
    return auth_scalar($conn, "SELECT COUNT(*) FROM user_backup_codes WHERE user_id = ? AND used_at IS NULL", "i", [$userId]);
}

/* ---------- Trusted devices ---------- */

function auth_request_is_https(): bool
{
    if (!empty($_SERVER["HTTPS"]) && strtolower((string) $_SERVER["HTTPS"]) !== "off") {
        return true;
    }
    if (strtolower((string) ($_SERVER["HTTP_X_FORWARDED_PROTO"] ?? "")) === "https") {
        return true;
    }
    return (int) ($_SERVER["SERVER_PORT"] ?? 0) === 443;
}

function auth_trusted_device_valid(mysqli $conn, int $userId): bool
{
    $token = (string) ($_COOKIE[AUTH_TRUSTED_DEVICE_COOKIE] ?? "");
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return false;
    }
    $hash = hash("sha256", $token);
    $stmt = $conn->prepare(
        "SELECT id FROM user_trusted_devices
         WHERE user_id = ? AND token_hash = ? AND revoked_at IS NULL AND expires_at > NOW() LIMIT 1"
    );
    $stmt->bind_param("is", $userId, $hash);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        return false;
    }
    $touch = $conn->prepare("UPDATE user_trusted_devices SET last_used_at = NOW(), ip_address = ? WHERE id = ?");
    $ip = auth_client_ip();
    $id = (int) $row["id"];
    $touch->bind_param("si", $ip, $id);
    $touch->execute();
    $touch->close();
    return true;
}

function auth_current_trusted_device_hash(): string
{
    $token = (string) ($_COOKIE[AUTH_TRUSTED_DEVICE_COOKIE] ?? "");
    return preg_match('/^[a-f0-9]{64}$/', $token) ? hash("sha256", $token) : "";
}

function auth_trust_device(mysqli $conn, int $userId): void
{
    $token = bin2hex(random_bytes(32));
    $hash = hash("sha256", $token);
    $label = substr(auth_device_label(auth_user_agent()), 0, 120);
    $ip = auth_client_ip();
    $days = AUTH_TRUSTED_DEVICE_DAYS;
    $stmt = $conn->prepare(
        "INSERT INTO user_trusted_devices (user_id, token_hash, label, ip_address, last_used_at, expires_at)
         VALUES (?, ?, ?, ?, NOW(), NOW() + INTERVAL {$days} DAY)"
    );
    $stmt->bind_param("isss", $userId, $hash, $label, $ip);
    $stmt->execute();
    $stmt->close();
    setcookie(AUTH_TRUSTED_DEVICE_COOKIE, $token, [
        "expires" => time() + $days * 86400,
        "path" => "/",
        "secure" => auth_request_is_https(),
        "httponly" => true,
        "samesite" => "Strict",
    ]);
}

function auth_revoke_trusted_devices(mysqli $conn, int $userId, ?int $deviceId = null): int
{
    if ($deviceId === null) {
        $stmt = $conn->prepare("UPDATE user_trusted_devices SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL");
        $stmt->bind_param("i", $userId);
    } else {
        $stmt = $conn->prepare("UPDATE user_trusted_devices SET revoked_at = NOW() WHERE user_id = ? AND id = ? AND revoked_at IS NULL");
        $stmt->bind_param("ii", $userId, $deviceId);
    }
    $stmt->execute();
    $count = $stmt->affected_rows;
    $stmt->close();
    return $count;
}
