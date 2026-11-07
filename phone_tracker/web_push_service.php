<?php

/*
 * Phone alerts for teachers, while the dashboard is closed (web push). The push carries no message:
 * it only wakes teacher-sw.js on the teacher's phone, which then asks this server what's new, using
 * the teacher's own session. So no visitor's name ever passes through the browser maker's servers.
 *
 * Signing uses a key pair (VAPID) that the server makes once and keeps in mobile_api_settings.
 * Nothing needs setting up. Set WEB_PUSH_CONTACT to change the contact sent with each push.
 */

require_once __DIR__ . "/runtime.php";

/** Only the browser makers' push services: a teacher's account can't make this server call anywhere else. */
const WEB_PUSH_HOST_ENDINGS = [
    "fcm.googleapis.com",
    ".push.apple.com",
    ".push.services.mozilla.com",
    ".notify.windows.com",
];

function web_push_encode(string $bytes): string
{
    return rtrim(strtr(base64_encode($bytes), "+/", "-_"), "=");
}

function web_push_decode(string $text): string
{
    return (string) base64_decode(strtr($text, "-_", "+/"), true);
}

function web_push_allowed_endpoint(string $endpoint): bool
{
    $parts = parse_url($endpoint);
    if (($parts["scheme"] ?? "") !== "https" || empty($parts["host"]) || strlen($endpoint) > 1024) {
        return false;
    }
    $host = strtolower((string) $parts["host"]);
    foreach (WEB_PUSH_HOST_ENDINGS as $ending) {
        if ($host === ltrim($ending, ".") || str_ends_with($host, $ending)) {
            return true;
        }
    }
    return false;
}

/**
 * The server's key pair: the private number and the public point, both raw and url-safe encoded.
 *
 * @return array{public:string, private:string}
 */
function web_push_keys(mysqli $conn): array
{
    $read = function () use ($conn): array {
        $keys = [];
        $result = $conn->query("SELECT setting_key, setting_value FROM mobile_api_settings WHERE setting_key IN ('web_push_public_key', 'web_push_private_key')");
        while ($row = $result->fetch_assoc()) {
            $keys[(string) $row["setting_key"]] = (string) $row["setting_value"];
        }
        return $keys;
    };
    $saved = $read();
    if (empty($saved["web_push_public_key"]) || empty($saved["web_push_private_key"])) {
        $made = web_push_generate_keys();
        // INSERT IGNORE: if two requests make keys at once, the first one saved wins for both.
        $save = $conn->prepare("INSERT IGNORE INTO mobile_api_settings (setting_key, setting_value) VALUES (?, ?)");
        foreach (["web_push_public_key" => $made["public"], "web_push_private_key" => $made["private"]] as $key => $value) {
            $save->bind_param("ss", $key, $value);
            $save->execute();
        }
        $save->close();
        $saved = $read();
    }
    return ["public" => $saved["web_push_public_key"], "private" => $saved["web_push_private_key"]];
}

/** @return array{public:string, private:string} */
function web_push_generate_keys(): array
{
    $options = ["curve_name" => "prime256v1", "private_key_type" => OPENSSL_KEYTYPE_EC];
    $key = @openssl_pkey_new($options);
    if ($key === false) {
        // XAMPP on Windows doesn't tell OpenSSL where its settings file is.
        foreach (["C:/xampp/php/extras/openssl/openssl.cnf", "C:/xampp/apache/conf/openssl.cnf"] as $config) {
            if (is_file($config)) {
                $key = @openssl_pkey_new($options + ["config" => $config]);
                if ($key !== false) {
                    break;
                }
            }
        }
    }
    if ($key === false) {
        throw new RuntimeException("Could not create the phone alert keys: " . (string) openssl_error_string());
    }
    $ec = openssl_pkey_get_details($key)["ec"];
    $pad = fn(string $value): string => str_pad($value, 32, "\0", STR_PAD_LEFT);
    return [
        "public" => web_push_encode("\x04" . $pad($ec["x"]) . $pad($ec["y"])),
        "private" => web_push_encode($pad($ec["d"])),
    ];
}

/** The raw key pair as the PEM text OpenSSL signs with (an EC key on the P-256 curve). */
function web_push_private_pem(array $keys): string
{
    $der = "\x30\x77\x02\x01\x01\x04\x20" . web_push_decode($keys["private"])
        . "\xa0\x0a\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07"
        . "\xa1\x44\x03\x42\x00" . web_push_decode($keys["public"]);
    return "-----BEGIN EC PRIVATE KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END EC PRIVATE KEY-----\n";
}

/** OpenSSL gives an ECDSA signature as DER; a JWT wants the two numbers side by side, 32 bytes each. */
function web_push_raw_signature(string $der): string
{
    $offset = 2;
    if ((ord($der[1]) & 0x80) !== 0) {
        $offset += ord($der[1]) & 0x7f;
    }
    $numbers = [];
    for ($i = 0; $i < 2; $i++) {
        $length = ord($der[$offset + 1]);
        $number = substr($der, $offset + 2, $length);
        $numbers[] = str_pad(ltrim($number, "\0"), 32, "\0", STR_PAD_LEFT);
        $offset += 2 + $length;
    }
    return $numbers[0] . $numbers[1];
}

/** The signed token (VAPID) that proves a push comes from this server. */
function web_push_token(string $endpoint, array $keys, int $now): string
{
    $parts = parse_url($endpoint);
    $contact = trim((string) isatu_env("WEB_PUSH_CONTACT", ""));
    if ($contact === "") {
        $from = trim((string) isatu_env("MAIL_FROM_ADDRESS", ""));
        $contact = $from !== "" ? "mailto:" . $from : "https://isatu.edu.ph";
    }
    $header = web_push_encode((string) json_encode(["typ" => "JWT", "alg" => "ES256"]));
    $claims = web_push_encode((string) json_encode([
        "aud" => $parts["scheme"] . "://" . $parts["host"],
        "exp" => $now + 12 * 3600,
        "sub" => $contact,
    ], JSON_UNESCAPED_SLASHES));
    $signature = "";
    if (!openssl_sign($header . "." . $claims, $signature, web_push_private_pem($keys), OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException("Could not sign the phone alert: " . (string) openssl_error_string());
    }
    return $header . "." . $claims . "." . web_push_encode(web_push_raw_signature($signature));
}

/** Wakes one phone. Returns the push service's status: 201 is sent, 404 and 410 mean it's gone. */
function web_push_send(string $endpoint, array $keys): int
{
    $curl = curl_init($endpoint);
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => "",
        CURLOPT_HTTPHEADER => [
            "TTL: 600",
            "Urgency: high",
            "Content-Length: 0",
            "Authorization: vapid t=" . web_push_token($endpoint, $keys, time()) . ", k=" . $keys["public"],
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    return $status;
}

/**
 * Sends what's waiting: one wake-up per teacher, however many notifications they have.
 *
 * @return array{teachers:int, sent:int, removed:int, failed:int}
 */
function web_push_deliver(mysqli $conn): array
{
    $counts = ["teachers" => 0, "sent" => 0, "removed" => 0, "failed" => 0];
    $result = $conn->query(
        "SELECT n.recipient_user_id, GROUP_CONCAT(n.id) AS notification_ids
         FROM app_notifications n
         INNER JOIN app_users u ON u.id = n.recipient_user_id AND u.role = 'teacher' AND u.is_active = 1
         WHERE n.web_pushed_at IS NULL AND n.read_at IS NULL AND n.created_at > NOW() - INTERVAL 15 MINUTE
         GROUP BY n.recipient_user_id
         LIMIT 50"
    );
    $waiting = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    if (!$waiting) {
        return $counts;
    }
    $keys = web_push_keys($conn);
    foreach ($waiting as $row) {
        $userId = (int) $row["recipient_user_id"];
        $ids = implode(",", array_map("intval", explode(",", (string) $row["notification_ids"])));
        $conn->query("UPDATE app_notifications SET web_pushed_at = NOW() WHERE id IN ({$ids})");
        $counts["teachers"]++;
        $phones = $conn->prepare("SELECT id, endpoint FROM web_push_subscriptions WHERE user_id = ?");
        $phones->bind_param("i", $userId);
        $phones->execute();
        foreach ($phones->get_result()->fetch_all(MYSQLI_ASSOC) as $phone) {
            $id = (int) $phone["id"];
            try {
                $status = web_push_send((string) $phone["endpoint"], $keys);
            } catch (Throwable $error) {
                error_log("Phone alert failed: " . $error->getMessage());
                $status = 0;
            }
            if ($status >= 200 && $status < 300) {
                $counts["sent"]++;
                $conn->query("UPDATE web_push_subscriptions SET last_sent_at = NOW(), failure_count = 0 WHERE id = {$id}");
            } elseif ($status === 404 || $status === 410) {
                // The teacher turned alerts off in the browser, or the phone is gone.
                $counts["removed"]++;
                $conn->query("DELETE FROM web_push_subscriptions WHERE id = {$id}");
            } else {
                $counts["failed"]++;
                error_log("Phone alert: the push service answered " . $status);
                $conn->query("UPDATE web_push_subscriptions SET failure_count = failure_count + 1 WHERE id = {$id}");
            }
        }
        $phones->close();
    }
    $conn->query("DELETE FROM web_push_subscriptions WHERE failure_count >= 20");
    return $counts;
}
