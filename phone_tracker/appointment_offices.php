<?php

/** The original catalog, used until personnel_directory_migration.sql creates the `offices` table. */
const APPOINTMENT_DEFAULT_OFFICES = [
    "IT" => "IT Department",
    "IS" => "IS Department",
    "CS" => "CS Department",
    "DEANS" => "Dean's Office",
    "TECH_SUPPORT" => "Tech Support",
];

/**
 * Whether an office with no active Office Personnel is hidden from visitors. Their requests would
 * never get an answer. Set this to false to list every active office, as before.
 */
const APPOINTMENT_HIDE_UNSTAFFED_OFFICES = true;

/**
 * Every row of the directory, archived ones and teachers included, so historical records keep their
 * names. Use appointment_office_active_map() or appointment_office_bookable_map() for anything a
 * person chooses.
 *
 * @return array<string, string> code => full name
 */
function appointment_office_map(): array
{
    return appointment_office_directory()["all"];
}

/**
 * Offices and courses that Office Personnel can be assigned to. A department only holds them, and a
 * teacher answers for themselves, so neither is listed.
 *
 * @return array<string, string> code => full name
 */
function appointment_office_active_map(): array
{
    return appointment_office_directory()["active"];
}

/**
 * The offices a visitor can choose: offices first, then any course with its own Office Personnel.
 *
 * @return array<string, string> code => full name
 */
function appointment_office_bookable_map(): array
{
    return appointment_office_directory()["bookable"];
}

/**
 * The teachers a visitor can choose: active accounts that have saved their hours.
 *
 * @return array<string, string> code => "Prof. Elena Reyes"
 */
function appointment_teacher_bookable_map(): array
{
    return appointment_office_directory()["teachers"];
}

/**
 * @return array{code:string, name:string, kind:string, parent_code:?string, is_active:bool, location:string,
 *     has_staff:bool, has_hours:bool, owner_user_id:?int, owner_active:bool, short_name:string}|null
 */
function appointment_office_row(string $code): ?array
{
    return appointment_office_directory()["rows"][$code] ?? null;
}

/**
 * " AND a.visitor_kind = 'visitor'": leaves ISATU students' visits out of what Security sees and out of
 * the visitor numbers. Empty before school_accounts_migration.sql, when every visit is a visitor's.
 */
function appointment_visitors_only_sql(mysqli $conn, string $alias = ""): string
{
    static $ready = null;
    if ($ready === null) {
        try {
            $ready = (bool) $conn->query("SELECT visitor_kind FROM appointments LIMIT 0");
        } catch (mysqli_sql_exception $error) {
            $ready = false;
        }
    }
    return $ready ? " AND " . ($alias !== "" ? $alias . "." : "") . "visitor_kind = 'visitor'" : "";
}

function appointment_is_teacher_code(string $code): bool
{
    return (appointment_office_row($code)["kind"] ?? "") === "teacher";
}

/**
 * The code and everything above it, nearest first: a teacher gives [T12, IT, CCI]. Map pins, routes and
 * directions are looked up along this, so a teacher needs none of their own.
 *
 * @return list<string>
 */
function appointment_office_chain(string $code): array
{
    $chain = [$code];
    $row = appointment_office_row($code);
    while ($row !== null && !empty($row["parent_code"]) && !in_array($row["parent_code"], $chain, true)) {
        $chain[] = (string) $row["parent_code"];
        $row = appointment_office_row((string) $row["parent_code"]);
    }
    return $chain;
}

/** "IT, CCI": where a teacher belongs. */
function appointment_teacher_place_label(string $code): string
{
    $chain = appointment_office_chain($code);
    return implode(", ", array_slice($chain, 1));
}

/** The name staff see: a teacher also shows where they belong, "Prof. Elena Reyes (IT, CCI)". */
function appointment_office_long_label(string $code): string
{
    $label = appointment_office_label($code);
    if (!appointment_is_teacher_code($code)) {
        return $label;
    }
    $place = appointment_teacher_place_label($code);
    return $place !== "" ? $label . " (" . $place . ")" : $label;
}

/**
 * Where a visitor can go when a teacher can't see them: the course's own office if it has one,
 * otherwise the department's first office with someone to answer.
 *
 * @return array{code:string, name:string}|null
 */
function appointment_fallback_office(string $teacherCode): ?array
{
    $bookable = appointment_office_bookable_map();
    $chain = appointment_office_chain($teacherCode);
    foreach (array_slice($chain, 1) as $code) {
        if (isset($bookable[$code])) {
            return ["code" => $code, "name" => $bookable[$code]];
        }
    }
    $department = $chain[count($chain) - 1];
    foreach ($bookable as $code => $name) {
        $row = appointment_office_row($code);
        if ($row !== null && $row["parent_code"] === $department && $row["kind"] === "office") {
            return ["code" => $code, "name" => $name];
        }
    }
    return null;
}

/**
 * Reads the directory once per request (pass $refresh after changing it).
 *
 * @return array{all: array<string, string>, active: array<string, string>, bookable: array<string, string>,
 *     teachers: array<string, string>, rows: array<string, array>}
 */
function appointment_office_directory(bool $refresh = false): array
{
    static $directory = null;
    if ($directory !== null && !$refresh) {
        return $directory;
    }
    $conn = $GLOBALS["conn"] ?? null;
    $rows = $conn instanceof mysqli ? appointment_office_rows($conn) : null;
    if ($rows === null) {
        $rows = [];
        foreach (APPOINTMENT_DEFAULT_OFFICES as $code => $name) {
            $rows[] = appointment_office_row_defaults(["code" => $code, "name" => $name]);
        }
    }
    $directory = appointment_office_arrange($rows);
    return $directory;
}

/** @return list<array>|null null when the offices table is not installed yet */
function appointment_office_rows(mysqli $conn): ?array
{
    $sql =
        "SELECT o.code, o.name, o.is_active, o.kind, o.parent_code, o.owner_user_id, o.location,
                EXISTS (SELECT 1 FROM app_users s WHERE s.office_code = o.code AND s.role = 'offices' AND s.is_active = 1) AS has_staff,
                EXISTS (SELECT 1 FROM office_availability_rules r WHERE r.office_code = o.code AND r.is_active = 1) AS has_hours,
                COALESCE(t.is_active, 0) AS owner_active,
                TRIM(CONCAT(COALESCE(t.name_title, ''), ' ', COALESCE(t.last_name, ''))) AS short_name
         FROM offices o
         LEFT JOIN app_users t ON t.id = o.owner_user_id
         ORDER BY o.sort_order ASC, o.name ASC";
    try {
        $result = $conn->query($sql);
        if ($result) {
            return array_map("appointment_office_row_defaults", $result->fetch_all(MYSQLI_ASSOC));
        }
    } catch (mysqli_sql_exception $error) {
        // teacher_visits_migration.sql has not run yet: fall through to the plain list.
    }
    try {
        $result = $conn->query("SELECT code, name, is_active, location FROM offices ORDER BY sort_order ASC, name ASC");
        if ($result) {
            return array_map("appointment_office_row_defaults", $result->fetch_all(MYSQLI_ASSOC));
        }
    } catch (mysqli_sql_exception $error) {
        // The offices table is not installed yet; keep the original catalog.
    }
    return null;
}

/** One directory row in a known shape. A row from before the migration is a staffed office. */
function appointment_office_row_defaults(array $row): array
{
    return [
        "code" => (string) $row["code"],
        "name" => (string) $row["name"],
        "kind" => (string) ($row["kind"] ?? "office"),
        "parent_code" => isset($row["parent_code"]) && $row["parent_code"] !== "" ? (string) $row["parent_code"] : null,
        "is_active" => (int) ($row["is_active"] ?? 1) === 1,
        "location" => trim((string) ($row["location"] ?? "")),
        "has_staff" => (int) ($row["has_staff"] ?? 1) === 1,
        "has_hours" => (int) ($row["has_hours"] ?? 0) === 1,
        "owner_user_id" => isset($row["owner_user_id"]) ? (int) $row["owner_user_id"] : null,
        "owner_active" => (int) ($row["owner_active"] ?? 0) === 1,
        "short_name" => trim((string) ($row["short_name"] ?? "")),
    ];
}

/**
 * Sorts the rows into what each kind of person may choose. No database here, so it can be tested alone.
 *
 * @param list<array> $rows from appointment_office_row_defaults()
 */
function appointment_office_arrange(array $rows): array
{
    $byCode = [];
    foreach ($rows as $row) {
        $byCode[$row["code"]] = $row;
    }
    // An archived department takes everything under it out of use.
    $inUse = function (array $row) use ($byCode): bool {
        $seen = [];
        while ($row !== null && !isset($seen[$row["code"]])) {
            if (!$row["is_active"]) {
                return false;
            }
            $seen[$row["code"]] = true;
            $row = $row["parent_code"] !== null ? ($byCode[$row["parent_code"]] ?? null) : null;
        }
        return true;
    };
    $directory = ["all" => [], "active" => [], "bookable" => [], "teachers" => [], "rows" => $byCode];
    foreach ($rows as $row) {
        $directory["all"][$row["code"]] = $row["name"];
        if (!$inUse($row)) {
            continue;
        }
        if ($row["kind"] === "office" || $row["kind"] === "course") {
            $directory["active"][$row["code"]] = $row["name"];
        }
        if ($row["kind"] === "teacher" && $row["owner_active"] && $row["has_hours"]) {
            $directory["teachers"][$row["code"]] = $row["name"];
        }
    }
    foreach (["office", "course"] as $kind) {
        foreach ($rows as $row) {
            if ($row["kind"] !== $kind || !isset($directory["active"][$row["code"]])) {
                continue;
            }
            // A course is only an office when it has someone to answer for it.
            if ($row["has_staff"] || ($kind === "office" && !APPOINTMENT_HIDE_UNSTAFFED_OFFICES)) {
                $directory["bookable"][$row["code"]] = $row["name"];
            }
        }
    }
    return $directory;
}

function appointment_office_label(string $code): string
{
    $map = appointment_office_map();
    if (isset($map[$code])) {
        return $map[$code];
    }

    // Preserve readable labels for historical records without allowing these legacy
    // codes on new appointments.
    $legacy = [
        "CCI" => "College of Computing and Informatics (legacy)",
        "COED" => "College of Education (legacy)",
        "CEA" => "College of Engineering and Architecture (legacy)",
        "CIT" => "College of Industrial Technology (legacy)",
        "CAS" => "College of Arts and Sciences (legacy)",
    ];
    return $legacy[$code] ?? $code;
}

/** appointment_office_map() with teachers named as staff see them: "Prof. Elena Reyes (IT, CCI)". */
function appointment_office_long_map(): array
{
    $map = [];
    foreach (appointment_office_map() as $code => $name) {
        $map[$code] = appointment_office_long_label($code);
    }
    return $map;
}
