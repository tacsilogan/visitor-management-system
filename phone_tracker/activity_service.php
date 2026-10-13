<?php
/*
 * Turns audit_logs rows into plain-language activity ("Approved Juan Dela Cruz's visit
 * to IT Department on Oct 5, 10:00 AM") for the admin's accountability views. The same
 * text is used on screen and in CSV exports.
 */

require_once __DIR__ . "/personnel.php";
require_once __DIR__ . "/auth_security.php";

const ACTIVITY_SIGNIN_ACTIONS = ["auth.signed_in", "auth.signed_out", "mobile.signed_in", "mobile.signed_out"];
const ACTIVITY_DECISION_ACTIONS = ["appointment.approved", "appointment.rejected", "appointment.reschedule_proposed", "appointment.stop_completed"];
const ACTIVITY_GATE_ACTIONS = [
    "appointment.checked_in", "appointment.completed", "appointment.qr_override_created", "visit.checked_in", "visit.completed",
    "presence.left_campus", "presence.returned_to_campus", "visit.arrived",
];
const ACTIVITY_SCHEDULE_ACTIONS = ["office.availability_updated", "office.availability_exception_added", "office.availability_exception_removed"];
const ACTIVITY_VISITOR_ACTIONS = ["appointment.created", "appointment.cancelled", "appointment.reschedule_response"];
const ACTIVITY_CATEGORIES = ["all", "signins", "decisions", "gate", "schedules", "accounts", "visitors", "system"];

const ACTIVITY_STATUS_LABELS = [
    "pending_approval" => "pending approval",
    "approved" => "approved",
    "rejected" => "declined",
    "cancelled" => "cancelled",
    "unanswered" => "not answered by the office",
    "reschedule_proposed" => "new time offered",
    "checked_in" => "checked in",
    "completed" => "completed",
    "window_closed" => "appointment window closed",
];

function activity_category(string $action, ?int $actorId): string
{
    if (in_array($action, ACTIVITY_SIGNIN_ACTIONS, true)) {
        return "signins";
    }
    if (in_array($action, ACTIVITY_DECISION_ACTIONS, true)) {
        return "decisions";
    }
    if (in_array($action, ACTIVITY_GATE_ACTIONS, true)) {
        return "gate";
    }
    if (in_array($action, ACTIVITY_SCHEDULE_ACTIONS, true)) {
        return "schedules";
    }
    if ($actorId === null && str_starts_with($action, "appointment.")) {
        return "system";
    }
    if (in_array($action, ACTIVITY_VISITOR_ACTIONS, true) || str_starts_with($action, "mobile.")) {
        return "visitors";
    }
    return "accounts";
}

/**
 * SQL condition on audit_logs alias g that matches activity_category(); "" for "all".
 * Placeholders are appended to $params in the same order they appear in the SQL.
 */
function activity_category_sql(string $category, array &$params, string &$types): string
{
    $in = function (array $values) use (&$params, &$types): string {
        foreach ($values as $value) {
            $params[] = $value;
            $types .= "s";
        }
        return implode(", ", array_fill(0, count($values), "?"));
    };
    $known = array_merge(ACTIVITY_SIGNIN_ACTIONS, ACTIVITY_DECISION_ACTIONS, ACTIVITY_GATE_ACTIONS, ACTIVITY_SCHEDULE_ACTIONS);
    $system = "(g.actor_user_id IS NULL AND g.action LIKE 'appointment.%')";
    switch ($category) {
        case "signins":
            return "g.action IN (" . $in(ACTIVITY_SIGNIN_ACTIONS) . ")";
        case "decisions":
            return "g.action IN (" . $in(ACTIVITY_DECISION_ACTIONS) . ")";
        case "gate":
            return "g.action IN (" . $in(ACTIVITY_GATE_ACTIONS) . ")";
        case "schedules":
            return "g.action IN (" . $in(ACTIVITY_SCHEDULE_ACTIONS) . ")";
        case "system":
            return "(" . $system . " AND g.action NOT IN (" . $in($known) . "))";
        case "visitors":
            return "(g.action NOT IN (" . $in($known) . ") AND NOT " . $system
                . " AND (g.action IN (" . $in(ACTIVITY_VISITOR_ACTIONS) . ") OR g.action LIKE 'mobile.%'))";
        case "accounts":
            return "(g.action NOT IN (" . $in($known) . ") AND NOT " . $system
                . " AND g.action NOT IN (" . $in(ACTIVITY_VISITOR_ACTIONS) . ") AND g.action NOT LIKE 'mobile.%')";
        default:
            return "";
    }
}

function activity_when(?string $value): string
{
    if (!$value) {
        return "";
    }
    $time = strtotime($value);
    return $time ? date("M j, Y, g:i A", $time) : "";
}

function activity_status(string $status): string
{
    return ACTIVITY_STATUS_LABELS[$status] ?? str_replace("_", " ", $status);
}

/**
 * @return array{title: string, text: string, tone: string}
 */
/** Title, text, and tone for a visit that ended, by how it ended (presence_service.php). */
function activity_visit_ended(string $method, string $visitorName, string $possessive, string $noun, array $details): array
{
    switch ($method) {
        case "scan":
            return ["Checked out a visitor", "Checked " . $visitorName . " out at the gate", "good"];
        case "left_campus":
            $at = activity_when($details["completed_at"] ?? null);
            return [
                "Visit ended automatically",
                ucfirst($visitorName) . " left the campus without checking out" . ($at !== "" ? " at " . $at : "")
                    . ", so the " . $noun . " ended and location tracking stopped",
                "warn",
            ];
        case "end_of_day":
            return [
                "Visit ended automatically",
                ucfirst($possessive) . " " . $noun . " was still open at the end of the day, so it ended automatically",
                "warn",
            ];
        default:
            return ["Ended a visit", "Ended " . $possessive . " " . $noun . " and stopped location tracking", "muted"];
    }
}

function activity_describe(array $row): array
{
    $action = (string) $row["action"];
    $details = json_decode((string) ($row["details_json"] ?? ""), true);
    $details = is_array($details) ? $details : [];
    $visitor = trim((string) ($row["visitor_full_name"] ?? ""));
    $visitorName = $visitor !== "" ? $visitor : "a visitor";
    $possessive = $visitor !== "" ? $visitor . "'s" : "a visitor's";
    $officeCode = (string) ($row["appointment_office_code"] ?? "");
    $office = $officeCode !== "" ? appointment_office_label($officeCode) : "";
    $when = activity_when($row["scheduled_start_at"] ?? ($row["appointment_at"] ?? null));
    $to = $office !== "" ? " to " . $office : "";
    $on = $when !== "" ? " on " . $when : "";
    $reason = trim((string) ($details["reason"] ?? ""));
    $because = $reason !== "" ? ". Reason: " . $reason : "";
    $targetName = !empty($row["target_username"])
        ? personnel_full_name([
            "first_name" => $row["target_first_name"] ?? "",
            "last_name" => $row["target_last_name"] ?? "",
            "display_name" => $row["target_display_name"] ?? "",
            "username" => $row["target_username"],
        ])
        : trim((string) ($details["name"] ?? ($details["username"] ?? "")));
    $target = $targetName !== "" ? $targetName : "an account";
    $detailOffice = appointment_office_label((string) ($details["office_code"] ?? ($row["entity_type"] === "office" ? $row["entity_id"] : "")));
    $department = (string) ($details["name"] ?? $detailOffice);

    switch ($action) {
        case "auth.signed_in":
            $methods = [
                "password" => "password",
                "password+authenticator" => "password and authenticator code",
                "password+backup_code" => "password and a backup code",
                "password+trusted_device" => "password on a trusted browser",
            ];
            $how = $methods[(string) ($details["method"] ?? "")] ?? "password";
            $device = (string) ($details["device"] ?? "");
            return ["Signed in", "Signed in" . ($device !== "" ? " on " . $device : "") . " with " . $how, "info"];
        case "auth.signed_out":
            return ["Signed out", "Signed out of the website", "muted"];
        case "mobile.signed_in":
            return ["Signed in to the app", "Signed in to the visitor app", "info"];
        case "mobile.signed_out":
            return ["Signed out of the app", "Signed out of the visitor app", "muted"];

        case "appointment.approved":
            return ["Approved an appointment", "Approved " . $possessive . " visit" . $to . $on, "good"];
        case "appointment.rejected":
            return ["Declined an appointment", "Declined " . $possessive . " visit" . $to . $on . $because, "danger"];
        case "appointment.reschedule_proposed":
            $count = (int) ($details["slot_count"] ?? 0);
            return ["Offered another time", "Offered " . $visitorName . " " . ($count === 1 ? "a new time" : $count . " new times") . $to . $because, "warn"];
        case "appointment.stop_completed":
            return ["Marked a meeting done", "Finished " . $possessive . " meeting" . ($office !== "" ? " at " . $office : ""), "good"];

        case "appointment.checked_in":
            return ["Checked in a visitor", "Checked in " . $visitorName . " at the gate" . ($office !== "" ? " for " . $office : ""), "good"];
        case "appointment.completed":
            return activity_visit_ended((string) ($details["method"] ?? ""), $visitorName, $possessive, "visit", $details);
        case "appointment.qr_override_created":
            $until = activity_when($details["valid_until"] ?? null);
            return ["Allowed a pass outside its time", "Let " . $possessive . " QR pass work" . ($until !== "" ? " until " . $until : "") . $because, "warn"];
        case "visit.checked_in":
            return ["Checked in a multi-office visit", "Checked in " . $visitorName . " for a visit to several offices", "good"];
        case "visit.completed":
            if (empty($details["method"])) {
                return ["Ended a multi-office visit", "Ended " . $possessive . " visit to several offices", "muted"];
            }
            return activity_visit_ended((string) $details["method"], $visitorName, $possessive, "visit to several offices", $details);
        case "presence.left_campus":
            $at = activity_when($details["at"] ?? null);
            return [
                "Went outside the campus",
                ucfirst($visitorName) . " went outside the campus boundary" . ($at !== "" ? " at " . $at : "")
                    . ". Their position is hidden while outside",
                "warn",
            ];
        case "presence.returned_to_campus":
            $at = activity_when($details["at"] ?? null);
            return ["Came back inside the campus", ucfirst($visitorName) . " came back inside the campus boundary" . ($at !== "" ? " at " . $at : ""), "info"];
        case "visit.arrived":
            $arrivedAt = $detailOffice !== "" ? $detailOffice : ($office !== "" ? $office : "their office");
            $accuracy = $details["accuracy_meters"] ?? null;
            $how = ($details["method"] ?? "") === "gps"
                ? "confirmed by GPS" . ($accuracy !== null ? " (±" . (int) round((float) $accuracy) . " m)" : "")
                : "confirmed by the visitor";
            return ["Arrived at an office", ucfirst($visitorName) . " arrived at " . $arrivedAt . ", " . $how, "good"];

        case "office.availability_updated":
            $open = !empty($details["accepting_visitors"]);
            return [
                "Updated visiting hours",
                "Changed " . $detailOffice . "'s schedule: " . ($open ? "accepting visitors" : "not accepting visitors")
                    . ", " . (int) ($details["slot_duration_minutes"] ?? 0) . "-minute slots, "
                    . (int) ($details["capacity"] ?? 0) . " per slot, " . (int) ($details["weekly_rule_count"] ?? 0) . " weekly hours",
                $open ? "info" : "warn",
            ];
        case "office.availability_exception_added":
            $opened = !empty($details["is_available"]);
            return [
                $opened ? "Added extra visiting hours" : "Closed the office for a period",
                ($opened ? "Opened " : "Closed ") . $detailOffice . " from " . activity_when($details["starts_at"] ?? null)
                    . " to " . activity_when($details["ends_at"] ?? null) . $because,
                $opened ? "info" : "warn",
            ];
        case "office.availability_exception_removed":
            return ["Removed a schedule change", "Removed a closure or extra hours for " . $detailOffice, "muted"];

        case "appointment.created":
        case "mobile.appointment_created":
            return ["Requested an appointment", $visitorName . " requested a visit" . $to . $on, "info"];
        case "appointment.cancelled":
        case "mobile.appointment_cancelled":
            return ["Cancelled an appointment", $visitorName . " cancelled their visit" . $to . $because, "muted"];
        case "appointment.reschedule_response":
        case "mobile.reschedule_response":
            $response = strtolower((string) ($details["response"] ?? ""));
            $accepted = str_contains($response, "accept");
            return ["Answered a new-time offer", $visitorName . " " . ($accepted ? "accepted" : "turned down") . " the time offered" . $to, $accepted ? "good" : "muted"];
        case "mobile.visit_created":
            return ["Requested a multi-office visit", "Requested a visit to several offices", "info"];
        case "mobile.visit_cancelled":
            return ["Cancelled a multi-office visit", "Cancelled a visit to several offices", "muted"];
        case "mobile.account_registered":
            return ["Registered", "Created a visitor account in the app", "info"];
        case "mobile.location_consent_granted":
            return ["Allowed location tracking", "Agreed to location tracking for a visit", "info"];
        case "mobile.location_consent_withdrawn":
            return ["Stopped location tracking", "Withdrew location consent for a visit", "muted"];
        case "mobile.tracking_started":
            return ["Started sharing location", "Started sharing location during a visit", "info"];

        case "appointment.automatic_status_change":
            return [
                "Status changed automatically",
                ucfirst($possessive) . " appointment changed from " . activity_status((string) ($details["from_status"] ?? ""))
                    . " to " . activity_status((string) ($details["to_status"] ?? "")),
                "muted",
            ];
        case "appointment.reschedule_expired":
            return ["New-time offer expired", $visitorName . " did not answer the time offered in time", "muted"];

        case "user.created":
            $role = personnel_department_label((string) ($details["role"] ?? ""), (string) ($details["office_code"] ?? ""));
            return ["Created an account", "Created " . $target . "'s account (" . $role . ")", "good"];
        case "user.updated":
            $labels = ["first_name" => "first name", "last_name" => "last name", "position" => "position", "email" => "email", "contact_number" => "contact number"];
            $parts = [];
            foreach ((array) ($details["changes"] ?? []) as $field => $change) {
                if ($field === "office_code") {
                    $parts[] = "department (" . ($change["from_label"] ?? $change["from"] ?? "") . " → " . ($change["to_label"] ?? $change["to"] ?? "") . ")";
                } else {
                    $parts[] = $labels[$field] ?? $field;
                }
            }
            return ["Updated account details", "Changed " . $target . "'s " . ($parts ? implode(", ", $parts) : "details"), isset($details["changes"]["office_code"]) ? "warn" : "info"];
        case "user.deleted":
            return ["Removed an account", "Removed " . $target . "'s account", "danger"];
        case "user.suspended":
            return ["Suspended an account", "Suspended " . $target . "'s account", "danger"];
        case "user.activated":
            return ["Activated an account", "Activated " . $target . "'s account", "good"];
        case "user.unlocked":
            return ["Unlocked sign-in", "Unlocked sign-in for " . $target, "good"];
        case "user.password_reset":
            $fromServer = ($details["by"] ?? "") === "command_line" ? " from the server" : "";
            return ["Reset a password", "Issued a temporary password to " . $target . $fromServer, "warn"];
        case "user.signed_out_by_admin":
            return ["Signed a user out", "Signed " . $target . " out on every device", "warn"];
        case "user.profile_updated":
            return ["Updated their profile", !empty($details["profile_image_changed"]) ? "Changed their profile photo" : "Saved their profile with no changes", "muted"];

        case "department.created":
            return ["Added a department", "Added the " . $department . " department", "good"];
        case "department.updated":
            return ["Edited a department", "Edited the " . $department . " department", "info"];
        case "department.archived":
            return ["Archived a department", "Archived " . $department . "; visitors can no longer book it", "danger"];
        case "department.restored":
            return ["Restored a department", "Made " . $department . " active again", "good"];
        case "department.deleted":
            return ["Deleted a department", "Deleted the unused " . $department . " department", "danger"];

        case "campus.map_saved":
            $gpsPins = (int) ($details["gps_pins"] ?? 0);
            return ["Updated the campus map", "Saved the campus map: " . (int) ($details["corners"] ?? 0) . " boundary corners, "
                . (int) ($details["gates"] ?? 0) . " gate(s), " . (int) ($details["offices"] ?? 0) . " department pin(s)"
                . ($gpsPins > 0 ? ", " . $gpsPins . " placed by GPS" : ""), "info"];
        case "campus.route_saved":
            return ["Added a walking route", "Added the route \"" . ($details["name"] ?? "") . "\" ("
                . (int) round((float) ($details["distance_meters"] ?? 0)) . " m, "
                . (($details["method"] ?? "") === "drawn" ? "drawn on the map" : "walked with GPS") . ")", "good"];
        case "campus.route_updated":
            return ["Edited a walking route", "Edited the route \"" . ($details["name"] ?? "") . "\" ("
                . (int) round((float) ($details["distance_meters"] ?? 0)) . " m)", "info"];
        case "campus.route_deleted":
            return ["Removed a walking route", "Removed the route \"" . ($details["name"] ?? "") . "\"", "warn"];

        case "auth.password_changed":
            return ["Changed their password", ($details["during"] ?? "") === "sign_in" ? "Set a new password while signing in" : "Changed it in Account security; other devices were signed out", "info"];
        case "auth.two_factor_enabled":
            return ["Turned on two-step verification", ($details["during"] ?? "") === "sign_in" ? "Set up an authenticator app while signing in" : "Set up an authenticator app in Account security", "good"];
        case "auth.two_factor_disabled":
            $by = (string) ($details["by"] ?? "self");
            return ["Two-step verification turned off", $by === "admin" ? "Reset two-step verification for " . $target : ($by === "command_line" ? "Two-step verification was reset from the server" : "Turned off two-step verification"), "warn"];
        case "auth.backup_code_used":
            return ["Used a backup code", "Signed in with a backup code (" . (int) ($details["remaining"] ?? 0) . " left)", "warn"];
        case "auth.backup_codes_regenerated":
            return ["Created new backup codes", "Created new two-step backup codes", "info"];
        case "auth.device_trusted":
            return ["Trusted a browser", "Skipped two-step codes on " . ($details["device"] ?? "a browser") . " for 30 days", "info"];
        case "auth.trusted_devices_forgotten":
            return ["Forgot trusted browsers", "Removed " . (int) ($details["count"] ?? 0) . " trusted browser(s)", "muted"];
        case "auth.signed_out_other_sessions":
            return ["Signed out other sessions", "Signed out on every other device", "muted"];
        case "auth.lockout":
            return ["Sign-in paused", "Paused sign-in for " . ($details["identifier"] ?? "an account") . " after failed attempts (" . activity_minutes((int) ($details["minutes"] ?? 0)) . ")", "danger"];
        case "auth.account_alarm":
            return ["Account locked", "Locked " . ($details["identifier"] ?? "an account") . " after failed attempts from several networks", "danger"];
        case "auth.lockout_released":
            return ["Released a lockout", "Let a paused sign-in try again", "good"];
        case "activity.exported":
            return [
                "Exported the activity log",
                "Downloaded " . (int) ($details["rows"] ?? 0) . " activity entries (" . ($details["from"] ?? "") . " to " . ($details["to"] ?? "") . ")"
                    . (($details["format"] ?? "") === "xlsx" ? " as an Excel report" : ""),
                "info",
            ];
        case "analytics.reset":
            $count = function (string $key, string $one, string $many) use ($details): ?string {
                $value = (int) ($details[$key] ?? 0);
                return $value > 0 ? number_format($value) . " " . ($value === 1 ? $one : $many) : null;
            };
            $parts = array_filter([
                $count("appointments", "visit record", "visit records"),
                $count("gps_points", "GPS point", "GPS points"),
                $count("tracking_sessions", "tracking session", "tracking sessions"),
                $count("notifications", "notification", "notifications"),
            ]);
            $scope = ($details["scope"] ?? "") === "finished" ? "finished visits" : "all visitor data";
            $with = ($details["confirmed_with"] ?? "") === "backup_code" ? "a backup code" : "an authenticator code";
            return [
                "Reset visitor data",
                "Reset Visitor Analytics (" . $scope . "), confirmed with " . $with . ": "
                    . ($parts ? "deleted " . implode(", ", $parts) : "there was nothing to delete"),
                "danger",
            ];
    }
    return [ucfirst(str_replace(["_", "."], [" ", ": "], $action)), "", "muted"];
}

function activity_minutes(int $minutes): string
{
    return $minutes >= 60 && $minutes % 60 === 0 ? ($minutes / 60) . " h" : $minutes . " min";
}

/** Shape of one entry sent to the browser (and the CSV export). */
function activity_entry(array $row): array
{
    [$title, $text, $tone] = activity_describe($row);
    $actorId = $row["actor_user_id"] !== null ? (int) $row["actor_user_id"] : null;
    $actor = null;
    if ($actorId !== null && $row["actor_username"] !== null) {
        $nameRow = [
            "first_name" => $row["actor_first_name"],
            "last_name" => $row["actor_last_name"],
            "display_name" => $row["actor_display_name"],
            "username" => $row["actor_username"],
        ];
        $actor = [
            "id" => $actorId,
            "name" => personnel_full_name($nameRow),
            "has_name" => personnel_has_name($nameRow),
            "username" => (string) $row["actor_username"],
            "role" => (string) $row["actor_role"],
            "department" => personnel_department_label((string) $row["actor_role"], (string) $row["actor_office_code"]),
            "position" => (string) $row["actor_position"],
        ];
    }
    $details = json_decode((string) ($row["details_json"] ?? ""), true);
    $appointmentId = $row["joined_appointment_id"] !== null ? (int) $row["joined_appointment_id"] : null;
    return [
        "id" => (int) $row["id"],
        "created_at" => $row["created_at"],
        "action" => (string) $row["action"],
        "category" => activity_category((string) $row["action"], $actorId),
        "title" => $title,
        "text" => $text,
        "tone" => $tone,
        "actor" => $actor,
        "appointment_id" => $appointmentId,
        "visitor_name" => (string) ($row["visitor_full_name"] ?? ""),
        "ip" => (string) $row["ip_address"],
        "device" => is_array($details) && !empty($details["device"]) ? (string) $details["device"] : "",
    ];
}

/** Columns shared by every activity query (audit_logs g joined to people and appointments). */
const ACTIVITY_SELECT = "SELECT g.id, g.actor_user_id, g.action, g.entity_type, g.entity_id, g.details_json, g.ip_address, g.created_at,
        u.username AS actor_username, u.first_name AS actor_first_name, u.last_name AS actor_last_name,
        u.display_name AS actor_display_name, u.role AS actor_role, u.office_code AS actor_office_code, u.position AS actor_position,
        a.id AS joined_appointment_id, a.visitor_full_name, a.office_code AS appointment_office_code, a.scheduled_start_at, a.appointment_at,
        t.username AS target_username, t.first_name AS target_first_name, t.last_name AS target_last_name, t.display_name AS target_display_name
     FROM audit_logs g
     LEFT JOIN app_users u ON u.id = g.actor_user_id
     LEFT JOIN appointments a ON a.id = COALESCE(g.appointment_id, CASE WHEN g.entity_type = 'appointment' THEN CAST(g.entity_id AS UNSIGNED) END)
     LEFT JOIN app_users t ON g.entity_type = 'app_user' AND t.id = CAST(g.entity_id AS UNSIGNED)";
