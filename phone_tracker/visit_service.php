<?php

/*
 * Multi-stop campus visits.
 *
 * A visit groups two or three office stops on the same day. Each stop is an ordinary
 * appointment row, so office approval, availability, rescheduling, and office-scoped
 * access work exactly as they do for single appointments. The visit owns what belongs
 * to being on campus: one QR pass, one gate check-in, and one GPS tracking session.
 *
 * GPS for a visit is stored against one stop (visits.tracking_appointment_id) so the
 * existing appointment-based tracking, route history, and retention code keeps working.
 * That stop's own meeting can finish before the visit does, so tracking decisions for
 * it must use the visit's state (see visit_apply_tracking_window()).
 */

require_once __DIR__ . "/appointment_offices.php";

const VISIT_MIN_STOPS = 2;
const VISIT_MAX_STOPS = 3;
const VISIT_STOP_GAP_MINUTES = 10;
const VISIT_ACTIVE_STOP_STATUSES = ["pending_approval", "reschedule_proposed", "approved", "checked_in"];

/**
 * True when multi_stop_visits_migration.sql has been applied. Installations without it
 * keep the single-appointment workflow unchanged.
 */
function visit_schema_ready(mysqli $conn): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    try {
        $result = $conn->query(
            "SELECT COUNT(*) AS total FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'appointments' AND COLUMN_NAME = 'visit_id'"
        );
        $ready = $result && (int) ($result->fetch_assoc()["total"] ?? 0) > 0;
    } catch (Throwable $ignored) {
        $ready = false;
    }
    return $ready;
}

/**
 * SELECT fragment for the visit columns of an appointment row. Returns NULL columns
 * before the migration so the same queries work on older installations.
 */
function visit_appointment_columns(mysqli $conn, string $alias = ""): string
{
    $prefix = $alias !== "" ? $alias . "." : "";
    return visit_schema_ready($conn)
        ? ", {$prefix}visit_id, {$prefix}stop_number"
        : ", NULL AS visit_id, NULL AS stop_number";
}

/**
 * visit_appointment_columns() plus the number of stops in the visit, for staff lists
 * that show "Stop 1 of 2" without revealing the other offices' stops.
 */
function visit_list_columns(mysqli $conn, string $alias): string
{
    if (!visit_schema_ready($conn)) {
        return ", NULL AS visit_id, NULL AS stop_number, NULL AS visit_stop_count";
    }
    return ", {$alias}.visit_id, {$alias}.stop_number,
            (SELECT COUNT(*) FROM appointments visit_stop WHERE visit_stop.visit_id = {$alias}.visit_id) AS visit_stop_count";
}

function visit_load(mysqli $conn, int $visitId, bool $forUpdate = false): ?array
{
    $stmt = $conn->prepare("SELECT * FROM visits WHERE id = ? LIMIT 1" . ($forUpdate ? " FOR UPDATE" : ""));
    $stmt->bind_param("i", $visitId);
    $stmt->execute();
    $visit = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $visit ?: null;
}

function visit_load_by_token(mysqli $conn, string $token): ?array
{
    $stmt = $conn->prepare("SELECT * FROM visits WHERE public_token = ? LIMIT 1");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $visit = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $visit ?: null;
}

/**
 * The visit an appointment belongs to, or null for a single appointment.
 */
function visit_for_appointment(mysqli $conn, int $appointmentId, bool $forUpdate = false): ?array
{
    if (!visit_schema_ready($conn)) {
        return null;
    }
    $stmt = $conn->prepare(
        "SELECT v.* FROM appointments a INNER JOIN visits v ON v.id = a.visit_id
         WHERE a.id = ? LIMIT 1" . ($forUpdate ? " FOR UPDATE" : "")
    );
    $stmt->bind_param("i", $appointmentId);
    $stmt->execute();
    $visit = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $visit ?: null;
}

/**
 * Stops of a visit in schedule order.
 */
function visit_stops(mysqli $conn, int $visitId, bool $forUpdate = false): array
{
    $stmt = $conn->prepare(
        "SELECT id, registration_code, public_token, office_code, visitor_user_id, visitor_full_name,
                visitor_email, contact_number, device_name, visit_type, purpose, destination, subject,
                additional_details, scheduled_start_at, scheduled_end_at, status, status_updated_at,
                rejection_reason, checked_in_at, completed_at, created_at, visit_id, stop_number
         FROM appointments WHERE visit_id = ?
         ORDER BY scheduled_start_at ASC, id ASC" . ($forUpdate ? " FOR UPDATE" : "")
    );
    $stmt->bind_param("i", $visitId);
    $stmt->execute();
    $result = $stmt->get_result();
    $stops = [];
    while ($row = $result->fetch_assoc()) {
        $stops[] = $row;
    }
    $stmt->close();
    return $stops;
}

/**
 * Scan window of a visit pass: 30 minutes before the first approved stop until the
 * end of the last approved or checked-in stop.
 *
 * @return array{0: DateTime, 1: DateTime}|null
 */
function visit_pass_window(array $stops): ?array
{
    $from = null;
    $until = null;
    foreach ($stops as $stop) {
        if (!in_array($stop["status"], ["approved", "checked_in"], true)) {
            continue;
        }
        $start = new DateTime((string) $stop["scheduled_start_at"]);
        $end = new DateTime((string) $stop["scheduled_end_at"]);
        if ($from === null || $start < $from) {
            $from = $start;
        }
        if ($until === null || $end > $until) {
            $until = $end;
        }
    }
    if ($from === null) {
        return null;
    }
    $from->modify("-30 minutes");
    return [$from, $until];
}

/**
 * "Dean's Office → IT Department" for the stops that are still part of the visit.
 */
function visit_route_label(array $stops, bool $withTimes = false): string
{
    $relevant = array_filter($stops, function (array $stop): bool {
        return !in_array($stop["status"], ["cancelled", "rejected", "unanswered"], true);
    });
    $parts = [];
    foreach ($relevant ?: $stops as $stop) {
        $label = appointment_office_label((string) $stop["office_code"]);
        if ($withTimes) {
            $label .= " (" . date("g:i A", strtotime((string) $stop["scheduled_start_at"])) . ")";
        }
        $parts[] = $label;
    }
    return implode(" → ", $parts);
}

/**
 * Status an approved stop should receive. A stop approved while its visit is already
 * on campus joins the active check-in instead of waiting for a second gate scan.
 */
function visit_status_for_approval(mysqli $conn, int $appointmentId): string
{
    $visit = visit_for_appointment($conn, $appointmentId);
    return $visit && $visit["status"] === "checked_in" ? "checked_in" : "approved";
}

/**
 * The visit whose tracking session is stored against this appointment, if any.
 */
function visit_for_tracking_appointment(mysqli $conn, int $appointmentId): ?array
{
    if (!visit_schema_ready($conn)) {
        return null;
    }
    $stmt = $conn->prepare("SELECT * FROM visits WHERE tracking_appointment_id = ? LIMIT 1");
    $stmt->bind_param("i", $appointmentId);
    $stmt->execute();
    $visit = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $visit ?: null;
}

/**
 * Replaces an appointment's status and check-in/completion times with its visit's when
 * the appointment owns a visit's tracking session. Other rows are returned unchanged.
 */
function visit_apply_tracking_window(mysqli $conn, array $appointment): array
{
    $visit = visit_for_tracking_appointment($conn, (int) $appointment["id"]);
    if (!$visit) {
        return $appointment;
    }
    if ($visit["status"] === "checked_in") {
        $appointment["status"] = "checked_in";
        $appointment["completed_at"] = null;
    } elseif ($visit["status"] === "completed") {
        $appointment["status"] = "completed";
        $appointment["completed_at"] = $visit["completed_at"];
    }
    if (!empty($visit["checked_in_at"])) {
        $appointment["checked_in_at"] = $visit["checked_in_at"];
    }
    return $appointment;
}

/**
 * Maps any stop of a checked-in visit to the stop that owns the visit's tracking
 * session, so every stop of the visit reports the same tracking state.
 */
function visit_tracking_appointment_id(mysqli $conn, int $appointmentId): int
{
    $visit = visit_for_appointment($conn, $appointmentId);
    return $visit && !empty($visit["tracking_appointment_id"]) ? (int) $visit["tracking_appointment_id"] : $appointmentId;
}

/**
 * Appointment ids that share one location consent decision: every stop of a visit, or
 * just the appointment itself.
 *
 * @return int[]
 */
function visit_consent_appointment_ids(mysqli $conn, int $appointmentId): array
{
    $visit = visit_for_appointment($conn, $appointmentId);
    if (!$visit) {
        return [$appointmentId];
    }
    return array_map(function (array $stop): int {
        return (int) $stop["id"];
    }, visit_stops($conn, (int) $visit["id"]));
}

/**
 * First active appointment of the visitor that overlaps the given time, including a
 * walking gap on both sides. Limit the search to one visit with $onlyVisitId.
 */
function visit_find_schedule_clash(
    mysqli $conn,
    int $visitorUserId,
    DateTime $start,
    DateTime $end,
    int $excludeAppointmentId = 0,
    int $onlyVisitId = 0
): ?array {
    $paddedStart = (clone $start)->modify("-" . VISIT_STOP_GAP_MINUTES . " minutes")->format("Y-m-d H:i:s");
    $paddedEnd = (clone $end)->modify("+" . VISIT_STOP_GAP_MINUTES . " minutes")->format("Y-m-d H:i:s");
    $sql =
        "SELECT id, office_code, scheduled_start_at, scheduled_end_at
         FROM appointments
         WHERE visitor_user_id = ? AND id <> ?
           AND status IN ('pending_approval', 'reschedule_proposed', 'approved', 'checked_in')
           AND scheduled_start_at < ? AND scheduled_end_at > ?";
    if ($onlyVisitId > 0) {
        $sql .= " AND visit_id = ?";
    }
    $sql .= " ORDER BY scheduled_start_at ASC LIMIT 1";
    $stmt = $conn->prepare($sql);
    if ($onlyVisitId > 0) {
        $stmt->bind_param("iissi", $visitorUserId, $excludeAppointmentId, $paddedEnd, $paddedStart, $onlyVisitId);
    } else {
        $stmt->bind_param("iiss", $visitorUserId, $excludeAppointmentId, $paddedEnd, $paddedStart);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/**
 * Keeps stop_number in schedule order after a stop moves.
 */
function visit_renumber_stops(mysqli $conn, int $visitId): void
{
    $position = 0;
    $update = $conn->prepare("UPDATE appointments SET stop_number = ? WHERE id = ?");
    foreach (visit_stops($conn, $visitId) as $stop) {
        $position++;
        $stopId = (int) $stop["id"];
        $update->bind_param("ii", $position, $stopId);
        $update->execute();
    }
    $update->close();
}

/**
 * Withdraws open reschedule proposals so their held slots are released.
 */
function visit_withdraw_open_proposals(mysqli $conn, int $appointmentId): void
{
    $stmt = $conn->prepare(
        "UPDATE appointment_reschedule_proposals SET status = 'withdrawn', responded_at = NOW()
         WHERE appointment_id = ? AND status = 'pending'"
    );
    $stmt->bind_param("i", $appointmentId);
    $stmt->execute();
    $stmt->close();
}

function visit_audit(mysqli $conn, ?int $actorUserId, ?int $appointmentId, string $action, int $visitId, array $details = []): void
{
    $audit = $conn->prepare(
        "INSERT INTO audit_logs
         (actor_user_id, appointment_id, action, entity_type, entity_id, details_json, ip_address)
         VALUES (?, ?, ?, 'visit', ?, ?, ?)"
    );
    if (!$audit) {
        return;
    }
    $entityId = (string) $visitId;
    $detailsJson = json_encode($details);
    $ipAddress = substr((string) ($_SERVER["REMOTE_ADDR"] ?? ""), 0, 45);
    $audit->bind_param("iissss", $actorUserId, $appointmentId, $action, $entityId, $detailsJson, $ipAddress);
    $audit->execute();
    $audit->close();
}

function visit_notify_office(mysqli $conn, int $appointmentId, string $officeCode, string $type, string $title, string $message): void
{
    $notify = $conn->prepare(
        "INSERT INTO app_notifications
         (recipient_user_id, appointment_id, notification_type, title, message)
         SELECT id, ?, ?, ?, ?
         FROM app_users WHERE role IN ('offices', 'teacher') AND office_code = ? AND is_active = 1"
    );
    $notify->bind_param("issss", $appointmentId, $type, $title, $message, $officeCode);
    $notify->execute();
    $notify->close();
}

function visit_notify_visitor(mysqli $conn, int $visitorUserId, ?int $appointmentId, string $type, string $title, string $message, array $data = []): void
{
    $notify = $conn->prepare(
        "INSERT INTO app_notifications
         (recipient_user_id, appointment_id, notification_type, title, message, data_json)
         VALUES (?, ?, ?, ?, ?, ?)"
    );
    $dataJson = $data ? json_encode($data) : null;
    $notify->bind_param("iissss", $visitorUserId, $appointmentId, $type, $title, $message, $dataJson);
    $notify->execute();
    $notify->close();
}

function visit_history(mysqli $conn, int $appointmentId, string $fromStatus, string $toStatus, ?int $actorUserId, string $note): void
{
    $history = $conn->prepare(
        "INSERT INTO appointment_status_history
         (appointment_id, from_status, to_status, changed_by_user_id, note)
         VALUES (?, ?, ?, ?, ?)"
    );
    $history->bind_param("issis", $appointmentId, $fromStatus, $toStatus, $actorUserId, $note);
    $history->execute();
    $history->close();
}

/**
 * Ends a checked-in visit: completes its checked-in stops, stops tracking, and notifies
 * the visitor. The caller owns the transaction and has locked the visit row.
 *
 * $checkout is true when the visitor has left campus. Stops that are still waiting for an
 * office decision are then cancelled because the visitor has left. $method is how the
 * visit ended (see presence_service.php); it chooses the visitor's notification.
 */
function visit_complete(mysqli $conn, array $visit, ?int $actorUserId, string $note, ?string $completedAt, bool $checkout, string $method = ""): void
{
    $visitId = (int) $visit["id"];
    $completedAt = $completedAt ?: date("Y-m-d H:i:s");
    $stops = visit_stops($conn, $visitId, true);
    foreach ($stops as $stop) {
        $stopId = (int) $stop["id"];
        $fromStatus = (string) $stop["status"];
        if ($fromStatus === "checked_in") {
            $update = $conn->prepare(
                "UPDATE appointments SET status = 'completed', status_updated_at = NOW(),
                        completed_at = ?, completed_by_user_id = ?
                 WHERE id = ? AND status = 'checked_in'"
            );
            $update->bind_param("sii", $completedAt, $actorUserId, $stopId);
            $update->execute();
            $changed = $update->affected_rows;
            $update->close();
            if ($changed > 0) {
                visit_history($conn, $stopId, "checked_in", "completed", $actorUserId, $note);
            }
        } elseif ($checkout && in_array($fromStatus, ["pending_approval", "reschedule_proposed", "approved"], true)) {
            $update = $conn->prepare(
                "UPDATE appointments SET status = 'cancelled', status_updated_at = NOW(),
                        cancelled_at = NOW(), cancelled_by_user_id = ?
                 WHERE id = ? AND status = ?"
            );
            $update->bind_param("iis", $actorUserId, $stopId, $fromStatus);
            $update->execute();
            $changed = $update->affected_rows;
            $update->close();
            if ($changed > 0) {
                visit_withdraw_open_proposals($conn, $stopId);
                visit_history($conn, $stopId, $fromStatus, "cancelled", $actorUserId, "Visitor left campus before this stop");
                visit_notify_office(
                    $conn,
                    $stopId,
                    (string) $stop["office_code"],
                    "appointment.cancelled",
                    "Visitor left campus",
                    $stop["visitor_full_name"] . " checked out before the " . date("M j, Y g:i A", strtotime((string) $stop["scheduled_start_at"])) . " stop."
                );
            }
        }
    }

    $update = $conn->prepare(
        "UPDATE visits SET status = 'completed', completed_at = ?, completed_by_user_id = ?
         WHERE id = ? AND status = 'checked_in'"
    );
    $update->bind_param("sii", $completedAt, $actorUserId, $visitId);
    $update->execute();
    $update->close();

    $trackingAppointmentId = !empty($visit["tracking_appointment_id"]) ? (int) $visit["tracking_appointment_id"] : null;
    if ($trackingAppointmentId) {
        $endedReason = $method === "left_campus" ? "campus_exit" : "completed";
        $tracking = $conn->prepare(
            "UPDATE location_tracking_sessions
             SET ended_at = COALESCE(ended_at, ?), ended_reason = ?
             WHERE appointment_id = ? AND ended_at IS NULL"
        );
        $tracking->bind_param("ssi", $completedAt, $endedReason, $trackingAppointmentId);
        $tracking->execute();
        $tracking->close();
    }

    $notifyAppointmentId = $trackingAppointmentId ?: (isset($stops[0]) ? (int) $stops[0]["id"] : null);
    if ($method !== "" && function_exists("gate_checkout_message")) {
        $message = gate_checkout_message($method);
    } else {
        $message = $checkout
            ? "Security completed your campus visit. Location tracking has stopped."
            : "Your last office stop has ended, so your campus visit is complete. Location tracking has stopped.";
    }
    visit_notify_visitor(
        $conn,
        (int) $visit["visitor_user_id"],
        $notifyAppointmentId,
        "visit.completed",
        "Visit completed",
        $message,
        ["visit_id" => $visitId]
    );
    visit_audit($conn, $actorUserId, $notifyAppointmentId, "visit.completed", $visitId, array_filter([
        "checkout" => $checkout,
        "method" => $method !== "" ? $method : null,
        "note" => $note,
        "completed_at" => $completedAt,
    ], function ($value): bool {
        return $value !== null;
    }));
}

/**
 * Applies time-based visit outcomes after the stop-level maintenance has run: an open
 * visit with no active stops left is closed.
 *
 * A checked-in visit no longer ends when its office slots end, because the visitor is
 * still on campus until they leave. It ends at the gate (second scan or End visit), on
 * a confirmed campus exit, or at the end of the day (see presence_service.php).
 */
function refresh_visit_states(mysqli $conn): void
{
    if (!visit_schema_ready($conn)) {
        return;
    }
    $result = $conn->query(
        "SELECT v.id,
                COALESCE(SUM(a.status IN ('pending_approval', 'reschedule_proposed', 'approved', 'checked_in')), 0) AS active_stops
         FROM visits v
         LEFT JOIN appointments a ON a.visit_id = v.id
         WHERE v.status = 'open'
         GROUP BY v.id
         HAVING active_stops = 0
         ORDER BY v.id ASC
         LIMIT 100"
    );
    if (!$result) {
        return;
    }
    while ($row = $result->fetch_assoc()) {
        $visitId = (int) $row["id"];
        $close = $conn->prepare("UPDATE visits SET status = 'closed', closed_at = NOW() WHERE id = ? AND status = 'open'");
        $close->bind_param("i", $visitId);
        $close->execute();
        $close->close();
    }
}

/**
 * Details the Security scanner shows for a visit pass, in the same shape as a single
 * appointment scan so the existing scanner page can display it.
 */
function visit_scan_details(array $visit, array $stops): array
{
    $window = visit_pass_window($stops);
    $first = $stops[0] ?? [];
    $last = $stops ? $stops[count($stops) - 1] : [];
    $status = (string) $visit["status"];
    $hasClosedStop = (bool) array_filter($stops, function (array $stop): bool {
        return $stop["status"] === "window_closed";
    });
    $displayStatus = [
        "open" => "approved",
        "checked_in" => "checked_in",
        "completed" => "completed",
        "closed" => $hasClosedStop ? "window_closed" : "cancelled",
    ][$status] ?? $status;
    return [
        "visit_id" => (int) $visit["id"],
        "appointment_id" => !empty($visit["tracking_appointment_id"])
            ? (int) $visit["tracking_appointment_id"]
            : (int) ($first["id"] ?? 0),
        "registration_code" => $visit["visit_code"],
        "device_name" => $first["device_name"] ?? "",
        "visitor_full_name" => $first["visitor_full_name"] ?? "Visitor",
        "visit_type" => "multi_stop",
        "office_label" => visit_route_label($stops, true),
        "scheduled_start_at" => $window ? (clone $window[0])->modify("+30 minutes")->format("Y-m-d H:i:s") : ($first["scheduled_start_at"] ?? null),
        "scheduled_end_at" => $window ? $window[1]->format("Y-m-d H:i:s") : ($last["scheduled_end_at"] ?? null),
        "qr_valid_from" => $window ? $window[0]->format("Y-m-d H:i:s") : null,
        "qr_valid_until" => $window ? $window[1]->format("Y-m-d H:i:s") : null,
        "status" => $displayStatus,
        "checked_in_at" => $visit["checked_in_at"],
        "completed_at" => $visit["completed_at"],
        "can_override" => false,
        "stop_count" => count($stops),
        "stops" => array_map(function (array $stop): array {
            return [
                "appointment_id" => (int) $stop["id"],
                "office_label" => appointment_office_label((string) $stop["office_code"]),
                "scheduled_start_at" => $stop["scheduled_start_at"],
                "scheduled_end_at" => $stop["scheduled_end_at"],
                "status" => $stop["status"],
            ];
        }, $stops),
    ];
}

/**
 * Checks in a visit pass. Every approved stop is checked in at once and the first one
 * becomes the owner of the visit's tracking session.
 */
function visit_handle_scan(mysqli $conn, int $visitId, int $securityUserId): array
{
    $visit = visit_load($conn, $visitId);
    if (!$visit) {
        return ["success" => false, "message" => "No appointment found for this QR code"];
    }
    $stops = visit_stops($conn, $visitId);
    $details = visit_scan_details($visit, $stops);

    if ($visit["status"] === "checked_in") {
        return array_merge($details, [
            "success" => true,
            "message" => "This visitor is already checked in.",
            "already_checked_in" => true,
        ]);
    }
    if ($visit["status"] === "completed") {
        return array_merge($details, ["success" => false, "message" => "This visit has already been completed."]);
    }

    $approved = array_values(array_filter($stops, function (array $stop): bool {
        return $stop["status"] === "approved";
    }));
    if (!$approved) {
        $closed = array_values(array_filter($stops, function (array $stop): bool {
            return $stop["status"] === "window_closed";
        }));
        if ($closed) {
            $latest = $closed[count($closed) - 1];
            return array_merge($details, [
                "success" => false,
                "message" => "Appointment Done. The check-in window for every stop has ended.",
                "can_override" => true,
                "window_state" => "closed",
                "status" => "window_closed",
                "appointment_id" => (int) $latest["id"],
                "scheduled_start_at" => $latest["scheduled_start_at"],
                "scheduled_end_at" => $latest["scheduled_end_at"],
            ]);
        }
        $waiting = array_filter($stops, function (array $stop): bool {
            return in_array($stop["status"], ["pending_approval", "reschedule_proposed"], true);
        });
        return array_merge($details, [
            "success" => false,
            "message" => $waiting
                ? "No office has approved a stop on this visit yet."
                : "None of the stops on this visit can be checked in.",
            "status" => $waiting ? "pending_approval" : "cancelled",
        ]);
    }

    [$validFrom, $validUntil] = visit_pass_window($stops);
    $stopIds = array_map(function (array $stop): int {
        return (int) $stop["id"];
    }, $stops);
    $placeholders = implode(",", array_fill(0, count($stopIds), "?"));
    $override = $conn->prepare(
        "SELECT id, appointment_id, override_valid_until FROM appointment_qr_overrides
         WHERE appointment_id IN ({$placeholders}) AND used_at IS NULL AND override_valid_until >= NOW()
         ORDER BY id DESC LIMIT 1"
    );
    $override->bind_param(str_repeat("i", count($stopIds)), ...$stopIds);
    $override->execute();
    $overrideRow = $override->get_result()->fetch_assoc();
    $override->close();

    $now = new DateTime("now");
    if (!$overrideRow && $now < $validFrom) {
        return array_merge($details, [
            "success" => false,
            "message" => "Too early. This visit pass becomes valid at " . $validFrom->format("M j, Y g:i A") . ".",
            "can_override" => true,
            "window_state" => "too_early",
            "appointment_id" => (int) $approved[0]["id"],
        ]);
    }
    if (!$overrideRow && $now > $validUntil) {
        $last = $approved[count($approved) - 1];
        return array_merge($details, [
            "success" => false,
            "message" => "Appointment Done. The scheduled check-in window has ended.",
            "can_override" => true,
            "window_state" => "closed",
            "status" => "window_closed",
            "appointment_id" => (int) $last["id"],
        ]);
    }

    $conn->begin_transaction();
    try {
        $locked = visit_load($conn, $visitId, true);
        if (!$locked || $locked["status"] !== "open") {
            throw new RuntimeException("Visit status changed before check-in");
        }
        $overrideId = $overrideRow ? (int) $overrideRow["id"] : 0;
        $historyNote = $overrideRow ? "Checked in using an authorized QR time override" : "Checked in by security scan of the visit pass";
        // Walk-in stops get the same four hours on campus as a single walk-in pass.
        $checkIn = $conn->prepare(
            "UPDATE appointments
             SET status = 'checked_in', status_updated_at = NOW(), checked_in_at = NOW(), checked_in_by_user_id = ?,
                 scheduled_end_at = IF(visit_type = 'walk_in',
                     GREATEST(scheduled_end_at, DATE_ADD(NOW(), INTERVAL 4 HOUR)), scheduled_end_at)
             WHERE id = ? AND status = 'approved'"
        );
        $checkedIn = [];
        foreach ($approved as $stop) {
            $stopId = (int) $stop["id"];
            $checkIn->bind_param("ii", $securityUserId, $stopId);
            $checkIn->execute();
            if ($checkIn->affected_rows === 1) {
                $checkedIn[] = $stopId;
                visit_history($conn, $stopId, "approved", "checked_in", $securityUserId, $historyNote);
            }
        }
        $checkIn->close();
        if (!$checkedIn) {
            throw new RuntimeException("Visit status changed before check-in");
        }
        if ($overrideRow) {
            $overrideUntil = (string) $overrideRow["override_valid_until"];
            $overrideAppointmentId = (int) $overrideRow["appointment_id"];
            $extend = $conn->prepare(
                "UPDATE appointments SET scheduled_end_at = GREATEST(scheduled_end_at, ?)
                 WHERE id = ? AND status = 'checked_in'"
            );
            $extend->bind_param("si", $overrideUntil, $overrideAppointmentId);
            $extend->execute();
            $extend->close();
            $used = $conn->prepare("UPDATE appointment_qr_overrides SET used_at = NOW() WHERE id = ?");
            $used->bind_param("i", $overrideId);
            $used->execute();
            $used->close();
        }
        $trackingAppointmentId = $checkedIn[0];
        $update = $conn->prepare(
            "UPDATE visits SET status = 'checked_in', checked_in_at = NOW(), checked_in_by_user_id = ?,
                    tracking_appointment_id = ?, closed_at = NULL
             WHERE id = ? AND status = 'open'"
        );
        $update->bind_param("iii", $securityUserId, $trackingAppointmentId, $visitId);
        $update->execute();
        $update->close();
        visit_audit($conn, $securityUserId, $trackingAppointmentId, "visit.checked_in", $visitId, [
            "checked_in_appointment_ids" => $checkedIn,
            "used_time_override" => (bool) $overrideRow,
            "override_id" => $overrideId ?: null,
        ]);
        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        return ["success" => false, "message" => "Could not check in this visitor."];
    }

    $count = count($checkedIn);
    return array_merge($details, [
        "success" => true,
        "action" => "checked_in",
        "message" => "Check-in recorded for {$count} office stop" . ($count === 1 ? "" : "s") . ". Visitor GPS can start.",
        "status" => "checked_in",
        "checked_in_at" => date("Y-m-d H:i:s"),
        "appointment_id" => $checkedIn[0],
        "used_time_override" => (bool) $overrideRow,
    ]);
}
