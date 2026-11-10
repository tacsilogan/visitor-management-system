<?php

require_once __DIR__ . "/visit_service.php";
require_once __DIR__ . "/presence_service.php";

/**
 * Applies time-based appointment and multi-stop visit outcomes. This is safe to call
 * from read APIs; each update includes the previous status so a record changes once.
 * Must not be called inside an open transaction (visit endings start their own).
 */
function refresh_appointment_time_states(mysqli $conn): void
{
    refresh_appointment_stop_time_states($conn);
    refresh_visit_states($conn);
    refresh_presence_exits($conn);
    refresh_checked_in_end_of_day($conn);
}

function refresh_appointment_stop_time_states(mysqli $conn): void
{
    $visitColumns = visit_appointment_columns($conn);
    // Checked-in single appointments are skipped: see the comment in the loop below.
    $skipOnCampus = visit_schema_ready($conn)
        ? "AND NOT (status = 'checked_in' AND visit_id IS NULL)"
        : "AND status <> 'checked_in'";
    $result = $conn->query(
        "SELECT id, visitor_user_id, status, scheduled_end_at{$visitColumns}
         FROM appointments
         WHERE scheduled_end_at < NOW()
           AND status IN ('pending_approval', 'approved', 'checked_in')
           {$skipOnCampus}
         ORDER BY id ASC
         LIMIT 200"
    );
    if (!$result) {
        return;
    }

    while ($appointment = $result->fetch_assoc()) {
        $appointmentId = (int) $appointment["id"];
        $visitorUserId = (int) $appointment["visitor_user_id"];
        $fromStatus = (string) $appointment["status"];
        $isVisitStop = !empty($appointment["visit_id"]);
        // A checked-in visitor is still on campus when their slot ends. Their visit ends
        // at the gate, on a confirmed campus exit, or at the end of the day
        // (presence_service.php). A multi-office stop still completes here because the
        // visit itself continues.
        if ($fromStatus === "checked_in" && !$isVisitStop) {
            continue;
        }
        $toStatus = "";
        $note = "";
        $extraSet = "";

        if ($fromStatus === "pending_approval") {
            $toStatus = "unanswered";
            $note = "Office did not respond before the scheduled appointment ended";
            $extraSet = ", unanswered_at = NOW()";
        } elseif ($fromStatus === "approved") {
            $toStatus = "window_closed";
            $note = "Approved appointment window ended before check-in";
            $extraSet = ", window_closed_at = NOW()";
        } elseif ($fromStatus === "checked_in") {
            $toStatus = "completed";
            $note = $isVisitStop
                ? "Office stop automatically completed at its scheduled end"
                : "Visit automatically completed at its scheduled end";
            $extraSet = ", completed_at = scheduled_end_at, completed_by_user_id = NULL";
        }

        if ($toStatus === "") {
            continue;
        }

        $updateSql =
            "UPDATE appointments SET status = ?, status_updated_at = NOW(){$extraSet}
             WHERE id = ? AND status = ?";
        $update = $conn->prepare($updateSql);
        if (!$update) {
            continue;
        }
        $update->bind_param("sis", $toStatus, $appointmentId, $fromStatus);
        $update->execute();
        $changed = $update->affected_rows;
        $update->close();
        if ($changed < 1) {
            continue;
        }

        // A multi-stop visit keeps tracking after the stop that owns its session ends;
        // refresh_visit_states() ends the session when the whole visit ends.
        $visitTracking = $toStatus === "completed" ? visit_for_tracking_appointment($conn, $appointmentId) : null;
        if ($toStatus === "completed" && !($visitTracking && $visitTracking["status"] === "checked_in")) {
            $tracking = false;
            try {
                $tracking = $conn->prepare(
                    "UPDATE location_tracking_sessions
                     SET ended_at = COALESCE(ended_at, ?), ended_reason = 'completed'
                     WHERE appointment_id = ? AND ended_at IS NULL"
                );
            } catch (Throwable $ignored) {
                // Preserve older installations until the Phase 4 migration is applied.
            }
            if ($tracking) {
                $scheduledEnd = (string) $appointment["scheduled_end_at"];
                $tracking->bind_param("si", $scheduledEnd, $appointmentId);
                $tracking->execute();
                $tracking->close();
            }
        }

        $history = $conn->prepare(
            "INSERT INTO appointment_status_history
             (appointment_id, from_status, to_status, changed_by_user_id, note)
             VALUES (?, ?, ?, NULL, ?)"
        );
        if ($history) {
            $history->bind_param("isss", $appointmentId, $fromStatus, $toStatus, $note);
            $history->execute();
            $history->close();
        }

        // A removed account's visits stay in the records with no visitor left to tell.
        $notification = $visitorUserId > 0 ? $conn->prepare(
            "INSERT INTO app_notifications
             (recipient_user_id, appointment_id, notification_type, title, message)
             VALUES (?, ?, ?, ?, ?)"
        ) : false;
        if ($notification) {
            $title = $toStatus === "unanswered"
                ? "Office did not respond"
                : ($toStatus === "window_closed" ? "Appointment Done" : ($isVisitStop ? "Office stop completed" : "Visit completed"));
            $notificationType = "appointment." . $toStatus;
            $notification->bind_param("iisss", $visitorUserId, $appointmentId, $notificationType, $title, $note);
            $notification->execute();
            $notification->close();
        }

        $audit = $conn->prepare(
            "INSERT INTO audit_logs
             (actor_user_id, appointment_id, action, entity_type, entity_id, details_json)
             VALUES (NULL, ?, 'appointment.automatic_status_change', 'appointment', ?, ?)"
        );
        if ($audit) {
            $entityId = (string) $appointmentId;
            $details = json_encode(["from_status" => $fromStatus, "to_status" => $toStatus]);
            $audit->bind_param("iss", $appointmentId, $entityId, $details);
            $audit->execute();
            $audit->close();
        }
    }

    // Close unanswered reschedule proposals after their response deadline.
    // This is separate from the original appointment window because the
    // office has already offered replacement times.
    $proposalResult = $conn->query(
        "SELECT p.id AS proposal_id, p.appointment_id, a.visitor_user_id, a.office_code
         FROM appointment_reschedule_proposals p
         INNER JOIN appointments a ON a.id = p.appointment_id
         WHERE p.status = 'pending'
           AND p.response_deadline IS NOT NULL
           AND p.response_deadline < NOW()
           AND a.status = 'reschedule_proposed'
         ORDER BY p.id ASC LIMIT 100"
    );
    if (!$proposalResult) {
        return;
    }

    while ($proposal = $proposalResult->fetch_assoc()) {
        $proposalId = (int) $proposal["proposal_id"];
        $appointmentId = (int) $proposal["appointment_id"];
        $visitorUserId = (int) $proposal["visitor_user_id"];
        $officeCode = (string) $proposal["office_code"];
        $conn->begin_transaction();
        try {
            $closeProposal = $conn->prepare(
                "UPDATE appointment_reschedule_proposals
                 SET status = 'closed', responded_at = NOW()
                 WHERE id = ? AND status = 'pending'"
            );
            $closeProposal->bind_param("i", $proposalId);
            $closeProposal->execute();
            $proposalChanged = $closeProposal->affected_rows;
            $closeProposal->close();
            if ($proposalChanged !== 1) {
                $conn->rollback();
                continue;
            }

            $cancel = $conn->prepare(
                "UPDATE appointments
                 SET status = 'cancelled', status_updated_at = NOW(), cancelled_at = NOW()
                 WHERE id = ? AND status = 'reschedule_proposed'"
            );
            $cancel->bind_param("i", $appointmentId);
            $cancel->execute();
            $appointmentChanged = $cancel->affected_rows;
            $cancel->close();
            if ($appointmentChanged !== 1) {
                $conn->rollback();
                continue;
            }

            $note = "Visitor did not respond to the proposed schedules before the deadline";
            $history = $conn->prepare(
                "INSERT INTO appointment_status_history
                 (appointment_id, from_status, to_status, changed_by_user_id, note)
                 VALUES (?, 'reschedule_proposed', 'cancelled', NULL, ?)"
            );
            $history->bind_param("is", $appointmentId, $note);
            $history->execute();
            $history->close();

            if ($visitorUserId > 0) {
                $visitorNotification = $conn->prepare(
                    "INSERT INTO app_notifications
                     (recipient_user_id, appointment_id, notification_type, title, message)
                     VALUES (?, ?, 'appointment.reschedule_expired', 'Schedule proposal expired', ?)"
                );
                $visitorNotification->bind_param("iis", $visitorUserId, $appointmentId, $note);
                $visitorNotification->execute();
                $visitorNotification->close();
            }

            $officeNotification = $conn->prepare(
                "INSERT INTO app_notifications
                 (recipient_user_id, appointment_id, notification_type, title, message)
                 SELECT id, ?, 'appointment.reschedule_expired', 'Visitor did not respond', ?
                 FROM app_users
                 WHERE role = 'offices' AND office_code = ? AND is_active = 1"
            );
            $officeNotification->bind_param("iss", $appointmentId, $note, $officeCode);
            $officeNotification->execute();
            $officeNotification->close();

            $audit = $conn->prepare(
                "INSERT INTO audit_logs
                 (actor_user_id, appointment_id, action, entity_type, entity_id, details_json)
                 VALUES (NULL, ?, 'appointment.reschedule_expired', 'appointment', ?, ?)"
            );
            if ($audit) {
                $entityId = (string) $appointmentId;
                $details = json_encode(["proposal_id" => $proposalId]);
                $audit->bind_param("iss", $appointmentId, $entityId, $details);
                $audit->execute();
                $audit->close();
            }
            $conn->commit();
        } catch (Throwable $error) {
            $conn->rollback();
        }
    }
}
