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
 * never get an answer. Set this to false to list every active office.
 */
const APPOINTMENT_HIDE_UNSTAFFED_OFFICES = true;

/**
 * Every office in the directory, archived ones included, so historical records keep their names.
 * Use appointment_office_active_map() or appointment_office_bookable_map() for anything a person chooses.
 *
 * @return array<string, string> code => full name
 */
function appointment_office_map(): array
{
    return appointment_office_directory()["all"];
}

/**
 * Offices that are in use: Office Personnel can be assigned to them.
 *
 * @return array<string, string> code => full name
 */
function appointment_office_active_map(): array
{
    return appointment_office_directory()["active"];
}

/**
 * The offices a visitor can choose: active ones with Office Personnel to answer them.
 *
 * @return array<string, string> code => full name
 */
function appointment_office_bookable_map(): array
{
    return appointment_office_directory()["bookable"];
}

/**
 * Reads the office directory once per request (pass $refresh after changing it).
 *
 * @return array{all: array<string, string>, active: array<string, string>, bookable: array<string, string>}
 */
function appointment_office_directory(bool $refresh = false): array
{
    static $directory = null;
    if ($directory !== null && !$refresh) {
        return $directory;
    }
    $conn = $GLOBALS["conn"] ?? null;
    if ($conn instanceof mysqli) {
        try {
            $result = $conn->query(
                "SELECT o.code, o.name, o.is_active,
                        EXISTS (SELECT 1 FROM app_users s WHERE s.office_code = o.code AND s.role = 'offices' AND s.is_active = 1) AS has_staff
                 FROM offices o
                 ORDER BY o.sort_order ASC, o.name ASC"
            );
            if ($result) {
                $directory = ["all" => [], "active" => [], "bookable" => []];
                while ($row = $result->fetch_assoc()) {
                    $code = (string) $row["code"];
                    $name = (string) $row["name"];
                    $directory["all"][$code] = $name;
                    if ((int) $row["is_active"] !== 1) {
                        continue;
                    }
                    $directory["active"][$code] = $name;
                    if ((int) $row["has_staff"] === 1 || !APPOINTMENT_HIDE_UNSTAFFED_OFFICES) {
                        $directory["bookable"][$code] = $name;
                    }
                }
                return $directory;
            }
        } catch (mysqli_sql_exception $error) {
            // The offices table is not installed yet; keep the original catalog.
        }
    }
    $directory = [
        "all" => APPOINTMENT_DEFAULT_OFFICES,
        "active" => APPOINTMENT_DEFAULT_OFFICES,
        "bookable" => APPOINTMENT_DEFAULT_OFFICES,
    ];
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
