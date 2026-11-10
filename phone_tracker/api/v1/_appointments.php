<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . "/appointment_offices.php";
require_once dirname(__DIR__, 2) . "/office_availability_service.php";
require_once dirname(__DIR__, 2) . "/appointment_maintenance.php";

function mobile_parse_datetime(string $value): ?DateTime
{
    foreach (["Y-m-d H:i:s", "Y-m-d\TH:i:s", "Y-m-d\TH:i"] as $format) {
        $date = DateTime::createFromFormat($format, $value);
        $errors = DateTime::getLastErrors();
        if ($date && ($errors === false || ((int) $errors["warning_count"] === 0 && (int) $errors["error_count"] === 0))) {
            return $date;
        }
    }
    return null;
}

function mobile_appointment_proposal(mysqli $conn, int $appointmentId): ?array
{
    $stmt = $conn->prepare(
        "SELECT id, reason, message, status, response_deadline, responded_at, created_at
         FROM appointment_reschedule_proposals
         WHERE appointment_id = ? ORDER BY id DESC LIMIT 1"
    );
    $stmt->bind_param("i", $appointmentId);
    $stmt->execute();
    $proposal = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$proposal) {
        return null;
    }
    $proposalId = (int) $proposal["id"];
    $slots = [];
    $slotStmt = $conn->prepare(
        "SELECT id, scheduled_start_at, scheduled_end_at, is_selected
         FROM appointment_reschedule_slots WHERE proposal_id = ? ORDER BY scheduled_start_at"
    );
    $slotStmt->bind_param("i", $proposalId);
    $slotStmt->execute();
    $result = $slotStmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $slots[] = [
            "id" => (int) $row["id"],
            "scheduled_start_at" => $row["scheduled_start_at"],
            "scheduled_end_at" => $row["scheduled_end_at"],
            "selected" => (bool) $row["is_selected"],
        ];
    }
    $slotStmt->close();
    return [
        "id" => $proposalId,
        "reason" => (string) $proposal["reason"],
        "message" => (string) $proposal["message"],
        "status" => (string) $proposal["status"],
        "response_deadline" => $proposal["response_deadline"],
        "responded_at" => $proposal["responded_at"],
        "created_at" => $proposal["created_at"],
        "slots" => $slots,
    ];
}

/**
 * Summary of the multi-stop visit an appointment belongs to. Cached per request
 * because appointment lists contain several stops of the same visit.
 */
function mobile_visit_summary(mysqli $conn, int $visitId, int $stopNumber): ?array
{
    static $visits = [];
    if (!array_key_exists($visitId, $visits)) {
        $stmt = $conn->prepare(
            "SELECT v.id, v.visit_code, v.visit_date, v.status,
                    (SELECT COUNT(*) FROM appointments s WHERE s.visit_id = v.id) AS stop_count
             FROM visits v WHERE v.id = ? LIMIT 1"
        );
        $stmt->bind_param("i", $visitId);
        $stmt->execute();
        $visits[$visitId] = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
    }
    $visit = $visits[$visitId];
    if (!$visit) {
        return null;
    }
    return [
        "id" => (int) $visit["id"],
        "visit_code" => (string) $visit["visit_code"],
        "visit_date" => (string) $visit["visit_date"],
        "status" => (string) $visit["status"],
        "stop_number" => $stopNumber,
        "stop_count" => (int) $visit["stop_count"],
    ];
}

function mobile_appointment_payload(mysqli $conn, array $row, bool $withDetails = false): array
{
    $status = (string) $row["status"];
    $officeCode = (string) $row["office_code"];
    $visitId = (int) ($row["visit_id"] ?? 0);
    $payload = [
        "id" => (int) $row["id"],
        "registration_code" => $row["registration_code"],
        "visit_type" => (string) ($row["visit_type"] ?? "appointment"),
        "office" => ["code" => $officeCode, "name" => appointment_office_label($officeCode)],
        "purpose" => (string) $row["purpose"],
        "subject" => (string) $row["subject"],
        "scheduled_start_at" => $row["scheduled_start_at"],
        "scheduled_end_at" => $row["scheduled_end_at"],
        "status" => $status,
        "status_updated_at" => $row["status_updated_at"],
        "rejection_reason" => (string) ($row["rejection_reason"] ?? ""),
        "checked_in_at" => $row["checked_in_at"] ?? null,
        "completed_at" => $row["completed_at"] ?? null,
        // How a finished visit ended: scan, guard, left_campus, or end_of_day.
        "checkout_method" => isset($row["checkout_method"]) ? (string) $row["checkout_method"] : null,
        "created_at" => $row["created_at"],
        "qr_pass" => null,
        "visit" => $visitId > 0 ? mobile_visit_summary($conn, $visitId, (int) ($row["stop_number"] ?? 0)) : null,
    ];
    // A stop of a multi-stop visit is checked in with the visit pass, not its own token.
    if ($visitId === 0 && in_array($status, ["approved", "checked_in"], true) && !empty($row["public_token"])) {
        $validFrom = new DateTime((string) $row["scheduled_start_at"]);
        $validFrom->modify("-30 minutes");
        $payload["qr_pass"] = [
            "payload" => "isatu-visitor://pass?token=" . $row["public_token"],
            "token" => $row["public_token"],
            "valid_from" => $validFrom->format("Y-m-d H:i:s"),
            "valid_until" => $row["scheduled_end_at"],
            // While checked in, the same pass is scanned again at the gate to check out.
            "currently_valid" => $status === "checked_in" || ($status === "approved" && new DateTime("now") >= $validFrom
                && new DateTime("now") <= new DateTime((string) $row["scheduled_end_at"])),
        ];
    }
    if ($withDetails) {
        $payload["visitor"] = [
            "full_name" => (string) $row["visitor_full_name"],
            "email" => (string) $row["visitor_email"],
            "contact_number" => (string) $row["contact_number"],
        ];
        $payload["additional_details"] = (string) ($row["additional_details"] ?? "");
        $payload["reschedule_proposal"] = mobile_appointment_proposal($conn, (int) $row["id"]);
    }
    return $payload;
}

function mobile_owned_appointment(mysqli $conn, int $appointmentId, int $visitorUserId, bool $forUpdate = false): ?array
{
    $visitColumns = visit_appointment_columns($conn);
    $checkoutColumn = presence_schema_ready($conn) ? ", checkout_method" : "";
    $sql =
        "SELECT id, registration_code, public_token, office_code, visitor_full_name, visitor_email,
                contact_number, device_name, visit_type, purpose, destination, subject, additional_details,
                scheduled_start_at, scheduled_end_at, visitor_user_id, status, status_updated_at,
                rejection_reason, checked_in_at, completed_at, created_at{$checkoutColumn}{$visitColumns}
         FROM appointments WHERE id = ? AND visitor_user_id = ? LIMIT 1" . ($forUpdate ? " FOR UPDATE" : "");
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $appointmentId, $visitorUserId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function mobile_create_appointment(mysqli $conn, array $user, array $input): array
{
    $visitType = strtolower(api_text($input, "visit_type", 16));
    if (!in_array($visitType, ["appointment", "walk_in"], true)) {
        $visitType = "appointment";
    }
    $officeCode = strtoupper(api_text($input, "office_code", 16));
    $purpose = api_text($input, "purpose", 100);
    $subject = api_text($input, "subject", 150);
    $additionalDetails = api_text($input, "additional_details", 3000);
    $startText = api_text($input, "scheduled_start_at", 32);
    $endText = api_text($input, "scheduled_end_at", 32);
    $deviceName = api_text($input, "device_name", 100);
    $consentInput = isset($input["location_consent"]) && is_array($input["location_consent"])
        ? $input["location_consent"] : [];
    $consentGranted = !empty($consentInput["granted"]);
    $consentVersion = trim((string) ($consentInput["version"] ?? ""));
    // Only offices with someone to answer (appointment_office_bookable_map()).
    $offices = appointment_office_bookable_map();
    $errors = [];
    if (!isset($offices[$officeCode])) {
        $errors["office_code"] = "Select a valid office";
    }
    if ($purpose === "") {
        $errors["purpose"] = "Select or enter the purpose of the visit";
    }
    if ($subject === "") {
        $errors["subject"] = "Enter the subject or concern";
    }
    if (!$consentGranted || $consentVersion === "") {
        $errors["location_consent"] = "Location consent and its displayed policy version are required";
    }
    $start = $visitType === "walk_in" ? new DateTime("now") : mobile_parse_datetime($startText);
    if ($visitType === "appointment" && (!$start || $start <= new DateTime("now"))) {
        $errors["scheduled_start_at"] = "Choose a valid future appointment time";
    }
    if ($errors) {
        throw new InvalidArgumentException(json_encode($errors));
    }
    $settings = office_availability_get_settings($conn, $officeCode);
    if ($visitType === "walk_in" && empty($settings["accepting_visitors"])) {
        throw new DomainException(
            trim((string) ($settings["unavailable_reason"] ?? "")) ?: "This office is not accepting visitors right now"
        );
    }
    if ($visitType === "walk_in") {
        // No walk-in passes during a break, a posted closure, or outside visitor hours.
        $blocked = office_walk_in_block_reason($conn, $officeCode, $offices[$officeCode], new DateTimeImmutable("now"));
        if ($blocked !== null) {
            throw new DomainException($blocked);
        }
    }
    $end = $visitType === "appointment" && $endText !== "" ? mobile_parse_datetime($endText) : null;
    if ($visitType === "walk_in") {
        $end = clone $start;
        $end->modify("+60 minutes");
    } elseif (!$end) {
        $end = clone $start;
        $end->modify("+" . max(15, (int) $settings["slot_duration_minutes"]) . " minutes");
    }
    if ($end <= $start || $start->format("Y-m-d") !== $end->format("Y-m-d")) {
        throw new InvalidArgumentException(json_encode(["scheduled_end_at" => "Appointment end time is invalid"]));
    }
    if ($visitType === "appointment") {
        $availability = office_availability_check($conn, $officeCode, $start, $end);
        if (!$availability["available"]) {
            throw new DomainException((string) $availability["message"]);
        }
    }
    $visitorUserId = (int) $user["id"];
    $visitorName = trim((string) $user["display_name"]);
    $visitorEmail = trim((string) ($user["email"] ?? ""));
    $contactNumber = trim((string) ($user["contact_number"] ?? ""));
    if ($visitorName === "" || $visitorEmail === "") {
        throw new DomainException("Complete the visitor profile before requesting an appointment");
    }
    if ($deviceName === "") {
        $deviceName = trim((string) ($user["device_name"] ?? "Android phone")) ?: "Android phone";
    }
    $initialStatus = $visitType === "walk_in" ? "approved" : "pending_approval";

    $conn->begin_transaction();
    try {
        $appointmentId = mobile_insert_appointment($conn, $user, [
            "office_code" => $officeCode,
            "visit_type" => $visitType,
            "purpose" => $purpose,
            "subject" => $subject,
            "additional_details" => $additionalDetails,
            "start" => $start,
            "end" => $end,
            "device_name" => $deviceName,
            "consent_version" => $consentVersion,
            "status" => $initialStatus,
            "history_note" => $visitType === "walk_in"
                ? "Walk-in pass created from the Android app"
                : "Appointment request created from the Android app",
            "notification_type" => $visitType === "walk_in" ? "walk_in_created" : "appointment_request",
            "notification_title" => $visitType === "walk_in" ? "Walk-in visitor arriving" : "New appointment request",
            "notification_text" => $visitType === "walk_in"
                ? $visitorName . " created a walk-in pass for " . $offices[$officeCode] . "."
                : $visitorName . " requested " . $start->format("M j, Y g:i A") . ".",
        ]);
        api_audit($conn, $visitorUserId, "mobile.appointment_created", "appointment", (string) $appointmentId, [
            "office_code" => $officeCode,
            "visit_type" => $visitType,
            "scheduled_start_at" => $start->format("Y-m-d H:i:s"),
            "scheduled_end_at" => $end->format("Y-m-d H:i:s"),
            "consent_version" => $consentVersion,
        ], $appointmentId);
        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }
    $created = mobile_owned_appointment($conn, $appointmentId, $visitorUserId);
    return mobile_appointment_payload($conn, $created, true);
}

/**
 * Inserts one appointment with its registration code, first history entry, location
 * consent, and office notification. Used for single appointments and for each stop of
 * a multi-stop visit. The caller owns the transaction.
 *
 * @param array{office_code: string, visit_type: string, purpose: string, subject: string,
 *     additional_details: string, start: DateTime, end: DateTime, device_name: string,
 *     consent_version: string, status: string, history_note: string, notification_type: string,
 *     notification_title: string, notification_text: string, visit_id?: int, stop_number?: int} $fields
 */
function mobile_insert_appointment(mysqli $conn, array $user, array $fields): int
{
    $visitorUserId = (int) $user["id"];
    $visitorName = trim((string) $user["display_name"]);
    $visitorEmail = trim((string) ($user["email"] ?? ""));
    $contactNumber = trim((string) ($user["contact_number"] ?? ""));
    $officeCode = $fields["office_code"];
    $visitType = $fields["visit_type"];
    $purpose = $fields["purpose"];
    $subject = $fields["subject"];
    $additionalDetails = $fields["additional_details"];
    $deviceName = $fields["device_name"];
    $status = $fields["status"];
    $startSql = $fields["start"]->format("Y-m-d H:i:s");
    $endSql = $fields["end"]->format("Y-m-d H:i:s");
    $publicToken = bin2hex(random_bytes(32));
    $destination = appointment_office_label($officeCode);

    $stmt = $conn->prepare(
        "INSERT INTO appointments
         (public_token, office_code, visitor_full_name, visitor_email, contact_number,
          device_name, visit_type, purpose, destination, subject, additional_details,
          appointment_at, scheduled_start_at, scheduled_end_at, visitor_user_id, status,
          approved_at, qr_issued_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                 IF(? = 'approved', NOW(), NULL), IF(? = 'approved', NOW(), NULL))"
    );
    $stmt->bind_param(
        "ssssssssssssssisss",
        $publicToken, $officeCode, $visitorName, $visitorEmail, $contactNumber,
        $deviceName, $visitType, $purpose, $destination, $subject, $additionalDetails,
        $startSql, $startSql, $endSql, $visitorUserId, $status, $status, $status
    );
    $stmt->execute();
    $appointmentId = (int) $conn->insert_id;
    $stmt->close();
    $registrationCode = "V-" . $fields["start"]->format("Y") . "-" . str_pad((string) $appointmentId, 6, "0", STR_PAD_LEFT);
    $code = $conn->prepare("UPDATE appointments SET registration_code = ? WHERE id = ?");
    $code->bind_param("si", $registrationCode, $appointmentId);
    $code->execute();
    $code->close();
    if (!empty($fields["visit_id"])) {
        $visitId = (int) $fields["visit_id"];
        $stopNumber = (int) ($fields["stop_number"] ?? 0);
        $link = $conn->prepare("UPDATE appointments SET visit_id = ?, stop_number = ? WHERE id = ?");
        $link->bind_param("iii", $visitId, $stopNumber, $appointmentId);
        $link->execute();
        $link->close();
    }
    $history = $conn->prepare(
        "INSERT INTO appointment_status_history
         (appointment_id, from_status, to_status, changed_by_user_id, note)
         VALUES (?, NULL, ?, ?, ?)"
    );
    $historyNote = $fields["history_note"];
    $history->bind_param("isis", $appointmentId, $status, $visitorUserId, $historyNote);
    $history->execute();
    $history->close();
    $consent = $conn->prepare(
        "INSERT INTO visitor_consents
         (appointment_id, visitor_user_id, consent_type, consent_version, device_info)
         VALUES (?, ?, 'location_tracking', ?, ?)"
    );
    $consentVersion = $fields["consent_version"];
    $deviceInfo = substr((string) ($_SERVER["HTTP_USER_AGENT"] ?? $deviceName), 0, 255);
    $consent->bind_param("iiss", $appointmentId, $visitorUserId, $consentVersion, $deviceInfo);
    $consent->execute();
    $consent->close();
    $notification = $conn->prepare(
        "INSERT INTO app_notifications
         (recipient_user_id, appointment_id, notification_type, title, message)
         SELECT id, ?, ?, ?, ?
         FROM app_users WHERE role = 'offices' AND office_code = ? AND is_active = 1"
    );
    $notificationType = $fields["notification_type"];
    $notificationTitle = $fields["notification_title"];
    $notificationText = $fields["notification_text"];
    $notification->bind_param("issss", $appointmentId, $notificationType, $notificationTitle, $notificationText, $officeCode);
    $notification->execute();
    $notification->close();
    return $appointmentId;
}

function mobile_cancel_appointment(mysqli $conn, int $appointmentId, int $visitorUserId, string $reason): array
{
    $reason = substr(trim($reason), 0, 500);
    $conn->begin_transaction();
    try {
        $appointment = mobile_owned_appointment($conn, $appointmentId, $visitorUserId, true);
        if (!$appointment) {
            throw new DomainException("Appointment not found");
        }
        if (!in_array((string) $appointment["status"], ["pending_approval", "approved", "reschedule_proposed"], true)) {
            throw new DomainException("This appointment can no longer be cancelled");
        }
        mobile_cancel_appointment_row($conn, $appointment, $visitorUserId, $reason);
        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }
    return ["appointment_id" => $appointmentId, "status" => "cancelled"];
}

/**
 * Cancels one locked, cancellable appointment row. The caller owns the transaction.
 */
function mobile_cancel_appointment_row(mysqli $conn, array $appointment, int $visitorUserId, string $reason): void
{
    $appointmentId = (int) $appointment["id"];
    $from = (string) $appointment["status"];
    $update = $conn->prepare(
        "UPDATE appointments SET status = 'cancelled', status_updated_at = NOW(),
         cancelled_at = NOW(), cancelled_by_user_id = ? WHERE id = ? AND visitor_user_id = ?"
    );
    $update->bind_param("iii", $visitorUserId, $appointmentId, $visitorUserId);
    $update->execute();
    $update->close();
    // Release any time slots an open office proposal is still holding.
    visit_withdraw_open_proposals($conn, $appointmentId);
    $note = "Cancelled by visitor" . ($reason !== "" ? ": " . $reason : "");
    $history = $conn->prepare(
        "INSERT INTO appointment_status_history
         (appointment_id, from_status, to_status, changed_by_user_id, note)
         VALUES (?, ?, 'cancelled', ?, ?)"
    );
    $history->bind_param("isis", $appointmentId, $from, $visitorUserId, $note);
    $history->execute();
    $history->close();
    $officeCode = (string) $appointment["office_code"];
    $notify = $conn->prepare(
        "INSERT INTO app_notifications
         (recipient_user_id, appointment_id, notification_type, title, message)
         SELECT id, ?, 'appointment.cancelled', 'Visitor cancelled appointment', ?
         FROM app_users WHERE role = 'offices' AND office_code = ? AND is_active = 1"
    );
    $message = $appointment["visitor_full_name"] . " cancelled " . $appointment["scheduled_start_at"] . ".";
    $notify->bind_param("iss", $appointmentId, $message, $officeCode);
    $notify->execute();
    $notify->close();
    api_audit($conn, $visitorUserId, "mobile.appointment_cancelled", "appointment", (string) $appointmentId, ["reason" => $reason], $appointmentId);
}
