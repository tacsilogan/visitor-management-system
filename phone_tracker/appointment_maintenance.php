<?php

require_once __DIR__ . "/visit_service.php";
require_once __DIR__ . "/presence_service.php";
require_once __DIR__ . "/teacher_service.php";
require_once __DIR__ . "/arrival_service.php";

/**
 * Applies time-based appointment and multi-stop visit outcomes. This is safe to call
 * from read APIs; each update includes the previous status so a record changes once.
 * Must not be called inside an open transaction (visit endings start their own).
 */
function refresh_appointment_time_states(mysqli $conn): void
{
    refresh_appointment_stop_time_states($conn);
    refresh_teacher_answer_reminders($conn);
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
    // A walk-in request to a teacher closes after a few minutes, long before its slot ends.
    $answerClosed = teacher_schema_ready($conn)
        ? "OR (status = 'pending_approval' AND answer_by_at IS NOT NULL AND answer_by_at < NOW())"
        : "";
    $studentColumns = arrival_has_visitor_kind($conn) ? ", visitor_kind, arrived_at" : ", 'visitor' AS visitor_kind, NULL AS arrived_at";
    $result = $conn->query(
        "SELECT id, visitor_user_id, office_code, visitor_full_name, status, scheduled_end_at{$visitColumns}{$studentColumns}
         FROM appointments
         WHERE (scheduled_end_at < NOW()
           AND status IN ('pending_approval', 'approved', 'checked_in')
           {$skipOnCampus})
           {$answerClosed}
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

        $officeCode = (string) $appointment["office_code"];
        $isTeacher = appointment_is_teacher_code($officeCode);
        $teacherName = $isTeacher ? teacher_short_label($officeCode) : "";
        if ($fromStatus === "pending_approval") {
            $toStatus = "unanswered";
            $note = $isTeacher
                ? "Your request closed with no answer from {$teacherName}. You can pick another time."
                : "Office did not respond before the scheduled appointment ended";
            $extraSet = ", unanswered_at = NOW()";
        } elseif ($fromStatus === "approved" && $appointment["visitor_kind"] === "student" && !empty($appointment["arrived_at"])) {
            // A student who said they were here: the visit happened, even if the teacher forgot "done".
            $toStatus = "completed";
            $note = "Your visit with {$teacherName} ended at its scheduled time.";
            $extraSet = ", completed_at = scheduled_end_at, completed_by_user_id = NULL";
        } elseif ($fromStatus === "approved") {
            $toStatus = "window_closed";
            $note = $appointment["visitor_kind"] === "student"
                ? "Your visit time with {$teacherName} ended."
                : "Approved appointment window ended before check-in";
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

        $notification = $conn->prepare(
            "INSERT INTO app_notifications
             (recipient_user_id, appointment_id, notification_type, title, message)
             VALUES (?, ?, ?, ?, ?)"
        );
        if ($notification) {
            $title = $toStatus === "unanswered"
                ? ($isTeacher ? "No answer from {$teacherName}" : "Office did not respond")
                : ($toStatus === "window_closed" ? "Appointment Done" : ($isVisitStop ? "Office stop completed" : "Visit completed"));
            $notificationType = "appointment." . $toStatus;
            $notification->bind_param("iisss", $visitorUserId, $appointmentId, $notificationType, $title, $note);
            $notification->execute();
            $notification->close();
        }
        if ($toStatus === "unanswered" && $isTeacher) {
            visit_notify_office(
                $conn,
                $appointmentId,
                $officeCode,
                "appointment.unanswered",
                "No answer in time",
                trim((string) $appointment["visitor_full_name"]) . "'s request closed before you answered."
            );
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

            $visitorNotification = $conn->prepare(
                "INSERT INTO app_notifications
                 (recipient_user_id, appointment_id, notification_type, title, message)
                 VALUES (?, ?, 'appointment.reschedule_expired', 'Schedule proposal expired', ?)"
            );
            $visitorNotification->bind_param("iis", $visitorUserId, $appointmentId, $note);
            $visitorNotification->execute();
            $visitorNotification->close();

            $officeNotification = $conn->prepare(
                "INSERT INTO app_notifications
                 (recipient_user_id, appointment_id, notification_type, title, message)
                 SELECT id, ?, 'appointment.reschedule_expired', 'Visitor did not respond', ?
                 FROM app_users
                 WHERE role IN ('offices', 'teacher') AND office_code = ? AND is_active = 1"
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

/**
 * A booked request to a teacher with no answer this close to its time: tell both sides, once. The
 * request stays open, because the teacher can still answer until the time has passed.
 */
function refresh_teacher_answer_reminders(mysqli $conn): void
{
    if (!teacher_schema_ready($conn)) {
        return;
    }
    $minutes = TEACHER_ANSWER_REMINDER_MINUTES;
    $result = $conn->query(
        "SELECT a.id, a.office_code, a.visitor_user_id, a.visitor_full_name, a.scheduled_start_at, a.purpose, a.subject
         FROM appointments a
         INNER JOIN offices o ON o.code = a.office_code AND o.kind = 'teacher'
         WHERE a.status = 'pending_approval' AND a.answer_by_at IS NULL AND a.answer_reminded_at IS NULL
           AND a.scheduled_start_at < NOW() + INTERVAL {$minutes} MINUTE AND a.scheduled_end_at > NOW()
           AND a.created_at < NOW() - INTERVAL 15 MINUTE
         ORDER BY a.id ASC LIMIT 50"
    );
    if (!$result) {
        return;
    }
    while ($row = $result->fetch_assoc()) {
        $appointmentId = (int) $row["id"];
        $mark = $conn->prepare("UPDATE appointments SET answer_reminded_at = NOW() WHERE id = ? AND answer_reminded_at IS NULL");
        $mark->bind_param("i", $appointmentId);
        $mark->execute();
        $changed = $mark->affected_rows === 1;
        $mark->close();
        if (!$changed) {
            continue;
        }
        $code = (string) $row["office_code"];
        $teacherName = teacher_short_label($code);
        $visitorName = trim((string) $row["visitor_full_name"]);
        $time = date("g:i A", strtotime((string) $row["scheduled_start_at"]));
        visit_notify_visitor(
            $conn,
            (int) $row["visitor_user_id"],
            $appointmentId,
            "appointment.no_answer_yet",
            "No answer yet",
            "{$teacherName} hasn't answered your request for {$time}. You'll get a notification when they do, or you can pick another time."
        );
        visit_notify_office($conn, $appointmentId, $code, "appointment.reminder", "Still needs your answer", "{$visitorName} is waiting to hear about {$time}.");
        teacher_queue_alert_email($conn, $code, "teacher_reminder", "Still needs your answer: {$visitorName}, {$time}", [
            "teacher" => $teacherName,
            "visitor" => $visitorName,
            "when" => date("l, F j \\a\\t g:i A", strtotime((string) $row["scheduled_start_at"])),
            "purpose" => (string) $row["purpose"],
            "subject" => (string) $row["subject"],
        ]);
    }
}
