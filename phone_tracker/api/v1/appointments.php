<?php
declare(strict_types=1);
require_once __DIR__ . "/_bootstrap.php";
require_once __DIR__ . "/_appointments.php";

api_require_method("GET", "POST");
$user = api_require_visitor();
refresh_appointment_time_states($conn);
$visitorUserId = (int) $user["id"];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $input = api_body();
    api_rate_limit($conn, "appointment_create", (string) $visitorUserId, 20, 3600);
    try {
        $appointment = mobile_create_appointment($conn, $user, $input);
        $message = ($appointment["visit_type"] ?? "appointment") === "walk_in"
            ? "Walk-in visitor pass created"
            : "Appointment request submitted";
        api_success(["appointment" => $appointment], 201, $message);
    } catch (InvalidArgumentException $error) {
        $errors = json_decode($error->getMessage(), true);
        api_fail("Check the appointment details", 422, is_array($errors) ? $errors : []);
    } catch (DomainException $error) {
        api_fail($error->getMessage(), 409);
    } catch (Throwable $error) {
        api_fail("Could not create the appointment request", 500, [], $error);
    }
}

$status = strtolower(trim((string) ($_GET["status"] ?? "")));
$allowedStatuses = [
    "pending_approval", "approved", "rejected", "cancelled", "unanswered",
    "reschedule_proposed", "checked_in", "completed", "window_closed",
];
$limit = max(1, min(100, (int) ($_GET["limit"] ?? 30)));
$beforeId = max(0, (int) ($_GET["before_id"] ?? 0));
$where = "visitor_user_id = ?";
$types = "i";
$params = [$visitorUserId];
if ($status !== "") {
    if (!in_array($status, $allowedStatuses, true)) {
        api_fail("Invalid appointment status filter", 422);
    }
    $where .= " AND status = ?";
    $types .= "s";
    $params[] = $status;
}
if ($beforeId > 0) {
    $where .= " AND id < ?";
    $types .= "i";
    $params[] = $beforeId;
}
$visitColumns = visit_appointment_columns($conn);
$sql =
    "SELECT id, registration_code, public_token, office_code, visitor_full_name, visitor_email,
            contact_number, device_name, visit_type, purpose, destination, subject, additional_details,
            scheduled_start_at, scheduled_end_at, status, status_updated_at, rejection_reason,
            checked_in_at, completed_at, created_at{$visitColumns}
     FROM appointments WHERE {$where} ORDER BY id DESC LIMIT ?";
$types .= "i";
$params[] = $limit + 1;
$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();
$items = [];
while ($row = $result->fetch_assoc()) {
    $items[] = mobile_appointment_payload($conn, $row, false);
}
$stmt->close();
$hasMore = count($items) > $limit;
if ($hasMore) {
    array_pop($items);
}
$nextBeforeId = $hasMore && $items ? (int) end($items)["id"] : null;
api_success([
    "appointments" => $items,
    "pagination" => ["has_more" => $hasMore, "next_before_id" => $nextBeforeId],
]);
