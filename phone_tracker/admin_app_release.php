<?php
/*
 * Admin › Menu › Visitor app (GROUP_VISITS.md): the version visitors download, a new version,
 * and going back.
 *   GET                                   the current and previous versions, a checked upload, and numbers
 *   POST (multipart) action=check, apk    checks an uploaded APK and keeps it, ready to publish
 *   POST {"action": "publish", "release_id": n, "whats_new": "…"}   makes it the version visitors get
 *   POST {"action": "go_back"}            makes the previous version current again
 *   POST {"action": "discard", "release_id": n}   drops a checked upload
 * Publishing and going back ask "Confirm it's you" (require_step_up_json()).
 * phone_tracker/.htaccess lets this one script take uploads of up to 110 MB.
 */
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/session_bootstrap.php";
require_once __DIR__ . "/app_release_service.php";

auth_json_exception_guard();
require_admin_json();

function admin_app_release_overview(mysqli $conn): array
{
    $current = app_release_current($conn);
    $previous = app_release_by_status($conn, "previous");
    $checked = app_release_by_status($conn, "checked");
    return [
        "success" => true,
        "ready" => app_release_schema_ready($conn),
        "current" => $current ? app_release_row($current) : null,
        "previous" => $previous ? app_release_row($previous) : null,
        "checked" => $checked ? app_release_row($checked) : null,
        "stats" => app_release_stats($conn),
        "page_url" => app_release_site_url(),
        "max_mb" => APP_RELEASE_MAX_BYTES / 1024 / 1024,
    ];
}

if ($_SERVER["REQUEST_METHOD"] === "GET") {
    auth_json(admin_app_release_overview($conn));
}
auth_require_method("POST");
$adminUserId = (int) $_SESSION["user_id"];

// The upload comes as a form; everything else as JSON.
$isUpload = isset($_POST["action"]) && $_POST["action"] === "check";
if ($isUpload) {
    $file = $_FILES["apk"] ?? null;
    if (!$file || !is_array($file) || ($file["error"] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $code = (int) ($file["error"] ?? UPLOAD_ERR_NO_FILE);
        $message = in_array($code, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
            ? "The file is too big for the server. Up to " . (APP_RELEASE_MAX_BYTES / 1024 / 1024) . " MB."
            : ($code === UPLOAD_ERR_NO_FILE ? "Choose the app file (.apk)." : "The upload didn't finish. Try again.");
        auth_json(["success" => false, "message" => $message], 422);
    }
    try {
        $checked = app_release_check_upload($conn, (string) $file["tmp_name"], (int) $file["size"], $adminUserId);
        auth_json(["success" => true, "checks" => $checked["checks"]] + admin_app_release_overview($conn));
    } catch (InvalidArgumentException $error) {
        auth_json(["success" => false, "message" => $error->getMessage()], 422);
    }
}

$input = json_decode((string) file_get_contents("php://input"), true);
if (!is_array($input)) {
    // A form post that was cut off for being bigger than post_max_size arrives empty.
    auth_json(["success" => false, "message" => "The file is too big for the server, or the request was empty."], 422);
}
$action = (string) ($input["action"] ?? "");
try {
    switch ($action) {
        case "publish":
            require_step_up_json();
            $release = app_release_publish($conn, (int) ($input["release_id"] ?? 0), (string) ($input["whats_new"] ?? ""), $adminUserId);
            auth_json(["message" => "Version {$release["version_name"]} is now on the download page."] + admin_app_release_overview($conn));
        case "go_back":
            require_step_up_json();
            $release = app_release_go_back($conn, $adminUserId);
            auth_json(["message" => "Visitors now get version {$release["version_name"]} again."] + admin_app_release_overview($conn));
        case "discard":
            app_release_discard($conn, (int) ($input["release_id"] ?? 0));
            auth_json(admin_app_release_overview($conn));
        default:
            auth_json(["success" => false, "message" => "Choose what to do."], 422);
    }
} catch (DomainException $error) {
    auth_json(["success" => false, "message" => $error->getMessage()], 409);
}
