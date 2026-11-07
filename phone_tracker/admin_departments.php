<?php
/*
 * Department directory: departments with the offices, courses and teachers under them, their people
 * and workload, and add / edit / archive / restore / delete. A code is permanent because appointments
 * and accounts store it; only unused rows can be deleted.
 */
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/session_bootstrap.php";
require_once __DIR__ . "/personnel.php";
require_once __DIR__ . "/office_directions_service.php";
require_once __DIR__ . "/school_accounts_service.php";

auth_json_exception_guard();
auth_require_method("GET", "POST");
require_permission_json("departments.manage");
personnel_require_schema($conn);

const DEPARTMENT_LEGACY_CODES = ["CCI", "COED", "CEA", "CIT", "CAS"];
const DEPARTMENT_STOPWORDS = ["OF", "THE", "AND", "FOR"];
// Dropped from a suggested code when the full name is too long for 16 characters.
const DEPARTMENT_GENERIC_WORDS = ["OFFICE", "DEPARTMENT", "DEPT", "UNIT", "SECTION", "CENTER", "CENTRE", "COLLEGE", "SERVICES"];

function department_table_exists(mysqli $conn, string $table): bool
{
    $stmt = $conn->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $stmt->bind_param("s", $table);
    $stmt->execute();
    $exists = (int) $stmt->get_result()->fetch_row()[0] > 0;
    $stmt->close();
    return $exists;
}

function department_load(mysqli $conn, string $code): ?array
{
    $tree = teacher_schema_ready($conn) ? "kind, parent_code" : "'office' AS kind, NULL AS parent_code";
    $stmt = $conn->prepare("SELECT code, name, location, description, is_active, {$tree} FROM offices WHERE code = ? LIMIT 1");
    $stmt->bind_param("s", $code);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/**
 * "Library" -> "LIBRARY", "Registrar's Office" -> "REGISTRARS",
 * "Guidance and Counseling Office" -> "GCO"; made unique with _2, _3 ...
 */
function department_suggest_code(mysqli $conn, string $name): string
{
    // Common Spanish/Filipino letters first: iconv's transliteration differs between systems.
    $name = strtr($name, [
        "ñ" => "n", "Ñ" => "N", "á" => "a", "Á" => "A", "é" => "e", "É" => "E", "í" => "i", "Í" => "I",
        "ó" => "o", "Ó" => "O", "ú" => "u", "Ú" => "U", "ü" => "u", "Ü" => "U",
    ]);
    $ascii = function_exists("iconv") ? @iconv("UTF-8", "ASCII//TRANSLIT//IGNORE", $name) : false;
    $plain = str_replace(["'", "\u{2019}"], "", strtoupper($ascii ?: $name));
    $words = preg_split('/[^A-Z0-9]+/', $plain) ?: [];
    $words = array_values(array_filter($words, fn ($word) => $word !== "" && !in_array($word, DEPARTMENT_STOPWORDS, true)));
    $base = implode("_", $words);
    if (strlen($base) > 16) {
        $core = array_values(array_filter($words, fn ($word) => !in_array($word, DEPARTMENT_GENERIC_WORDS, true)));
        if ($core) {
            $base = implode("_", $core);
        }
    }
    if (strlen($base) > 16 && count($words) >= 3) {
        $base = implode("", array_map(fn ($word) => $word[0], $words));
    }
    $base = rtrim(substr($base, 0, 16), "_");
    if (strlen($base) < 2 || !preg_match('/^[A-Z]/', $base)) {
        $base = rtrim(substr("DEPT_" . $base, 0, 16), "_");
    }
    $code = $base;
    for ($n = 2; department_load($conn, $code) || in_array($code, DEPARTMENT_LEGACY_CODES, true); $n++) {
        $suffix = "_" . $n;
        $code = substr($base, 0, 16 - strlen($suffix)) . $suffix;
    }
    return $code;
}

/**
 * Add department: a department like CCI, which holds offices, courses and teachers, made together
 * with its Dean's Office. That office's Office Personnel approve the department's teachers.
 */
function department_create_with_dean(mysqli $conn, string $code, string $name, string $location, string $description, int $adminId): never
{
    if (!teacher_schema_ready($conn) || !school_schema_ready($conn)) {
        auth_json_error(503, "migration_required", "Database update required: import teacher_visits_migration.sql and school_accounts_migration.sql.");
    }
    $problem = department_name_error($name);
    if ($problem !== "") {
        auth_json_error(422, "invalid_name", $problem);
    }
    if (department_name_taken($conn, $name)) {
        auth_json_error(409, "name_taken", "A department with this name already exists.");
    }
    // Short, because its Dean's Office is called "<CODE>_DEANS" within the 16-character limit.
    if (!preg_match('/^[A-Z][A-Z0-9]{1,9}$/', $code)) {
        auth_json_error(422, "invalid_code", "The short name must be 2–10 capital letters or numbers, starting with a letter, like CCI.");
    }
    $deanCode = $code . "_DEANS";
    $deanName = $code . " Dean's Office";
    if (department_load($conn, $code) || department_load($conn, $deanCode)) {
        auth_json_error(409, "code_taken", "The short name " . $code . " is already used. Choose another.");
    }
    if (department_name_taken($conn, $deanName)) {
        auth_json_error(409, "name_taken", "An office called " . $deanName . " already exists.");
    }
    $conn->begin_transaction();
    try {
        $order = (int) $conn->query("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM offices")->fetch_row()[0];
        $stmt = $conn->prepare(
            "INSERT INTO offices (code, kind, name, location, description, is_active, sort_order, created_by_user_id)
             VALUES (?, 'department', ?, ?, ?, 1, ?, ?)"
        );
        $stmt->bind_param("ssssii", $code, $name, $location, $description, $order, $adminId);
        $stmt->execute();
        $stmt->close();
        $deanDescription = "Approves " . $code . "'s teachers.";
        $deanOrder = $order + 1;
        $dean = $conn->prepare(
            "INSERT INTO offices (code, kind, parent_code, approves_teachers, name, location, description, is_active, sort_order, created_by_user_id)
             VALUES (?, 'office', ?, 1, ?, ?, ?, 1, ?, ?)"
        );
        $dean->bind_param("sssssii", $deanCode, $code, $deanName, $location, $deanDescription, $deanOrder, $adminId);
        $dean->execute();
        $dean->close();
        $settings = $conn->prepare("INSERT IGNORE INTO office_availability_settings (office_code) VALUES (?), (?)");
        $settings->bind_param("ss", $code, $deanCode);
        $settings->execute();
        $settings->close();
        auth_audit($conn, $adminId, "department.created", "office", $code, [
            "code" => $code, "name" => $name, "kind" => "department", "dean_office" => $deanCode,
        ]);
        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }
    auth_json([
        "success" => true,
        "code" => $code,
        "dean_office_code" => $deanCode,
        "message" => $name . " was added with the " . $deanName . ". Add Office Personnel to the " . $deanName . " next: they approve the department's teachers.",
    ]);
}

function department_name_error(string $name): string
{
    if (mb_strlen($name, "UTF-8") < 2 || mb_strlen($name, "UTF-8") > 100) {
        return "Enter a department name of 2–100 characters.";
    }
    if (!preg_match("/^[\\p{L}\\p{N}][\\p{L}\\p{N}\\p{M} &'.,()\\/-]*$/u", $name)) {
        return "The name can use letters, numbers, spaces, and & ' . , ( ) / -";
    }
    return "";
}

function department_name_taken(mysqli $conn, string $name, string $exceptCode = ""): bool
{
    $stmt = $conn->prepare("SELECT COUNT(*) FROM offices WHERE name = ? AND code <> ?");
    $stmt->bind_param("ss", $name, $exceptCode);
    $stmt->execute();
    $taken = (int) $stmt->get_result()->fetch_row()[0] > 0;
    $stmt->close();
    return $taken;
}

function department_usage(mysqli $conn, string $code): array
{
    $count = function (string $sql) use ($conn, $code): int {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $code);
        $stmt->execute();
        $value = (int) $stmt->get_result()->fetch_row()[0];
        $stmt->close();
        return $value;
    };
    return [
        "appointments" => $count("SELECT COUNT(*) FROM appointments WHERE office_code = ?"),
        "open_appointments" => $count(
            "SELECT COUNT(*) FROM appointments WHERE office_code = ?
               AND status IN ('pending_approval', 'approved', 'reschedule_proposed', 'checked_in')"
        ),
        "children" => teacher_schema_ready($conn) ? $count("SELECT COUNT(*) FROM offices WHERE parent_code = ?") : 0,
        "active_children" => teacher_schema_ready($conn) ? $count("SELECT COUNT(*) FROM offices WHERE parent_code = ? AND is_active = 1") : 0,
        "personnel" => $count("SELECT COUNT(*) FROM app_users WHERE role = 'offices' AND office_code = ?"),
        "active_personnel" => $count("SELECT COUNT(*) FROM app_users WHERE role = 'offices' AND office_code = ? AND is_active = 1"),
        "schedule_entries" => $count("SELECT COUNT(*) FROM office_availability_rules WHERE office_code = ?")
            + $count("SELECT COUNT(*) FROM office_availability_exceptions WHERE office_code = ?")
            + (department_table_exists($conn, "office_availability_breaks")
                ? $count("SELECT COUNT(*) FROM office_availability_breaks WHERE office_code = ?") : 0),
    ];
}

/** Puts each teacher on their course, with what the admin needs to see about their setup. */
function departments_add_teachers(mysqli $conn, array &$departments): void
{
    $rows = $conn->query(
        "SELECT o.code, o.parent_code, o.is_active AS calendar_active, u.id, u.username, u.first_name, u.last_name, u.name_title,
                u.position, u.is_active, u.last_login_at, TIMESTAMPDIFF(SECOND, u.last_seen_at, NOW()) AS seen_seconds_ago,
                EXISTS (SELECT 1 FROM office_availability_rules r WHERE r.office_code = o.code AND r.is_active = 1) AS has_hours,
                (SELECT COUNT(*) FROM appointments a WHERE a.office_code = o.code AND a.status = 'pending_approval') AS pending_appointments,
                (SELECT COUNT(*) FROM appointments a WHERE a.office_code = o.code
                   AND a.status IN ('pending_approval', 'approved', 'reschedule_proposed', 'checked_in')) AS open_appointments
         FROM offices o
         INNER JOIN app_users u ON u.id = o.owner_user_id
         WHERE o.kind = 'teacher' AND o.is_active = 1
         ORDER BY u.last_name ASC, u.first_name ASC"
    );
    foreach ($rows->fetch_all(MYSQLI_ASSOC) as $row) {
        $course = (string) $row["parent_code"];
        if (!isset($departments[$course])) {
            continue;
        }
        $departments[$course]["teachers"][] = [
            "id" => (int) $row["id"],
            "code" => (string) $row["code"],
            "name" => teacher_display_name((string) $row["name_title"], (string) $row["first_name"], (string) $row["last_name"]),
            "username" => (string) $row["username"],
            "position" => (string) $row["position"],
            "is_active" => (int) $row["is_active"] === 1,
            "signed_in" => $row["last_login_at"] !== null,
            "has_hours" => (int) $row["has_hours"] === 1,
            "online" => $row["seen_seconds_ago"] !== null && (int) $row["seen_seconds_ago"] <= 300,
            "pending_appointments" => (int) $row["pending_appointments"],
            "open_appointments" => (int) $row["open_appointments"],
        ];
    }
}

/**
 * Turns the flat rows into the directory: a department holds its offices and courses, and carries
 * their totals so its card can show them at a glance. Only rows without a parent are listed at the top.
 *
 * @return list<array>
 */
function departments_nest(array $departments): array
{
    foreach ($departments as $code => $row) {
        $parent = $row["parent_code"];
        if ($parent !== null && isset($departments[$parent])) {
            $departments[$parent][$row["kind"] === "course" ? "courses" : "offices"][] = $row;
        }
    }
    $top = [];
    foreach ($departments as $row) {
        if ($row["parent_code"] !== null && isset($departments[$row["parent_code"]])) {
            continue;
        }
        $row["teacher_total"] = 0;
        $row["office_total"] = 0;
        foreach (array_merge($row["offices"], $row["courses"]) as $child) {
            $row["pending_appointments"] += $child["pending_appointments"];
            $row["open_appointments"] += $child["open_appointments"];
            foreach ($child["teachers"] as $teacher) {
                $row["teacher_total"]++;
                $row["pending_appointments"] += $teacher["pending_appointments"];
                $row["open_appointments"] += $teacher["open_appointments"];
            }
            if ($child["kind"] === "office" || $child["personnel"]) {
                $row["office_total"]++;
            }
        }
        $top[] = $row;
    }
    return $top;
}

function departments_overview(mysqli $conn): array
{
    $hasPins = department_table_exists($conn, "campus_places");
    $pinSql = $hasPins
        ? "EXISTS (SELECT 1 FROM campus_places p WHERE p.place_type = 'office' AND p.office_code = o.code)"
        : "0";
    // Directions set the location (Campus Map > Inside), so it is changed there.
    $directionsSql = office_directions_schema_ready($conn)
        ? "EXISTS (SELECT 1 FROM office_directions d WHERE d.office_code = o.code AND (d.building <> '' OR d.floor <> '' OR d.room <> ''))"
        : "0";
    $tree = teacher_schema_ready($conn);
    $treeColumns = $tree ? "o.kind, o.parent_code," : "'office' AS kind, NULL AS parent_code,";
    $school = school_schema_ready($conn);
    $treeColumns .= $school ? " o.approves_teachers," : " 0 AS approves_teachers,";
    $notTeachers = $tree ? "WHERE o.kind <> 'teacher'" : "";
    $result = $conn->query(
        "SELECT o.code, {$treeColumns} o.name, o.location, o.description, o.is_active, o.created_at,
                COALESCE(s.accepting_visitors, 1) AS accepting_visitors,
                (SELECT COUNT(*) FROM appointments a WHERE a.office_code = o.code) AS total_appointments,
                (SELECT COUNT(*) FROM appointments a WHERE a.office_code = o.code AND a.status = 'pending_approval') AS pending_appointments,
                (SELECT COUNT(*) FROM appointments a WHERE a.office_code = o.code
                   AND a.status IN ('pending_approval', 'approved', 'reschedule_proposed', 'checked_in')) AS open_appointments,
                {$pinSql} AS has_map_pin,
                {$directionsSql} AS location_from_directions
         FROM offices o
         LEFT JOIN office_availability_settings s ON s.office_code = o.code
         {$notTeachers}
         ORDER BY o.is_active DESC, o.sort_order ASC, o.name ASC"
    );
    $departments = [];
    foreach ($result->fetch_all(MYSQLI_ASSOC) as $row) {
        $departments[(string) $row["code"]] = [
            "code" => (string) $row["code"],
            "kind" => (string) $row["kind"],
            "parent_code" => $row["parent_code"] !== null ? (string) $row["parent_code"] : null,
            "name" => (string) $row["name"],
            "location" => (string) $row["location"],
            "description" => (string) $row["description"],
            "is_active" => (int) $row["is_active"] === 1,
            "created_at" => $row["created_at"],
            "accepting_visitors" => (int) $row["accepting_visitors"] === 1,
            "total_appointments" => (int) $row["total_appointments"],
            "pending_appointments" => (int) $row["pending_appointments"],
            "open_appointments" => (int) $row["open_appointments"],
            "has_map_pin" => (int) $row["has_map_pin"] === 1,
            "location_from_directions" => (int) $row["location_from_directions"] === 1,
            // The Dean's Office of a department: its Office Personnel approve the department's teachers.
            "approves_teachers" => (int) $row["approves_teachers"] === 1,
            "personnel" => [],
            "offices" => [],
            "courses" => [],
            "teachers" => [],
        ];
    }

    $people = $conn->query(
        "SELECT id, username, display_name, first_name, last_name, position, office_code, is_active,
                last_login_at, last_seen_at, TIMESTAMPDIFF(SECOND, last_seen_at, NOW()) AS seen_seconds_ago
         FROM app_users
         WHERE role = 'offices'
         ORDER BY last_name ASC, first_name ASC, username ASC"
    );
    $unassigned = [];
    foreach ($people->fetch_all(MYSQLI_ASSOC) as $row) {
        $person = [
            "id" => (int) $row["id"],
            "name" => personnel_full_name($row),
            "has_name" => personnel_has_name($row),
            "username" => (string) $row["username"],
            "position" => (string) $row["position"],
            "is_active" => (int) $row["is_active"] === 1,
            "last_login_at" => $row["last_login_at"],
            "last_seen_at" => $row["last_seen_at"],
            "online" => $row["seen_seconds_ago"] !== null && (int) $row["seen_seconds_ago"] <= 300,
        ];
        $code = (string) $row["office_code"];
        if (isset($departments[$code])) {
            $departments[$code]["personnel"][] = $person;
        } else {
            $person["office_code"] = $code;
            $unassigned[] = $person;
        }
    }
    if ($tree) {
        departments_add_teachers($conn, $departments);
    }
    $nested = departments_nest($departments);
    foreach ($nested as &$department) {
        if ($department["kind"] !== "department") {
            continue;
        }
        // Who answers teacher requests, and whether anyone is there to: without Office Personnel in
        // the Dean's Office, teachers can't choose this department when they request an account.
        $approver = null;
        foreach ($department["offices"] as $office) {
            if ($office["approves_teachers"]) {
                $approver = $office;
            }
        }
        $department["approver_code"] = $approver["code"] ?? "";
        $department["approver_name"] = $approver["name"] ?? "";
        $department["approver_staffed"] = $approver !== null && (bool) array_filter(
            $approver["personnel"],
            fn(array $person): bool => $person["is_active"]
        );
        $department["teacher_requests"] = $school ? school_pending_teacher_request_count($conn, $department["code"]) : 0;
    }
    unset($department);
    return ["success" => true, "departments" => $nested, "unassigned_personnel" => $unassigned];
}

if (strtoupper((string) $_SERVER["REQUEST_METHOD"]) === "GET") {
    if (isset($_GET["suggest_code"])) {
        auth_json(["success" => true, "code" => department_suggest_code($conn, personnel_clean_text((string) $_GET["suggest_code"], 100))]);
    }
    auth_json(departments_overview($conn));
}

$input = auth_json_body();
$action = (string) ($input["action"] ?? "");
$adminId = (int) $_SESSION["user_id"];
$code = strtoupper(trim((string) ($input["code"] ?? "")));
$name = personnel_clean_text((string) ($input["name"] ?? ""), 100);
$location = personnel_clean_text((string) ($input["location"] ?? ""), 150);
$description = personnel_clean_text((string) ($input["description"] ?? ""), 255);

switch ($action) {
    case "create":
        $kind = (string) ($input["kind"] ?? "office");
        $kind = in_array($kind, ["office", "course", "department"], true) ? $kind : "office";
        $parentCode = strtoupper(trim((string) ($input["parent_code"] ?? "")));
        if ($kind === "department") {
            department_create_with_dean($conn, $code, $name, $location, $description, $adminId);
        }
        $parent = $parentCode !== "" ? department_load($conn, $parentCode) : null;
        if ($parentCode !== "" && (!$parent || $parent["kind"] !== "department")) {
            auth_json_error(422, "invalid_parent", "Choose the department this belongs to.");
        }
        if ($kind === "course") {
            // A course is named by its short name: "BSEMC" is also its code, and if it gets Office
            // Personnel, visitors see "CCI BSEMC Office".
            if ($parent === null) {
                auth_json_error(422, "invalid_parent", "A course must belong to a department.");
            }
            $code = strtoupper(preg_replace('/[^A-Za-z0-9_]/', "", $name) ?? "");
            $name = $parentCode . " " . $code . " Office";
        }
        $problem = department_name_error($name);
        if ($problem !== "") {
            auth_json_error(422, "invalid_name", $problem);
        }
        if (department_name_taken($conn, $name)) {
            auth_json_error(409, "name_taken", "A department with this name already exists.");
        }
        if ($code === "") {
            $code = department_suggest_code($conn, $name);
        }
        if (!preg_match('/^[A-Z][A-Z0-9_]{1,15}$/', $code)) {
            auth_json_error(422, "invalid_code", "The short code must be 2–16 capital letters, numbers, or underscores, starting with a letter.");
        }
        if (in_array($code, DEPARTMENT_LEGACY_CODES, true) || department_load($conn, $code)) {
            auth_json_error(409, "code_taken", "The short code " . $code . " is already used. Choose another.");
        }
        $order = (int) $conn->query("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM offices")->fetch_row()[0];
        $stmt = $conn->prepare(
            "INSERT INTO offices (code, name, location, description, is_active, sort_order, created_by_user_id)
             VALUES (?, ?, ?, ?, 1, ?, ?)"
        );
        $stmt->bind_param("ssssii", $code, $name, $location, $description, $order, $adminId);
        $stmt->execute();
        $stmt->close();
        if ($parent !== null) {
            $place = $conn->prepare("UPDATE offices SET kind = ?, parent_code = ? WHERE code = ?");
            $place->bind_param("sss", $kind, $parentCode, $code);
            $place->execute();
            $place->close();
        }
        $settings = $conn->prepare("INSERT IGNORE INTO office_availability_settings (office_code) VALUES (?)");
        $settings->bind_param("s", $code);
        $settings->execute();
        $settings->close();
        auth_audit($conn, $adminId, "department.created", "office", $code, ["code" => $code, "name" => $name, "location" => $location]);
        auth_json([
            "success" => true,
            "code" => $code,
            "message" => $kind === "course"
                ? "The course " . $code . " was added. You can now add its teachers."
                : $name . " was added. You can now add Office Personnel to it.",
        ]);

    case "update":
        $department = department_load($conn, $code);
        if (!$department) {
            auth_json_error(404, "not_found", "Department not found.");
        }
        $problem = department_name_error($name);
        if ($problem !== "") {
            auth_json_error(422, "invalid_name", $problem);
        }
        if (department_name_taken($conn, $name, $code)) {
            auth_json_error(409, "name_taken", "A department with this name already exists.");
        }
        if (office_directions_where(office_directions_load($conn, $code)[$code] ?? office_directions_blank()) !== "") {
            $location = (string) $department["location"];
        }
        $changes = [];
        foreach (["name" => $name, "location" => $location, "description" => $description] as $field => $value) {
            if ($value !== (string) $department[$field]) {
                $changes[$field] = ["from" => (string) $department[$field], "to" => $value];
            }
        }
        if (!$changes) {
            auth_json(["success" => true, "message" => "Nothing changed."]);
        }
        $stmt = $conn->prepare("UPDATE offices SET name = ?, location = ?, description = ? WHERE code = ?");
        $stmt->bind_param("ssss", $name, $location, $description, $code);
        $stmt->execute();
        $stmt->close();
        auth_audit($conn, $adminId, "department.updated", "office", $code, ["code" => $code, "name" => $name, "changes" => $changes]);
        auth_json(["success" => true, "message" => "Saved."]);

    case "archive":
    case "restore":
        $department = department_load($conn, $code);
        if (!$department) {
            auth_json_error(404, "not_found", "Department not found.");
        }
        $archive = $action === "archive";
        if ((int) $department["is_active"] === ($archive ? 0 : 1)) {
            auth_json_error(409, "unchanged", $archive ? "This department is already archived." : "This department is already active.");
        }
        $usage = department_usage($conn, $code);
        if ($archive && $usage["active_children"] > 0) {
            auth_json_error(409, "in_use", $department["kind"] === "course"
                ? "This course still has teachers. Move them to another course first."
                : "This department still has offices or courses. Archive them first.");
        }
        if ($archive) {
            require_step_up_json();
        }
        $active = $archive ? 0 : 1;
        $stmt = $conn->prepare("UPDATE offices SET is_active = ? WHERE code = ?");
        $stmt->bind_param("is", $active, $code);
        $stmt->execute();
        $stmt->close();
        auth_audit($conn, $adminId, $archive ? "department.archived" : "department.restored", "office", $code, [
            "code" => $code,
            "name" => (string) $department["name"],
            "open_appointments" => $usage["open_appointments"],
            "active_personnel" => $usage["active_personnel"],
        ]);
        auth_json([
            "success" => true,
            "message" => $archive
                ? $department["name"] . " is archived. Visitors can no longer book it; its records stay."
                : $department["name"] . " is active again.",
        ]);

    case "delete":
        $department = department_load($conn, $code);
        if (!$department) {
            auth_json_error(404, "not_found", "Department not found.");
        }
        $usage = department_usage($conn, $code);
        if ($usage["appointments"] > 0 || $usage["personnel"] > 0 || $usage["schedule_entries"] > 0 || $usage["children"] > 0) {
            auth_json_error(409, "in_use", "This has appointments, personnel, a schedule, or something under it, so it can only be archived.");
        }
        require_step_up_json();
        $directionPhotos = array_column(office_directions_load($conn, $code)[$code]["steps"] ?? [], "photo");
        $conn->begin_transaction();
        try {
            foreach ([
                "DELETE FROM office_availability_settings WHERE office_code = ?",
                "DELETE FROM offices WHERE code = ?",
            ] as $sql) {
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("s", $code);
                $stmt->execute();
                $stmt->close();
            }
            if (department_table_exists($conn, "campus_places")) {
                $stmt = $conn->prepare("DELETE FROM campus_places WHERE place_type = 'office' AND office_code = ?");
                $stmt->bind_param("s", $code);
                $stmt->execute();
                $stmt->close();
            }
            $conn->commit();
        } catch (Throwable $error) {
            $conn->rollback();
            throw $error;
        }
        office_directions_remove_unused_photos($conn, $directionPhotos);
        auth_audit($conn, $adminId, "department.deleted", "office", $code, ["code" => $code, "name" => (string) $department["name"]]);
        auth_json(["success" => true, "message" => $department["name"] . " was deleted."]);

    default:
        auth_json_error(400, "invalid_action", "Unknown action.");
}
