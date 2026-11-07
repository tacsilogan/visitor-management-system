<?php
/**
 * Accounts for ISATU people, and the email code every new account starts with (SCHOOL_ACCOUNTS.md).
 *
 * - A visitor or student signs up in the app. Nothing is created until they type the 6-digit code
 *   that was emailed to them. A student must use their @students.isatu.edu.ph email.
 * - A teacher asks for an account on the website with their @isatu.edu.ph email and the same kind of
 *   code. The Office Personnel of their department's Dean's Office then approve or reject it.
 *
 * Until then the person is a row in account_requests. Only an HMAC of a code is stored.
 */

require_once __DIR__ . "/auth_totp.php";
require_once __DIR__ . "/mail_service.php";
require_once __DIR__ . "/appointment_offices.php";
require_once __DIR__ . "/teacher_service.php";
require_once __DIR__ . "/visit_service.php";

const SCHOOL_STUDENT_DOMAIN = "students.isatu.edu.ph";
const SCHOOL_STAFF_DOMAIN = "isatu.edu.ph";
/** How long a code works, how many wrong tries it allows, and how often a new one can be sent. */
const SCHOOL_CODE_MINUTES = 10;
const SCHOOL_CODE_TRIES = 5;
const SCHOOL_CODE_RESEND_SECONDS = 60;
/** Codes per request. After that, the person starts again, which the per-network limit also counts. */
const SCHOOL_CODE_MAX_SENDS = 5;
/** Student years a student can choose. */
const SCHOOL_YEAR_LEVELS = [1, 2, 3, 4, 5];

/** Why a request or a code was refused. $field names the form field the message belongs to. */
final class SchoolAccountError extends RuntimeException
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

function school_schema_ready(mysqli $conn): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    try {
        $ready = (bool) $conn->query("SELECT id FROM account_requests LIMIT 0")
            && (bool) $conn->query("SELECT user_id FROM student_profiles LIMIT 0")
            && (bool) $conn->query("SELECT visitor_kind FROM appointments LIMIT 0")
            && (bool) $conn->query("SELECT approves_teachers FROM offices LIMIT 0");
    } catch (mysqli_sql_exception $error) {
        $ready = false;
    }
    return $ready;
}

function school_email_domain(string $email): string
{
    $at = strrpos($email, "@");
    return $at === false ? "" : strtolower(substr($email, $at + 1));
}

/**
 * What is wrong with this email for this kind of account, or "" when it is fine.
 * Visitors may use any address except a student's, who has an account type of their own.
 */
function school_email_problem(string $accountType, string $email): string
{
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return "Enter a valid email address";
    }
    $domain = school_email_domain($email);
    if ($accountType === "student" && $domain !== SCHOOL_STUDENT_DOMAIN) {
        return "Use your @" . SCHOOL_STUDENT_DOMAIN . " email";
    }
    if ($accountType === "teacher") {
        if ($domain === SCHOOL_STUDENT_DOMAIN) {
            return "This is a student email. Students sign up in the ISATU app.";
        }
        if ($domain !== SCHOOL_STAFF_DOMAIN) {
            return "Use your @" . SCHOOL_STAFF_DOMAIN . " email";
        }
    }
    if ($accountType === "visitor" && $domain === SCHOOL_STUDENT_DOMAIN) {
        return "This is a student email. Sign up as an ISATU student instead.";
    }
    return "";
}

/** A student number: digits, with dashes between them, as on an ISATU ID. */
function school_student_number_problem(string $number): string
{
    if ($number === "") {
        return "Enter your student number";
    }
    if (!preg_match('/^\d+(-\d+)*$/', $number) || strlen($number) < 4 || strlen($number) > 20) {
        return "Use only numbers and dashes, as on your ID";
    }
    return "";
}

function school_new_code(): string
{
    return str_pad((string) random_int(0, 999999), 6, "0", STR_PAD_LEFT);
}

function school_code_hash(int $requestId, string $code): string
{
    return hash_hmac("sha256", $requestId . ":" . $code, auth_secret_key());
}

function school_account_exists(mysqli $conn, string $email): bool
{
    $stmt = $conn->prepare("SELECT 1 FROM app_users WHERE LOWER(email) = ? LIMIT 1");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $found = (bool) $stmt->get_result()->fetch_row();
    $stmt->close();
    return $found;
}

function school_request_load(mysqli $conn, int $requestId, bool $forUpdate = false): ?array
{
    $stmt = $conn->prepare("SELECT * FROM account_requests WHERE id = ?" . ($forUpdate ? " FOR UPDATE" : ""));
    $stmt->bind_param("i", $requestId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/**
 * Saves a sign-up or a teacher request that still needs its code. Someone who starts again with the
 * same email replaces their earlier unconfirmed request, so the number of codes sent carries over.
 *
 * @param array $fields account_type, email, password_hash, full_name and the type's own details
 * @return int the request id
 */
function school_save_request(mysqli $conn, array $fields): int
{
    $email = strtolower((string) $fields["email"]);
    if (school_account_exists($conn, $email)) {
        throw new SchoolAccountError("An account already uses this email. Sign in instead.", "email_taken", 409, "email");
    }
    $open = $conn->prepare(
        "SELECT id, account_type, status FROM account_requests
         WHERE email = ? AND status IN ('unconfirmed', 'pending')
         ORDER BY id DESC LIMIT 1"
    );
    $open->bind_param("s", $email);
    $open->execute();
    $existing = $open->get_result()->fetch_assoc();
    $open->close();
    if ($existing && $existing["status"] === "pending") {
        throw new SchoolAccountError(
            "A teacher request with this email is waiting for the Dean's Office.",
            "request_pending",
            409,
            "email"
        );
    }

    $columns = [
        "account_type" => (string) $fields["account_type"],
        "email" => $email,
        "password_hash" => (string) $fields["password_hash"],
        "name_title" => (string) ($fields["name_title"] ?? ""),
        "first_name" => (string) ($fields["first_name"] ?? ""),
        "last_name" => (string) ($fields["last_name"] ?? ""),
        "full_name" => (string) $fields["full_name"],
        "contact_number" => (string) ($fields["contact_number"] ?? ""),
        "department_code" => isset($fields["department_code"]) ? (string) $fields["department_code"] : null,
        "course_code" => isset($fields["course_code"]) && $fields["course_code"] !== "" ? (string) $fields["course_code"] : null,
        "course_name" => (string) ($fields["course_name"] ?? ""),
        "student_number" => (string) ($fields["student_number"] ?? ""),
        "year_level" => isset($fields["year_level"]) ? (int) $fields["year_level"] : null,
        "section" => (string) ($fields["section"] ?? ""),
        "consent_version" => (string) ($fields["consent_version"] ?? ""),
        "requested_ip" => (string) ($fields["requested_ip"] ?? ""),
    ];
    $types = "";
    foreach ($columns as $name => $value) {
        $types .= $name === "year_level" ? "i" : "s";
    }
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
 * Emails a new code for an unconfirmed request, and tries to send it at once. An older code stops
 * working. Refused within a minute of the last code, and after SCHOOL_CODE_MAX_SENDS codes.
 *
 * @return array{request_id:int, email:string, expires_at:string, resend_after_seconds:int, sent_now:bool, code:string}
 *     code is for the caller's development mode only; never show it in production.
 */
function school_send_code(mysqli $conn, int $requestId): array
{
    $conn->begin_transaction();
    try {
        $request = school_request_load($conn, $requestId, true);
        if (!$request || $request["status"] !== "unconfirmed") {
            throw new SchoolAccountError("This sign-up has ended. Start again.", "request_closed", 410);
        }
        if (!empty($request["code_sent_at"])) {
            $waited = time() - strtotime((string) $request["code_sent_at"]);
            if ($waited < SCHOOL_CODE_RESEND_SECONDS) {
                $wait = SCHOOL_CODE_RESEND_SECONDS - $waited;
                throw new SchoolAccountError(
                    "Wait " . $wait . " seconds before asking for another code.",
                    "resend_wait",
                    429,
                    "",
                    ["retry_after" => $wait]
                );
            }
        }
        if ((int) $request["codes_sent"] >= SCHOOL_CODE_MAX_SENDS) {
            throw new SchoolAccountError("Too many codes were sent to this email. Try again tomorrow.", "too_many_codes", 429);
        }
        $code = school_new_code();
        $hash = school_code_hash($requestId, $code);
        $minutes = SCHOOL_CODE_MINUTES;
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
            "account_type" => (string) $request["account_type"],
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
    // Outside the transaction: a slow email service never holds the request locked.
    $sentNow = false;
    try {
        $sentNow = mail_send_now($conn, $emailId);
    } catch (Throwable $error) {
        error_log("The code for account request " . $requestId . " stays queued: " . $error->getMessage());
    }
    return [
        "request_id" => $requestId,
        "email" => $email,
        "expires_at" => (string) $expires,
        "resend_after_seconds" => SCHOOL_CODE_RESEND_SECONDS,
        "sent_now" => $sentNow,
        "code" => $code,
    ];
}

/**
 * Checks a code inside the caller's transaction and returns the request, locked. A wrong code rolls the
 * transaction back, then counts the try on its own, so the count survives.
 */
function school_verify_code(mysqli $conn, int $requestId, string $code): array
{
    $request = school_request_load($conn, $requestId, true);
    $fail = function (string $message, string $reason, int $status, array $extra = []) use ($conn): never {
        $conn->rollback();
        throw new SchoolAccountError($message, $reason, $status, "code", $extra);
    };
    if (!$request || $request["status"] !== "unconfirmed" || empty($request["code_hash"])) {
        $fail("This sign-up has ended. Start again.", "request_closed", 410);
    }
    if (strtotime((string) $request["code_expires_at"]) <= time()) {
        $fail("This code has expired. Ask for a new one.", "code_expired", 410);
    }
    if ((int) $request["code_attempts"] >= SCHOOL_CODE_TRIES) {
        $fail("Too many wrong codes. Ask for a new one.", "too_many_tries", 429);
    }
    if (!preg_match('/^\d{6}$/', $code) || !hash_equals((string) $request["code_hash"], school_code_hash($requestId, $code))) {
        $conn->rollback();
        $count = $conn->prepare("UPDATE account_requests SET code_attempts = code_attempts + 1 WHERE id = ?");
        $count->bind_param("i", $requestId);
        $count->execute();
        $count->close();
        $left = max(0, SCHOOL_CODE_TRIES - (int) $request["code_attempts"] - 1);
        throw new SchoolAccountError(
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

/** A username nobody has: "visitor_3f9a…" for app accounts, the email's name part for a teacher. */
function school_unique_username(mysqli $conn, string $accountType, string $email): string
{
    $check = $conn->prepare("SELECT 1 FROM app_users WHERE username = ? LIMIT 1");
    $base = $accountType === "teacher"
        ? (substr((string) preg_replace('/[^a-z0-9._-]/', "", strtolower(strstr($email, "@", true) ?: "teacher")), 0, 56) ?: "teacher")
        : "";
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $candidate = $base !== ""
            ? ($attempt === 0 ? $base : $base . ($attempt + 1))
            : $accountType . "_" . substr(bin2hex(random_bytes(10)), 0, 16);
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
 * Turns a confirmed visitor or student sign-up into an account, inside the caller's transaction.
 *
 * @return int the new user's id
 */
function school_create_app_account(mysqli $conn, array $request): int
{
    $type = (string) $request["account_type"];
    if (!in_array($type, ["visitor", "student"], true)) {
        throw new RuntimeException("Only a visitor or student sign-up becomes an app account");
    }
    $email = (string) $request["email"];
    if (school_account_exists($conn, $email)) {
        throw new SchoolAccountError("An account already uses this email. Sign in instead.", "email_taken", 409, "email");
    }
    $username = school_unique_username($conn, $type, $email);
    $insert = $conn->prepare(
        "INSERT INTO app_users
         (username, email, password_hash, display_name, contact_number, email_verified_at, role, office_code, is_active)
         VALUES (?, ?, ?, ?, ?, NOW(), ?, '', 1)"
    );
    $passwordHash = (string) $request["password_hash"];
    $fullName = (string) $request["full_name"];
    $contact = (string) $request["contact_number"];
    $insert->bind_param("ssssss", $username, $email, $passwordHash, $fullName, $contact, $type);
    $insert->execute();
    $userId = (int) $conn->insert_id;
    $insert->close();
    if ($type === "student") {
        $profile = $conn->prepare(
            "INSERT INTO student_profiles (user_id, student_number, course_code, course_name, year_level, section)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $number = (string) $request["student_number"];
        $courseCode = $request["course_code"] !== null && $request["course_code"] !== "" ? (string) $request["course_code"] : null;
        $courseName = (string) $request["course_name"];
        $year = (int) $request["year_level"];
        $section = (string) $request["section"];
        $profile->bind_param("isssis", $userId, $number, $courseCode, $courseName, $year, $section);
        $profile->execute();
        $profile->close();
    }
    $requestId = (int) $request["id"];
    $done = $conn->prepare("UPDATE account_requests SET status = 'approved', user_id = ?, password_hash = '' WHERE id = ?");
    $done->bind_param("ii", $userId, $requestId);
    $done->execute();
    $done->close();
    return $userId;
}

/**
 * The students' details a teacher may see, by user id. The course reads "BSIT 3-B": the course's short
 * name, the year and the section.
 *
 * @param list<int> $userIds
 * @return array<int, array{student_number:string, course:string, year_level:int, section:string, label:string}>
 */
function school_student_profiles(mysqli $conn, array $userIds): array
{
    $userIds = array_values(array_unique(array_filter(array_map("intval", $userIds))));
    if (!$userIds || !school_schema_ready($conn)) {
        return [];
    }
    $marks = implode(",", array_fill(0, count($userIds), "?"));
    $stmt = $conn->prepare(
        "SELECT user_id, student_number, course_code, course_name, year_level, section
         FROM student_profiles WHERE user_id IN ({$marks})"
    );
    $stmt->bind_param(str_repeat("i", count($userIds)), ...$userIds);
    $stmt->execute();
    $profiles = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $profiles[(int) $row["user_id"]] = school_student_profile_payload($row);
    }
    $stmt->close();
    return $profiles;
}

function school_student_profile_payload(array $row): array
{
    $course = $row["course_code"] !== null && $row["course_code"] !== "" ? (string) $row["course_code"] : trim((string) $row["course_name"]);
    $department = "";
    if ($row["course_code"] !== null && $row["course_code"] !== "") {
        $chain = appointment_office_chain((string) $row["course_code"]);
        $department = count($chain) > 1 ? (string) $chain[count($chain) - 1] : "";
    }
    $year = (int) $row["year_level"];
    $section = trim((string) $row["section"]);
    $yearSection = $year . ($section !== "" ? "-" . $section : "");
    return [
        "student_number" => (string) $row["student_number"],
        "course_code" => $row["course_code"] !== null ? (string) $row["course_code"] : "",
        "course" => $course,
        "department" => $department,
        "year_level" => $year,
        "section" => $section,
        // "IT 3-B", the way a class is named on the timetable.
        "label" => trim($course . " " . $yearSection),
    ];
}

// ---------------------------------------------------------------------------------------------------
// Departments and their Dean's Office
// ---------------------------------------------------------------------------------------------------

/**
 * The departments a teacher can request an account in: active, with a Dean's Office that has active
 * Office Personnel to answer. Each comes with its courses.
 *
 * @return list<array{code:string, name:string, approver:string, courses:list<array{code:string, name:string}>}>
 */
function school_open_departments(mysqli $conn): array
{
    $result = $conn->query(
        "SELECT d.code, d.name, a.code AS approver
         FROM offices d
         JOIN offices a ON a.parent_code = d.code AND a.kind = 'office' AND a.approves_teachers = 1 AND a.is_active = 1
         WHERE d.kind = 'department' AND d.is_active = 1
           AND EXISTS (SELECT 1 FROM app_users s WHERE s.office_code = a.code AND s.role = 'offices' AND s.is_active = 1)
         ORDER BY d.sort_order ASC, d.name ASC"
    );
    $departments = [];
    foreach ($result->fetch_all(MYSQLI_ASSOC) as $row) {
        $code = (string) $row["code"];
        $courses = [];
        $stmt = $conn->prepare("SELECT code FROM offices WHERE parent_code = ? AND kind = 'course' AND is_active = 1 ORDER BY sort_order ASC, code ASC");
        $stmt->bind_param("s", $code);
        $stmt->execute();
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $course) {
            $courses[] = ["code" => (string) $course["code"], "name" => (string) $course["code"]];
        }
        $stmt->close();
        $departments[$code] = [
            "code" => $code,
            "name" => (string) $row["name"],
            "approver" => (string) $row["approver"],
            "courses" => $courses,
        ];
    }
    return array_values($departments);
}

/**
 * Every active course a student can choose, grouped by department, whatever the Dean's Office staffing.
 *
 * @return list<array{code:string, name:string, courses:list<array{code:string, name:string}>}>
 */
function school_student_courses(mysqli $conn): array
{
    $result = $conn->query(
        "SELECT d.code AS department, d.name AS department_name, c.code
         FROM offices c
         JOIN offices d ON d.code = c.parent_code AND d.kind = 'department' AND d.is_active = 1
         WHERE c.kind = 'course' AND c.is_active = 1
         ORDER BY d.sort_order ASC, d.name ASC, c.sort_order ASC, c.code ASC"
    );
    $departments = [];
    foreach ($result->fetch_all(MYSQLI_ASSOC) as $row) {
        $code = (string) $row["department"];
        $departments[$code] ??= ["code" => $code, "name" => (string) $row["department_name"], "courses" => []];
        $departments[$code]["courses"][] = ["code" => (string) $row["code"], "name" => (string) $row["code"]];
    }
    return array_values($departments);
}

/**
 * The department whose teachers this office approves, or "" when it approves none. Only the Dean's
 * Office of a department does.
 */
function school_approver_department(mysqli $conn, string $officeCode): string
{
    if ($officeCode === "" || !school_schema_ready($conn)) {
        return "";
    }
    $stmt = $conn->prepare(
        "SELECT d.code FROM offices o
         JOIN offices d ON d.code = o.parent_code AND d.kind = 'department'
         WHERE o.code = ? AND o.kind = 'office' AND o.approves_teachers = 1 AND o.is_active = 1
         LIMIT 1"
    );
    $stmt->bind_param("s", $officeCode);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_row();
    $stmt->close();
    return $row ? (string) $row[0] : "";
}

/** The department a teacher's calendar belongs to: the top of [T12, IT, CCI]. */
function school_teacher_department(string $teacherCode): string
{
    $chain = appointment_office_chain($teacherCode);
    return count($chain) > 1 ? (string) $chain[count($chain) - 1] : "";
}

// ---------------------------------------------------------------------------------------------------
// Teacher requests, answered by the Dean's Office
// ---------------------------------------------------------------------------------------------------

/** A confirmed teacher request goes to its Dean's Office, with a notification for each of its staff. */
function school_submit_teacher_request(mysqli $conn, array $request): void
{
    $requestId = (int) $request["id"];
    $pending = $conn->prepare("UPDATE account_requests SET status = 'pending' WHERE id = ? AND status = 'unconfirmed'");
    $pending->bind_param("i", $requestId);
    $pending->execute();
    $pending->close();
    $approver = "";
    foreach (school_open_departments($conn) as $department) {
        if ($department["code"] === (string) $request["department_code"]) {
            $approver = $department["approver"];
        }
    }
    if ($approver === "") {
        return;
    }
    $name = teacher_display_name((string) $request["name_title"], (string) $request["first_name"], (string) $request["last_name"]);
    $title = "New teacher request";
    $message = $name . " asked for a teacher account in " . ($request["course_code"] ?: $request["department_code"]) . ".";
    $type = "teacher_request";
    $data = json_encode(["account_request_id" => $requestId], JSON_UNESCAPED_SLASHES);
    $notify = $conn->prepare(
        "INSERT INTO app_notifications (recipient_user_id, appointment_id, notification_type, title, message, data_json)
         SELECT id, NULL, ?, ?, ?, ? FROM app_users WHERE role = 'offices' AND office_code = ? AND is_active = 1"
    );
    $notify->bind_param("sssss", $type, $title, $message, $data, $approver);
    $notify->execute();
    $notify->close();
}

/** @return list<array> the department's requests waiting for an answer, oldest first */
function school_pending_teacher_requests(mysqli $conn, string $departmentCode): array
{
    $stmt = $conn->prepare(
        "SELECT id, email, name_title, first_name, last_name, full_name, department_code, course_code,
                email_confirmed_at, created_at, updated_at
         FROM account_requests
         WHERE account_type = 'teacher' AND status = 'pending' AND department_code = ?
         ORDER BY email_confirmed_at ASC, id ASC"
    );
    $stmt->bind_param("s", $departmentCode);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return array_map(fn(array $row): array => [
        "id" => (int) $row["id"],
        "name" => teacher_display_name((string) $row["name_title"], (string) $row["first_name"], (string) $row["last_name"]),
        "email" => (string) $row["email"],
        "course_code" => (string) ($row["course_code"] ?? ""),
        "asked_at" => (string) ($row["email_confirmed_at"] ?? $row["updated_at"]),
    ], $rows);
}

function school_pending_teacher_request_count(mysqli $conn, string $departmentCode): int
{
    if (!school_schema_ready($conn)) {
        return 0;
    }
    $stmt = $conn->prepare("SELECT COUNT(*) FROM account_requests WHERE account_type = 'teacher' AND status = 'pending' AND department_code = ?");
    $stmt->bind_param("s", $departmentCode);
    $stmt->execute();
    $count = (int) $stmt->get_result()->fetch_row()[0];
    $stmt->close();
    return $count;
}

function school_pending_request_for_department(mysqli $conn, int $requestId, string $departmentCode): array
{
    $request = school_request_load($conn, $requestId, true);
    if (!$request || $request["account_type"] !== "teacher" || $request["department_code"] !== $departmentCode) {
        throw new SchoolAccountError("This request is not for your department.", "not_found", 404);
    }
    if ($request["status"] !== "pending") {
        throw new SchoolAccountError("This request was already answered.", "already_answered", 409);
    }
    return $request;
}

/**
 * Approves a teacher request: the account and its calendar are made, with the password the teacher
 * chose. The Dean's Office may correct the course. Caller owns the transaction.
 *
 * @return array{user_id:int, username:string, teacher_code:string, name:string}
 */
function school_approve_teacher_request(mysqli $conn, int $requestId, string $departmentCode, string $courseCode, int $actorId): array
{
    $request = school_pending_request_for_department($conn, $requestId, $departmentCode);
    $courseCode = strtoupper(trim($courseCode)) ?: (string) $request["course_code"];
    $course = appointment_office_row($courseCode);
    if (!$course || $course["kind"] !== "course" || $course["parent_code"] !== $departmentCode || !$course["is_active"]) {
        throw new SchoolAccountError("Choose one of your department's courses.", "invalid_course", 422, "course_code");
    }
    $email = (string) $request["email"];
    if (school_account_exists($conn, $email)) {
        throw new SchoolAccountError("An account already uses this email.", "email_taken", 409);
    }
    $username = school_unique_username($conn, "teacher", $email);
    $title = (string) $request["name_title"];
    $first = (string) $request["first_name"];
    $last = (string) $request["last_name"];
    $display = mb_substr($first . " " . $last, 0, 100, "UTF-8");
    $passwordHash = (string) $request["password_hash"];
    $insert = $conn->prepare(
        "INSERT INTO app_users
         (username, email, password_hash, display_name, first_name, last_name, name_title, position,
          role, office_code, email_verified_at, password_changed_at, is_active)
         VALUES (?, ?, ?, ?, ?, ?, ?, 'Teacher', 'teacher', '', NOW(), NOW(), 1)"
    );
    $insert->bind_param("sssssss", $username, $email, $passwordHash, $display, $first, $last, $title);
    $insert->execute();
    $userId = (int) $conn->insert_id;
    $insert->close();
    $teacherCode = teacher_create_calendar($conn, $userId, $title, $first, $last, $courseCode, "", $actorId);
    $done = $conn->prepare(
        "UPDATE account_requests
         SET status = 'approved', user_id = ?, course_code = ?, decided_by_user_id = ?, decided_at = NOW(), password_hash = ''
         WHERE id = ?"
    );
    $done->bind_param("isii", $userId, $courseCode, $actorId, $requestId);
    $done->execute();
    $done->close();
    $officeName = school_approver_name($conn, $departmentCode);
    school_queue_email($conn, $userId, $email, "teacher_request_approved", "Your ISATU teacher account is ready", [
        "name" => teacher_display_name($title, $first, $last),
        "office" => $officeName,
        "link" => teacher_site_base_url() !== "" ? teacher_site_base_url() . "/login.html" : "",
    ]);
    school_audit($conn, $actorId, "teacher_request.approved", "app_user", (string) $userId, [
        "request_id" => $requestId,
        "course_code" => $courseCode,
        "username" => $username,
    ]);
    return ["user_id" => $userId, "username" => $username, "teacher_code" => $teacherCode, "name" => teacher_display_name($title, $first, $last)];
}

/** Rejects a teacher request with a reason the person gets by email. Caller owns the transaction. */
function school_reject_teacher_request(mysqli $conn, int $requestId, string $departmentCode, string $reason, int $actorId): void
{
    $request = school_pending_request_for_department($conn, $requestId, $departmentCode);
    $reason = trim($reason);
    if ($reason === "") {
        throw new SchoolAccountError("Choose a reason. It is sent to the person.", "reason_required", 422, "reason");
    }
    $update = $conn->prepare(
        "UPDATE account_requests
         SET status = 'rejected', decided_by_user_id = ?, decided_at = NOW(), decision_reason = ?, password_hash = ''
         WHERE id = ?"
    );
    $update->bind_param("isi", $actorId, $reason, $requestId);
    $update->execute();
    $update->close();
    school_queue_email($conn, null, (string) $request["email"], "teacher_request_rejected", "Your ISATU teacher account request", [
        "name" => teacher_display_name((string) $request["name_title"], (string) $request["first_name"], (string) $request["last_name"]),
        "office" => school_approver_name($conn, $departmentCode),
        "reason" => $reason,
    ]);
    school_audit($conn, $actorId, "teacher_request.rejected", "account_request", (string) $requestId, ["reason" => $reason]);
}

/** "CCI Dean's Office": the office that answers a department's teacher requests. */
function school_approver_name(mysqli $conn, string $departmentCode): string
{
    $stmt = $conn->prepare("SELECT name FROM offices WHERE parent_code = ? AND kind = 'office' AND approves_teachers = 1 LIMIT 1");
    $stmt->bind_param("s", $departmentCode);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_row();
    $stmt->close();
    return $row ? (string) $row[0] : $departmentCode . " Dean's Office";
}

/**
 * The department's teachers for its Dean's Office, with whether each account is on.
 *
 * @return list<array{user_id:int, code:string, name:string, course:string, is_active:bool}>
 */
function school_department_teachers(mysqli $conn, string $departmentCode): array
{
    $rows = $conn->query(
        "SELECT o.code, o.name, o.parent_code, u.id AS user_id, u.is_active
         FROM offices o JOIN app_users u ON u.id = o.owner_user_id
         WHERE o.kind = 'teacher' AND u.role = 'teacher'
         ORDER BY o.name ASC"
    )->fetch_all(MYSQLI_ASSOC);
    $teachers = [];
    foreach ($rows as $row) {
        if (school_teacher_department((string) $row["code"]) !== $departmentCode) {
            continue;
        }
        $teachers[] = [
            "user_id" => (int) $row["user_id"],
            "code" => (string) $row["code"],
            "name" => (string) $row["name"],
            "course" => (string) $row["parent_code"],
            "is_active" => (int) $row["is_active"] === 1,
        ];
    }
    return $teachers;
}

/**
 * Turns a teacher's account off (they left) or back on. Turning off signs them out everywhere, and
 * their open requests and upcoming visits are cancelled, with a message to each person. Their hours
 * stay, so turning them back on needs nothing else. Caller owns the transaction.
 */
function school_set_teacher_active(mysqli $conn, int $teacherUserId, string $departmentCode, bool $active, int $actorId): array
{
    $stmt = $conn->prepare("SELECT id, role, office_code, is_active FROM app_users WHERE id = ? FOR UPDATE");
    $stmt->bind_param("i", $teacherUserId);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$user || $user["role"] !== "teacher" || school_teacher_department((string) $user["office_code"]) !== $departmentCode) {
        throw new SchoolAccountError("This teacher is not in your department.", "not_found", 404);
    }
    $code = (string) $user["office_code"];
    $value = $active ? 1 : 0;
    $update = $conn->prepare("UPDATE app_users SET is_active = ?, session_version = session_version + 1 WHERE id = ?");
    $update->bind_param("ii", $value, $teacherUserId);
    $update->execute();
    $update->close();
    $cancelled = 0;
    if (!$active) {
        $teacherName = teacher_short_label($code);
        $open = $conn->prepare(
            "SELECT * FROM appointments
             WHERE office_code = ? AND status IN ('pending_approval', 'reschedule_proposed', 'approved') AND scheduled_end_at > NOW()
             FOR UPDATE"
        );
        $open->bind_param("s", $code);
        $open->execute();
        $appointments = $open->get_result()->fetch_all(MYSQLI_ASSOC);
        $open->close();
        foreach ($appointments as $appointment) {
            if ($appointment["status"] === "pending_approval") {
                $reject = $conn->prepare(
                    "UPDATE appointments SET status = 'rejected', status_updated_at = NOW(), rejection_reason = ?
                     WHERE id = ? AND status = 'pending_approval'"
                );
                $reason = $teacherName . " is no longer taking visits.";
                $appointmentId = (int) $appointment["id"];
                $reject->bind_param("si", $reason, $appointmentId);
                $reject->execute();
                $reject->close();
                visit_history($conn, $appointmentId, "pending_approval", "rejected", $actorId, "The teacher's account was turned off by the Dean's Office");
                visit_notify_visitor($conn, (int) $appointment["visitor_user_id"], $appointmentId, "appointment.rejected",
                    "{$teacherName} can't see you", "{$teacherName} is no longer taking visits. You can book another teacher.");
            } else {
                teacher_cancel_visit($conn, $appointment, $actorId, "the teacher's account was turned off");
            }
            $cancelled++;
        }
    }
    school_audit($conn, $actorId, $active ? "teacher.turned_on" : "teacher.turned_off", "app_user", (string) $teacherUserId, [
        "department" => $departmentCode,
        "cancelled_visits" => $cancelled,
    ]);
    return ["is_active" => $active, "cancelled_visits" => $cancelled];
}

// ---------------------------------------------------------------------------------------------------

/**
 * Counts one try for this scope, identity and network, in the same table as the app's limits
 * (api_rate_limits). Returns 0 when allowed, or the seconds until the next try is.
 */
function school_rate_limit(mysqli $conn, string $scope, string $identity, string $ip, int $limit, int $windowSeconds): int
{
    $key = hash("sha256", $scope . "|" . strtolower(trim($identity)) . "|" . $ip);
    $stmt = $conn->prepare("SELECT attempt_count, window_started_at, blocked_until FROM api_rate_limits WHERE bucket_key = ? LIMIT 1");
    $stmt->bind_param("s", $key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $now = time();
    if ($row && $row["blocked_until"] && strtotime((string) $row["blocked_until"]) > $now) {
        return max(1, strtotime((string) $row["blocked_until"]) - $now);
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
        return 0;
    }
    $attempts = (int) $row["attempt_count"] + 1;
    $blockedUntil = $attempts > $limit ? date("Y-m-d H:i:s", $windowStart + $windowSeconds) : null;
    $update = $conn->prepare("UPDATE api_rate_limits SET attempt_count = ?, blocked_until = ? WHERE bucket_key = ?");
    $update->bind_param("iss", $attempts, $blockedUntil, $key);
    $update->execute();
    $update->close();
    return $blockedUntil !== null ? max(1, strtotime($blockedUntil) - $now) : 0;
}

function school_queue_email(mysqli $conn, ?int $userId, string $email, string $template, string $subject, array $payload): void
{
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
    $stmt = $conn->prepare(
        "INSERT INTO outbound_emails (user_id, recipient_email, template_key, subject, payload_json) VALUES (?, ?, ?, ?, ?)"
    );
    $stmt->bind_param("issss", $userId, $email, $template, $subject, $json);
    $stmt->execute();
    $stmt->close();
}

function school_audit(mysqli $conn, ?int $actorId, string $action, string $entityType, string $entityId, array $details): void
{
    $json = json_encode($details, JSON_UNESCAPED_SLASHES);
    $ip = substr((string) ($_SERVER["REMOTE_ADDR"] ?? ""), 0, 45);
    $stmt = $conn->prepare(
        "INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, details_json, ip_address) VALUES (?, ?, ?, ?, ?, ?)"
    );
    $stmt->bind_param("isssss", $actorId, $action, $entityType, $entityId, $json, $ip);
    $stmt->execute();
    $stmt->close();
}
