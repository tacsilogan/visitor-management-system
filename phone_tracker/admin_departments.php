<?php
/*
 * The Office Directory (admin.html): each office with its Office Personnel and workload, and
 * add / edit / archive / restore / delete. An office's code is permanent because
 * appointments and accounts store it; only unused offices can be deleted.
 */
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/session_bootstrap.php";
require_once __DIR__ . "/personnel.php";
require_once __DIR__ . "/office_directions_service.php";

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
    $stmt = $conn->prepare("SELECT code, name, location, description, is_active FROM offices WHERE code = ? LIMIT 1");
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

function department_name_error(string $name): string
{
    if (mb_strlen($name, "UTF-8") < 2 || mb_strlen($name, "UTF-8") > 100) {
        return "Enter an office name of 2–100 characters.";
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
        "personnel" => $count("SELECT COUNT(*) FROM app_users WHERE role = 'offices' AND office_code = ?"),
        "active_personnel" => $count("SELECT COUNT(*) FROM app_users WHERE role = 'offices' AND office_code = ? AND is_active = 1"),
        "schedule_entries" => $count("SELECT COUNT(*) FROM office_availability_rules WHERE office_code = ?")
            + $count("SELECT COUNT(*) FROM office_availability_exceptions WHERE office_code = ?")
            + (department_table_exists($conn, "office_availability_breaks")
                ? $count("SELECT COUNT(*) FROM office_availability_breaks WHERE office_code = ?") : 0),
    ];
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
    $result = $conn->query(
        "SELECT o.code, o.name, o.location, o.description, o.is_active, o.created_at,
                COALESCE(s.accepting_visitors, 1) AS accepting_visitors,
                (SELECT COUNT(*) FROM appointments a WHERE a.office_code = o.code) AS total_appointments,
                (SELECT COUNT(*) FROM appointments a WHERE a.office_code = o.code AND a.status = 'pending_approval') AS pending_appointments,
                (SELECT COUNT(*) FROM appointments a WHERE a.office_code = o.code
                   AND a.status IN ('pending_approval', 'approved', 'reschedule_proposed', 'checked_in')) AS open_appointments,
                {$pinSql} AS has_map_pin,
                {$directionsSql} AS location_from_directions
         FROM offices o
         LEFT JOIN office_availability_settings s ON s.office_code = o.code
         ORDER BY o.is_active DESC, o.sort_order ASC, o.name ASC"
    );
    $departments = [];
    foreach ($result->fetch_all(MYSQLI_ASSOC) as $row) {
        $departments[(string) $row["code"]] = [
            "code" => (string) $row["code"],
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
            "personnel" => [],
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
    return ["success" => true, "departments" => array_values($departments), "unassigned_personnel" => $unassigned];
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
        $problem = department_name_error($name);
        if ($problem !== "") {
            auth_json_error(422, "invalid_name", $problem);
        }
        if (department_name_taken($conn, $name)) {
            auth_json_error(409, "name_taken", "An office with this name already exists.");
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
        $settings = $conn->prepare("INSERT IGNORE INTO office_availability_settings (office_code) VALUES (?)");
        $settings->bind_param("s", $code);
        $settings->execute();
        $settings->close();
        auth_audit($conn, $adminId, "department.created", "office", $code, ["code" => $code, "name" => $name, "location" => $location]);
        auth_json(["success" => true, "code" => $code, "message" => $name . " was added. You can now add Office Personnel to it."]);

    case "update":
        $department = department_load($conn, $code);
        if (!$department) {
            auth_json_error(404, "not_found", "Office not found.");
        }
        $problem = department_name_error($name);
        if ($problem !== "") {
            auth_json_error(422, "invalid_name", $problem);
        }
        if (department_name_taken($conn, $name, $code)) {
            auth_json_error(409, "name_taken", "An office with this name already exists.");
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
            auth_json_error(404, "not_found", "Office not found.");
        }
        $archive = $action === "archive";
        if ((int) $department["is_active"] === ($archive ? 0 : 1)) {
            auth_json_error(409, "unchanged", $archive ? "This office is already archived." : "This office is already active.");
        }
        if ($archive) {
            require_step_up_json();
        }
        $usage = department_usage($conn, $code);
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
            auth_json_error(404, "not_found", "Office not found.");
        }
        $usage = department_usage($conn, $code);
        if ($usage["appointments"] > 0 || $usage["personnel"] > 0 || $usage["schedule_entries"] > 0) {
            auth_json_error(409, "in_use", "This office has appointments, personnel, or a schedule, so it can only be archived.");
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
