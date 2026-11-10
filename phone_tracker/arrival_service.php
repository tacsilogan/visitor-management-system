<?php

/**
 * Confirmed arrival at an office stop (VISITOR_NAVIGATION.md).
 *
 * The visitor app reports an arrival in one of two ways:
 * - "gps": the phone placed the visitor within the arrival distance of the office pin, with
 *   a reading accurate to ARRIVAL_MAX_ACCURACY_METERS, for several seconds in a row;
 * - "visitor": the visitor tapped "I'm here" (for example inside a building, where GPS
 *   cannot confirm it).
 *
 * Only the first arrival at each stop is kept. Requires visitor_arrival_migration.sql.
 */

require_once __DIR__ . "/presence_service.php";

/** Used when mobile_api_settings has no arrival_distance_meters. */
const ARRIVAL_DEFAULT_DISTANCE_METERS = 3;
/** A GPS arrival needs a reading at least this accurate, so a weak signal never fakes one. */
const ARRIVAL_MAX_ACCURACY_METERS = 8;
const ARRIVAL_METHODS = ["gps", "visitor"];
const ARRIVAL_MIGRATION_MESSAGE = "Database update required: import phone_tracker/visitor_arrival_migration.sql into phone_tracker.";

function arrival_schema_ready(mysqli $conn): bool
{
    static $ready = null;
    if ($ready === null) {
        try {
            $column = $conn->query("SHOW COLUMNS FROM appointments LIKE 'arrived_at'");
            $ready = $column && $column->num_rows > 0;
        } catch (Throwable $ignored) {
            $ready = false;
        }
    }
    return $ready;
}

/** The arrival fields as sent to the app and to staff pages. */
function arrival_public(?array $row): array
{
    return [
        "arrived_at" => $row["arrived_at"] ?? null,
        "arrival_method" => $row["arrival_method"] ?? null,
        "arrival_distance_meters" => isset($row["arrival_distance_meters"]) ? (float) $row["arrival_distance_meters"] : null,
        "arrival_accuracy_meters" => isset($row["arrival_accuracy_meters"]) ? (float) $row["arrival_accuracy_meters"] : null,
    ];
}

/**
 * Recorded arrivals for these appointments, keyed by appointment id. Empty before the
 * migration.
 *
 * @param array<int, int|string> $appointmentIds
 * @return array<int, array>
 */
function arrival_lookup(mysqli $conn, array $appointmentIds): array
{
    $ids = array_values(array_unique(array_filter(array_map("intval", $appointmentIds))));
    if (!$ids || !arrival_schema_ready($conn)) {
        return [];
    }
    $result = $conn->query(
        "SELECT id, arrived_at, arrival_method, arrival_distance_meters, arrival_accuracy_meters
         FROM appointments WHERE arrived_at IS NOT NULL AND id IN (" . implode(",", $ids) . ")"
    );
    $arrivals = [];
    foreach ($result->fetch_all(MYSQLI_ASSOC) as $row) {
        $arrivals[(int) $row["id"]] = arrival_public($row);
    }
    return $arrivals;
}

/** "confirmed by GPS (±4 m)" or "confirmed by the visitor". */
function arrival_method_text(?string $method, $accuracyMeters = null): string
{
    if ($method === "gps") {
        return "confirmed by GPS" . ($accuracyMeters !== null ? " (±" . (int) round((float) $accuracyMeters) . " m)" : "");
    }
    return $method === "visitor" ? "confirmed by the visitor" : "";
}

/**
 * Records the first arrival at a checked-in stop, tells the office, and writes the
 * activity log, all in one transaction. A repeated report returns the first arrival
 * unchanged with already_recorded = true.
 *
 * @throws RuntimeException with a message for the visitor; its code is the HTTP status.
 */
function arrival_record(
    mysqli $conn,
    int $appointmentId,
    int $visitorUserId,
    string $method,
    ?float $distanceMeters,
    ?float $accuracyMeters,
    float $arrivalDistanceMeters
): array {
    if (!arrival_schema_ready($conn)) {
        throw new RuntimeException(ARRIVAL_MIGRATION_MESSAGE, 503);
    }
    if (!in_array($method, ARRIVAL_METHODS, true)) {
        throw new RuntimeException("Arrival method must be gps or visitor", 422);
    }
    // The app only reports GPS arrivals that meet these rules; check them again here.
    if ($method === "gps" && ($distanceMeters === null || $accuracyMeters === null
        || $distanceMeters > $arrivalDistanceMeters + 0.5 || $accuracyMeters > ARRIVAL_MAX_ACCURACY_METERS)) {
        throw new RuntimeException("This GPS reading is not close or accurate enough to confirm arrival", 422);
    }

    $conn->begin_transaction();
    try {
        $load = $conn->prepare(
            "SELECT id, status, office_code, visitor_full_name, arrived_at, arrival_method,
                    arrival_distance_meters, arrival_accuracy_meters
             FROM appointments WHERE id = ? AND visitor_user_id = ? LIMIT 1 FOR UPDATE"
        );
        $load->bind_param("ii", $appointmentId, $visitorUserId);
        $load->execute();
        $row = $load->get_result()->fetch_assoc();
        $load->close();
        if (!$row) {
            throw new RuntimeException("Appointment not found", 404);
        }
        if ($row["arrived_at"] !== null) {
            $conn->commit();
            return arrival_public($row) + ["already_recorded" => true];
        }
        if ($row["status"] !== "checked_in") {
            throw new RuntimeException("An arrival can only be recorded while the visit is checked in", 409);
        }

        $update = $conn->prepare(
            "UPDATE appointments
             SET arrived_at = NOW(), arrival_method = ?, arrival_distance_meters = ?, arrival_accuracy_meters = ?
             WHERE id = ? AND arrived_at IS NULL"
        );
        $update->bind_param("sddi", $method, $distanceMeters, $accuracyMeters, $appointmentId);
        $update->execute();
        $update->close();

        $officeCode = (string) $row["office_code"];
        $officeLabel = appointment_office_label($officeCode);
        $visitorName = trim((string) $row["visitor_full_name"]) ?: "A visitor";
        $how = arrival_method_text($method, $accuracyMeters);
        visit_notify_office(
            $conn,
            $appointmentId,
            $officeCode,
            "visitor_arrived",
            "Visitor arrived",
            "{$visitorName} has arrived at {$officeLabel} ({$how})."
        );
        presence_audit($conn, $visitorUserId, $appointmentId, "visit.arrived", [
            "office_code" => $officeCode,
            "method" => $method,
            "distance_meters" => $distanceMeters,
            "accuracy_meters" => $accuracyMeters,
        ]);

        $reload = $conn->prepare(
            "SELECT arrived_at, arrival_method, arrival_distance_meters, arrival_accuracy_meters FROM appointments WHERE id = ?"
        );
        $reload->bind_param("i", $appointmentId);
        $reload->execute();
        $stored = $reload->get_result()->fetch_assoc();
        $reload->close();
        $conn->commit();
        return arrival_public($stored) + ["already_recorded" => false];
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }
}
