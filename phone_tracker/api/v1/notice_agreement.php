<?php
declare(strict_types=1);
require_once __DIR__ . "/_bootstrap.php";

// The visitor agreed to this version of the Terms and Privacy Notice, including "I am 18 or older,
// or my parent or guardian agrees for me" (AUTH_SECURITY.md, "Visitors' own accounts"). The app
// sends it when it asks an account to agree again after the notice changes; sign-up records it
// by itself.

api_require_method("POST");
$user = api_require_visitor();
$input = api_body();
$version = api_text($input, "version", 40);
if (!preg_match(VISITOR_NOTICE_VERSION_PATTERN, $version)) {
    api_fail("Unknown notice version", 422, ["version" => "Unknown notice version"]);
}
if (!visitor_rights_schema_ready($conn)) {
    api_fail("Database update required: import phone_tracker/visitor_rights_migration.sql", 503);
}
$userId = (int) $user["id"];
visitor_record_notice_agreement($conn, $userId, $version);
api_audit($conn, $userId, "mobile.notice_agreed", "app_user", (string) $userId, ["version" => $version]);
$user["notice_version"] = $version;
api_success(["user" => api_user_payload($user)], 200, "Thank you. Your agreement is saved.");
