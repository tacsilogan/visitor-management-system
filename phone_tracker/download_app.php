<?php
/*
 * The visitor app download (GROUP_VISITS.md): the version the Admin published in Admin › Menu ›
 * Visitor app, from the private folder it is kept in. Public: the site's main page links here.
 * Each download is counted, with only the time.
 */
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/app_release_service.php";

$release = app_release_current($conn);
$path = $release ? app_release_dir() . "/" . basename((string) $release["file_name"]) : "";
if (!$release || !is_file($path)) {
    $conn->close();
    http_response_code(404);
    header("Content-Type: text/html; charset=utf-8");
    $home = htmlspecialchars(app_release_site_url() ?: "../", ENT_QUOTES);
    echo "<!DOCTYPE html><html lang=\"en\"><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">"
        . "<title>ISATU Visits</title></head><body style=\"font-family:system-ui,sans-serif;padding:24px;line-height:1.5\">"
        . "<h1 style=\"font-size:20px\">The app isn't on this website yet</h1>"
        . "<p>Ask the guard at the gate: they can register your visit without the app.</p>"
        . "<p><a href=\"{$home}\">Back</a></p></body></html>";
    exit;
}

if (($_SERVER["REQUEST_METHOD"] ?? "GET") === "GET") {
    try {
        app_release_count_download($conn, (int) $release["id"]);
    } catch (Throwable $error) {
        error_log("download_app.php could not count a download: " . $error->getMessage());
    }
}
$conn->close();

$name = "ISATU-Visits-" . preg_replace('/[^0-9A-Za-z.\-]/', "", (string) $release["version_name"]) . ".apk";
header("Content-Type: application/vnd.android.package-archive");
header("Content-Disposition: attachment; filename=\"{$name}\"");
header("Content-Length: " . filesize($path));
header("Cache-Control: no-store");
if (($_SERVER["REQUEST_METHOD"] ?? "GET") === "HEAD") {
    exit;
}
while (ob_get_level() > 0) {
    ob_end_clean();
}
readfile($path);
