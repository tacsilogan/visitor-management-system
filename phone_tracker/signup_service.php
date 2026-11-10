<?php
/**
 * Creating a visitor account in the app, with a code sent to the email (PHASE4_MOBILE_API.md,
 * "Creating an account").
 *
 * Nothing is created until the visitor types the 6-digit code that was emailed to them, so an address
 * nobody can open never becomes an account. Until then the sign-up is a row in account_requests.
 * Only an HMAC of a code is stored (key: config/auth_secret.php), and the email queue drops the code
 * once it is sent.
 */

require_once __DIR__ . "/auth_totp.php";
require_once __DIR__ . "/mail_service.php";

/** How long a code works, how many wrong tries it allows, and how often a new one can be sent. */
const SIGNUP_CODE_MINUTES = 10;
const SIGNUP_CODE_TRIES = 5;
const SIGNUP_CODE_RESEND_SECONDS = 60;
/** Codes per sign-up. After that, the person starts again, which the per-network limit also counts. */
const SIGNUP_CODE_MAX_SENDS = 5;

/** Why a sign-up or a code was refused. $field names the form field the message belongs to. */
final class SignupError extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $reason,
        public readonly int $status = 422,
        public readonly string $field = "",
        public readonly array $extra = []
    ) {
        parent::__construct($message, $status);
    }
}

function signup_schema_ready(mysqli $conn): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    try {
        $ready = (bool) $conn->query("SELECT id, code_hash FROM account_requests LIMIT 0");
    } catch (mysqli_sql_exception $error) {
        $ready = false;
    }
    return $ready;
}

/** What is wrong with this email, or "" when it is fine. Any address a person can open will do. */
function signup_email_problem(string $email): string
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) ? "" : "Enter a valid email address";
}

function signup_new_code(): string
{
    return str_pad((string) random_int(0, 999999), 6, "0", STR_PAD_LEFT);
}

function signup_code_hash(int $requestId, string $code): string
{
    return hash_hmac("sha256", $requestId . ":" . $code, auth_secret_key());
}

function signup_account_exists(mysqli $conn, string $email): bool
{
    $stmt = $conn->prepare("SELECT 1 FROM app_users WHERE LOWER(email) = ? LIMIT 1");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $found = (bool) $stmt->get_result()->fetch_row();
    $stmt->close();
    return $found;
}

function signup_request_load(mysqli $conn, int $requestId, bool $forUpdate = false): ?array
{
    $stmt = $conn->prepare("SELECT * FROM account_requests WHERE id = ?" . ($forUpdate ? " FOR UPDATE" : ""));
    $stmt->bind_param("i", $requestId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/**
 * Saves a sign-up that still needs its code. Someone who starts again with the same email replaces
 * their earlier unconfirmed sign-up, so the number of codes sent carries over.
 *
 * @param array{email:string, password_hash:string, full_name:string, contact_number?:string,
 *     consent_version?:string, requested_ip?:string} $fields
 * @return int the request id
 */
function signup_save_request(mysqli $conn, array $fields): int
{
    $email = strtolower((string) $fields["email"]);
    if (signup_account_exists($conn, $email)) {
        throw new SignupError("An account already uses this email. Sign in instead.", "email_taken", 409, "email");
    }
    $open = $conn->prepare(
        "SELECT id FROM account_requests WHERE email = ? AND status = 'unconfirmed' ORDER BY id DESC LIMIT 1"
    );
    $open->bind_param("s", $email);
    $open->execute();
    $existing = $open->get_result()->fetch_assoc();
    $open->close();

    $columns = [
        "email" => $email,
        "password_hash" => (string) $fields["password_hash"],
        "full_name" => (string) $fields["full_name"],
        "contact_number" => (string) ($fields["contact_number"] ?? ""),
        "consent_version" => (string) ($fields["consent_version"] ?? ""),
        "requested_ip" => (string) ($fields["requested_ip"] ?? ""),
    ];
    $types = str_repeat("s", count($columns));
    $values = array_values($columns);
    if ($existing) {
        $set = implode(", ", array_map(fn(string $name): string => "`{$name}` = ?", array_keys($columns)));
        $stmt = $conn->prepare("UPDATE account_requests SET {$set}, code_attempts = 0 WHERE id = ?");
        $requestId = (int) $existing["id"];
        $stmt->bind_param($types . "i", ...[...$values, $requestId]);
        $stmt->execute();
        $stmt->close();
        return $requestId;
    }
    $names = implode(", ", array_map(fn(string $name): string => "`{$name}`", array_keys($columns)));
    $marks = implode(", ", array_fill(0, count($columns), "?"));
    $stmt = $conn->prepare("INSERT INTO account_requests ({$names}) VALUES ({$marks})");
    $stmt->bind_param($types, ...$values);
    $stmt->execute();
    $requestId = (int) $conn->insert_id;
    $stmt->close();
    return $requestId;
}

/**
 * Emails a new code for an unconfirmed sign-up, and tries to send it at once. An older code stops
 * working. Refused within a minute of the last code, and after SIGNUP_CODE_MAX_SENDS codes.
 *
 * @return array{request_id:int, email:string, expires_at:string, resend_after_seconds:int, sent_now:bool, code:string}
 *     code is for the caller's development mode only; never show it in production.
 */
function signup_send_code(mysqli $conn, int $requestId): array
{
    $conn->begin_transaction();
    try {
        $request = signup_request_load($conn, $requestId, true);
        if (!$request || $request["status"] !== "unconfirmed") {
            throw new SignupError("This sign-up has ended. Start again.", "request_closed", 410);
        }
        if (!empty($request["code_sent_at"])) {
            $waited = time() - strtotime((string) $request["code_sent_at"]);
            if ($waited < SIGNUP_CODE_RESEND_SECONDS) {
                $wait = SIGNUP_CODE_RESEND_SECONDS - $waited;
                throw new SignupError(
                    "Wait " . $wait . " seconds before asking for another code.",
                    "resend_wait",
                    429,
                    "",
                    ["retry_after" => $wait]
                );
            }
        }
        if ((int) $request["codes_sent"] >= SIGNUP_CODE_MAX_SENDS) {
            throw new SignupError("Too many codes were sent to this email. Try again tomorrow.", "too_many_codes", 429);
        }
        $code = signup_new_code();
        $hash = signup_code_hash($requestId, $code);
        $minutes = SIGNUP_CODE_MINUTES;
        $update = $conn->prepare(
            "UPDATE account_requests
             SET code_hash = ?, code_sent_at = NOW(), code_expires_at = NOW() + INTERVAL {$minutes} MINUTE,
                 code_attempts = 0, codes_sent = codes_sent + 1
             WHERE id = ?"
        );
        $update->bind_param("si", $hash, $requestId);
        $update->execute();
        $update->close();
        $expires = $conn->query("SELECT code_expires_at FROM account_requests WHERE id = " . $requestId)->fetch_row()[0];
        $payload = json_encode([
            "code" => $code,
            "minutes" => $minutes,
            "name" => (string) $request["full_name"],
            "expires_at" => $expires,
        ], JSON_UNESCAPED_SLASHES);
        $subject = "Your ISATU confirmation code";
        $template = "account_code";
        $email = (string) $request["email"];
        $queue = $conn->prepare(
            "INSERT INTO outbound_emails (user_id, recipient_email, template_key, subject, payload_json)
             VALUES (NULL, ?, ?, ?, ?)"
        );
        $queue->bind_param("ssss", $email, $template, $subject, $payload);
        $queue->execute();
        $emailId = (int) $conn->insert_id;
        $queue->close();
        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }
    // Outside the transaction: a slow email service never holds the sign-up locked.
    $sentNow = false;
    try {
        $sentNow = mail_send_now($conn, $emailId);
    } catch (Throwable $error) {
        error_log("The code for sign-up " . $requestId . " stays queued: " . $error->getMessage());
    }
    return [
        "request_id" => $requestId,
        "email" => $email,
        "expires_at" => (string) $expires,
        "resend_after_seconds" => SIGNUP_CODE_RESEND_SECONDS,
        "sent_now" => $sentNow,
        "code" => $code,
    ];
}

/**
 * Checks a code inside the caller's transaction and returns the sign-up, locked. A wrong code rolls the
 * transaction back, then counts the try on its own, so the count survives.
 */
function signup_verify_code(mysqli $conn, int $requestId, string $code): array
{
    $request = signup_request_load($conn, $requestId, true);
    $fail = function (string $message, string $reason, int $status, array $extra = []) use ($conn): never {
        $conn->rollback();
        throw new SignupError($message, $reason, $status, "code", $extra);
    };
    if (!$request || $request["status"] !== "unconfirmed" || empty($request["code_hash"])) {
        $fail("This sign-up has ended. Start again.", "request_closed", 410);
    }
    if (strtotime((string) $request["code_expires_at"]) <= time()) {
        $fail("This code has expired. Ask for a new one.", "code_expired", 410);
    }
    if ((int) $request["code_attempts"] >= SIGNUP_CODE_TRIES) {
        $fail("Too many wrong codes. Ask for a new one.", "too_many_tries", 429);
    }
    if (!preg_match('/^\d{6}$/', $code) || !hash_equals((string) $request["code_hash"], signup_code_hash($requestId, $code))) {
        $conn->rollback();
        $count = $conn->prepare("UPDATE account_requests SET code_attempts = code_attempts + 1 WHERE id = ?");
        $count->bind_param("i", $requestId);
        $count->execute();
        $count->close();
        $left = max(0, SIGNUP_CODE_TRIES - (int) $request["code_attempts"] - 1);
        throw new SignupError(
            $left > 0 ? "That code is not right. " . $left . ($left === 1 ? " try" : " tries") . " left." : "Too many wrong codes. Ask for a new one.",
            $left > 0 ? "wrong_code" : "too_many_tries",
            $left > 0 ? 422 : 429,
            "code",
            ["tries_left" => $left]
        );
    }
    $confirm = $conn->prepare("UPDATE account_requests SET code_hash = NULL, email_confirmed_at = NOW() WHERE id = ?");
    $confirm->bind_param("i", $requestId);
    $confirm->execute();
    $confirm->close();
    $request["email_confirmed_at"] = date("Y-m-d H:i:s");
    return $request;
}

/** A username nobody has. A visitor signs in with their email, so it is made up: "visitor_3f9a…". */
function signup_unique_username(mysqli $conn): string
{
    $check = $conn->prepare("SELECT 1 FROM app_users WHERE username = ? LIMIT 1");
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $candidate = "visitor_" . substr(bin2hex(random_bytes(10)), 0, 16);
        $check->bind_param("s", $candidate);
        $check->execute();
        $taken = (bool) $check->get_result()->fetch_row();
        if (!$taken) {
            $check->close();
            return $candidate;
        }
    }
    $check->close();
    throw new RuntimeException("Could not choose a username");
}

/**
 * Turns a confirmed sign-up into a visitor account, inside the caller's transaction.
 *
 * @return int the new user's id
 */
function signup_create_account(mysqli $conn, array $request): int
{
    $email = (string) $request["email"];
    if (signup_account_exists($conn, $email)) {
        throw new SignupError("An account already uses this email. Sign in instead.", "email_taken", 409, "email");
    }
    $username = signup_unique_username($conn);
    $insert = $conn->prepare(
        "INSERT INTO app_users
         (username, email, password_hash, display_name, contact_number, email_verified_at, role, office_code, is_active)
         VALUES (?, ?, ?, ?, ?, NOW(), 'visitor', '', 1)"
    );
    $passwordHash = (string) $request["password_hash"];
    $fullName = (string) $request["full_name"];
    $contact = (string) $request["contact_number"];
    $insert->bind_param("sssss", $username, $email, $passwordHash, $fullName, $contact);
    $insert->execute();
    $userId = (int) $conn->insert_id;
    $insert->close();
    $requestId = (int) $request["id"];
    $done = $conn->prepare("UPDATE account_requests SET status = 'approved', user_id = ?, password_hash = '' WHERE id = ?");
    $done->bind_param("ii", $userId, $requestId);
    $done->execute();
    $done->close();
    return $userId;
}
