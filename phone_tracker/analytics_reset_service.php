<?php
/*
 * Resetting Visitor Analytics (admin_analytics_reset.php): deletes visit records, that is
 * appointments and multi-office visits, with everything recorded about them: GPS points,
 * tracking sessions, location consents, on-campus state, status history, notifications
 * about them, pass overrides, and new-time offers.
 *
 * Kept: every account (staff and visitors), departments and office schedules, the campus
 * map and walking routes, settings, sign-in history, and the activity log, which records
 * the reset itself. Activity entries about deleted visits stay, without the link to them.
 *
 * Two scopes:
 *   all       every visit record, including upcoming bookings and visitors on campus now
 *   finished  only visits that are over (completed, cancelled, declined, unanswered, done),
 *             and GPS points that belong to no visit at all
 *
 * Everything is deleted in one transaction, children before parents, so a failure leaves
 * nothing half-deleted. The order also matters because a tracking session must go before
 * the location consent it points to.
 */

const ANALYTICS_RESET_SCOPES = ["all", "finished"];
const ANALYTICS_RESET_FINISHED_STATUSES = ["completed", "cancelled", "rejected", "unanswered", "window_closed"];
const ANALYTICS_RESET_UPCOMING_STATUSES = ["pending_approval", "approved", "reschedule_proposed"];

/** SQL condition on appointments alias "a" for the scope. */
function analytics_reset_where(string $scope): string
{
    if ($scope !== "finished") {
        return "1 = 1";
    }
    return "a.status IN ('" . implode("', '", ANALYTICS_RESET_FINISHED_STATUSES) . "')";
}

/** Rows that point at the scope's appointments, in $table (column appointment_id). */
function analytics_reset_count_linked(mysqli $conn, string $table, string $scope): int
{
    if ($scope === "all") {
        return analytics_reset_scalar($conn, "SELECT COUNT(*) FROM {$table} x WHERE x.appointment_id IS NOT NULL");
    }
    return analytics_reset_scalar($conn,
        "SELECT COUNT(*) FROM {$table} x JOIN appointments a ON a.id = x.appointment_id WHERE " . analytics_reset_where($scope));
}

function analytics_reset_scalar(mysqli $conn, string $sql): int
{
    $result = $conn->query($sql);
    $row = $result ? $result->fetch_row() : null;
    return (int) ($row[0] ?? 0);
}

/**
 * What a reset of $scope would delete.
 *
 * @return array<string, int>
 */
function analytics_reset_counts(mysqli $conn, string $scope): array
{
    $finished = "'" . implode("', '", ANALYTICS_RESET_FINISHED_STATUSES) . "'";
    $upcoming = "'" . implode("', '", ANALYTICS_RESET_UPCOMING_STATUSES) . "'";
    $appointments = $conn->query(
        "SELECT COUNT(*) AS total,
                COALESCE(SUM(a.status = 'checked_in'), 0) AS on_campus,
                COALESCE(SUM(a.status IN ({$upcoming})), 0) AS upcoming,
                COALESCE(SUM(a.status IN ({$finished})), 0) AS finished,
                MIN(COALESCE(a.checked_in_at, a.appointment_at, a.created_at)) AS oldest,
                MAX(COALESCE(a.checked_in_at, a.appointment_at, a.created_at)) AS newest
         FROM appointments a
         WHERE " . analytics_reset_where($scope)
    )->fetch_assoc();

    $visits = $scope === "all"
        ? analytics_reset_scalar($conn, "SELECT COUNT(*) FROM visits")
        : analytics_reset_scalar($conn, analytics_reset_finished_visits_sql("SELECT COUNT(*)"));
    // GPS points that belong to no visit (old records) cannot be part of an upcoming or current
    // visit, so "finished" clears them too; analytics never show them.
    $gpsPoints = $scope === "all"
        ? analytics_reset_scalar($conn, "SELECT COUNT(*) FROM locations")
        : analytics_reset_count_linked($conn, "locations", $scope)
            + analytics_reset_scalar($conn, "SELECT COUNT(*) FROM locations WHERE appointment_id IS NULL");

    return [
        "appointments" => (int) $appointments["total"],
        "on_campus" => (int) $appointments["on_campus"],
        "upcoming" => (int) $appointments["upcoming"],
        "finished" => (int) $appointments["finished"],
        "visits" => $visits,
        "gps_points" => $gpsPoints,
        "tracking_sessions" => analytics_reset_count_linked($conn, "location_tracking_sessions", $scope),
        "consents" => analytics_reset_count_linked($conn, "visitor_consents", $scope),
        "notifications" => analytics_reset_count_linked($conn, "app_notifications", $scope),
        "status_changes" => analytics_reset_count_linked($conn, "appointment_status_history", $scope),
        "oldest" => $appointments["oldest"],
        "newest" => $appointments["newest"],
    ];
}

/**
 * Multi-office visits that are over and have no stop left that is not finished; with
 * "finished", those go along with their stops.
 */
function analytics_reset_finished_visits_sql(string $select): string
{
    $finished = "'" . implode("', '", ANALYTICS_RESET_FINISHED_STATUSES) . "'";
    return $select . " FROM visits v
        WHERE v.status IN ('completed', 'closed')
          AND NOT EXISTS (SELECT 1 FROM appointments a WHERE a.visit_id = v.id AND a.status NOT IN ({$finished}))";
}

/**
 * Deletes the scope's visit records and everything about them, in one transaction.
 *
 * @return array<string, int> what was deleted (the counts from just before)
 */
function analytics_reset_run(mysqli $conn, string $scope): array
{
    if (!in_array($scope, ANALYTICS_RESET_SCOPES, true)) {
        throw new InvalidArgumentException("Unknown reset scope: " . $scope);
    }
    $conn->begin_transaction();
    try {
        $counts = analytics_reset_counts($conn, $scope);
        if ($scope === "all") {
            $statements = [
                "DELETE FROM locations",
                "DELETE FROM location_tracking_sessions",
                "DELETE FROM visitor_consents",
                "DELETE FROM visitor_presence",
                "DELETE FROM app_notifications WHERE appointment_id IS NOT NULL",
                "DELETE FROM appointment_status_history",
                "DELETE FROM appointment_qr_overrides",
                "DELETE FROM appointment_reschedule_proposals",
                "DELETE FROM appointments",
                "DELETE FROM visits",
            ];
        } else {
            $where = analytics_reset_where($scope);
            $linked = function (string $table) use ($where): string {
                return "DELETE x FROM {$table} x JOIN appointments a ON a.id = x.appointment_id WHERE {$where}";
            };
            $statements = [
                $linked("locations"),
                "DELETE FROM locations WHERE appointment_id IS NULL",
                $linked("location_tracking_sessions"),
                $linked("visitor_consents"),
                $linked("visitor_presence"),
                $linked("app_notifications"),
                $linked("appointment_status_history"),
                $linked("appointment_qr_overrides"),
                $linked("appointment_reschedule_proposals"),
                "DELETE FROM appointments WHERE status IN ('" . implode("', '", ANALYTICS_RESET_FINISHED_STATUSES) . "')",
                // After the stops, so a visit is only removed once nothing of it is left.
                analytics_reset_finished_visits_sql("DELETE v"),
            ];
        }
        foreach ($statements as $sql) {
            $conn->query($sql);
        }
        $conn->commit();
        return $counts;
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }
}
