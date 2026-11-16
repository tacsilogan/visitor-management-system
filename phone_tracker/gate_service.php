<?php
/*
 * Registering a visitor at the gate (GATE_REGISTRATION.md). For people who can't use the ISATU
 * Visits app (an iPhone, a basic phone, no mobile data, a dead battery) or don't want location
 * tracking: Security checks their ID, enters the visit on the Security dashboard, and the visitor
 * is checked in at once. The visit is never tracked and has no visitor account; it is kept, shown
 * and deleted after a year like every other visit.
 *
 * Also gives the pass of a booked visitor who can't show their QR code, so the guard can check
 * them in by name or reference after checking their ID (scan_appointment.php, appointment_id).
 */

require_once __DIR__ . "/appointment_offices.php";
require_once __DIR__ . "/office_availability_service.php";

/** The IDs a guard can record. Only the type is kept, never the ID number. */
const GATE_ID_TYPES = [
    "PhilSys national ID",
    "Driver's license",
    "Passport",
    "School ID",
    "Company ID",
    "Other government ID",
];

/** The purposes the visitor app offers (BookingScreen.kt), so reports group them the same way. */
const GATE_PURPOSES = [
    "Academic Concern",
    "Office Transaction",
    "Technical Support",
    "Document Request",
    "Official Business",
    "Other",
];

/** What the guard reads to the visitor before registering them. Change the version with the text. */
const GATE_NOTICE_VERSION = "gate-notice-1";
const GATE_NOTICE_TEXT = "We'll record your name, the type of ID you showed, your contact number if you give one, "
    . "the office you visit, and your time in and out. It's kept for one year, and your location is not tracked.";

/** A gate visit has no booked slot. As with an app walk-in after check-in, "Overstay" starts after this. */
const GATE_VISIT_HOURS = 4;

/** Whether gate_registration_migration.sql is installed. */
function gate_schema_ready(mysqli $conn): bool
{
    static $ready = null;
    if ($ready === null) {
        $result = $conn->query(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'appointments' AND COLUMN_NAME = 'registration_source'"
        );
        $ready = $result && (int) $result->fetch_row()[0] > 0;
    }
    return $ready;
}

/**
 * Every office a visitor can be registered for (active, with Office Personnel), whether a gate
 * registration is allowed at [$now], and one sentence for the guard.
 *
 * @return list<array{code:string, name:string, can_register:bool, message:string}>
 */
function gate_office_states(mysqli $conn, ?DateTimeImmutable $now = null): array
{
    $now = $now ?: new DateTimeImmutable("now");
    $offices = [];
    foreach (appointment_office_bookable_map() as $code => $name) {
        $offices[] = gate_office_state($conn, (string) $code, (string) $name, $now);
    }
    return $offices;
}

/**
 * The same rule as an app walk-in (office_walk_in_block_reason): the office must be open, or open
 * again within OFFICE_WALK_IN_LEAD_MINUTES, and taking visitors.
 *
 * @return array{code:string, name:string, can_register:bool, message:string}
 */
function gate_office_state(mysqli $conn, string $code, string $name, DateTimeImmutable $now): array
{
    $state = office_open_state($conn, $code, $now);
    $until = $state["until"] !== null ? new DateTimeImmutable((string) $state["until"]) : null;
    if (office_walk_in_block_reason_for($state, $name, $now) === null) {
        if ($state["state"] === "open") {
            $message = office_state_message($state, $name, $now);
            if (!empty($state["next"]) && $state["next"] !== "Closing") {
                $message .= " Next: " . $state["next"] . ".";
            }
        } else {
            // Opens again within the lead time: the visitor can wait at the office.
            $message = "{$name} opens again at " . $until->format("g:i A") . ". The visitor can wait there.";
        }
        return ["code" => $code, "name" => $name, "can_register" => true, "message" => $message];
    }
    $message = office_state_message($state, $name, $now);
    if (in_array($state["state"], ["break", "closed"], true) && $until !== null) {
        $from = $until->modify("-" . OFFICE_WALK_IN_LEAD_MINUTES . " minutes")->format("Y-m-d H:i:s");
        $message .= " You can register visitors for it " . office_when($from, $now) . ".";
    }
    return ["code" => $code, "name" => $name, "can_register" => false, "message" => $message];
}

/** Collapses spaces and trims. */
function gate_clean_text(mixed $value): string
{
    return trim((string) preg_replace('/\s+/u', " ", (string) $value));
}

/**
 * Registers a visitor at the gate and checks them in, in one transaction.
 *
 * [$input]: full_name, id_type, contact_number (optional), office_code, purpose, concern
 * (optional) and consent (true once the guard has read GATE_NOTICE_TEXT to the visitor).
 *
 * @return array{appointment_id:int, registration_code:string, visitor_full_name:string,
 *     office_code:string, office_label:string, checked_in_at:string}
 * @throws InvalidArgumentException with a JSON object of field => message
 * @throws DomainException when the office can't take visitors now
 */
function gate_register_visitor(mysqli $conn, int $guardUserId, array $input, ?DateTimeImmutable $now = null): array
{
    if (!gate_schema_ready($conn)) {
        throw new DomainException("Database update required: import phone_tracker/gate_registration_migration.sql.");
    }
    $now = $now ?: new DateTimeImmutable("now");
    $name = gate_clean_text($input["full_name"] ?? "");
    $idType = gate_clean_text($input["id_type"] ?? "");
    $contact = gate_clean_text($input["contact_number"] ?? "");
    $officeCode = strtoupper(gate_clean_text($input["office_code"] ?? ""));
    $purpose = gate_clean_text($input["purpose"] ?? "");
    $concern = gate_clean_text($input["concern"] ?? "");
    $consent = ($input["consent"] ?? false) === true;

    $errors = [];
    if (mb_strlen($name) < 2 || mb_strlen($name) > 150 || !preg_match('/\p{L}/u', $name)) {
        $errors["full_name"] = "Enter the visitor's full name as it appears on their ID.";
    }
    if (!in_array($idType, GATE_ID_TYPES, true)) {
        $errors["id_type"] = "Choose the type of ID the visitor showed.";
    }
    if ($contact !== "") {
        $digits = strlen((string) preg_replace('/\D/', "", $contact));
        if (!preg_match('/^[0-9+()\- ]{7,32}$/', $contact) || $digits < 7 || $digits > 15) {
            $errors["contact_number"] = "Enter a phone number, or leave it empty.";
        }
    }
    $offices = appointment_office_bookable_map();
    if (!isset($offices[$officeCode])) {
        $errors["office_code"] = "Choose an office that has Office Personnel.";
    }
    if (!in_array($purpose, GATE_PURPOSES, true)) {
        $errors["purpose"] = "Choose the purpose of the visit.";
    }
    if (mb_strlen($concern) > 150) {
        $errors["concern"] = "Keep the concern under 150 characters.";
    }
    if (!$consent) {
        $errors["consent"] = "Read the notice to the visitor, then tick that they agreed.";
    }
    if ($errors) {
        throw new InvalidArgumentException((string) json_encode($errors));
    }
    $officeLabel = $offices[$officeCode];
    $office = gate_office_state($conn, $officeCode, $officeLabel, $now);
    if (!$office["can_register"]) {
        throw new DomainException($office["message"]);
    }

    $nowSql = $now->format("Y-m-d H:i:s");
    $endSql = $now->modify("+" . GATE_VISIT_HOURS . " hours")->format("Y-m-d H:i:s");
    // Never shown or printed: the visitor has no pass. It only keeps the column's uniqueness.
    $token = bin2hex(random_bytes(32));

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare(
            "INSERT INTO appointments
             (public_token, office_code, visitor_full_name, visitor_email, contact_number, device_name,
              visit_type, registration_source, id_type_shown, gate_consent_at, purpose, destination, subject,
              appointment_at, scheduled_start_at, scheduled_end_at, visitor_user_id, status, status_updated_at,
              approved_at, checked_in_at, checked_in_by_user_id)
             VALUES (?, ?, ?, '', ?, '', 'walk_in', 'gate', ?, ?, ?, ?, ?, ?, ?, ?, NULL, 'checked_in', ?, ?, ?, ?)"
        );
        $stmt->bind_param(
            "sssssssssssssssi",
            $token, $officeCode, $name, $contact, $idType, $nowSql, $purpose, $officeLabel, $concern,
            $nowSql, $nowSql, $endSql, $nowSql, $nowSql, $nowSql, $guardUserId
        );
        $stmt->execute();
        $appointmentId = (int) $conn->insert_id;
        $stmt->close();

        $registrationCode = "V-" . $now->format("Y") . "-" . str_pad((string) $appointmentId, 6, "0", STR_PAD_LEFT);
        $code = $conn->prepare("UPDATE appointments SET registration_code = ? WHERE id = ?");
        $code->bind_param("si", $registrationCode, $appointmentId);
        $code->execute();
        $code->close();

        $history = $conn->prepare(
            "INSERT INTO appointment_status_history (appointment_id, from_status, to_status, changed_by_user_id, note)
             VALUES (?, NULL, 'checked_in', ?, 'Registered at the gate by Security after an ID check; not tracked')"
        );
        $history->bind_param("ii", $appointmentId, $guardUserId);
        $history->execute();
        $history->close();

        // The office hears about it the same way as an app walk-in.
        $notice = $conn->prepare(
            "INSERT INTO app_notifications (recipient_user_id, appointment_id, notification_type, title, message)
             SELECT id, ?, 'walk_in_created', 'Walk-in visitor arriving', ?
             FROM app_users WHERE role = 'offices' AND office_code = ? AND is_active = 1"
        );
        $noticeText = "{$name} was registered at the gate by Security and is on the way. This visit is not tracked.";
        $notice->bind_param("iss", $appointmentId, $noticeText, $officeCode);
        $notice->execute();
        $notice->close();

        // Counted as a check-in in the activity log, under the guard's name (activity_service.php).
        $audit = $conn->prepare(
            "INSERT INTO audit_logs (actor_user_id, appointment_id, action, entity_type, entity_id, details_json, ip_address)
             VALUES (?, ?, 'appointment.checked_in', 'appointment', ?, ?, ?)"
        );
        $entityId = (string) $appointmentId;
        $details = (string) json_encode([
            "registered_at_gate" => true,
            "office_code" => $officeCode,
            "purpose" => $purpose,
            "id_type" => $idType,
            "notice_version" => GATE_NOTICE_VERSION,
            "visitor_agreed" => true,
        ]);
        $ip = isset($_SERVER["REMOTE_ADDR"]) ? substr((string) $_SERVER["REMOTE_ADDR"], 0, 45) : "";
        $audit->bind_param("iisss", $guardUserId, $appointmentId, $entityId, $details, $ip);
        $audit->execute();
        $audit->close();

        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }

    return [
        "appointment_id" => $appointmentId,
        "registration_code" => $registrationCode,
        "visitor_full_name" => $name,
        "office_code" => $officeCode,
        "office_label" => $officeLabel,
        "checked_in_at" => $nowSql,
    ];
}

/** The pass token of a booked visit Security checks in without the QR code, or null. */
function gate_token_for_appointment(mysqli $conn, int $appointmentId): ?string
{
    if ($appointmentId <= 0) {
        return null;
    }
    $stmt = $conn->prepare("SELECT public_token FROM appointments WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $appointmentId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? (string) $row["public_token"] : null;
}
