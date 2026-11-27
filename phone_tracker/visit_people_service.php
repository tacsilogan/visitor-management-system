<?php
/*
 * The people who come with a visitor (GROUP_VISITS.md).
 *
 * The visitor names them when booking: each person's full name as on their ID, and whether they
 * are 18 or older. At the gate the guard checks each adult's ID against their name and records
 * only its type; children are named but need no ID. The guard can fix the list: someone didn't
 * come, a name is spelled differently on the ID, someone came who wasn't on it. If a name doesn't
 * match, only that person is turned away.
 *
 * They have no account, no pass of their own, and are never tracked: only the visitor's phone is.
 * They are kept and deleted with the visit (the foreign keys cascade when
 * record_retention_service.php deletes it).
 *
 * A list belongs to a pass: a visit to one office (appointment_id) or a visit to several offices
 * (visit_id), whose stops share one pass.
 */

require_once __DIR__ . "/gate_service.php";

/** At most this many people with one visitor. */
const VISIT_PEOPLE_MAX = 10;
/** What the guard taps when someone shows no ID: stored as an empty type, with an optional reason. */
const VISIT_PEOPLE_NO_ID = "none";
const VISIT_PEOPLE_RESULTS = ["in", "absent", "refused"];

/** Whether group_visits_migration.sql is installed. */
function visit_people_schema_ready(mysqli $conn): bool
{
    static $ready = null;
    if ($ready === null) {
        $result = $conn->query(
            "SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'visit_people'"
        );
        $ready = $result && (int) $result->fetch_row()[0] > 0;
    }
    return $ready;
}

/**
 * The pass of an appointment row: the visit to several offices it is a stop of, or itself.
 *
 * @return array{appointment_id: ?int, visit_id: ?int}
 */
function visit_people_pass_for_row(array $row): array
{
    $visitId = (int) ($row["visit_id"] ?? 0);
    return $visitId > 0
        ? ["appointment_id" => null, "visit_id" => $visitId]
        : ["appointment_id" => (int) $row["id"], "visit_id" => null];
}

/** "WHERE"-clause part and its one parameter for a pass. */
function visit_people_pass_sql(array $pass): array
{
    return !empty($pass["visit_id"])
        ? ["visit_id = ?", (int) $pass["visit_id"]]
        : ["appointment_id = ?", (int) $pass["appointment_id"]];
}

/** A person's name: the gate's rule for the visitor's own name (gate_register_visitor()). */
function visit_people_valid_name(string $name): bool
{
    return mb_strlen($name) >= 2 && mb_strlen($name) <= 150 && preg_match('/\p{L}/u', $name) === 1;
}

/** true / false from what an app or a page sends ("1", 1, true …), or null when it's missing or unclear. */
function visit_people_flag(mixed $value): ?bool
{
    if (is_bool($value)) {
        return $value;
    }
    if ($value === 1 || $value === "1" || $value === "true") {
        return true;
    }
    if ($value === 0 || $value === "0" || $value === "false") {
        return false;
    }
    return null;
}

/**
 * Cleans the people a visitor sends with a booking ("people": [{"full_name", "is_adult"}]).
 * A booking without the field (today's app) has nobody with the visitor.
 *
 * @return list<array{full_name: string, is_adult: bool}>
 * @throws InvalidArgumentException with a JSON object of field => message
 */
function visit_people_clean_booking(mixed $raw): array
{
    if ($raw === null) {
        return [];
    }
    if (!is_array($raw) || ($raw !== [] && !array_is_list($raw))) {
        throw new InvalidArgumentException((string) json_encode(["people" => "The list of people with you is invalid."]));
    }
    if (count($raw) > VISIT_PEOPLE_MAX) {
        throw new InvalidArgumentException((string) json_encode(["people" => "Up to " . VISIT_PEOPLE_MAX . " people can come with you."]));
    }
    $people = [];
    $errors = [];
    foreach ($raw as $index => $person) {
        if (!is_array($person)) {
            $errors["people.{$index}"] = "Person " . ($index + 1) . ": the details are invalid.";
            continue;
        }
        $name = gate_clean_text($person["full_name"] ?? "");
        $adult = visit_people_flag($person["is_adult"] ?? null);
        if (!visit_people_valid_name($name)) {
            $errors["people.{$index}.full_name"] = "Person " . ($index + 1) . ": enter their full name as it appears on their ID.";
        }
        if ($adult === null) {
            $errors["people.{$index}.is_adult"] = "Person " . ($index + 1) . ": choose 18 or older, or under 18.";
        }
        $people[] = ["full_name" => $name, "is_adult" => (bool) $adult];
    }
    if ($errors) {
        throw new InvalidArgumentException((string) json_encode($errors));
    }
    return $people;
}

/**
 * Adds people to a pass. The caller owns the transaction.
 *
 * [$checkedInBy] > 0 records them as checked in now (Register at the gate, or added at check-in);
 * each person may then carry "id_type" (adults: a GATE_ID_TYPES value, or "" with an optional
 * "no_id_reason").
 *
 * @param list<array{full_name: string, is_adult: bool, id_type?: string, no_id_reason?: string}> $people
 */
function visit_people_insert(mysqli $conn, array $pass, array $people, int $checkedInBy = 0, bool $addedAtGate = false): void
{
    if (!$people) {
        return;
    }
    $appointmentId = !empty($pass["appointment_id"]) ? (int) $pass["appointment_id"] : null;
    $visitId = !empty($pass["visit_id"]) ? (int) $pass["visit_id"] : null;
    [$where, $param] = visit_people_pass_sql($pass);
    $next = $conn->prepare("SELECT COALESCE(MAX(sort_order), -1) + 1 FROM visit_people WHERE {$where}");
    $next->bind_param("i", $param);
    $next->execute();
    $order = (int) $next->get_result()->fetch_row()[0];
    $next->close();

    $checkedIn = $checkedInBy > 0;
    $status = $checkedIn ? "in" : "expected";
    $guard = $checkedIn ? $checkedInBy : null;
    $gate = $addedAtGate ? 1 : 0;
    $stmt = $conn->prepare(
        "INSERT INTO visit_people
         (appointment_id, visit_id, full_name, is_adult, added_at_gate, gate_status, id_type_shown, no_id_reason,
          checked_in_at, checked_in_by_user_id, sort_order)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, IF(? = 'in', NOW(), NULL), ?, ?)"
    );
    foreach ($people as $person) {
        $name = (string) $person["full_name"];
        $adult = !empty($person["is_adult"]) ? 1 : 0;
        $idType = $adult && $checkedIn ? (string) ($person["id_type"] ?? "") : "";
        $reason = $adult && $checkedIn && $idType === "" ? (string) ($person["no_id_reason"] ?? "") : "";
        $stmt->bind_param("iisiissssii", $appointmentId, $visitId, $name, $adult, $gate, $status, $idType, $reason, $status, $guard, $order);
        $stmt->execute();
        $order++;
    }
    $stmt->close();
}

/**
 * Replaces the people on a pass that hasn't been checked in yet (the visitor changed the list in
 * the app). The caller owns the transaction and has checked the pass's status.
 */
function visit_people_replace(mysqli $conn, array $pass, array $people): void
{
    [$where, $param] = visit_people_pass_sql($pass);
    $delete = $conn->prepare("DELETE FROM visit_people WHERE {$where}");
    $delete->bind_param("i", $param);
    $delete->execute();
    $delete->close();
    visit_people_insert($conn, $pass, $people);
}

/** The people on a pass, in the order they were added. */
function visit_people_list(mysqli $conn, array $pass, bool $forUpdate = false): array
{
    if (!visit_people_schema_ready($conn) || (empty($pass["appointment_id"]) && empty($pass["visit_id"]))) {
        return [];
    }
    [$where, $param] = visit_people_pass_sql($pass);
    $stmt = $conn->prepare("SELECT * FROM visit_people WHERE {$where} ORDER BY sort_order, id" . ($forUpdate ? " FOR UPDATE" : ""));
    $stmt->bind_param("i", $param);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/**
 * The people on the passes of many appointment rows at once (lists on the Office, Security and
 * Admin pages): appointment id => people. A stop of a visit to several offices gets the
 * visit's list.
 *
 * @param list<array> $rows appointment rows with "id" and, for stops, "visit_id"
 * @return array<int, list<array>>
 */
function visit_people_for_rows(mysqli $conn, array $rows): array
{
    if (!$rows || !visit_people_schema_ready($conn)) {
        return [];
    }
    $appointmentIds = [];
    $visitIds = [];
    foreach ($rows as $row) {
        $pass = visit_people_pass_for_row($row);
        if ($pass["visit_id"]) {
            $visitIds[$pass["visit_id"]] = true;
        } else {
            $appointmentIds[$pass["appointment_id"]] = true;
        }
    }
    $fetch = function (string $column, array $ids) use ($conn): array {
        $found = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $list = implode(",", array_map("intval", $chunk));
            $result = $conn->query("SELECT * FROM visit_people WHERE {$column} IN ({$list}) ORDER BY sort_order, id");
            while ($person = $result->fetch_assoc()) {
                $found[(int) $person[$column]][] = $person;
            }
        }
        return $found;
    };
    $byAppointment = $fetch("appointment_id", array_keys($appointmentIds));
    $byVisit = $fetch("visit_id", array_keys($visitIds));
    $people = [];
    foreach ($rows as $row) {
        $pass = visit_people_pass_for_row($row);
        $people[(int) $row["id"]] = $pass["visit_id"] ? ($byVisit[$pass["visit_id"]] ?? []) : ($byAppointment[$pass["appointment_id"]] ?? []);
    }
    return $people;
}

/** One person for the app and the website. Never more than the visit already shows. */
function visit_people_payload(array $person): array
{
    $status = (string) $person["gate_status"];
    return [
        "id" => (int) $person["id"],
        "full_name" => (string) $person["full_name"],
        "is_adult" => (bool) $person["is_adult"],
        "added_at_gate" => (bool) $person["added_at_gate"],
        // The name as booked, when the guard fixed it to match the ID.
        "booked_name" => $person["booked_name"] !== null ? (string) $person["booked_name"] : null,
        "gate_status" => $status,
        "id_type_shown" => (string) $person["id_type_shown"],
        "no_id_reason" => (string) $person["no_id_reason"],
        "checked_in_at" => $person["checked_in_at"],
        "left_at" => $person["left_at"],
    ];
}

/** @param list<array> $people */
function visit_people_payloads(array $people): array
{
    return array_map("visit_people_payload", $people);
}

/** "Elena Dela Cruz and Miguel Dela Cruz (under 18)" */
function visit_people_names_text(array $people): string
{
    $names = array_map(function (array $person): string {
        return (string) $person["full_name"] . (empty($person["is_adult"]) ? " (under 18)" : "");
    }, $people);
    if (count($names) <= 1) {
        return implode("", $names);
    }
    return implode(", ", array_slice($names, 0, -1)) . " and " . $names[count($names) - 1];
}

/** A GATE_ID_TYPES value, VISIT_PEOPLE_NO_ID, or null when it is neither. */
function visit_people_clean_id_type(mixed $value): ?string
{
    $type = gate_clean_text($value ?? "");
    if ($type === VISIT_PEOPLE_NO_ID || in_array($type, GATE_ID_TYPES, true)) {
        return $type;
    }
    return null;
}

/**
 * Checks the guard's answers at check-in (scan_appointment.php, step "confirm") against the
 * people on the pass:
 *
 *   {"holder": {"id_type": "Driver's license" | "none", "no_id_reason": "…"},
 *    "people": [{"id": 12, "result": "in" | "absent" | "refused", "id_type": "…", "no_id_reason": "…",
 *                "full_name": "the name fixed to match the ID"},
 *               {"full_name": "Lito Torres", "is_adult": true, "result": "in", "id_type": "School ID"}]}
 *
 * An entry without "id" is someone the guard added. Anyone on the pass missing from "people"
 * didn't come.
 *
 * @param list<array> $existing the people on the pass (visit_people_list())
 * @return array{holder: array{id_type: string, no_id_reason: string}, people: list<array>}
 * @throws InvalidArgumentException with a message for the guard
 */
function visit_people_clean_check(array $input, array $existing): array
{
    $holderInput = isset($input["holder"]) && is_array($input["holder"]) ? $input["holder"] : [];
    $holderType = visit_people_clean_id_type($holderInput["id_type"] ?? "");
    if ($holderType === null) {
        throw new InvalidArgumentException("Choose the ID the visitor showed, or No ID.");
    }
    $holderReason = $holderType === VISIT_PEOPLE_NO_ID ? mb_substr(gate_clean_text($holderInput["no_id_reason"] ?? ""), 0, 150) : "";

    $byId = [];
    foreach ($existing as $person) {
        $byId[(int) $person["id"]] = $person;
    }
    $rawPeople = $input["people"] ?? [];
    if (!is_array($rawPeople) || ($rawPeople !== [] && !array_is_list($rawPeople))) {
        throw new InvalidArgumentException("The list of people is invalid. Scan the pass again.");
    }
    $answered = [];
    $people = [];
    foreach ($rawPeople as $raw) {
        if (!is_array($raw)) {
            throw new InvalidArgumentException("The list of people is invalid. Scan the pass again.");
        }
        $id = isset($raw["id"]) && $raw["id"] !== null ? (int) $raw["id"] : 0;
        if ($id > 0) {
            if (!isset($byId[$id])) {
                throw new InvalidArgumentException("The list of people on this pass changed. Scan the pass again.");
            }
            if (isset($answered[$id])) {
                throw new InvalidArgumentException("Someone is on the list twice. Scan the pass again.");
            }
            $answered[$id] = true;
            $stored = $byId[$id];
            $adult = (bool) $stored["is_adult"];
            $result = (string) ($raw["result"] ?? "");
            if (!in_array($result, VISIT_PEOPLE_RESULTS, true)) {
                throw new InvalidArgumentException("Say whether " . $stored["full_name"] . " came in.");
            }
            $name = (string) $stored["full_name"];
            $renameFrom = null;
            if (isset($raw["full_name"])) {
                $fixed = gate_clean_text($raw["full_name"]);
                if ($fixed !== $name) {
                    if (!visit_people_valid_name($fixed)) {
                        throw new InvalidArgumentException("Enter the name as it appears on the ID.");
                    }
                    $renameFrom = $name;
                    $name = $fixed;
                }
            }
        } else {
            $name = gate_clean_text($raw["full_name"] ?? "");
            if (!visit_people_valid_name($name)) {
                throw new InvalidArgumentException("Enter the full name of the person you added, as it appears on their ID.");
            }
            $adult = visit_people_flag($raw["is_adult"] ?? null);
            if ($adult === null) {
                throw new InvalidArgumentException("Choose 18 or older, or under 18, for " . $name . ".");
            }
            // Someone added at the gate came in: nobody adds a person only to turn them away.
            $result = "in";
            $renameFrom = null;
        }
        $idType = "";
        $reason = "";
        if ($result === "in" && $adult) {
            $type = visit_people_clean_id_type($raw["id_type"] ?? "");
            if ($type === null) {
                throw new InvalidArgumentException("Choose the ID " . $name . " showed, or No ID.");
            }
            if ($type === VISIT_PEOPLE_NO_ID) {
                $reason = mb_substr(gate_clean_text($raw["no_id_reason"] ?? ""), 0, 150);
            } else {
                $idType = $type;
            }
        }
        $people[] = [
            "id" => $id > 0 ? $id : null,
            "full_name" => $name,
            "is_adult" => $adult,
            "result" => $result,
            "id_type" => $idType,
            "no_id_reason" => $reason,
            "rename_from" => $renameFrom,
        ];
    }
    // Anyone on the pass the guard didn't answer for didn't come.
    foreach ($existing as $person) {
        if (!isset($answered[(int) $person["id"]])) {
            $people[] = [
                "id" => (int) $person["id"],
                "full_name" => (string) $person["full_name"],
                "is_adult" => (bool) $person["is_adult"],
                "result" => "absent",
                "id_type" => "",
                "no_id_reason" => "",
                "rename_from" => null,
            ];
        }
    }
    if (count($people) > VISIT_PEOPLE_MAX) {
        throw new InvalidArgumentException("Up to " . VISIT_PEOPLE_MAX . " people can come with one visitor.");
    }
    return [
        "holder" => ["id_type" => $holderType === VISIT_PEOPLE_NO_ID ? "" : $holderType, "no_id_reason" => $holderReason],
        "people" => $people,
    ];
}

/**
 * Records the guard's answers at check-in, inside the check-in's transaction: the visitor's ID
 * on every checked-in stop, and each person's result. Returns what the activity log keeps.
 *
 * @param list<int> $appointmentIds the stops checked in with this pass
 * @return array{id_type: string, no_id_reason: string, people: list<array>}
 */
function visit_people_record_check(mysqli $conn, array $pass, array $appointmentIds, array $answer, int $guardUserId): array
{
    $holder = $answer["holder"];
    if ($appointmentIds && gate_schema_ready($conn)) {
        $list = implode(",", array_map("intval", $appointmentIds));
        $hasReason = visit_people_schema_ready($conn);
        $stmt = $conn->prepare(
            "UPDATE appointments SET id_type_shown = ?" . ($hasReason ? ", no_id_shown = ?, no_id_reason = ?" : "") . " WHERE id IN ({$list})"
        );
        if ($hasReason) {
            $noId = $holder["id_type"] === "" ? 1 : 0;
            $stmt->bind_param("sis", $holder["id_type"], $noId, $holder["no_id_reason"]);
        } else {
            $stmt->bind_param("s", $holder["id_type"]);
        }
        $stmt->execute();
        $stmt->close();
    }
    $summary = [];
    if (visit_people_schema_ready($conn)) {
        [$where, $param] = visit_people_pass_sql($pass);
        $update = $conn->prepare(
            "UPDATE visit_people
             SET gate_status = ?, id_type_shown = ?, no_id_reason = ?,
                 checked_in_at = IF(? = 'in', NOW(), NULL), checked_in_by_user_id = ?,
                 booked_name = IF(? <> '' AND booked_name IS NULL, ?, booked_name), full_name = ?
             WHERE id = ? AND {$where}"
        );
        $added = [];
        foreach ($answer["people"] as $person) {
            if ($person["id"] === null) {
                $added[] = $person;
                continue;
            }
            $result = $person["result"];
            $from = (string) ($person["rename_from"] ?? "");
            $id = (int) $person["id"];
            $update->bind_param(
                "ssssisssii",
                $result, $person["id_type"], $person["no_id_reason"], $result, $guardUserId,
                $from, $from, $person["full_name"], $id, $param
            );
            $update->execute();
        }
        $update->close();
        visit_people_insert($conn, $pass, array_map(function (array $person): array {
            return [
                "full_name" => $person["full_name"],
                "is_adult" => $person["is_adult"],
                "id_type" => $person["id_type"],
                "no_id_reason" => $person["no_id_reason"],
            ];
        }, $added), $guardUserId, true);
        // Each person turned away gets an entry of their own in the activity log.
        foreach ($answer["people"] as $person) {
            if ($person["result"] === "refused" && $person["id"] !== null) {
                visit_people_audit($conn, $guardUserId, $appointmentIds[0] ?? null, "visit_person.refused", (int) $person["id"], [
                    "name" => $person["full_name"],
                    "adult" => $person["is_adult"],
                    "late" => false,
                ]);
            }
        }
        foreach ($answer["people"] as $person) {
            $summary[] = [
                "name" => $person["full_name"],
                "adult" => $person["is_adult"],
                "result" => $person["result"],
                "id_type" => $person["id_type"],
                "no_id" => $person["result"] === "in" && $person["is_adult"] && $person["id_type"] === "",
                "no_id_reason" => $person["no_id_reason"],
                "added" => $person["id"] === null,
                "booked_as" => $person["rename_from"],
            ];
        }
    }
    return ["id_type" => $holder["id_type"], "no_id_reason" => $holder["no_id_reason"], "people" => $summary];
}

/**
 * The answer to a scan's "check" step: the pass, everyone on it, and the IDs the guard can choose.
 * Nothing is checked in yet.
 */
function visit_people_check_response(mysqli $conn, array $details, array $pass): array
{
    return array_merge($details, [
        "success" => true,
        "action" => "check_ids",
        "message" => "Check their IDs, then check them in.",
        "people" => visit_people_payloads(visit_people_list($conn, $pass)),
        "id_types" => GATE_ID_TYPES,
        "max_people" => VISIT_PEOPLE_MAX,
    ]);
}

/**
 * The pass holder's name didn't match their ID: nobody on the pass is checked in, and the pass
 * stays valid for its owner. Only the activity log changes.
 */
function visit_people_refuse_pass(mysqli $conn, int $guardUserId, int $appointmentId, ?int $visitId, bool $withoutQr, array $details): array
{
    $audit = $conn->prepare(
        "INSERT INTO audit_logs (actor_user_id, appointment_id, action, entity_type, entity_id, details_json, ip_address)
         VALUES (?, ?, 'appointment.pass_refused', ?, ?, ?, ?)"
    );
    if ($audit) {
        $entityType = $visitId ? "visit" : "appointment";
        $entityId = (string) ($visitId ?: $appointmentId);
        $json = (string) json_encode(["visit_id" => $visitId, "without_qr" => $withoutQr]);
        $ip = isset($_SERVER["REMOTE_ADDR"]) ? substr((string) $_SERVER["REMOTE_ADDR"], 0, 45) : "";
        $audit->bind_param("iissss", $guardUserId, $appointmentId, $entityType, $entityId, $json, $ip);
        $audit->execute();
        $audit->close();
    }
    return array_merge($details, [
        "success" => true,
        "action" => "refused",
        "message" => "Not checked in. The pass still works for " . ($details["visitor_full_name"] ?? "its owner") . ".",
    ]);
}

/** Writes an audit_logs row for something done to one person on a pass. */
function visit_people_audit(mysqli $conn, ?int $actorUserId, ?int $appointmentId, string $action, int $personId, array $details): void
{
    $stmt = $conn->prepare(
        "INSERT INTO audit_logs (actor_user_id, appointment_id, action, entity_type, entity_id, details_json, ip_address)
         VALUES (?, ?, ?, 'visit_person', ?, ?, ?)"
    );
    if (!$stmt) {
        return;
    }
    $entityId = (string) $personId;
    $json = (string) json_encode($details);
    $ip = isset($_SERVER["REMOTE_ADDR"]) ? substr((string) $_SERVER["REMOTE_ADDR"], 0, 45) : "";
    $stmt->bind_param("iissss", $actorUserId, $appointmentId, $action, $entityId, $json, $ip);
    $stmt->execute();
    $stmt->close();
}

/**
 * One person with their visit: the visitor's name, the office(s), whether the visit is on campus,
 * and the appointment the activity log files it under.
 */
function visit_people_find(mysqli $conn, int $personId, bool $forUpdate = false): ?array
{
    if ($personId <= 0 || !visit_people_schema_ready($conn)) {
        return null;
    }
    $stmt = $conn->prepare("SELECT * FROM visit_people WHERE id = ? LIMIT 1" . ($forUpdate ? " FOR UPDATE" : ""));
    $stmt->bind_param("i", $personId);
    $stmt->execute();
    $person = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$person) {
        return null;
    }
    if (!empty($person["visit_id"])) {
        $visit = $conn->prepare(
            "SELECT v.status, v.visit_code AS reference, COALESCE(v.tracking_appointment_id, MIN(a.id)) AS appointment_id,
                    MIN(a.visitor_full_name) AS visitor_full_name, GROUP_CONCAT(a.office_code ORDER BY a.stop_number) AS office_codes
             FROM visits v JOIN appointments a ON a.visit_id = v.id WHERE v.id = ? GROUP BY v.id"
        );
        $visitId = (int) $person["visit_id"];
        $visit->bind_param("i", $visitId);
    } else {
        $visit = $conn->prepare(
            "SELECT status, registration_code AS reference, id AS appointment_id, visitor_full_name, office_code AS office_codes
             FROM appointments WHERE id = ?"
        );
        $appointmentId = (int) $person["appointment_id"];
        $visit->bind_param("i", $appointmentId);
    }
    $visit->execute();
    $row = $visit->get_result()->fetch_assoc();
    $visit->close();
    if (!$row) {
        return null;
    }
    $offices = array_map("appointment_office_label", array_filter(explode(",", (string) $row["office_codes"])));
    return $person + [
        "visit_status" => (string) $row["status"],
        "reference" => (string) $row["reference"],
        "pass_appointment_id" => (int) $row["appointment_id"],
        "visitor_full_name" => (string) $row["visitor_full_name"],
        "office_label" => implode(", ", $offices),
        "on_campus" => $row["status"] === "checked_in",
    ];
}

/**
 * Someone who came with a visitor leaves before the rest of the group: the guard marks them as
 * left (Security Home). The rest stay checked in.
 *
 * @throws DomainException with a message for the guard
 */
function visit_people_mark_left(mysqli $conn, int $personId, int $guardUserId): array
{
    $conn->begin_transaction();
    try {
        $person = visit_people_find($conn, $personId, true);
        if (!$person) {
            throw new DomainException("That person is no longer on a visit.");
        }
        if (!$person["on_campus"] || $person["gate_status"] !== "in") {
            throw new DomainException($person["full_name"] . " isn't checked in.");
        }
        if ($person["left_at"] !== null) {
            throw new DomainException($person["full_name"] . " has already left.");
        }
        $stmt = $conn->prepare("UPDATE visit_people SET left_at = NOW(), left_by_user_id = ? WHERE id = ? AND left_at IS NULL");
        $stmt->bind_param("ii", $guardUserId, $personId);
        $stmt->execute();
        $stmt->close();
        visit_people_audit($conn, $guardUserId, $person["pass_appointment_id"], "visit_person.left", $personId, [
            "name" => $person["full_name"],
            "visitor" => $person["visitor_full_name"],
        ]);
        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }
    return visit_people_find($conn, $personId) ?? [];
}

/**
 * Someone on the list who missed the group at the gate comes later: the guard checks their ID
 * and checks them in, or turns them away when the name doesn't match ([$idType] null).
 *
 * @throws DomainException with a message for the guard
 */
function visit_people_late_arrival(mysqli $conn, int $personId, ?string $idType, string $noIdReason, int $guardUserId): array
{
    $conn->begin_transaction();
    try {
        $person = visit_people_find($conn, $personId, true);
        if (!$person) {
            throw new DomainException("That person is no longer on a visit.");
        }
        if (!$person["on_campus"]) {
            throw new DomainException("The visit isn't checked in, so " . $person["full_name"] . " comes in with the visitor's pass.");
        }
        if (!in_array($person["gate_status"], ["expected", "absent"], true)) {
            throw new DomainException($person["full_name"] . " was already checked at the gate.");
        }
        $refused = $idType === null;
        $type = "";
        $reason = "";
        if (!$refused && $person["is_adult"]) {
            $clean = visit_people_clean_id_type($idType);
            if ($clean === null) {
                throw new DomainException("Choose the ID " . $person["full_name"] . " showed, or No ID.");
            }
            if ($clean === VISIT_PEOPLE_NO_ID) {
                $reason = mb_substr(gate_clean_text($noIdReason), 0, 150);
            } else {
                $type = $clean;
            }
        }
        $status = $refused ? "refused" : "in";
        $stmt = $conn->prepare(
            "UPDATE visit_people SET gate_status = ?, id_type_shown = ?, no_id_reason = ?,
                    checked_in_at = IF(? = 'in', NOW(), NULL), checked_in_by_user_id = ?
             WHERE id = ?"
        );
        $stmt->bind_param("ssssii", $status, $type, $reason, $status, $guardUserId, $personId);
        $stmt->execute();
        $stmt->close();
        visit_people_audit($conn, $guardUserId, $person["pass_appointment_id"], $refused ? "visit_person.refused" : "visit_person.checked_in", $personId, [
            "name" => $person["full_name"],
            "visitor" => $person["visitor_full_name"],
            "adult" => (bool) $person["is_adult"],
            "id_type" => $type,
            "no_id_reason" => $reason,
            "late" => true,
        ]);
        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }
    return visit_people_find($conn, $personId) ?? [];
}

/**
 * How many people are inside with a visitor now: checked in, not left early, on a pass that is
 * still checked in.
 *
 * @return array{people: int, under_18: int}
 */
function visit_people_inside_counts(mysqli $conn): array
{
    if (!visit_people_schema_ready($conn)) {
        return ["people" => 0, "under_18" => 0];
    }
    $row = $conn->query(
        "SELECT COUNT(*) AS people, COALESCE(SUM(p.is_adult = 0), 0) AS under_18
         FROM visit_people p
         LEFT JOIN appointments a ON a.id = p.appointment_id
         LEFT JOIN visits v ON v.id = p.visit_id
         WHERE p.gate_status = 'in' AND p.left_at IS NULL
           AND ((p.appointment_id IS NOT NULL AND a.status = 'checked_in')
             OR (p.visit_id IS NOT NULL AND v.status = 'checked_in'))"
    )->fetch_assoc();
    return ["people" => (int) $row["people"], "under_18" => (int) $row["under_18"]];
}

/**
 * Security's search for the people with a visitor (Security Home): by name, the visitor they came
 * with, or the pass reference, among visits from the last day that aren't finished.
 *
 * @return list<array>
 */
function visit_people_search(mysqli $conn, string $query, int $limit = 8): array
{
    $query = gate_clean_text($query);
    if (mb_strlen($query) < 2 || !visit_people_schema_ready($conn)) {
        return [];
    }
    $like = "%" . addcslashes($query, "%_\\") . "%";
    $stmt = $conn->prepare(
        "SELECT p.id
         FROM visit_people p
         LEFT JOIN appointments a ON a.id = p.appointment_id
         LEFT JOIN visits v ON v.id = p.visit_id
         LEFT JOIN appointments s ON s.visit_id = v.id AND s.stop_number = 1
         WHERE (p.full_name LIKE ? OR COALESCE(a.visitor_full_name, s.visitor_full_name) LIKE ?
                OR COALESCE(a.registration_code, v.visit_code) LIKE ?)
           AND COALESCE(a.status, v.status) IN ('approved', 'checked_in', 'open')
           AND COALESCE(a.scheduled_start_at, s.scheduled_start_at) >= DATE_SUB(CURDATE(), INTERVAL 1 DAY)
         ORDER BY p.full_name LIMIT ?"
    );
    $stmt->bind_param("sssi", $like, $like, $like, $limit);
    $stmt->execute();
    $ids = array_map(fn ($row) => (int) $row[0], $stmt->get_result()->fetch_all());
    $stmt->close();
    $found = [];
    foreach ($ids as $id) {
        $person = visit_people_find($conn, $id);
        if ($person) {
            $found[] = $person;
        }
    }
    return $found;
}

/** One person for Security's search results. */
function visit_people_search_payload(array $person): array
{
    $status = (string) $person["gate_status"];
    $inside = $person["on_campus"] && $status === "in" && $person["left_at"] === null;
    return visit_people_payload($person) + [
        "visitor_full_name" => (string) $person["visitor_full_name"],
        "reference" => (string) $person["reference"],
        "office_label" => (string) $person["office_label"],
        "visit_status" => (string) $person["visit_status"],
        "on_campus" => (bool) $person["on_campus"],
        "inside" => $inside,
        // Missed the group at the gate, and the group is inside: the guard can check them in now.
        "can_join" => $person["on_campus"] && in_array($status, ["expected", "absent"], true),
        "can_leave" => $inside,
    ];
}

/**
 * The sentence for a check-in in the activity log, from what visit_people_record_check() kept:
 * "Juan Dela Cruz (driver's license), Elena Dela Cruz (PhilSys ID), Miguel Dela Cruz (under 18).
 * Kristine Tan didn't come." People turned away get their own entries.
 */
function visit_people_activity_text(string $visitorName, array $details): string
{
    $people = isset($details["people"]) && is_array($details["people"]) ? $details["people"] : [];
    $idPart = function (string $type, bool $noId, string $reason): string {
        if ($type !== "") {
            return $type === "PhilSys national ID" ? "PhilSys ID" : lcfirst($type);
        }
        return $noId ? "no ID shown" . ($reason !== "" ? ": " . $reason : "") : "";
    };
    $holderType = (string) ($details["id_type"] ?? "");
    $holderReason = (string) ($details["no_id_reason"] ?? "");
    $holderKnown = array_key_exists("id_type", $details);
    $holder = $holderKnown ? $idPart($holderType, $holderType === "", $holderReason) : "";
    if (!$people) {
        return $holder !== "" ? ". ID: " . $holder : "";
    }
    $came = [$visitorName . ($holder !== "" ? " (" . $holder . ")" : "")];
    $absent = [];
    foreach ($people as $person) {
        $name = (string) ($person["name"] ?? "");
        $result = (string) ($person["result"] ?? "");
        if ($result === "absent") {
            $absent[] = $name;
            continue;
        }
        if ($result !== "in") {
            continue;
        }
        $notes = [];
        if (empty($person["adult"])) {
            $notes[] = "under 18";
        } else {
            $part = $idPart((string) ($person["id_type"] ?? ""), !empty($person["no_id"]), (string) ($person["no_id_reason"] ?? ""));
            if ($part !== "") {
                $notes[] = $part;
            }
        }
        if (!empty($person["added"])) {
            $notes[] = "added at the gate";
        }
        if (!empty($person["booked_as"])) {
            $notes[] = "booked as " . $person["booked_as"];
        }
        $came[] = $name . ($notes ? " (" . implode(", ", $notes) . ")" : "");
    }
    $list = count($came) === 1 ? $came[0] : implode(", ", array_slice($came, 0, -1)) . " and " . $came[count($came) - 1];
    $text = ": " . $list;
    if ($absent) {
        $text .= ". " . (count($absent) === 1 ? $absent[0] : implode(", ", array_slice($absent, 0, -1)) . " and " . $absent[count($absent) - 1]) . " didn't come";
    }
    return $text;
}
