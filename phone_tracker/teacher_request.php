<?php
/*
 * A teacher asks for an account from the sign-in page (teacher_request.html, SCHOOL_ACCOUNTS.md).
 * Their @isatu.edu.ph email is confirmed with a 6-digit code, then their department's Dean's Office
 * approves or rejects the request. No session: nobody is signed in yet.
 *
 * GET                                       {departments: [{code, name, courses: [{code, name}]}], titles}
 * POST {action: "request", email, name_title, first_name, last_name, department_code, course_code, password}
 *                                           {request_id, email, expires_at, resend_after_seconds}
 * POST {action: "resend", request_id}       the same, with a new code
 * POST {action: "confirm", request_id, code}  {department_name, approver_name}
 */
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/auth_flow.php";
require_once __DIR__ . "/school_accounts_service.php";

auth_json_exception_guard();
auth_require_method("GET", "POST");
if (!school_schema_ready($conn) || !teacher_schema_ready($conn)) {
    auth_json_error(503, "migration_required", "Teacher requests need a database update. Tell the administrator.");
}

if (strtoupper((string) $_SERVER["REQUEST_METHOD"]) === "GET") {
    auth_json([
        "success" => true,
        "departments" => school_open_departments($conn),
        "titles" => TEACHER_TITLES,
        "email_domain" => SCHOOL_STAFF_DOMAIN,
    ]);
}

auth_require_csrf();
$input = auth_json_body();
$action = (string) ($input["action"] ?? "");
$ip = auth_client_ip();

$fail = function (SchoolAccountError $error): never {
    auth_json_error($error->status, $error->reason, $error->getMessage(), array_filter([
        "field" => $error->field,
        "retry_after" => $error->extra["retry_after"] ?? null,
        "tries_left" => $error->extra["tries_left"] ?? null,
    ], fn($value) => $value !== null && $value !== ""));
};
$limited = function (int $wait): void {
    if ($wait > 0) {
        header("Retry-After: " . $wait);
        auth_json_error(429, "too_many", "Too many tries from this network. Try again in " . max(1, (int) ceil($wait / 60)) . " minutes.");
    }
};
$codeReply = function (array $sent): array {
    $reply = [
        "success" => true,
        "request_id" => $sent["request_id"],
        "email" => $sent["email"],
        "expires_at" => $sent["expires_at"],
        "resend_after_seconds" => $sent["resend_after_seconds"],
    ];
    // Only on a developer's own computer, never online (VISITOR_APP_ENV, like the app's reset codes).
    if (strtolower((string) (getenv("VISITOR_APP_ENV") ?: "production")) === "development") {
        $reply["development_code"] = $sent["code"];
    }
    return $reply;
};

switch ($action) {
    case "request":
        $limited(school_rate_limit($conn, "teacher_request_network", "", $ip, 20, 3600));
        $email = strtolower(trim((string) ($input["email"] ?? "")));
        $title = trim((string) ($input["name_title"] ?? ""));
        $first = trim(preg_replace('/\s+/', " ", (string) ($input["first_name"] ?? "")) ?? "");
        $last = trim(preg_replace('/\s+/', " ", (string) ($input["last_name"] ?? "")) ?? "");
        $departmentCode = strtoupper(trim((string) ($input["department_code"] ?? "")));
        $courseCode = strtoupper(trim((string) ($input["course_code"] ?? "")));
        $password = (string) ($input["password"] ?? "");
        $limited(school_rate_limit($conn, "teacher_request_email", $email, $ip, 5, 3600));

        $errors = [];
        $emailProblem = school_email_problem("teacher", $email);
        if ($emailProblem !== "") {
            $errors["email"] = $emailProblem;
        }
        if (!in_array($title, TEACHER_TITLES, true)) {
            $errors["name_title"] = "Choose your title";
        }
        if (mb_strlen($first) < 1 || mb_strlen($first) > 60) {
            $errors["first_name"] = "Enter your first name";
        }
        if (mb_strlen($last) < 1 || mb_strlen($last) > 60) {
            $errors["last_name"] = "Enter your last name";
        }
        $department = null;
        foreach (school_open_departments($conn) as $open) {
            if ($open["code"] === $departmentCode) {
                $department = $open;
            }
        }
        if ($department === null) {
            $errors["department_code"] = "Choose your department";
        } elseif (!in_array($courseCode, array_column($department["courses"], "code"), true)) {
            $errors["course_code"] = "Choose your course";
        }
        $passwordProblems = auth_password_problems($password, [
            "username" => (string) strstr($email, "@", true),
            "email" => $email,
            "first_name" => $first,
            "last_name" => $last,
            "display_name" => $first . " " . $last,
        ]);
        if ($passwordProblems) {
            $errors["password"] = $passwordProblems[0];
        }
        if ($errors) {
            auth_json_error(422, "invalid", "Check the highlighted details.", ["errors" => $errors]);
        }
        try {
            $requestId = school_save_request($conn, [
                "account_type" => "teacher",
                "email" => $email,
                "password_hash" => password_hash($password, PASSWORD_DEFAULT),
                "name_title" => $title,
                "first_name" => $first,
                "last_name" => $last,
                "full_name" => mb_substr($first . " " . $last, 0, 100, "UTF-8"),
                "department_code" => $departmentCode,
                "course_code" => $courseCode,
                "requested_ip" => $ip,
            ]);
            $sent = school_send_code($conn, $requestId);
        } catch (SchoolAccountError $error) {
            $fail($error);
        }
        auth_json($codeReply($sent));

    case "resend":
        $limited(school_rate_limit($conn, "teacher_request_network", "", $ip, 20, 3600));
        $requestId = (int) ($input["request_id"] ?? 0);
        $request = school_request_load($conn, $requestId);
        if (!$request || $request["account_type"] !== "teacher") {
            auth_json_error(410, "request_closed", "Start again.");
        }
        try {
            $sent = school_send_code($conn, $requestId);
        } catch (SchoolAccountError $error) {
            $fail($error);
        }
        auth_json($codeReply($sent));

    case "confirm":
        $requestId = (int) ($input["request_id"] ?? 0);
        $limited(school_rate_limit($conn, "teacher_request_confirm", (string) $requestId, $ip, 20, 3600));
        $code = preg_replace('/\D/', "", (string) ($input["code"] ?? "")) ?? "";
        $conn->begin_transaction();
        try {
            $request = school_verify_code($conn, $requestId, $code);
            if ($request["account_type"] !== "teacher") {
                $conn->rollback();
                auth_json_error(409, "wrong_place", "This code is for the ISATU app. Enter it there.");
            }
            school_submit_teacher_request($conn, $request);
            school_audit($conn, null, "teacher_request.submitted", "account_request", (string) $requestId, [
                "email" => (string) $request["email"],
                "department_code" => (string) $request["department_code"],
                "course_code" => (string) $request["course_code"],
            ]);
            $conn->commit();
        } catch (SchoolAccountError $error) {
            try {
                $conn->rollback();
            } catch (Throwable) {
                // school_verify_code() already rolled back.
            }
            $fail($error);
        }
        $departmentCode = (string) $request["department_code"];
        auth_json([
            "success" => true,
            "name" => teacher_display_name((string) $request["name_title"], (string) $request["first_name"], (string) $request["last_name"]),
            "department" => $departmentCode,
            "course" => (string) $request["course_code"],
            "approver_name" => school_approver_name($conn, $departmentCode),
        ]);

    default:
        auth_json_error(400, "bad_action", "Unknown action.");
}
