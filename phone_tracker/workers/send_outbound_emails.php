<?php
declare(strict_types=1);

if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}

/*
 * Sends the visitor emails waiting in outbound_emails (mail_service.php).
 *   php workers/send_outbound_emails.php                    send what is due
 *   php workers/send_outbound_emails.php --test you@isatu   send one test email now
 * Exit code 2 means email is not set up yet; queued emails wait until it is.
 */

require_once dirname(__DIR__) . "/db.php";
require_once dirname(__DIR__) . "/mail_service.php";

$settings = mail_settings();
$problem = mail_configuration_problem($settings);
if ($problem !== null) {
    fwrite(STDERR, $problem . "\n");
    exit(2);
}

$testIndex = array_search("--test", $argv, true);
if ($testIndex !== false) {
    $to = strtolower(trim((string) ($argv[$testIndex + 1] ?? "")));
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        fwrite(STDERR, "Usage: php workers/send_outbound_emails.php --test you@example.com\n");
        exit(1);
    }
    // Sent the way the app's codes are, from the app's name with the seal, so this shows what people get.
    $app = mail_app_name();
    $settings["from_name"] = $app;
    $text = "This is a test from " . $app . ". Email is working: visitors will receive their codes.\n\n" . $app . "\n";
    $html = '<!doctype html><html><body style="margin:0;padding:24px;background:#f4f6fa;font-family:Arial,Helvetica,sans-serif;color:#1f2937">'
        . '<div style="max-width:520px;margin:0 auto;background:#ffffff;border:1px solid #e2e8f0;border-radius:12px;padding:28px">'
        . mail_header_html(true)
        . '<p style="margin:16px 0 0;line-height:1.5">This is a test from ' . htmlspecialchars($app, ENT_QUOTES, "UTF-8")
        . '. Email is working: visitors will receive their codes.</p></div></body></html>';
    try {
        $messageId = mail_send($settings, $to, "", $app . " test email", $text, $html);
    } catch (Throwable $error) {
        fwrite(STDERR, "The test email was not sent through " . $settings["transport"] . ": " . $error->getMessage() . "\n");
        exit(1);
    }
    echo "Test email accepted by " . $settings["transport"] . " for " . $to . ($messageId !== "" ? " (message " . $messageId . ")" : "") . ".\n";
    exit(0);
}

try {
    $counts = mail_process_queue($conn, $settings);
} catch (mysqli_sql_exception $error) {
    fwrite(STDERR, "The email queue could not be read: " . $error->getMessage() . ". Import mobile_api_migration.sql.\n");
    exit(1);
}
echo "Email worker complete: {$counts['sent']} sent, {$counts['failed']} failed, {$counts['expired']} expired.\n";
