<?php
declare(strict_types=1);

require_once __DIR__ . "/_appointments.php";

function mobile_owned_visit(mysqli $conn, int $visitId, int $visitorUserId, bool $forUpdate = false): ?array
{
    $stmt = $conn->prepare(
        "SELECT * FROM visits WHERE id = ? AND visitor_user_id = ? LIMIT 1" . ($forUpdate ? " FOR UPDATE" : "")
    );
    $stmt->bind_param("ii", $visitId, $visitorUserId);
    $stmt->execute();
    $visit = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $visit ?: null;
}

function mobile_visit_payload(mysqli $conn, array $visit, bool $withDetails = true): array
{
    $visitId = (int) $visit["id"];
    $status = (string) $visit["status"];
    $stops = visit_stops($conn, $visitId);

    // The stop the visitor should head to now: the earliest one still in progress on
    // campus, or before check-in, the first approved stop.
    $currentStopId = null;
    foreach ($stops as $stop) {
        $wanted = $status === "checked_in" ? "checked_in" : "approved";
        if ($stop["status"] === $wanted) {
            $currentStopId = (int) $stop["id"];
            break;
        }
    }

    $qrPass = null;
    $window = in_array($status, ["open", "checked_in"], true) ? visit_pass_window($stops) : null;
    if ($window) {
        [$validFrom, $validUntil] = $window;
        $now = new DateTime("now");
        $qrPass = [
            "payload" => "isatu-visitor://pass?token=" . $visit["public_token"],
            "token" => (string) $visit["public_token"],
            "valid_from" => $validFrom->format("Y-m-d H:i:s"),
            "valid_until" => $validUntil->format("Y-m-d H:i:s"),
            // While checked in, the same pass is scanned again at the gate to check out.
            "currently_valid" => $status === "checked_in" || ($status === "open" && $now >= $validFrom && $now <= $validUntil),
        ];
    }

    return [
        "id" => $visitId,
        "visit_code" => (string) $visit["visit_code"],
        "visit_date" => (string) $visit["visit_date"],
        "status" => $status,
        "checked_in_at" => $visit["checked_in_at"],
        "completed_at" => $visit["completed_at"],
        // How a finished visit ended: scan, guard, left_campus, or end_of_day.
        "checkout_method" => isset($visit["checkout_method"]) ? (string) $visit["checkout_method"] : null,
        "tracking_appointment_id" => !empty($visit["tracking_appointment_id"]) ? (int) $visit["tracking_appointment_id"] : null,
        "current_stop_id" => $currentStopId,
        "qr_pass" => $qrPass,
        "stops" => array_map(function (array $stop) use ($conn, $withDetails): array {
            return mobile_appointment_payload($conn, $stop, $withDetails);
        }, $stops),
    ];
}

/**
 * Validates and books a same-day visit with two or three office stops, created together
 * or not at all.
 *
 * - Appointment visits: every stop needs an available slot, stops are at least
 *   VISIT_STOP_GAP_MINUTES apart, and none may clash with the visitor's other bookings.
 * - Walk-in visits: every office must be accepting visitors now. Stops are approved at
 *   once, keep the order the visitor chose, and share the one-hour walk-in pass window.
 */
function mobile_create_visit(mysqli $conn, array $user, array $input): array
{
    $rawStops = isset($input["stops"]) && is_array($input["stops"]) ? array_values($input["stops"]) : [];
    if (count($rawStops) < VISIT_MIN_STOPS || count($rawStops) > VISIT_MAX_STOPS) {
        throw new InvalidArgumentException(json_encode(["stops" => "Add two or three office stops"]));
    }
    $isWalkIn = strtolower(api_text($input, "visit_type", 16)) === "walk_in";
    $deviceName = api_text($input, "device_name", 100);
    $consentInput = isset($input["location_consent"]) && is_array($input["location_consent"])
        ? $input["location_consent"] : [];
    $consentVersion = trim((string) ($consentInput["version"] ?? ""));
    $errors = [];
    if (empty($consentInput["granted"]) || $consentVersion === "") {
        $errors["location_consent"] = "Location consent and its displayed policy version are required";
    }

    // Only offices with someone to answer (appointment_office_bookable_map()).
    $offices = appointment_office_bookable_map();
    $now = new DateTime("now");
    $stops = [];
    $seenOffices = [];
    foreach ($rawStops as $index => $raw) {
        $label = "Stop " . ($index + 1);
        if (!is_array($raw)) {
            $errors["stops.{$index}"] = "{$label}: the stop details are invalid";
            continue;
        }
        $officeCode = strtoupper(api_text($raw, "office_code", 16));
        $purpose = api_text($raw, "purpose", 100);
        $subject = api_text($raw, "subject", 150);
        $start = mobile_parse_datetime(api_text($raw, "scheduled_start_at", 32));
        $stopErrors = [];
        if (!isset($offices[$officeCode])) {
            $stopErrors["office_code"] = "{$label}: select a valid office";
        } elseif (isset($seenOffices[$officeCode])) {
            $stopErrors["office_code"] = "{$label}: each stop must be a different office";
        }
        if ($purpose === "") {
            $stopErrors["purpose"] = "{$label}: select the purpose of the visit";
        }
        if ($subject === "") {
            $stopErrors["subject"] = "{$label}: enter the subject or concern";
        }
        if (!$isWalkIn && (!$start || $start <= $now)) {
            $stopErrors["scheduled_start_at"] = "{$label}: choose a valid future time";
        }
        foreach ($stopErrors as $field => $message) {
            $errors["stops.{$index}.{$field}"] = $message;
        }
        if ($stopErrors) {
            continue;
        }
        $seenOffices[$officeCode] = true;
        $stops[] = [
            "office_code" => $officeCode,
            "purpose" => $purpose,
            "subject" => $subject,
            "additional_details" => api_text($raw, "additional_details", 3000),
            "start" => $start,
            "end_text" => api_text($raw, "scheduled_end_at", 32),
            "index" => $index,
        ];
    }
    if ($errors) {
        throw new InvalidArgumentException(json_encode($errors));
    }

    if ($isWalkIn) {
        mobile_prepare_walk_in_stops($conn, $stops, $now);
    } else {
        mobile_prepare_appointment_stops($conn, $stops);
    }
    $visitDate = $stops[0]["start"]->format("Y-m-d");

    $visitorUserId = (int) $user["id"];
    $visitorName = trim((string) $user["display_name"]);
    if ($visitorName === "" || trim((string) ($user["email"] ?? "")) === "") {
        throw new DomainException("Complete the visitor profile before requesting an appointment");
    }
    if ($deviceName === "") {
        $deviceName = trim((string) ($user["device_name"] ?? "Android phone")) ?: "Android phone";
    }

    $conn->begin_transaction();
    try {
        // Serialize this visitor's bookings so two simultaneous requests cannot both
        // pass the same-day and overlap checks below.
        $lock = $conn->prepare("SELECT id FROM app_users WHERE id = ? FOR UPDATE");
        $lock->bind_param("i", $visitorUserId);
        $lock->execute();
        $lock->close();

        $existing = $conn->prepare(
            "SELECT id FROM visits WHERE visitor_user_id = ? AND visit_date = ? AND status IN ('open', 'checked_in') LIMIT 1"
        );
        $existing->bind_param("is", $visitorUserId, $visitDate);
        $existing->execute();
        $hasVisit = (bool) $existing->get_result()->fetch_assoc();
        $existing->close();
        if ($hasVisit) {
            throw new DomainException("You already have a multi-stop visit on this day. Cancel it first or book a separate appointment.");
        }
        // Walk-in stops share one window and are visited in the chosen order, so they
        // are not checked for overlaps, as with a single walk-in pass.
        foreach ($isWalkIn ? [] : $stops as $stop) {
            $clash = visit_find_schedule_clash($conn, $visitorUserId, $stop["start"], $stop["end"]);
            if ($clash) {
                throw new DomainException(
                    appointment_office_label($stop["office_code"]) . " at " . $stop["start"]->format("g:i A")
                    . " is too close to your " . appointment_office_label((string) $clash["office_code"])
                    . " visit at " . date("g:i A", (int) strtotime((string) $clash["scheduled_start_at"]))
                );
            }
        }

        $publicToken = bin2hex(random_bytes(32));
        $insert = $conn->prepare(
            "INSERT INTO visits (visitor_user_id, public_token, visit_date, status) VALUES (?, ?, ?, 'open')"
        );
        $insert->bind_param("iss", $visitorUserId, $publicToken, $visitDate);
        $insert->execute();
        $visitId = (int) $conn->insert_id;
        $insert->close();
        $visitCode = "MV-" . $stops[0]["start"]->format("Y") . "-" . str_pad((string) $visitId, 6, "0", STR_PAD_LEFT);
        $code = $conn->prepare("UPDATE visits SET visit_code = ? WHERE id = ?");
        $code->bind_param("si", $visitCode, $visitId);
        $code->execute();
        $code->close();

        $stopCount = count($stops);
        $appointmentIds = [];
        foreach ($stops as $position => $stop) {
            $stopNumber = $position + 1;
            $stopText = "stop {$stopNumber} of {$stopCount} in a multi-stop visit";
            $officeLabel = appointment_office_label($stop["office_code"]);
            $appointmentIds[] = mobile_insert_appointment($conn, $user, [
                "office_code" => $stop["office_code"],
                "visit_type" => $isWalkIn ? "walk_in" : "appointment",
                "purpose" => $stop["purpose"],
                "subject" => $stop["subject"],
                "additional_details" => $stop["additional_details"],
                "start" => $stop["start"],
                "end" => $stop["end"],
                "device_name" => $deviceName,
                "consent_version" => $consentVersion,
                "status" => $isWalkIn ? "approved" : "pending_approval",
                "visit_id" => $visitId,
                "stop_number" => $stopNumber,
                "history_note" => $isWalkIn
                    ? "Walk-in stop {$stopNumber} of {$stopCount} in multi-stop visit {$visitCode}, created from the Android app"
                    : "Stop {$stopNumber} of {$stopCount} in multi-stop visit {$visitCode}, requested from the Android app",
                "notification_type" => $isWalkIn ? "walk_in_created" : "appointment_request",
                "notification_title" => $isWalkIn ? "Walk-in visitor arriving" : "New appointment request",
                "notification_text" => $isWalkIn
                    ? "{$visitorName} created a walk-in pass for {$officeLabel} ({$stopText})."
                    : "{$visitorName} requested " . $stop["start"]->format("M j, Y g:i A") . " ({$stopText}).",
            ]);
        }
        api_audit($conn, $visitorUserId, "mobile.visit_created", "visit", (string) $visitId, [
            "visit_code" => $visitCode,
            "visit_type" => $isWalkIn ? "walk_in" : "appointment",
            "visit_date" => $visitDate,
            "appointment_ids" => $appointmentIds,
            "consent_version" => $consentVersion,
        ], $appointmentIds[0]);
        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }
    $visit = mobile_owned_visit($conn, $visitId, $visitorUserId);
    return mobile_visit_payload($conn, $visit);
}

/**
 * Walk-in stops start now and share the same one-hour pass window as a single walk-in.
 * Each office must be accepting visitors; the visitor's chosen order is kept.
 */
function mobile_prepare_walk_in_stops(mysqli $conn, array &$stops, DateTime $now): void
{
    foreach ($stops as &$stop) {
        $settings = office_availability_get_settings($conn, $stop["office_code"]);
        if (empty($settings["accepting_visitors"])) {
            $reason = trim((string) ($settings["unavailable_reason"] ?? ""));
            throw new DomainException(
                appointment_office_label($stop["office_code"]) . ": "
                . ($reason !== "" ? $reason : "This office is not accepting visitors right now")
            );
        }
        $blocked = office_walk_in_block_reason(
            $conn,
            $stop["office_code"],
            appointment_office_label($stop["office_code"]),
            DateTimeImmutable::createFromMutable($now)
        );
        if ($blocked !== null) {
            throw new DomainException($blocked);
        }
        $stop["start"] = clone $now;
        $stop["end"] = (clone $now)->modify("+60 minutes");
    }
    unset($stop);
}

/**
 * Appointment stops each need a free slot at their office, are ordered by time, and must
 * leave the walking gap between consecutive stops on one day.
 */
function mobile_prepare_appointment_stops(mysqli $conn, array &$stops): void
{
    foreach ($stops as &$stop) {
        $settings = office_availability_get_settings($conn, $stop["office_code"]);
        $end = $stop["end_text"] !== "" ? mobile_parse_datetime($stop["end_text"]) : null;
        if (!$end) {
            $end = (clone $stop["start"])->modify("+" . max(15, (int) $settings["slot_duration_minutes"]) . " minutes");
        }
        if ($end <= $stop["start"] || $stop["start"]->format("Y-m-d") !== $end->format("Y-m-d")) {
            throw new InvalidArgumentException(json_encode([
                "stops.{$stop["index"]}.scheduled_end_at" => "Stop " . ($stop["index"] + 1) . ": the end time is invalid",
            ]));
        }
        $stop["end"] = $end;
    }
    unset($stop);

    usort($stops, function (array $left, array $right): int {
        return $left["start"] <=> $right["start"];
    });
    $visitDate = $stops[0]["start"]->format("Y-m-d");
    foreach ($stops as $position => $stop) {
        $officeLabel = appointment_office_label($stop["office_code"]);
        if ($stop["start"]->format("Y-m-d") !== $visitDate) {
            throw new DomainException("All stops of a multi-stop visit must be on the same day");
        }
        if ($position > 0) {
            $previous = $stops[$position - 1];
            $earliest = (clone $previous["end"])->modify("+" . VISIT_STOP_GAP_MINUTES . " minutes");
            if ($stop["start"] < $earliest) {
                throw new DomainException(
                    "Leave at least " . VISIT_STOP_GAP_MINUTES . " minutes after your "
                    . appointment_office_label($previous["office_code"]) . " stop before going to {$officeLabel}"
                );
            }
        }
        $availability = office_availability_check($conn, $stop["office_code"], $stop["start"], $stop["end"]);
        if (!$availability["available"]) {
            throw new DomainException($officeLabel . ": " . $availability["message"]);
        }
    }
}

/**
 * Cancels every stop of a visit that has not started yet and closes the visit.
 */
function mobile_cancel_visit(mysqli $conn, int $visitId, int $visitorUserId, string $reason): array
{
    $reason = substr(trim($reason), 0, 500);
    $conn->begin_transaction();
    try {
        $visit = mobile_owned_visit($conn, $visitId, $visitorUserId, true);
        if (!$visit) {
            throw new DomainException("Visit not found");
        }
        if ($visit["status"] !== "open") {
            throw new DomainException(
                $visit["status"] === "checked_in"
                    ? "You are already checked in. Ask Security to end the visit when you leave."
                    : "This visit can no longer be cancelled"
            );
        }
        $cancelled = 0;
        foreach (visit_stops($conn, $visitId, true) as $stop) {
            if (in_array((string) $stop["status"], ["pending_approval", "approved", "reschedule_proposed"], true)) {
                mobile_cancel_appointment_row($conn, $stop, $visitorUserId, $reason);
                $cancelled++;
            }
        }
        $close = $conn->prepare("UPDATE visits SET status = 'closed', closed_at = NOW() WHERE id = ? AND status = 'open'");
        $close->bind_param("i", $visitId);
        $close->execute();
        $close->close();
        api_audit($conn, $visitorUserId, "mobile.visit_cancelled", "visit", (string) $visitId, [
            "reason" => $reason,
            "cancelled_stops" => $cancelled,
        ]);
        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }
    return ["visit_id" => $visitId, "status" => "closed", "cancelled_stops" => $cancelled];
}
