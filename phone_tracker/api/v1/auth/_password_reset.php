<?php
declare(strict_types=1);
// "Forgot password?": a 6-digit code by email, as signing up has (signup_service.php).
// Shared by request_password_reset.php and reset_password.php.
require_once dirname(__DIR__) . "/_bootstrap.php";
require_once dirname(__DIR__, 3) . "/auth_totp.php";

/** How long a code works. */
const API_RESET_CODE_MINUTES = 15;
/** Wrong tries a code takes before it stops working. */
const API_RESET_CODE_TRIES = 5;
/** Codes one account can be sent in an hour, from any network. */
const API_RESET_CODES_PER_HOUR = 5;
/** How long the app waits before it offers to send another code. */
const API_RESET_RESEND_SECONDS = 60;

/**
 * The code is kept only as a hash keyed by the server's secret and tied to its row, so the
 * database alone can't give it away.
 */
function api_reset_code_hash(int $tokenId, string $code): string
{
    return hash_hmac("sha256", "password_reset:" . $tokenId . ":" . $code, auth_secret_key());
}

/**
 * Emails a new code and returns it. The account's earlier code stops working. Returns null, and
 * sends nothing, when the account was already sent its fill of codes this hour.
 */
function api_send_reset_code(mysqli $conn, int $userId, string $email): ?string
{
    $recent = $conn->prepare(
        "SELECT COUNT(*) FROM account_action_tokens
         WHERE user_id = ? AND purpose = 'password_reset' AND created_at > NOW() - INTERVAL 1 HOUR"
    );
    $recent->bind_param("i", $userId);
    $recent->execute();
    $sentThisHour = (int) $recent->get_result()->fetch_row()[0];
    $recent->close();
    if ($sentThisHour >= API_RESET_CODES_PER_HOUR) {
        return null;
    }

    $code = str_pad((string) random_int(0, 999999), 6, "0", STR_PAD_LEFT);
    $minutes = API_RESET_CODE_MINUTES;
    $expires = (new DateTimeImmutable("now"))->modify("+{$minutes} minutes")->format("Y-m-d H:i:s");
    $ip = api_client_ip();
    $conn->begin_transaction();
    try {
        $invalidate = $conn->prepare(
            "UPDATE account_action_tokens SET consumed_at = NOW()
             WHERE user_id = ? AND purpose = 'password_reset' AND consumed_at IS NULL"
        );
        $invalidate->bind_param("i", $userId);
        $invalidate->execute();
        $invalidate->close();

        // The hash needs the row's id, so the row starts with a random stand-in (hashes are unique).
        $standIn = bin2hex(random_bytes(32));
        $insert = $conn->prepare(
            "INSERT INTO account_action_tokens (user_id, purpose, token_hash, expires_at, requested_ip)
             VALUES (?, 'password_reset', ?, ?, ?)"
        );
        $insert->bind_param("isss", $userId, $standIn, $expires, $ip);
        $insert->execute();
        $tokenId = (int) $insert->insert_id;
        $insert->close();
        $hash = api_reset_code_hash($tokenId, $code);
        $store = $conn->prepare("UPDATE account_action_tokens SET token_hash = ? WHERE id = ?");
        $store->bind_param("si", $hash, $tokenId);
        $store->execute();
        $store->close();

        $subject = "Reset your ISATU Visits password";
        $payload = json_encode(["code" => $code, "minutes" => $minutes, "expires_at" => $expires], JSON_UNESCAPED_SLASHES);
        $mail = $conn->prepare(
            "INSERT INTO outbound_emails (user_id, recipient_email, template_key, subject, payload_json)
             VALUES (?, ?, 'password_reset', ?, ?)"
        );
        $mail->bind_param("isss", $userId, $email, $subject, $payload);
        $mail->execute();
        $mail->close();
        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }
    return $code;
}
