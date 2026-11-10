<?php

/*
 * Who is behind each account: real names, departments, and validation shared by the
 * user-management, department-directory, and activity endpoints.
 */

require_once __DIR__ . "/appointment_offices.php";

const PERSONNEL_MIGRATION_MESSAGE = "Database update required: import phone_tracker/personnel_directory_migration.sql into phone_tracker.";

function personnel_schema_ready(mysqli $conn): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    try {
        foreach ([
            "SELECT first_name, last_name, position, last_seen_at, contact_number, email FROM app_users LIMIT 0",
            "SELECT code, name, location, description, is_active FROM offices LIMIT 0",
        ] as $sql) {
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

/** Stops the request with a clear instruction when the directory migration is missing. */
function personnel_require_schema(mysqli $conn): void
{
    if (!personnel_schema_ready($conn)) {
        auth_json_error(503, "migration_required", PERSONNEL_MIGRATION_MESSAGE);
    }
}

/** "First Last" when the person's name is on file, else the older display name or username. */
function personnel_full_name(array $row): string
{
    $name = trim(trim((string) ($row["first_name"] ?? "")) . " " . trim((string) ($row["last_name"] ?? "")));
    if ($name !== "") {
        return $name;
    }
    $display = trim((string) ($row["display_name"] ?? ""));
    return $display !== "" ? $display : (string) ($row["username"] ?? "");
}

function personnel_has_name(array $row): bool
{
    return trim((string) ($row["first_name"] ?? "")) !== "" && trim((string) ($row["last_name"] ?? "")) !== "";
}

/**
 * Department key used by filters: "admin", "security", "visitor", or an office code.
 */
function personnel_department_key(string $role, string $officeCode): string
{
    return $role === "offices" ? $officeCode : $role;
}

function personnel_department_label(string $role, string $officeCode): string
{
    $labels = ["admin" => "Administration", "security" => "Security", "visitor" => "Visitors"];
    if ($role === "offices") {
        return $officeCode !== "" ? appointment_office_label($officeCode) : "Unassigned office";
    }
    return $labels[$role] ?? $role;
}

/** @return string error message, or "" when valid */
function personnel_name_error(string $value, string $label): string
{
    if ($value === "") {
        return "Enter the " . $label . ".";
    }
    if (mb_strlen($value, "UTF-8") > 60 || !preg_match("/^\\p{L}[\\p{L}\\p{M} .'\\-]*$/u", $value)) {
        return "The " . $label . " can use letters, spaces, periods, apostrophes, and hyphens (up to 60).";
    }
    return "";
}

/** Collapses inner whitespace: "  Ma.   Theresa " becomes "Ma. Theresa". */
function personnel_clean_text(string $value, int $maxLength): string
{
    $value = trim(preg_replace('/\s+/u', " ", $value) ?? "");
    return mb_substr($value, 0, $maxLength, "UTF-8");
}

function personnel_contact_error(string $value): string
{
    if ($value !== "" && !preg_match('/^\+?[0-9 ()\-]{7,20}$/', $value)) {
        return "Enter a valid contact number (digits, spaces, +, -, or parentheses).";
    }
    return "";
}

function personnel_email_error(string $value): string
{
    if ($value !== "" && (strlen($value) > 190 || !filter_var($value, FILTER_VALIDATE_EMAIL))) {
        return "Enter a valid email address.";
    }
    return "";
}
