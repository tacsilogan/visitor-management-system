<?php
/**
 * Sends the emails the visitor app queues in outbound_emails (password-reset recovery
 * codes and email verification). workers/send_outbound_emails.php runs it every minute
 * through workers/run_scheduled_jobs.php.
 *
 * Choose one way to send, in environment variables (DEPLOYMENT.md, "Email"):
 *   BREVO_API_KEY   Brevo over HTTPS. Free for 300 emails a day, needs no domain, and works
 *                   on Railway's Hobby plan, which blocks SMTP.
 *   RESEND_API_KEY  Resend over HTTPS. Needs a domain you own.
 *   SMTP_HOST       Any SMTP server (school mail, Gmail with an app password), with
 *                   SMTP_PORT, SMTP_ENCRYPTION (tls, ssl, or none), SMTP_USERNAME, SMTP_PASSWORD.
 * plus MAIL_FROM_ADDRESS (a sender address the service has verified) and optionally
 * MAIL_FROM_NAME (the website's emails), MAIL_APP_FROM_NAME (the app's emails, "ISATU Visits")
 * and MAIL_REPLY_TO. MAIL_TRANSPORT (brevo, resend, smtp) picks one when several are set.
 */

require_once __DIR__ . "/runtime.php";
require_once __DIR__ . "/lib/PHPMailer/Exception.php";
require_once __DIR__ . "/lib/PHPMailer/PHPMailer.php";
require_once __DIR__ . "/lib/PHPMailer/SMTP.php";

use PHPMailer\PHPMailer\PHPMailer;

/** Attempts per email before it is given up (a recovery code is only valid for 30 minutes). */
const MAIL_MAX_ATTEMPTS = 5;

function mail_settings(): array
{
    $transport = strtolower(trim((string) isatu_env("MAIL_TRANSPORT", "")));
    if ($transport === "") {
        $transport = isatu_env("BREVO_API_KEY") !== null ? "brevo"
            : (isatu_env("RESEND_API_KEY") !== null ? "resend"
            : (isatu_env("SMTP_HOST") !== null ? "smtp" : ""));
    }
    $encryption = strtolower(trim((string) isatu_env("SMTP_ENCRYPTION", "tls")));
    $defaultPort = $encryption === "ssl" ? "465" : ($encryption === "none" ? "25" : "587");
    return [
        "transport" => $transport,
        "from_address" => trim((string) isatu_env("MAIL_FROM_ADDRESS", (string) isatu_env("SMTP_USERNAME", ""))),
        "from_name" => trim((string) isatu_env("MAIL_FROM_NAME", "ISATU Visitor Management")),
        "reply_to" => trim((string) isatu_env("MAIL_REPLY_TO", "")),
        "brevo_api_key" => (string) isatu_env("BREVO_API_KEY", ""),
        "resend_api_key" => (string) isatu_env("RESEND_API_KEY", ""),
        "smtp_host" => (string) isatu_env("SMTP_HOST", ""),
        "smtp_port" => (int) isatu_env("SMTP_PORT", $defaultPort),
        "smtp_encryption" => $encryption,
        "smtp_username" => (string) isatu_env("SMTP_USERNAME", ""),
        "smtp_password" => (string) isatu_env("SMTP_PASSWORD", ""),
    ];
}

/** Null when email can be sent, otherwise what is missing, in words for the administrator. */
function mail_configuration_problem(?array $settings = null): ?string
{
    $settings = $settings ?? mail_settings();
    $transport = $settings["transport"];
    if ($transport === "") {
        return "Email is not set up. Set BREVO_API_KEY (or RESEND_API_KEY, or SMTP_HOST) and MAIL_FROM_ADDRESS.";
    }
    if (!in_array($transport, ["brevo", "resend", "smtp"], true)) {
        return "MAIL_TRANSPORT must be brevo, resend, or smtp.";
    }
    if ($transport === "brevo" && $settings["brevo_api_key"] === "") {
        return "MAIL_TRANSPORT is brevo but BREVO_API_KEY is empty.";
    }
    if ($transport === "resend" && $settings["resend_api_key"] === "") {
        return "MAIL_TRANSPORT is resend but RESEND_API_KEY is empty.";
    }
    if ($transport === "smtp") {
        if ($settings["smtp_host"] === "") {
            return "MAIL_TRANSPORT is smtp but SMTP_HOST is empty.";
        }
        if (!in_array($settings["smtp_encryption"], ["tls", "ssl", "none"], true)) {
            return "SMTP_ENCRYPTION must be tls, ssl, or none.";
        }
        if ($settings["smtp_port"] < 1 || $settings["smtp_port"] > 65535) {
            return "SMTP_PORT is not a valid port number.";
        }
    }
    if (!filter_var($settings["from_address"], FILTER_VALIDATE_EMAIL)) {
        return "MAIL_FROM_ADDRESS must be the sender's email address.";
    }
    if (in_array($transport, ["brevo", "resend"], true) && !function_exists("curl_init")) {
        return "The PHP cURL extension is required to send email through " . ucfirst($transport) . ".";
    }
    return null;
}

/*
 * Two names send email. People who use the app (visitors and ISATU students) hear from the app,
 * ISATU Visits, with the seal at the top. The website's people (teachers, the Dean's Office, staff)
 * hear from the website, MAIL_FROM_NAME (ISATU Visitor Management).
 */

/** The app's name as an email sender. MAIL_APP_FROM_NAME changes it. */
function mail_app_name(): string
{
    return trim((string) isatu_env("MAIL_APP_FROM_NAME", "ISATU Visits")) ?: "ISATU Visits";
}

/** Whether an email is for someone who uses the app, so it comes from the app's name. */
function mail_is_app_email(string $templateKey, array $payload): bool
{
    if (in_array($templateKey, ["password_reset", "verify_email"], true)) {
        return true;
    }
    return $templateKey === "account_code" && (string) ($payload["account_type"] ?? "visitor") !== "teacher";
}

/** Where pictures in an email are served from: the site's public address, or none, and then no picture. */
function mail_site_base_url(): string
{
    if (function_exists("teacher_site_base_url")) {
        return teacher_site_base_url();
    }
    $configured = trim((string) (getenv("PUBLIC_BASE_URL") ?: ""));
    if ($configured !== "") {
        return rtrim($configured, "/");
    }
    // The email worker runs with no request to read the address from. Railway says what it is.
    $railway = trim((string) (getenv("RAILWAY_PUBLIC_DOMAIN") ?: ""));
    return $railway !== "" ? "https://" . $railway . "/phone_tracker" : "";
}

/** The top of an email: the app's seal and name, or the website's name in small capitals. */
function mail_header_html(bool $app): string
{
    if (!$app) {
        return '<p style="margin:0 0 6px;font-size:13px;font-weight:bold;letter-spacing:.06em;color:#1d4ed8">ISATU VISITOR MANAGEMENT</p>';
    }
    $name = htmlspecialchars(mail_app_name(), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");
    $base = mail_site_base_url();
    $seal = $base !== ""
        ? '<td style="padding:0 12px 0 0;vertical-align:middle"><img src="' . htmlspecialchars($base, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8")
            . '/assets/images/isatu-seal-192.png" width="44" height="44" alt="ISATU" style="display:block;border:0;width:44px;height:44px"></td>'
        : '';
    return '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 6px;border-collapse:collapse"><tr>' . $seal
        . '<td style="vertical-align:middle;font-size:18px;font-weight:bold;color:#003B82">' . $name . '</td></tr></table>'
        . '<div style="height:3px;width:56px;background:#FFCB2F;border-radius:2px;margin:0 0 4px"></div>';
}

/** The name at the end of an email's plain-text version. */
function mail_signature(bool $app): string
{
    return $app ? mail_app_name() : "ISATU Visitor Management";
}

/** The alerts a teacher gets by email: no code, just who wants to see them and a link to answer. */
const MAIL_TEACHER_TEMPLATES = [
    "teacher_request" => ["%s asked to see you on %s.", "Answer this request"],
    "teacher_walk_in" => ["%s wants to see you now. The request closes in 10 minutes.", "Answer this walk-in"],
    "teacher_booked" => ["%s booked a visit with you on %s. It was accepted automatically.", "See your visits"],
    "teacher_reminder" => ["%s is still waiting to hear from you about %s.", "Answer this request"],
];

function mail_render_teacher_alert(string $templateKey, array $payload): array
{
    [$sentence, $action] = MAIL_TEACHER_TEMPLATES[$templateKey];
    $visitor = (string) ($payload["visitor"] ?? "A visitor");
    $when = (string) ($payload["when"] ?? "");
    $intro = sprintf($sentence, $visitor, $when);
    $reason = trim(implode(". ", array_filter([(string) ($payload["purpose"] ?? ""), (string) ($payload["subject"] ?? "")])));
    $greeting = "Hello " . trim((string) ($payload["teacher"] ?? "")) . ",";
    $link = (string) ($payload["link"] ?? "");

    $text = $greeting . "\n\n" . $intro . ($reason !== "" ? "\nReason: " . $reason . "." : "")
        . ($link !== "" ? "\n\n" . $action . ": " . $link : "") . "\n\n" . mail_signature(false) . "\n";

    $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");
    $html = '<!doctype html><html><body style="margin:0;padding:24px;background:#f4f6fa;font-family:Arial,Helvetica,sans-serif;color:#1f2937">'
        . '<div style="max-width:520px;margin:0 auto;background:#ffffff;border:1px solid #e2e8f0;border-radius:12px;padding:28px">'
        . mail_header_html(false)
        . '<p style="margin:16px 0">' . $escape($greeting) . '</p>'
        . '<p style="margin:0 0 8px;line-height:1.5">' . $escape($intro) . '</p>'
        . ($reason !== "" ? '<p style="margin:0 0 18px;line-height:1.5;color:#64748b">Reason: ' . $escape($reason) . '.</p>' : '')
        . ($link !== "" ? '<a href="' . $escape($link) . '" style="display:inline-block;padding:11px 18px;border-radius:8px;background:#0755b5;color:#ffffff;font-weight:bold;text-decoration:none">' . $escape($action) . '</a>' : '')
        . '</div></body></html>';
    return ["text" => $text, "html" => $html];
}

/**
 * The emails about an account that does not exist yet (school_accounts_service.php): the 6-digit code
 * that confirms an email address, and the Dean's Office's answer to a teacher's request.
 */
const MAIL_ACCOUNT_TEMPLATES = ["account_code", "password_reset", "teacher_request_approved", "teacher_request_rejected"];

function mail_render_account(string $templateKey, array $payload, string $recipientName): array
{
    $name = trim((string) ($payload["name"] ?? "")) ?: $recipientName;
    $greeting = $name !== "" ? "Hello " . $name . "," : "Hello,";
    $code = "";
    $outro = "";
    if ($templateKey === "account_code") {
        $code = (string) ($payload["code"] ?? "");
        if (!preg_match('/^\d{6}$/', $code)) {
            throw new InvalidArgumentException("The email has no valid code to send.");
        }
        $minutes = max(1, (int) ($payload["minutes"] ?? 10));
        $intro = match ((string) ($payload["account_type"] ?? "")) {
            "student" => "Enter this code in the " . mail_app_name() . " app to finish creating your student account:",
            "teacher" => "Enter this code on the ISATU website to send your teacher account request:",
            default => "Enter this code in the " . mail_app_name() . " app to finish creating your visitor account:",
        };
        $after = "The code works once and expires in " . $minutes . " minutes.";
        $outro = "If you didn't ask for an ISATU account, ignore this email. Nothing is created without the code.";
    } elseif ($templateKey === "password_reset") {
        $code = (string) ($payload["code"] ?? "");
        if (!preg_match('/^\d{6}$/', $code)) {
            throw new InvalidArgumentException("The email has no valid code to send.");
        }
        $minutes = max(1, (int) ($payload["minutes"] ?? 15));
        $intro = "Someone asked to reset the password of your " . mail_app_name() . " account. If it was you, enter this code in the app with your new password:";
        $after = "The code works once and expires in " . $minutes . " minutes. Asking for another code ends this one.";
        $outro = "If you didn't ask for this, ignore this email. Your password stays the same.";
    } elseif ($templateKey === "teacher_request_approved") {
        $office = (string) ($payload["office"] ?? "Your Dean's Office");
        $intro = $office . " approved your teacher account. Sign in with your school email and the password you chose, then set your hours. Students and visitors can find you once you have.";
        $after = (string) ($payload["link"] ?? "");
    } else {
        $office = (string) ($payload["office"] ?? "Your Dean's Office");
        $reason = trim((string) ($payload["reason"] ?? ""));
        $intro = $office . " did not approve your teacher account request" . ($reason !== "" ? ": " . $reason . "." : ".");
        $after = "If this is a mistake, contact " . $office . ", then request an account again.";
    }

    $app = mail_is_app_email($templateKey, $payload);
    $text = $greeting . "\n\n" . $intro . ($code !== "" ? "\n\n" . $code : "") . ($after !== "" ? "\n\n" . $after : "")
        . ($outro !== "" ? "\n\n" . $outro : "") . "\n\n" . mail_signature($app) . "\n";

    $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");
    $isLink = str_starts_with($after, "http");
    $html = '<!doctype html><html><body style="margin:0;padding:24px;background:#f4f6fa;font-family:Arial,Helvetica,sans-serif;color:#1f2937">'
        . '<div style="max-width:520px;margin:0 auto;background:#ffffff;border:1px solid #e2e8f0;border-radius:12px;padding:28px">'
        . mail_header_html($app)
        . '<p style="margin:16px 0">' . $escape($greeting) . '</p>'
        . '<p style="margin:0 0 16px;line-height:1.5">' . $escape($intro) . '</p>'
        . ($code !== "" ? '<p style="margin:0 0 16px;padding:14px;background:#eef2ff;border-radius:8px;font-family:Consolas,Menlo,monospace;font-size:28px;font-weight:bold;letter-spacing:.3em;text-align:center">' . $escape($code) . '</p>' : '')
        . ($isLink
            ? '<a href="' . $escape($after) . '" style="display:inline-block;padding:11px 18px;border-radius:8px;background:#0755b5;color:#ffffff;font-weight:bold;text-decoration:none">Sign in</a>'
            : ($after !== "" ? '<p style="margin:0 0 16px;line-height:1.5">' . $escape($after) . '</p>' : ''))
        . ($outro !== "" ? '<p style="margin:16px 0 0;line-height:1.5;color:#64748b">' . $escape($outro) . '</p>' : '')
        . '</div></body></html>';
    return ["text" => $text, "html" => $html, "from_name" => $app ? mail_app_name() : null];
}

/**
 * Builds the message for a queued email.
 *
 * @return array{text: string, html: string, from_name: ?string} from_name replaces MAIL_FROM_NAME
 */
function mail_render(string $templateKey, array $payload, string $recipientName): array
{
    if (isset(MAIL_TEACHER_TEMPLATES[$templateKey])) {
        return mail_render_teacher_alert($templateKey, $payload);
    }
    if (in_array($templateKey, MAIL_ACCOUNT_TEMPLATES, true)) {
        return mail_render_account($templateKey, $payload, $recipientName);
    }
    $code = (string) ($payload["token"] ?? "");
    if (!preg_match('/^[a-f0-9]{64}$/', $code)) {
        throw new InvalidArgumentException("The email has no valid code to send.");
    }
    $expires = "";
    try {
        $expiresAt = new DateTimeImmutable((string) ($payload["expires_at"] ?? ""));
        $expires = $expiresAt->format('g:i A \o\n F j, Y');
    } catch (Throwable) {
        $expires = "";
    }
    $greeting = $recipientName !== "" ? "Hello " . $recipientName . "," : "Hello,";

    // Only the older email check still uses a long code. "Forgot password?" sends 6 digits (above).
    $appName = mail_app_name();
    if ($templateKey === "verify_email") {
        $intro = "Confirm the email address of your " . $appName . " account by entering this verification code in the " . $appName . " app:";
        $outro = "If you did not create an " . $appName . " account, ignore this email.";
        $label = "Verification code";
    } else {
        throw new InvalidArgumentException("Unknown email template: " . $templateKey);
    }
    $validity = $expires !== ""
        ? "The code works once and expires at " . $expires . " (Philippine time)."
        : "The code works once and expires soon.";

    $text = $greeting . "\n\n" . $intro . "\n\n" . $code . "\n\n" . $validity . "\n\n" . $outro
        . "\n\n" . mail_signature(true) . "\n";

    $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");
    $html = '<!doctype html><html><body style="margin:0;padding:24px;background:#f4f6fa;font-family:Arial,Helvetica,sans-serif;color:#1f2937">'
        . '<div style="max-width:520px;margin:0 auto;background:#ffffff;border:1px solid #e2e8f0;border-radius:12px;padding:28px">'
        . mail_header_html(true)
        . '<p style="margin:16px 0">' . $escape($greeting) . '</p>'
        . '<p style="margin:0 0 16px;line-height:1.5">' . $escape($intro) . '</p>'
        . '<p style="margin:0 0 6px;font-size:13px;color:#64748b">' . $escape($label) . '</p>'
        . '<p style="margin:0 0 16px;padding:14px;background:#eef2ff;border-radius:8px;font-family:Consolas,Menlo,monospace;font-size:15px;word-break:break-all;user-select:all">' . $escape($code) . '</p>'
        . '<p style="margin:0 0 16px;line-height:1.5">' . $escape($validity) . '</p>'
        . '<p style="margin:0;line-height:1.5;color:#64748b">' . $escape($outro) . '</p>'
        . '</div></body></html>';

    return ["text" => $text, "html" => $html, "from_name" => $appName];
}

/** POSTs JSON and returns [HTTP status, decoded body]. */
function mail_post_json(string $url, array $headers, array $body): array
{
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => array_merge($headers, [
            "Content-Type: application/json",
            "Accept: application/json",
            "User-Agent: ISATU-Visitor-Management/1.0",
        ]),
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
    ]);
    $response = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);
    if ($response === false) {
        throw new RuntimeException("Could not reach the email service: " . ($error ?: "no response"));
    }
    $decoded = json_decode((string) $response, true);
    return [$status, is_array($decoded) ? $decoded : ["raw" => substr((string) $response, 0, 300)]];
}

/**
 * Sends one email and returns the provider's message id (empty when it gives none).
 * Throws with the provider's reason when the email was not accepted.
 */
function mail_send(array $settings, string $to, string $toName, string $subject, string $text, string $html): string
{
    $fromName = $settings["from_name"];
    $fromAddress = $settings["from_address"];
    $replyTo = filter_var($settings["reply_to"], FILTER_VALIDATE_EMAIL) ? $settings["reply_to"] : "";

    if ($settings["transport"] === "brevo") {
        $body = [
            "sender" => ["name" => $fromName, "email" => $fromAddress],
            "to" => [$toName !== "" ? ["email" => $to, "name" => $toName] : ["email" => $to]],
            "subject" => $subject,
            "htmlContent" => $html,
            "textContent" => $text,
        ];
        if ($replyTo !== "") {
            $body["replyTo"] = ["email" => $replyTo];
        }
        [$status, $response] = mail_post_json("https://api.brevo.com/v3/smtp/email", ["api-key: " . $settings["brevo_api_key"]], $body);
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException("Brevo refused the email (HTTP " . $status . "): " . (string) ($response["message"] ?? $response["code"] ?? $response["raw"] ?? ""));
        }
        return (string) ($response["messageId"] ?? "");
    }

    if ($settings["transport"] === "resend") {
        $from = $fromName !== "" ? $fromName . " <" . $fromAddress . ">" : $fromAddress;
        $body = ["from" => $from, "to" => [$to], "subject" => $subject, "html" => $html, "text" => $text];
        if ($replyTo !== "") {
            $body["reply_to"] = $replyTo;
        }
        [$status, $response] = mail_post_json("https://api.resend.com/emails", ["Authorization: Bearer " . $settings["resend_api_key"]], $body);
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException("Resend refused the email (HTTP " . $status . "): " . (string) ($response["message"] ?? $response["name"] ?? $response["raw"] ?? ""));
        }
        return (string) ($response["id"] ?? "");
    }

    $mailer = new PHPMailer(true);
    $mailer->isSMTP();
    $mailer->Host = $settings["smtp_host"];
    $mailer->Port = $settings["smtp_port"];
    $mailer->Timeout = 20;
    $mailer->CharSet = PHPMailer::CHARSET_UTF8;
    // Message ids name the sender's domain instead of the server's machine name, and the
    // mailer's version is not advertised.
    $mailer->Hostname = substr((string) strrchr($fromAddress, "@"), 1);
    $mailer->XMailer = " ";
    if ($settings["smtp_encryption"] === "ssl") {
        $mailer->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    } elseif ($settings["smtp_encryption"] === "tls") {
        $mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    } else {
        $mailer->SMTPSecure = "";
        $mailer->SMTPAutoTLS = false;
    }
    if ($settings["smtp_username"] !== "") {
        $mailer->SMTPAuth = true;
        $mailer->Username = $settings["smtp_username"];
        $mailer->Password = $settings["smtp_password"];
    }
    $mailer->setFrom($fromAddress, $fromName);
    $mailer->addAddress($to, $toName);
    if ($replyTo !== "") {
        $mailer->addReplyTo($replyTo);
    }
    $mailer->Subject = $subject;
    $mailer->isHTML(true);
    $mailer->Body = $html;
    $mailer->AltBody = $text;
    $mailer->send();
    return (string) $mailer->getLastMessageID();
}

/**
 * Sends queued emails that are due. Failures are retried after 1, 2, 4, and 8 minutes; a
 * code that expires first is not sent at all. Once an email is sent or given up, its code
 * is removed from the queue so the table never holds a usable recovery code.
 *
 * @return array{sent: int, failed: int, expired: int}
 */
function mail_process_queue(mysqli $conn, array $settings, int $limit = 25): array
{
    $counts = ["sent" => 0, "failed" => 0, "expired" => 0];
    // An email left "sending" by a run that stopped part-way is tried again.
    $conn->query(
        "UPDATE outbound_emails
         SET status = 'failed', next_attempt_at = NOW(), last_error = 'Recovered an interrupted send'
         WHERE status = 'sending' AND updated_at < NOW() - INTERVAL 10 MINUTE"
    );
    $maxAttempts = MAIL_MAX_ATTEMPTS;
    $limit = max(1, min(100, $limit));
    $result = $conn->query(
        "SELECT e.id, e.recipient_email, e.template_key, e.subject, e.payload_json, e.attempt_count,
                COALESCE(u.display_name, '') AS display_name
         FROM outbound_emails e
         LEFT JOIN app_users u ON u.id = e.user_id
         WHERE e.status IN ('queued', 'failed') AND e.next_attempt_at <= NOW()
           AND e.attempt_count < {$maxAttempts}
         ORDER BY e.id ASC
         LIMIT {$limit}"
    );

    while ($email = $result->fetch_assoc()) {
        mail_deliver_row($conn, $settings, $email, $counts);
    }
    return $counts;
}

/**
 * Sends one queued email straight away, for a code someone is waiting for on screen. When email is
 * not set up, or the send fails, the email stays queued for the next run of the queue.
 */
function mail_send_now(mysqli $conn, int $emailId): bool
{
    $settings = mail_settings();
    if (mail_configuration_problem($settings) !== null) {
        return false;
    }
    $maxAttempts = MAIL_MAX_ATTEMPTS;
    $stmt = $conn->prepare(
        "SELECT e.id, e.recipient_email, e.template_key, e.subject, e.payload_json, e.attempt_count,
                COALESCE(u.display_name, '') AS display_name
         FROM outbound_emails e
         LEFT JOIN app_users u ON u.id = e.user_id
         WHERE e.id = ? AND e.status IN ('queued', 'failed') AND e.attempt_count < {$maxAttempts}"
    );
    $stmt->bind_param("i", $emailId);
    $stmt->execute();
    $email = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$email) {
        return false;
    }
    $counts = ["sent" => 0, "failed" => 0, "expired" => 0];
    mail_deliver_row($conn, $settings, $email, $counts);
    return $counts["sent"] === 1;
}

/** Claims one queued email and sends it. Another run that claimed it first wins. */
function mail_deliver_row(mysqli $conn, array $settings, array $email, array &$counts): void
{
    $emailId = (int) $email["id"];
    $claim = $conn->prepare(
        "UPDATE outbound_emails SET status = 'sending', attempt_count = attempt_count + 1
         WHERE id = ? AND status IN ('queued', 'failed')"
    );
    $claim->bind_param("i", $emailId);
    $claim->execute();
    $claimed = $claim->affected_rows === 1;
    $claim->close();
    if (!$claimed) {
        return;
    }
    $attempt = (int) $email["attempt_count"] + 1;
    $payload = json_decode((string) $email["payload_json"], true);
    $payload = is_array($payload) ? $payload : [];

    $expiresAt = strtotime((string) ($payload["expires_at"] ?? ""));
    if ($expiresAt !== false && $expiresAt <= time()) {
        mail_finish($conn, $emailId, "failed", "Not sent: the code expired before it could be delivered.", true);
        $counts["expired"]++;
        return;
    }

    try {
        $message = mail_render((string) $email["template_key"], $payload, trim((string) $email["display_name"]));
    } catch (InvalidArgumentException $error) {
        mail_finish($conn, $emailId, "failed", "Not sent: " . $error->getMessage(), true);
        $counts["failed"]++;
        return;
    }

    // App emails come from the app's name. The address stays MAIL_FROM_ADDRESS.
    if (($message["from_name"] ?? null) !== null) {
        $settings["from_name"] = $message["from_name"];
    }

    try {
        mail_send(
            $settings,
            (string) $email["recipient_email"],
            trim((string) $email["display_name"]),
            (string) $email["subject"],
            $message["text"],
            $message["html"]
        );
        mail_finish($conn, $emailId, "sent", "", true);
        $counts["sent"]++;
    } catch (Throwable $error) {
        $giveUp = $attempt >= MAIL_MAX_ATTEMPTS;
        $delayMinutes = 2 ** min(4, $attempt - 1);
        mail_finish($conn, $emailId, "failed", $error->getMessage(), $giveUp, $delayMinutes);
        error_log("Email " . $emailId . " was not sent (attempt " . $attempt . "): " . $error->getMessage());
        $counts["failed"]++;
    }
}

/** Records the outcome of one email; $final removes its code from the queue. */
function mail_finish(mysqli $conn, int $emailId, string $status, string $error, bool $final, int $retryMinutes = 0): void
{
    $error = mb_substr($error, 0, 1000);
    // A given-up email keeps attempt_count at the limit so it is never picked up again.
    $attemptFloor = $final && $status !== "sent" ? MAIL_MAX_ATTEMPTS : 0;
    $removeCode = $final ? 1 : 0;
    $update = $conn->prepare(
        "UPDATE outbound_emails
         SET status = ?, last_error = ?,
             sent_at = IF(? = 'sent', NOW(), sent_at),
             next_attempt_at = NOW() + INTERVAL ? MINUTE,
             attempt_count = GREATEST(attempt_count, ?),
             payload_json = IF(? = 1, NULL, payload_json)
         WHERE id = ?"
    );
    $update->bind_param("sssiiii", $status, $error, $status, $retryMinutes, $attemptFloor, $removeCode, $emailId);
    $update->execute();
    $update->close();
}
