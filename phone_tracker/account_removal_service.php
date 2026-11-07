<?php

/*
 * Removing an account (AUTH_SECURITY.md, "Removing an account"). An administrator can remove any
 * account, even one with years of visits:
 *
 * - The person's sign-in, phones, notifications, location sharing and student profile go with the
 *   account (ON DELETE CASCADE).
 * - Their visits and everything staff did stay in the records. Each visit keeps the name and email it
 *   was booked with, so the guard's and the offices' history still reads correctly; the rows only stop
 *   pointing at the account (ON DELETE SET NULL, account_removal_migration.sql).
 * - Anything still to come is closed first, so no one waits for a person who is gone: a visitor's or
 *   student's requests and visits are cancelled and the office or teacher is told; a teacher's visits
 *   are cancelled and each visitor is told.
 *
 * The one thing that waits: a visitor who is inside the campus. The guard ends that visit first.
 */

require_once __DIR__ . "/auth_security.php";
require_once __DIR__ . "/visit_service.php";
require_once __DIR__ . "/teacher_service.php";

/** The account can't be removed right now; the message says why, for the administrator. */
final class AccountRemovalError extends RuntimeException
{
}

/**
 * Removes the account in one transaction.
 *
 * @return array{name:string, role:string, cancelled_visits:int}
 * @throws AccountRemovalError when it can't be removed now, with nothing changed
 */
function account_remove(mysqli $conn, int $userId, int $actorId): array
{
    if ($userId === $actorId) {
        throw new AccountRemovalError("You cannot remove your own account.");
    }
    $conn->begin_transaction();
    try {
        $lookup = $conn->prepare("SELECT id, username, display_name, role, office_code, is_active FROM app_users WHERE id = ? FOR UPDATE");
        $lookup->bind_param("i", $userId);
        $lookup->execute();
        $user = $lookup->get_result()->fetch_assoc();
        $lookup->close();
        if (!$user) {
            throw new AccountRemovalError("This account was already removed.");
        }
        $name = trim((string) ($user["display_name"] ?? ""));
        $name = $name !== "" ? $name : (string) $user["username"];
        $role = (string) $user["role"];
        if ($role === "admin" && (int) $user["is_active"] === 1
            && auth_scalar($conn, "SELECT COUNT(*) FROM app_users WHERE role = 'admin' AND is_active = 1 FOR UPDATE") <= 1) {
            throw new AccountRemovalError("You cannot remove the last active administrator.");
        }

        $cancelled = 0;
        if ($role === "visitor" || $role === "student") {
            $cancelled = account_removal_close_visits($conn, $userId, $actorId, $name);
        } elseif ($role === "teacher") {
            $code = (string) $user["office_code"];
            if ($code !== "" && appointment_is_teacher_code($code)) {
                $cancelled = teacher_close_open_visits($conn, $code, $actorId, "removed by an administrator");
            }
            // Their hours go; the calendar stays, archived, when visits carry the teacher's name.
            teacher_retire_calendar($conn, $code);
        }

        // Emails still waiting in the queue are not sent to someone who is no longer here.
        $mail = $conn->prepare("DELETE FROM outbound_emails WHERE user_id = ? AND status = 'queued'");
        $mail->bind_param("i", $userId);
        $mail->execute();
        $mail->close();

        $delete = $conn->prepare("DELETE FROM app_users WHERE id = ? LIMIT 1");
        $delete->bind_param("i", $userId);
        $delete->execute();
        $delete->close();

        auth_audit($conn, $actorId, "user.deleted", "app_user", (string) $userId, [
            "username" => (string) $user["username"],
            "name" => $name,
            "role" => $role,
            "cancelled_visits" => $cancelled,
        ]);
        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }
    return ["name" => $name, "role" => $role, "cancelled_visits" => $cancelled];
}

/**
 * A visitor or student is being removed. Every request and visit of theirs that is still open is
 * cancelled, so nothing is left for the time-based updates (appointment_maintenance.php) to report to
 * someone who is gone, and a saved picture of their pass no longer opens the gate. Each office or
 * teacher still expecting them is told. The caller owns the transaction and has locked the account.
 * Returns how many upcoming visits were cancelled.
 *
 * @throws AccountRemovalError while the person is inside the campus
 */
function account_removal_close_visits(mysqli $conn, int $userId, int $actorId, string $name): int
{
    // Visits before their stops, the order the gate scan locks them in.
    $visitsReady = visit_schema_ready($conn);
    $insideSince = null;
    if ($visitsReady) {
        $visits = $conn->prepare(
            "SELECT id, status, checked_in_at FROM visits WHERE visitor_user_id = ? AND status IN ('open', 'checked_in') FOR UPDATE"
        );
        $visits->bind_param("i", $userId);
        $visits->execute();
        foreach ($visits->get_result()->fetch_all(MYSQLI_ASSOC) as $visit) {
            if ($visit["status"] === "checked_in") {
                $insideSince = (string) ($visit["checked_in_at"] ?? "");
            }
        }
        $visits->close();
    }
    $open = $conn->prepare(
        "SELECT * FROM appointments
         WHERE visitor_user_id = ? AND status IN ('pending_approval', 'approved', 'reschedule_proposed', 'checked_in')
         ORDER BY scheduled_start_at ASC
         FOR UPDATE"
    );
    $open->bind_param("i", $userId);
    $open->execute();
    $appointments = $open->get_result()->fetch_all(MYSQLI_ASSOC);
    $open->close();
    foreach ($appointments as $appointment) {
        // A single visit (no multi-stop visit around it) is checked in at the gate itself.
        if ($appointment["status"] === "checked_in" && (!$visitsReady || empty($appointment["visit_id"]))) {
            $insideSince = (string) ($appointment["checked_in_at"] ?? "");
        }
    }
    if ($insideSince !== null) {
        $since = $insideSince !== "" ? " since " . date("g:i A", strtotime($insideSince)) : "";
        throw new AccountRemovalError(
            "{$name} is inside the campus now{$since}. Ask the guard to end the visit, or remove the account after they leave."
        );
    }

    $upcoming = 0;
    foreach ($appointments as $appointment) {
        $from = (string) $appointment["status"];
        if ($from === "checked_in") {
            // A stop of a visit that is no longer inside; the time-based updates finish it.
            continue;
        }
        $appointmentId = (int) $appointment["id"];
        $cancel = $conn->prepare(
            "UPDATE appointments SET status = 'cancelled', status_updated_at = NOW(), cancelled_at = NOW(), cancelled_by_user_id = ?
             WHERE id = ? AND status = ?"
        );
        $cancel->bind_param("iis", $actorId, $appointmentId, $from);
        $cancel->execute();
        $cancel->close();
        visit_withdraw_open_proposals($conn, $appointmentId);
        visit_history($conn, $appointmentId, $from, "cancelled", $actorId, "Cancelled because the visitor's account was removed");
        if (strtotime((string) $appointment["scheduled_end_at"]) <= time()) {
            // Its time is already over; no one is waiting for it.
            continue;
        }
        $visitor = trim((string) $appointment["visitor_full_name"]);
        $visitor = $visitor !== "" ? $visitor : $name;
        $time = date("M j, g:i A", strtotime((string) $appointment["scheduled_start_at"]));
        $what = $from === "pending_approval" ? "request for {$time}" : "visit on {$time}";
        visit_notify_office(
            $conn,
            $appointmentId,
            (string) $appointment["office_code"],
            "appointment.cancelled",
            "Visit cancelled",
            "{$visitor}'s account was removed, so their {$what} is cancelled."
        );
        $upcoming++;
    }
    if ($visitsReady) {
        $close = $conn->prepare("UPDATE visits SET status = 'closed', closed_at = NOW() WHERE visitor_user_id = ? AND status = 'open'");
        $close->bind_param("i", $userId);
        $close->execute();
        $close->close();
    }
    return $upcoming;
}
