<?php
declare(strict_types=1);
require_once __DIR__ . "/_bootstrap.php";
require_once dirname(__DIR__, 2) . "/app_release_service.php";

// The newest version of the visitor app on the website (GROUP_VISITS.md). The app (0.13.0 and
// later) compares version_code with its own and, when this one is newer, offers "Update", which
// opens page_url. No sign-in: anyone may know which version is current.
api_require_method("GET");
$release = app_release_current($conn);
api_success([
    "version_name" => $release ? (string) $release["version_name"] : null,
    "version_code" => $release ? (int) $release["version_code"] : 0,
    "whats_new" => $release ? (string) $release["whats_new"] : "",
    "published_at" => $release["published_at"] ?? null,
    "page_url" => app_release_site_url(),
]);
